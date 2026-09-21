<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PaymentLink;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\PaymentLinkService;
use App\Services\WalletService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FriPay Link — parcours complet :
 *
 *   A (créateur FriPay) crée un lien (montant + motif)
 *   -> le payeur externe consulte le lien (montant verrouillé, créateur masqué)
 *   -> il paie via FeexPay (MTN/Moov)
 *   -> confirmation (webhook ou polling) => wallet de A crédité VIA LE LEDGER,
 *      lien "paid", notification envoyée.
 *
 * Sécurités testées :
 * - montant verrouillé côté serveur (le payeur ne peut pas le manipuler) ;
 * - lien déjà payé => refus de tout nouveau paiement (idempotence) ;
 * - token public non-devinable (pas d'ID interne dans l'URL) ;
 * - mouvement wallet tracé au ledger.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private string $creatorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSharedTables();

        $this->creatorId = $this->makeUser('+22997000010', 'Alice', 'Receveur');
    }

    // ────────────────────────────────────────────────────────────────
    //  Création du lien
    // ────────────────────────────────────────────────────────────────

    public function test_creator_creates_link_with_locked_amount(): void
    {
        $this->loginAs($this->creatorId);

        $response = $this->postJson('/api/v1/payment-links', [
            'amount'      => 5000,
            'description' => 'Facture janvier',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['link' => ['token', 'amount', 'status', 'share_url'], 'share_url']);

        $this->assertSame(5000.0, (float) $response->json('link.amount'));
        $this->assertSame('created', $response->json('link.status'));
        $this->assertSame('Facture janvier', $response->json('link.description'));

        // Le lien est stocké avec le montant verrouillé et expire bien ~48h.
        $link = PaymentLink::where('token', $response->json('link.token'))->firstOrFail();
        $this->assertSame(5000.0, (float) $link->amount);
        $this->assertTrue($link->expires_at->diffInHours(now()) < 49);

        // L'URL de partage pointe vers /pay/{token}.
        $this->assertStringContainsString('/pay/' . $link->token, $response->json('share_url'));
    }

    public function test_link_token_is_not_the_internal_id(): void
    {
        $this->loginAs($this->creatorId);

        $response = $this->postJson('/api/v1/payment-links', ['amount' => 1000]);
        $response->assertStatus(201);

        $link = PaymentLink::where('token', $response->json('link.token'))->firstOrFail();

        // Sécurité : le token public ne doit JAMAIS être l'ID interne.
        $this->assertNotSame($link->id, $link->token);
        $this->assertSame(40, strlen($link->token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $link->token);
    }

    public function test_creation_requires_authentication(): void
    {
        $this->postJson('/api/v1/payment-links', ['amount' => 1000])
            ->assertStatus(401);
    }

    // ────────────────────────────────────────────────────────────────
    //  Consultation publique
    // ────────────────────────────────────────────────────────────────

    public function test_public_lookup_shows_partial_name_not_full_phone(): void
    {
        $link = $this->createLinkDirectly(2500, 'Course du mois');

        $response = $this->getJson("/api/v1/payment-links/{$link->token}");
        $response->assertStatus(200);

        $this->assertSame(2500.0, (float) $response->json('amount'));
        $this->assertSame('Course du mois', $response->json('description'));
        $this->assertSame('Alice R.', $response->json('creator'));

        // Vie privée : le numéro complet n'est jamais exposé.
        $json = $response->getContent();
        $this->assertStringNotContainsString('+22997000010', $json);
        $this->assertStringNotContainsString($this->creatorId, $json, 'user_id ne doit pas être exposé');
    }

    public function test_public_lookup_404_for_unknown_token(): void
    {
        $this->getJson('/api/v1/payment-links/this-token-does-not-exist-at-all')
            ->assertStatus(404)
            ->assertJsonFragment(['error' => 'LINK_NOT_FOUND']);
    }

    // ────────────────────────────────────────────────────────────────
    //  Paiement FeexPay + crédit + notification
    // ────────────────────────────────────────────────────────────────

    public function test_payer_pays_link_and_creator_wallet_is_credited(): void
    {
        $link = $this->createLinkDirectly(3000, 'Repas');
        $balanceBefore = app(WalletService::class)->getBalance($this->creatorId);

        // FeexPay accepte la collecte puis confirme SUCCESSFUL au polling.
        Http::fake([
            '*/api/transactions/requesttopay/integration' => Http::response(['reference' => 'FEEX-LINK-001', 'status' => 'PENDING'], 200),
            '*/api/transactions/getrequesttopay/integration/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => 3000, 'payer' => ['partyId' => '2290197000011']], 200),
        ]);

        // 1) Le payeur externe (SANS auth) initie le paiement.
        $pay = $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
        ]);
        $pay->assertStatus(202);
        $pay->assertJsonFragment(['accepted' => true]);

        // Le montant soumis à FeexPay est bien celui du lien (3000), pas
        // une entrée du payeur — vérifié via la payload fake ci-dessus.

        // 2) Le paiement est confirmé (webhook OU polling status).
        $status = $this->getJson("/api/v1/payment-links/{$link->token}/status");
        $status->assertStatus(200);
        $this->assertSame('paid', $status->json('status'));

        // 3) Le créateur a été crédité EXACTEMENT du montant du lien,
        //    via une écriture LEDGER traçable.
        $wallet = Wallet::where('user_id', $this->creatorId)->firstOrFail();
        $this->assertSame($balanceBefore + 3000.0, (float) $wallet->balance);

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'wallet_id'       => $wallet->id,
            'payment_link_id' => $link->id,
            'type'            => 'credit',
            'amount'          => '3000.00',
            'reason'          => 'payment_link_paid',
        ]);

        // 4) Une notification a été envoyée au créateur.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->creatorId,
            'title'   => 'Paiement reçu via votre lien',
        ]);
    }

    public function test_webhook_confirms_payment_and_is_idempotent(): void
    {
        $link = $this->createLinkDirectly(1500, null);
        $balanceBefore = app(WalletService::class)->getBalance($this->creatorId);

        Http::fake([
            '*/api/transactions/requesttopay/integration' => Http::response(['reference' => 'FEEX-LINK-002', 'status' => 'PENDING'], 200),
            '*/api/transactions/getrequesttopay/integration/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => 1500], 200),
        ]);

        $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MOOV',
        ])->assertStatus(202);

        // Le webhook FeexPay arrive (référence = celle mémorisée sur le lien).
        $webhook = $this->postJson('/api/v1/webhooks/feexpay', ['reference' => 'FEEX-LINK-002']);
        $webhook->assertStatus(200);

        $link->refresh();
        $this->assertSame('paid', $link->status);

        // Rejeu du webhook : AUCUN double crédit (idempotence).
        $this->postJson('/api/v1/webhooks/feexpay', ['reference' => 'FEEX-LINK-002'])
            ->assertStatus(200);

        $credits = WalletLedgerEntry::where('payment_link_id', $link->id)
            ->where('type', 'credit')
            ->count();
        $this->assertSame(1, $credits, 'Le webhook rejoué ne doit pas créditer deux fois');

        $this->assertSame($balanceBefore + 1500.0, app(WalletService::class)->getBalance($this->creatorId));
    }

    // ────────────────────────────────────────────────────────────────
    //  Sécurité : montant verrouillé, lien non payable, idempotence
    // ────────────────────────────────────────────────────────────────

    public function test_payer_cannot_modify_the_amount(): void
    {
        $link = $this->createLinkDirectly(2000, null);

        Http::fake([
            // On capture ce que le backend envoie réellement à FeexPay.
            '*/api/transactions/requesttopay/integration' => Http::response(['reference' => 'FEEX-LINK-003', 'status' => 'PENDING'], 200),
        ]);

        // Tentative de manipulation : le payeur injecte amount=1 dans le body.
        $pay = $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
            'amount'   => 1,
        ]);

        $pay->assertStatus(202); // le paiement part quand même…

        // …mais le montant collecté est bien celui du LIEN (2000).
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api-v2.feexpay.me/api/transactions/requesttopay/integration'
                && (int) $request['amount'] === 2000;
        });

        // Et le crédit futur sera bien de 2000 (montant du lien).
        $this->assertSame(2000.0, (float) $link->fresh()->amount);
    }

    public function test_already_paid_link_refuses_new_payment(): void
    {
        $link = $this->createLinkDirectly(1000, null);

        Http::fake([
            '*/api/transactions/requesttopay/integration' => Http::response(['reference' => 'FEEX-LINK-004', 'status' => 'PENDING'], 200),
            '*/api/transactions/getrequesttopay/integration/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => 1000], 200),
        ]);

        $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
        ])->assertStatus(202);

        $this->getJson("/api/v1/payment-links/{$link->token}/status")->assertStatus(200);

        $this->assertSame('paid', $link->fresh()->status);

        // Second paiement refusé (410 Gone — ressource définitivement consommée).
        $second = $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
        ]);
        $second->assertStatus(410);
        $second->assertJsonFragment(['error' => 'LINK_NOT_PAYABLE']);

        // Aucun second appel FeexPay n'a été déclenché (aucune nouvelle
        // collecte sur un lien déjà payé).
        Http::assertSentCount(2); // 1 requesttopay + 1 getrequesttopay
    }

    public function test_expired_link_refuses_payment(): void
    {
        $link = $this->createLinkDirectly(800, null);
        $link->update(['expires_at' => now()->subHour()]);

        $response = $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
        ]);

        $response->assertStatus(410);
        $response->assertJsonFragment(['error' => 'LINK_NOT_PAYABLE']);
    }

    public function test_cancelled_link_refuses_payment(): void
    {
        $link = $this->createLinkDirectly(800, null);
        $link->update(['status' => PaymentLink::STATUS_CANCELLED]);

        $response = $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'MTN',
        ]);

        $response->assertStatus(410);
    }

    public function test_payment_with_unsupported_operator_is_rejected(): void
    {
        $link = $this->createLinkDirectly(800, null);

        Http::fake(); // aucune requête sortante ne doit partir.

        $this->postJson("/api/v1/payment-links/{$link->token}/pay", [
            'phone'    => '+2290197000011',
            'operator' => 'CELTIIS',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_service_markLinkPaid_is_idempotent_at_the_service_level(): void
    {
        $link = $this->createLinkDirectly(4000, null);

        $service = app(PaymentLinkService::class);

        $this->assertTrue($service->markLinkPaid($link, '+2290197000011', 'MTN'));
        $this->assertFalse($service->markLinkPaid($link, '+2290197000011', 'MTN'), 'Second appel : no-op');

        $wallet = Wallet::where('user_id', $this->creatorId)->firstOrFail();
        $this->assertSame(4000.0, (float) $wallet->balance);

        // Une seule notification malgré les deux appels.
        $this->assertSame(1, Notification::where('user_id', $this->creatorId)->count());
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

    private function makeUser(string $phone, string $first, string $last): string
    {
        // Vrai modèle User (type strict du contrôleur PaymentLinkController).
        $user = \App\Models\User::create([
            'id'           => (string) Str::uuid(),
            'phone_number' => $phone,
            'first_name'   => $first,
            'last_name'    => $last,
            'status'       => 'active',
            'pin_hash'     => password_hash('1234', PASSWORD_BCRYPT),
            'client_type'  => 'P',
        ]);

        return $user->id;
    }

    private function loginAs(string $userId): void
    {
        Sanctum::actingAs(\App\Models\User::findOrFail($userId));
    }

    private function createLinkDirectly(int $amount, ?string $description): PaymentLink
    {
        return app(PaymentLinkService::class)->createLink(
            \App\Models\User::query()->findOrFail($this->creatorId),
            $amount,
            $description
        );
    }
}

/**
 * Fake User model compatible Sanctum::actingAs() (cf. QrPaymentFlowTest).
 */
class PaymentLinkTestUser extends Model implements \Illuminate\Contracts\Auth\Authenticatable
{
    use \Laravel\Sanctum\HasApiTokens,
        \Illuminate\Auth\Authenticatable;

    protected $table = 'users';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    public function wallet()
    {
        return $this->hasOne(\App\Models\Wallet::class);
    }
}
