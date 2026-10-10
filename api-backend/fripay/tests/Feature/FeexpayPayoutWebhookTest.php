<?php

namespace Tests\Feature;

use App\Jobs\ProcessFeexpayWebhook;
use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use App\Models\WebhookEvent;
use App\Services\PaymentLinkService;
use App\Services\TopupService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Finalisation des PAYOUTS (FriPay -> MTN/Moov/Celtiis via FeexPay) par webhook.
 *
 * Valide :
 * - SUCCESSFUL : transaction « succeeded », aucun remboursement ;
 * - FAILED : transaction « failed » + remboursement EXACT, une seule fois ;
 * - rejeu d'un webhook : aucun double remboursement (idempotence) ;
 * - webhook sans secret valide : ignoré (aucune finalisation, aucun remboursement) ;
 * - webhook arrivé AVANT l'enregistrement de la référence FeexPay : retrouvé
 *   via callback_info (référence FriPay) ;
 * - statut PENDING : aucun changement ;
 * - le contrôleur calcule bien « trusted » à partir de ?token=.
 */
class FeexpayPayoutWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID    = 'payout-user-1';
    private const ACCOUNT_ID = 'payout-account-1';
    private const FEEX_REF   = 'FEEX-PAYOUT-001';

    protected function setUp(): void
    {
        parent::setUp();

        // Hermétique : aucun appel réseau réel (le .env local peut contenir
        // de vraies clés FeexPay).
        Http::fake(['*' => Http::response([], 404)]);

        if (DB::table('operators')->where('code', 'MTN')->doesntExist()) {
            (new \Database\Seeders\OperatorSeeder)->run();
        }

        DB::table('users')->insert([
            'id' => self::USER_ID, 'phone_number' => '+22990001001',
            'first_name' => 'Payout', 'last_name' => 'Tester', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('linked_accounts')->insert([
            'id' => self::ACCOUNT_ID, 'user_id' => self::USER_ID,
            'operator_id' => (int) DB::table('operators')->where('code', 'MTN')->value('id'),
            'msisdn' => '+22990001001', 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** Wallet crédité à 5000, puis payout de 1000 (+15 de frais) débité. */
    private function makePayout(string $status = 'processing', ?string $externalReference = self::FEEX_REF): Transaction
    {
        $wallets = app(WalletService::class);
        $wallets->credit(self::USER_ID, 5000, null, 'manual_topup', 'Solde de test');

        $transaction = Transaction::create([
            'reference'            => 'TXN-PAYOUT-001',
            'idempotency_key'      => 'payout-key-001',
            'sender_user_id'       => self::USER_ID,
            'sender_account_id'    => self::ACCOUNT_ID,
            'recipient_phone'      => '+22990001002',
            'recipient_operator_id' => (int) DB::table('operators')->where('code', 'MTN')->value('id'),
            'amount'               => 1000,
            'currency'             => 'XOF',
            'fee_amount'           => 15,
            'total_debited'        => 1015,
            'rail_used'            => 'aggregator',
            'aggregator_provider'  => 'feexpay',
            'status'               => $status,
            'external_reference'   => $externalReference,
            'client_type_snapshot' => 'P',
            'initiated_at'         => now(),
        ]);

        $wallets->debit(self::USER_ID, 1015, $transaction->id, 'transfer_out', 'Payout de test');

        return $transaction;
    }

    private function balance(): float
    {
        return app(WalletService::class)->getBalance(self::USER_ID);
    }

    /** Rejoue exactement ce que fait la file « webhooks ». */
    private function runWebhook(array $payload, bool $trusted, string $reference = self::FEEX_REF): WebhookEvent
    {
        $event = WebhookEvent::create([
            'provider'        => 'feexpay',
            'signature_valid' => false,
            'payload'         => $payload,
            'processed'       => false,
        ]);

        (new ProcessFeexpayWebhook($event->id, $reference, $trusted))
            ->handle(app(TopupService::class), app(PaymentLinkService::class));

        return $event->fresh();
    }

    // ── Tests ───────────────────────────────────────────────────────

    public function test_successful_webhook_marks_payout_succeeded_without_refund(): void
    {
        $transaction = $this->makePayout();
        $this->assertSame(3985.0, $this->balance());

        $event = $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'SUCCESSFUL'], true);

        $transaction->refresh();
        $this->assertSame('succeeded', $transaction->status);
        $this->assertNotNull($transaction->completed_at);
        $this->assertSame(3985.0, $this->balance(), 'Un payout réussi ne doit rien rembourser.');
        $this->assertTrue((bool) $event->processed);

        $this->assertTrue(
            TransactionStatusHistory::where('transaction_id', $transaction->id)
                ->where('new_status', 'succeeded')
                ->where('source', 'webhook')
                ->exists()
        );
    }

    public function test_failed_webhook_refunds_exactly_once_even_when_replayed(): void
    {
        $transaction = $this->makePayout();
        $this->assertSame(3985.0, $this->balance());

        $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'FAILED', 'message' => 'Solde insuffisant'], true);

        $transaction->refresh();
        $this->assertSame('failed', $transaction->status);
        $this->assertStringContainsString('Solde insuffisant', (string) $transaction->failure_reason);
        $this->assertSame(5000.0, $this->balance(), 'Remboursement exact du montant débité (1015).');

        // Rejeu du même webhook : AUCUN second remboursement.
        $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'FAILED'], true);
        $this->assertSame(5000.0, $this->balance());

        // Un SUCCESSFUL tardif ne ressuscite pas un payout déjà échoué/remboursé.
        $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'SUCCESSFUL'], true);
        $this->assertSame('failed', $transaction->fresh()->status);
        $this->assertSame(5000.0, $this->balance());
    }

    public function test_replayed_success_webhook_is_idempotent(): void
    {
        $transaction = $this->makePayout();

        $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'SUCCESSFUL'], true);
        $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'SUCCESSFUL'], true);

        $this->assertSame('succeeded', $transaction->fresh()->status);
        $this->assertSame(1, TransactionStatusHistory::where('transaction_id', $transaction->id)
            ->where('new_status', 'succeeded')->count());
        $this->assertSame(3985.0, $this->balance());
    }

    public function test_webhook_without_valid_secret_does_not_finalize_or_refund(): void
    {
        $transaction = $this->makePayout();

        $event = $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'FAILED'], false);

        $this->assertSame('processing', $transaction->fresh()->status, 'Un webhook non authentifié ne finalise rien.');
        $this->assertSame(3985.0, $this->balance(), 'Aucun remboursement sans secret valide.');
        $this->assertFalse((bool) $event->processed);
        $this->assertNotNull($event->processing_error);
    }

    public function test_webhook_arriving_before_reference_is_stored_matches_by_callback_info(): void
    {
        // La réponse FeexPay est lente : le webhook arrive alors que la
        // transaction n'a pas encore de external_reference.
        $transaction = $this->makePayout('pending', null);

        $this->runWebhook([
            'reference'     => self::FEEX_REF,
            'status'        => 'SUCCESSFUL',
            'callback_info' => 'TXN-PAYOUT-001',
        ], true);

        $transaction->refresh();
        $this->assertSame('succeeded', $transaction->status);
        $this->assertSame(self::FEEX_REF, $transaction->external_reference);
    }

    public function test_pending_webhook_changes_nothing(): void
    {
        $transaction = $this->makePayout();

        $this->runWebhook(['reference' => self::FEEX_REF, 'status' => 'PENDING'], true);

        $this->assertSame('processing', $transaction->fresh()->status);
        $this->assertSame(3985.0, $this->balance());
    }

    public function test_unknown_reference_is_ignored_safely(): void
    {
        $transaction = $this->makePayout();

        $this->runWebhook(['reference' => 'FEEX-UNKNOWN', 'status' => 'FAILED'], true, 'FEEX-UNKNOWN');

        $this->assertSame('processing', $transaction->fresh()->status);
        $this->assertSame(3985.0, $this->balance());
    }

    public function test_controller_marks_webhook_trusted_only_with_the_right_token(): void
    {
        Config::set('fripay.feexpay.webhook_secret', 'secret-123');
        Bus::fake();

        $this->postJson('/api/v1/webhooks/feexpay?token=secret-123', ['reference' => 'FEEX-T-1'])
            ->assertStatus(200);

        Bus::assertDispatched(fn (ProcessFeexpayWebhook $job) => $job->providerReference === 'FEEX-T-1' && $job->trusted === true);

        $this->postJson('/api/v1/webhooks/feexpay?token=mauvais', ['reference' => 'FEEX-T-2'])
            ->assertStatus(200);

        Bus::assertDispatched(fn (ProcessFeexpayWebhook $job) => $job->providerReference === 'FEEX-T-2' && $job->trusted === false);

        $this->postJson('/api/v1/webhooks/feexpay', ['reference' => 'FEEX-T-3'])
            ->assertStatus(200);

        Bus::assertDispatched(fn (ProcessFeexpayWebhook $job) => $job->providerReference === 'FEEX-T-3' && $job->trusted === false);
    }

    public function test_controller_never_trusts_when_no_secret_is_configured(): void
    {
        Config::set('fripay.feexpay.webhook_secret', '');
        Bus::fake();

        $this->postJson('/api/v1/webhooks/feexpay?token=', ['reference' => 'FEEX-T-4'])
            ->assertStatus(200);

        Bus::assertDispatched(fn (ProcessFeexpayWebhook $job) => $job->providerReference === 'FEEX-T-4' && $job->trusted === false);
    }
}
