<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\InitiatePaymentLinkCharge;
use App\Jobs\RefreshPaymentLinkStatus;
use App\Models\PaymentLink;
use App\Services\AuthService;
use App\Services\PaymentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * FriPay Link — liens de paiement partageables.
 *
 * Routes (préfixe /api/v1) :
 *   POST /payment-links                  (auth)    -> crée un lien
 *   GET  /payment-links                  (auth)    -> liens du créateur
 *   GET  /payment-links/{token}          (public)  -> consultation publique
 *   POST /payment-links/{token}/pay      (public)  -> initie la collecte FeexPay
 *   GET  /payment-links/{token}/status   (public)  -> statut temps réel (polling)
 */
class PaymentLinkController extends Controller
{
    public function __construct(
        private readonly PaymentLinkService $links,
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/v1/payment-links — (auth Sanctum)
     * Body : { amount: int, description?: string, ttl_hours?: int }
     *
     * @response status=201 {"link": {...}, "share_url": "https://.../pay/Ab3..."}
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'      => ['required', 'integer', 'min:100', 'max:1000000'],
            'description' => ['nullable', 'string', 'max:255'],
            'ttl_hours'   => ['nullable', 'integer', 'min:1', 'max:720'],
        ], [
            'amount.min' => 'Le montant minimum est de 100 FCFA.',
            'amount.max' => 'Le montant maximum est de 1 000 000 FCFA.',
        ]);

        $link = $this->links->createLink(
            $request->user(),
            (int) $validated['amount'],
            $validated['description'] ?? null,
            (int) ($validated['ttl_hours'] ?? PaymentLink::DEFAULT_TTL_HOURS)
        );

        return response()->json([
            'link'      => $this->serialize($link, $request),
            'share_url' => $this->links->publicUrl($request, $link),
            'message'   => 'Lien de paiement créé — partagez-le pour recevoir votre paiement.',
        ], 201);
    }

    /**
     * GET /api/v1/payment-links — (auth) liens de l'utilisateur courant.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('size', 20), 100);

        $links = PaymentLink::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($links->items())->map(fn ($l) => $this->serialize($l, $request)),
            'meta' => [
                'current_page' => $links->currentPage(),
                'last_page'    => $links->lastPage(),
                'per_page'     => $links->perPage(),
                'total'        => $links->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/payment-links/{token} — PUBLIC (sans auth).
     * La page de paiement consomme ceci pour afficher montant + créateur.
     *
     * @response status=200 {"token":"...","amount":5000,"currency":"XOF","description":"...","creator":"Alice R.","payable":true}
     * @response status=404 {"error":"LINK_NOT_FOUND"}
     */
    public function lookup(Request $request, string $token): JsonResponse
    {
        $link = PaymentLink::where('token', $token)->first();

        if (! $link) {
            return response()->json(['error' => 'LINK_NOT_FOUND'], 404);
        }

        return response()->json($this->serialize($link, $request));
    }

    /**
     * POST /api/v1/payment-links/{token}/pay — PUBLIC (sans auth).
     * Body : { phone: string, operator: "MTN"|"MOOV" }
     *
     * Le montant n'est JAMAIS pris du corps de la requête : c'est celui du
     * lien, verrouillé côté serveur. La validation (lien payable, numéro,
     * opérateur) est faite ICI, puis réponse 202 IMMÉDIATE — l'appel
     * FeexPay part dans la queue `feexpay` (job InitiatePaymentLinkCharge).
     *
     * @response status=202 {"accepted":true,"message":"Demande acceptée"}
     * @response status=422 {"error":"LINK_NOT_PAYABLE","message":"Ce lien a déjà été payé."}
     */
    public function pay(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'phone'    => ['required', 'string', 'max:20'],
            'operator' => ['required', 'string', 'max:10'],
        ], [
            'phone.required'    => 'Votre numéro de téléphone est requis.',
            'operator.required' => 'Choisissez votre opérateur (MTN ou Moov).',
        ]);

        $link = PaymentLink::where('token', $token)->first();

        if (! $link) {
            return response()->json([
                'error'   => 'LINK_NOT_FOUND',
                'message' => "Ce lien de paiement n'existe pas ou a été supprimé.",
            ], 404);
        }

        $result = $this->links->initiatePayment(
            $link,
            $validated['phone'],
            $validated['operator']
        );

        if (! $result['accepted']) {
            $isLinkState = in_array($link->status, [PaymentLink::STATUS_PAID, PaymentLink::STATUS_CANCELLED], true)
                || $link->isExpired();

            return response()->json([
                'error'   => 'LINK_NOT_PAYABLE',
                'message' => $result['message'],
            ], $isLinkState ? 410 : 422);
        }

        // Découplage : l'appel FeexPay (jusqu'à 30 s) part en queue — le
        // push arrive sur le téléphone du payeur quelques secondes plus tard.
        InitiatePaymentLinkCharge::dispatch($link->id, $result['phone'], $result['operator']);

        return response()->json([
            'accepted' => true,
            'message'  => 'Demande acceptée — validez sur votre téléphone (' . $result['operator'] . ').',
        ], 202);
    }

    /**
     * POST /api/v1/payment-links/{token}/pay-wallet — (auth Sanctum).
     * Body : { pin: string }
     *
     * Paiement d'un lien DEPUIS LE SOLDE FRIPAY du payeur connecté —
     * parcours « Payer via FriPay » de web1 : la page vérifie que le numéro
     * possède un compte puis ouvre l'app via fripay://pay/{token}, qui
     * atterrit sur l'écran de paiement in-app (LinkPayScreen). Le créateur
     * ne peut pas payer son propre lien.
     *
     * @response status=200 {"status":"paid","amount":5000,"creator":"Alice R."}
     * @response status=401 {"error":"INVALID_PIN"}
     * @response status=409 {"error":"SELF_PAYMENT"}
     * @response status=422 {"error":"INSUFFICIENT_FUNDS"}
     */
    public function payWithWallet(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'min:4', 'max:8'],
        ]);

        $link = PaymentLink::where('token', $token)->first();

        if (! $link) {
            return $this->errorResponse(
                'LINK_NOT_FOUND', 'Lien introuvable', 404,
                "Ce lien de paiement n'existe pas ou a été supprimé.", $request
            );
        }

        try {
            $this->links->payWithWallet($link, $request->user(), $validated['pin'], $this->authService);
        } catch (\RuntimeException $e) {
            [$title, $status, $detail] = match ($e->getMessage()) {
                'INVALID_PIN' => ['PIN invalide', 401, 'Le code PIN est incorrect.'],
                'SELF_PAYMENT' => ['Paiement impossible', 409, "Vous ne pouvez pas payer votre propre lien."],
                'INSUFFICIENT_FUNDS' => ['Solde insuffisant', 422, 'Votre solde FriPay est insuffisant pour payer ce lien.'],
                'LINK_NOT_PAYABLE' => ['Lien non payable', 410, 'Ce lien ne peut plus être payé (déjà payé, annulé ou expiré).'],
                default => ['Erreur', 500, 'Une erreur est survenue.'],
            };

            return $this->errorResponse($e->getMessage(), $title, $status, $detail, $request);
        }

        $link->refresh();

        return response()->json([
            'status'  => $link->status,
            'amount'  => (float) $link->amount,
            'creator' => $link->creatorDisplayName(),
        ]);
    }

    /**
     * GET /api/v1/payment-links/{token}/status — PUBLIC.
     * Polling côté page web : renvoie l'état LOCAL du lien (réponse
     * immédiate) et dispatche une vérification active auprès de FeexPay
     * (queue `feexpay`) — au plus une par lien toutes les 3 s. La
     * confirmation (crédit + notification) est visible au poll suivant.
     */
    public function status(Request $request, string $token): JsonResponse
    {
        $link = PaymentLink::where('token', $token)->first();

        if (! $link) {
            return response()->json(['error' => 'LINK_NOT_FOUND'], 404);
        }

        // Vérification active découplée (anti-spam : 1 appel FeexPay / 3 s).
        if ($link->status === PaymentLink::STATUS_CREATED
            && $link->provider_reference
            && Cache::add("link:refresh:{$link->id}", true, 3)) {
            RefreshPaymentLinkStatus::dispatch($link->id);
        }

        return response()->json($this->serialize($link->fresh(), $request));
    }

    private function serialize(PaymentLink $link, Request $request): array
    {
        return [
            'token'       => $link->token,
            'amount'      => (float) $link->amount,
            'currency'    => $link->currency,
            'description' => $link->description,
            'status'      => $link->status,
            'payable'     => $link->isPayable(),
            'expired'     => $link->isExpired(),
            // Vie privée : nom partiel du créateur, jamais le numéro complet.
            'creator'     => $link->creatorDisplayName(),
            // Public : on n'expose NI user_id, NI provider_reference.
            'expires_at'  => $link->expires_at?->toISOString(),
            'paid_at'     => $link->paid_at?->toISOString(),
            'share_url'   => $link->status === PaymentLink::STATUS_CREATED && $request->user() !== null
                ? $this->links->publicUrl($request, $link)
                : null,
        ];
    }
}
