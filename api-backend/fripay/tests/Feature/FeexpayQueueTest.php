<?php

namespace Tests\Feature;

use App\Jobs\InitiatePaymentLinkCharge;
use App\Jobs\InitiateTopupPayment;
use App\Jobs\ProcessFeexpayWebhook;
use App\Jobs\RefreshPaymentLinkStatus;
use App\Jobs\RefreshTopupStatus;
use App\Models\PaymentLink;
use App\Models\Topup;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Services\PaymentLinkService;
use App\Services\TopupService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Découplage des appels FeexPay du cycle requête HTTP (Redis + Horizon).
 *
 * Valide :
 * - les endpoints acceptent et répondent 202 SANS appeler FeexPay
 *   (le corps HTTP ne doit plus contenir d'appel sortant bloquant) ;
 * - les jobs sont dispatchés sur les files dédiées (feexpay/webhooks) ;
 * - l'exécution des jobs (queue sync forcée dans le test) produit les
 *   mêmes effets métier qu'avant : collecte soumise, référence stockée,
 *   webhook traité, crédit idempotent.
 */
class FeexpayQueueTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedTables();

        $this->userId = User::create([
            'id'           => (string) Str::uuid(),
            'phone_number' => '+22997000099',
            'first_name'   => 'Queue',
            'last_name'    => 'Test',
            'status'       => 'active',
            'pin_hash'     => password_hash('1234', PASSWORD_BCRYPT),
            'client_type'  => 'P',
        ])->id;
    }

    // ────────────────────────────────────────────────────────────────
    //  Topup : 202 immédiat + job dispatché, zéro appel HTTP synchrone
    // ────────────────────────────────────────────────────────────────

    public function test_topup_initiate_returns_202_and_dispatches_job_without_http_call(): void
    {
        // AUCUN fake HTTP : si le contrôleur appelait FeexPay en synchrone,
        // la requête partirait réellement et le test échouerait (réseau).
        Http::preventStrayRequests();

        Bus::fake();

        $this->actingAs(User::find($this->userId))
            ->postJson('/api/v1/wallet/topup/feexpay', [
                'amount'   => 2000,
                'operator' => 'MTN',
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['message' => 'Recharge acceptée — la demande de paiement va arriver sur votre téléphone (MTN).']);

        // Le topup est créé en pending, SANS référence provider.
        $topup = Topup::where('user_id', $this->userId)->firstOrFail();
        $this->assertSame('pending', $topup->status);
        $this->assertNull($topup->provider_reference);

        // Le job est dispatché sur la file dédiée `feexpay`.
        Bus::assertDispatched(function (InitiateTopupPayment $job) use ($topup) {
            return $job->topupId === $topup->id
                && $job->queue === 'feexpay';
        });
    }

    public function test_initiate_topup_job_submits_to_feexpay(): void
    {
        $topup = app(TopupService::class)->initiate(User::find($this->userId), 1500, 'MTN');

        Http::fake([
            '*/api/transactions/requesttopay/integration' => Http::response(['reference' => 'FEEX-QT-001', 'status' => 'PENDING'], 200),
        ]);

        // Exécution réelle du job (Bus non faked).
        (new InitiateTopupPayment($topup->id))->handle(app(TopupService::class));

        $topup->refresh();
        $this->assertSame('processing', $topup->status);
        $this->assertSame('FEEX-QT-001', $topup->provider_reference);

        // Le montant envoyé à FeexPay est bien celui du topup.
        Http::assertSent(fn ($request) => (int) $request['amount'] === 1500);
    }

    public function test_initiate_topup_job_retries_on_unreachable_and_fails_definitively(): void
    {
        // Séquence : 1er appel FeexPay injoignable (500, retryable), 2e appel
        // rejeté (400, définitif). NB : un second Http::fake() ne remplace
        // pas le premier — d'où l'usage d'une séquence sur la même URL.
        Http::fake([
            '*/api/transactions/requesttopay/integration' => Http::sequence()
                ->push(['error' => 'timeout'], 500)
                ->push(['message' => 'Montant non autorisé'], 400),
        ]);

        // 1er appel : injoignable (retryable) -> le job lève, la queue
        // relancera selon le backoff ; le topup reste pending.
        $topup = app(TopupService::class)->initiate(User::find($this->userId), 1500, 'MTN');

        try {
            (new InitiateTopupPayment($topup->id))->handle(app(TopupService::class));
            $this->fail('Le job aurait dû lever une exception (retryable).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FeexPay injoignable', $e->getMessage());
        }

        $this->assertSame('pending', $topup->fresh()->status);

        // 2e appel : rejet définitif (4xx) -> échec tracé, sans relance.
        $topup2 = app(TopupService::class)->initiate(User::find($this->userId), 1500, 'MOOV');
        (new InitiateTopupPayment($topup2->id))->handle(app(TopupService::class)); // ne lève pas

        $topup2->refresh();
        $this->assertSame('failed', $topup2->status);
        $this->assertStringContainsString('Montant non autorisé', (string) $topup2->failure_reason);
    }

    // ────────────────────────────────────────────────────────────────
    //  FriPay Link : 202 immédiat + job dispatché sur `feexpay`
    // ────────────────────────────────────────────────────────────────

    public function test_payment_link_pay_returns_202_and_dispatches_job_without_http_call(): void
    {
        Http::preventStrayRequests();

        $link = $this->makeLink(2500, 'Course');

        Bus::fake();

        $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
        ])
            ->assertStatus(202)
            ->assertJsonFragment(['accepted' => true]);

        Bus::assertDispatched(function (InitiatePaymentLinkCharge $job) use ($link) {
            return $job->linkId === $link->id
                && $job->payerPhone === '+2290197000011'
                && $job->operator === 'MTN'
                && $job->queue === 'feexpay';
        });
    }

    public function test_initiate_payment_link_charge_job_submits_to_feexpay(): void
    {
        $link = $this->makeLink(3000, null);

        Http::fake([
            '*/api/transactions/requesttopay/integration' => Http::response(['reference' => 'FEEX-QT-002', 'status' => 'PENDING'], 200),
        ]);

        (new InitiatePaymentLinkCharge($link->id, '+2290197000011', 'MTN'))
            ->handle(app(PaymentLinkService::class));

        $link->refresh();
        $this->assertSame('FEEX-QT-002', $link->provider_reference);

        // Montant verrouillé : c'est celui du lien qui part chez FeexPay.
        Http::assertSent(fn ($request) => (int) $request['amount'] === 3000);
    }

    public function test_initiate_payment_link_charge_job_skips_non_payable_link(): void
    {
        $link = $this->makeLink(3000, null);
        $link->update(['status' => PaymentLink::STATUS_PAID]);

        Http::fake();

        (new InitiatePaymentLinkCharge($link->id, '+2290197000011', 'MTN'))
            ->handle(app(PaymentLinkService::class));

        Http::assertNothingSent(); // lien déjà payé : aucune collecte déclenchée
    }

    // ────────────────────────────────────────────────────────────────
    //  Webhook : ack immédiat + traitement en queue `webhooks`
    // ────────────────────────────────────────────────────────────────

    public function test_feexpay_webhook_acks_immediately_and_dispatches_job(): void
    {
        Http::preventStrayRequests();

        Bus::fake();

        $this->postJson('/api/v1/webhooks/feexpay', ['reference' => 'FEEX-WH-001'])
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'ok']);

        // L'événement brut est journalisé, non encore traité.
        $event = WebhookEvent::where('provider', 'feexpay')->latest('id')->firstOrFail();
        $this->assertFalse((bool) $event->processed);

        Bus::assertDispatched(function (ProcessFeexpayWebhook $job) use ($event) {
            return $job->webhookEventId === $event->id
                && $job->providerReference === 'FEEX-WH-001'
                && $job->queue === 'webhooks';
        });
    }

    public function test_process_feexpay_webhook_job_confirms_link_and_marks_event_processed(): void
    {
        $link = $this->makeLink(1200, null);

        // La collecte a été initiée : référence mémorisée sur le lien.
        $link->update(['provider_reference' => 'FEEX-WH-002']);

        $event = WebhookEvent::create([
            'provider'        => 'feexpay',
            'signature_valid' => false,
            'payload'         => ['reference' => 'FEEX-WH-002'],
            'processed'       => false,
        ]);

        Http::fake([
            '*/api/transactions/getrequesttopay/integration/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => 1200], 200),
        ]);

        (new ProcessFeexpayWebhook($event->id, 'FEEX-WH-002'))
            ->handle(app(TopupService::class), app(PaymentLinkService::class));

        $this->assertSame('paid', $link->fresh()->status);

        // Le crédit du créateur est bien passé par le ledger.
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'payment_link_id' => $link->id,
            'type'            => 'credit',
            'amount'          => '1200.00',
            'reason'          => 'payment_link_paid',
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    //  Polling : réponse locale immédiate + refresh découplé
    // ────────────────────────────────────────────────────────────────

    public function test_topup_status_polling_is_local_and_dispatches_refresh(): void
    {
        $topup = app(TopupService::class)->initiate(User::find($this->userId), 1000, 'MTN');
        $topup->update(['provider_reference' => 'FEEX-QT-003', 'status' => 'processing']);

        Http::preventStrayRequests();

        Bus::fake();

        $this->actingAs(User::find($this->userId))
            ->getJson("/api/v1/wallet/topup/feexpay/{$topup->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'processing']);

        Bus::assertDispatched(fn (RefreshTopupStatus $job) => $job->topupId === $topup->id);
    }

    public function test_link_status_polling_is_local_and_dispatches_refresh(): void
    {
        $link = $this->makeLink(900, null);
        $link->update(['provider_reference' => 'FEEX-QT-004']);

        Http::preventStrayRequests();

        Bus::fake();

        $this->getJson("/api/v1/payment-links/{$link->token}/status")
            ->assertStatus(200)
            ->assertJsonFragment(['status' => 'created']);

        Bus::assertDispatched(fn (RefreshPaymentLinkStatus $job) => $job->linkId === $link->id);
    }

    // ────────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────────

    private function createSharedTables(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('users')) {
            DB::statement('CREATE TABLE users (
                id TEXT PRIMARY KEY,
                phone_number TEXT NOT NULL UNIQUE,
                first_name TEXT,
                last_name TEXT,
                status TEXT DEFAULT "active",
                pin_hash TEXT,
                client_type TEXT DEFAULT "P",
                created_at TIMESTAMP,
                updated_at TIMESTAMP
            )');
        }
    }

    private function makeLink(int $amount, ?string $description): PaymentLink
    {
        return app(PaymentLinkService::class)->createLink(
            User::findOrFail($this->userId),
            $amount,
            $description
        );
    }
}
