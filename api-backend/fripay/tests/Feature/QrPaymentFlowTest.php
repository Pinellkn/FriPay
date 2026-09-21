<?php

namespace Tests\Feature;

use App\Models\OfflineQrCode;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\TransferService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cycle complet §6 « Recevoir » (QR marchand MPM généré par un
 * particulier depuis l'écran Recevoir de l'appli) :
 *
 *   A génère un QR → B scanne, saisit un montant + PIN, valide
 *   → le montant est DÉBITÉ de B et CRÉDITÉ à A (ledger)
 *   → la transaction est annulée
 *   → les soldes reviennent EXACTEMENT à leur valeur de départ,
 *     sans surplus ni perte (pas de remboursement « aveugle »).
 *
 * Ces tests reproduisent les deux bugs observés en test réel :
 * - BUG 1 : aucun mouvement wallet au paiement (l'argent ne bouge jamais) ;
 * - BUG 2 : l'annulation crédite un montant sans débit correspondant
 *   (10000 → +100 → 11000 au lieu de revenir à 10000).
 */
class QrPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $payeeId;   // A — celui qui génère le QR pour recevoir
    private string $payerId;   // B — celui qui scanne et paie

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedTables();

        $this->payeeId = $this->makeUser('+22997000001', 'Alice', 'Receveur');
        $this->payerId = $this->makeUser('+22997000002', 'Bob', 'Payeur');

        // Compte lié du payeur — FK transactions.sender_account_id.
        DB::table('linked_accounts')->insert([
            'id'         => 'qr-flow-account-1',
            'user_id'    => $this->payerId,
            'operator_id'=> 1,
            'msisdn'     => '+22997000002',
            'is_primary' => 1,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSharedTables(): void
    {
        // Les users appartiennent au même microservice fusionné : la table
        // existe déjà via les migrations. On ne la crée que si absente
        // (robustesse si l'environnement de test change).
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

    private function makeUser(string $phone, string $first, string $last): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id'           => $id,
            'phone_number' => $phone,
            'first_name'   => $first,
            'last_name'    => $last,
            'status'       => 'active',
            'pin_hash'     => password_hash('1234', PASSWORD_BCRYPT),
            'client_type'  => 'P',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $id;
    }

    private function loginAs(string $userId): void
    {
        $user = new QrFlowTestUser();
        $user->id = $userId;
        $user->exists = true;
        Sanctum::actingAs($user);
    }

    private function topUp(string $userId, float $amount): void
    {
        app(WalletService::class)->credit($userId, $amount, null, 'manual_topup', 'Solde de test');
    }

    private function balance(string $userId): float
    {
        return app(WalletService::class)->getBalance($userId);
    }

    /**
     * Génère un QR « Recevoir » exactement comme l'écran mobile :
     * POST /qr/mpm/generate (QR statique MPM, montant saisi par le payeur).
     */
    private function generateReceivingQr(string $payeeId): array
    {
        $this->loginAs($payeeId);

        $response = $this->postJson('/api/v1/qr/mpm/generate', [
            'qr_type'     => 'static',
            'description' => 'Test réception',
        ]);

        $response->assertStatus(201);

        $uuid = $response->json('uuid');
        $qr = OfflineQrCode::where('uuid', $uuid)->firstOrFail();

        return ['qr' => $qr, 'uuid' => $uuid];
    }

    /**
     * B scanne le QR de A, saisit montant + PIN et valide :
     * POST /qr/mpm/pay.
     */
    private function payQr(string $uuid, int $amount, string $payerId): \Illuminate\Testing\TestResponse
    {
        $this->loginAs($payerId);

        return $this->postJson('/api/v1/qr/mpm/pay', [
            'uuid'              => $uuid,
            'amount'            => $amount,
            'pin'               => '1234',
            'sender_account_id' => 'qr-flow-account-1',
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    //  BUG 1 — le débit/crédit doit se produire au paiement
    // ────────────────────────────────────────────────────────────────

    public function test_payment_debits_payer_and_credits_payee(): void
    {
        $this->topUp($this->payerId, 10000);

        ['uuid' => $uuid] = $this->generateReceivingQr($this->payeeId);

        $response = $this->payQr($uuid, 100, $this->payerId);
        $response->assertStatus(202);

        // BUG 1 corrigé : l'argent a réellement bougé.
        $this->assertSame(9900.0, $this->balance($this->payerId), 'Le payeur doit être débité de 100');
        $this->assertSame(100.0, $this->balance($this->payeeId), 'Le bénéficiaire doit être crédité de 100');

        // Les deux mouvements sont tracés dans le ledger avec la même
        // transaction — jamais un solde modifié sans écriture traçable.
        $transactionId = $response->json('transaction_id');
        $this->assertNotNull($transactionId);

        $payerWallet = Wallet::where('user_id', $this->payerId)->firstOrFail();
        $payeeWallet = Wallet::where('user_id', $this->payeeId)->firstOrFail();

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'wallet_id'      => $payerWallet->id,
            'transaction_id' => $transactionId,
            'type'           => 'debit',
            'amount'         => '100.00',
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'wallet_id'      => $payeeWallet->id,
            'transaction_id' => $transactionId,
            'type'           => 'credit',
            'amount'         => '100.00',
        ]);

        // Virement interne Fripay → transaction réglée immédiatement.
        $this->assertSame('completed', Transaction::findOrFail($transactionId)->status);
    }

    public function test_payment_rejects_insufficient_funds_and_moves_nothing(): void
    {
        $this->topUp($this->payerId, 50);

        ['uuid' => $uuid] = $this->generateReceivingQr($this->payeeId);

        $response = $this->payQr($uuid, 100, $this->payerId);
        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'INSUFFICIENT_FUNDS']);

        // Rien n'a bougé, aucune transaction créée.
        $this->assertSame(50.0, $this->balance($this->payerId));
        $this->assertSame(0.0, $this->balance($this->payeeId));
        $this->assertSame(0, Transaction::count());
    }

    // ────────────────────────────────────────────────────────────────
    //  BUG 2 — l'annulation est l'exact inverse du débit d'origine
    // ────────────────────────────────────────────────────────────────

    public function test_cancel_after_payment_returns_exact_initial_balances(): void
    {
        // Scénario exact du rapport de bug : solde de départ 10000,
        // paiement de 100 via QR, puis annulation.
        $this->topUp($this->payerId, 10000);

        ['uuid' => $uuid] = $this->generateReceivingQr($this->payeeId);

        $pay = $this->payQr($uuid, 100, $this->payerId);
        $pay->assertStatus(202);

        $this->assertSame(9900.0, $this->balance($this->payerId));
        $this->assertSame(100.0, $this->balance($this->payeeId));

        // Annulation de la transaction par le payeur.
        $transactionId = $pay->json('transaction_id');
        $this->loginAs($this->payerId);
        $cancel = $this->postJson("/api/v1/transfers/{$transactionId}/cancel");
        $cancel->assertOk();
        $cancel->assertJsonFragment(['status' => 'cancelled']);

        // Retour EXACT au solde de départ, sans surplus ni perte.
        $this->assertSame(10000.0, $this->balance($this->payerId), 'Le payeur doit revenir exactement à 10000');
        $this->assertSame(0.0, $this->balance($this->payeeId), 'Le bénéficiaire doit revenir exactement à 0');

        // Le remboursement est l'exact inverse symétrique du débit
        // d'origine (même transaction_id, même montant).
        $payerWallet = Wallet::where('user_id', $this->payerId)->firstOrFail();
        $entries = WalletLedgerEntry::where('wallet_id', $payerWallet->id)
            ->where('transaction_id', $transactionId)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame('debit', $entries[0]->type);
        $this->assertSame('credit', $entries[1]->type);
        $this->assertEquals($entries[0]->amount, $entries[1]->amount);
    }

    public function test_cancel_is_idempotent_no_second_refund(): void
    {
        $this->topUp($this->payerId, 10000);

        ['uuid' => $uuid] = $this->generateReceivingQr($this->payeeId);

        $pay = $this->payQr($uuid, 100, $this->payerId);
        $transactionId = $pay->json('transaction_id');

        // Deux remboursements consécutifs (webhook + annulation, par ex.)
        // ne doivent créditer qu'UNE seule fois : le net redevenant 0,
        // le second est un no-op.
        app(TransferService::class)->refundWallet(Transaction::findOrFail($transactionId), 'transfer_refund_webhook_failed');
        app(TransferService::class)->refundWallet(Transaction::findOrFail($transactionId), 'transfer_refund_webhook_failed');

        $this->assertSame(10000.0, $this->balance($this->payerId));

        // Une seule écriture de crédit de remboursement.
        $payerWallet = Wallet::where('user_id', $this->payerId)->firstOrFail();
        $refundCount = WalletLedgerEntry::where('wallet_id', $payerWallet->id)
            ->where('transaction_id', $transactionId)
            ->where('type', 'credit')
            ->count();
        $this->assertSame(1, $refundCount);
    }

    public function test_cancel_without_any_debit_creates_no_money(): void
    {
        // Le cœur du BUG 2 : une annulation sur une transaction dont le
        // débit n'a JAMAIS eu lieu ne doit pas créer d'argent.
        $this->topUp($this->payerId, 10000);

        // Transaction insérée « à la main » en pending, sans aucun
        // mouvement ledger correspondant (état historique du bug).
        $transaction = Transaction::create([
            'reference'            => 'TXN-NODEBIT-001',
            'idempotency_key'      => (string) Str::uuid(),
            'sender_user_id'       => $this->payerId,
            'sender_account_id'    => 'qr-flow-account-1',
            'recipient_phone'      => '+22997000001',
            'amount'               => 100,
            'currency'             => 'XOF',
            'fee_amount'           => 0,
            'total_debited'        => 100,
            'rail_used'            => 'qr_mpm',
            'status'               => 'pending',
            'client_type_snapshot' => 'qr_payment',
            'initiated_at'         => now(),
        ]);

        app(TransferService::class)->refundWallet($transaction, 'transfer_refund_cancelled');

        // AUCUN crédit : le solde reste à 10000 (le bug donnait 11000).
        $this->assertSame(10000.0, $this->balance($this->payerId));
    }

    public function test_revoke_refunds_only_the_actual_hold(): void
    {
        // QR « argent » (POST /qr/generate) : l'envoyeur est débité (hold)
        // à la génération ; la révocation doit lui restituer exactement ce
        // hold — pas plus.
        $this->topUp($this->payeeId, 5000);
        $this->loginAs($this->payeeId);

        $gen = $this->postJson('/api/v1/qr/generate', ['amount' => 700]);
        $gen->assertStatus(201);

        $this->assertSame(4300.0, $this->balance($this->payeeId), 'La génération doit réserver 700');

        $uuid = $gen->json('uuid');
        $revoke = $this->postJson('/api/v1/qr/revoke', ['uuid' => $uuid]);
        $revoke->assertOk();

        // Retour exact au solde de départ.
        $this->assertSame(5000.0, $this->balance($this->payeeId));
    }
}

/**
 * Fake User model compatible Sanctum::actingAs() (cf. OfflineQrControllerTest).
 */
class QrFlowTestUser extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use \Laravel\Sanctum\HasApiTokens,
        \Illuminate\Auth\Authenticatable;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
}
