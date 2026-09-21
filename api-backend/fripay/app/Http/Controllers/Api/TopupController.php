<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\InitiateTopupPayment;
use App\Jobs\RefreshTopupStatus;
use App\Models\Topup;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Recharge du wallet via FeexPay (agrégateur).
 *
 * Routes (fripay-payments, préfixe /api/v1) :
 *   POST /wallet/topup/feexpay            -> initie une recharge
 *   GET  /wallet/topup/feexpay/{id}       -> vérifie le statut et crédite si confirmé
 *   GET  /wallet/topup/feexpay            -> historique des recharges de l'utilisateur
 */
class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topups) {}

    /**
     * POST /api/v1/wallet/topup/feexpay
     * Body : { amount: int (XOF), operator: "MTN"|"MOOV" }
     *
     * Crée la recharge locale puis RÉPOND IMMÉDIATEMENT 202 : l'appel
     * FeexPay (jusqu'à 30 s) est effectué par le job InitiateTopupPayment
     * (queue `feexpay`), jamais dans le cycle requête HTTP. Le push arrive
     * sur le téléphone du client quelques secondes plus tard ; le wallet
     * n'est crédité qu'À LA CONFIRMATION (webhook ou statut).
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'   => ['required', 'integer', 'min:100', 'max:1000000'],
            'operator' => ['required', 'string', 'in:MTN,MOOV'],
        ], [
            'amount.min'       => 'Le montant minimum de recharge est de 100 FCFA.',
            'operator.in'      => 'Opérateur non supporté par la recharge FeexPay (MTN ou Moov).',
        ]);

        $topup = $this->topups->initiate(
            $request->user(),
            (float) $validated['amount'],
            $validated['operator']
        );

        // Découplage : le worker HTTP n'attend jamais l'agrégateur.
        InitiateTopupPayment::dispatch($topup->id);

        return response()->json([
            'topup'   => $this->serialize($topup),
            'message' => 'Recharge acceptée — la demande de paiement va arriver sur votre téléphone (' . $topup->operator_code . ').',
        ], 202);
    }

    /**
     * GET /api/v1/wallet/topup/feexpay/{topupId}
     * Statut LOCAL du topup (réponse immédiate) + dispatch d'une
     * vérification active auprès de FeexPay (queue `feexpay`) : l'appel
     * sortant getStatus ne bloque plus le worker HTTP. Le résultat d'une
     * confirmation est visible au poll suivant. Idempotent.
     */
    public function status(Request $request, string $topupId): JsonResponse
    {
        $topup = Topup::where('user_id', $request->user()->id)->find($topupId);

        if (! $topup) {
            return $this->errorResponse(
                'TOPUP_NOT_FOUND', 'Recharge introuvable', 404,
                'Aucune recharge correspondante pour cet utilisateur.', $request
            );
        }

        // Vérification active découplée — au plus une demande par topup
        // toutes les 5 s (le polling client ne doit pas spammer FeexPay).
        if (in_array($topup->status, ['pending', 'processing'], true)
            && Cache::add("topup:refresh:{$topup->id}", true, 5)) {
            RefreshTopupStatus::dispatch($topup->id);
        }

        return response()->json([
            'topup'   => $this->serialize($topup),
            'message' => match ($topup->status) {
                'completed' => 'Recharge confirmée — solde crédité.',
                'failed'    => 'Recharge échouée : ' . ($topup->failure_reason ?? 'paiement refusé'),
                default     => 'Paiement en attente de validation sur votre téléphone.',
            },
        ]);
    }

    /**
     * GET /api/v1/wallet/topup/feexpay — historique des recharges.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('size', 20), 100);

        $topups = Topup::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($topups->items())->map(fn ($t) => $this->serialize($t)),
            'meta' => [
                'current_page' => $topups->currentPage(),
                'last_page'    => $topups->lastPage(),
                'per_page'     => $topups->perPage(),
                'total'        => $topups->total(),
            ],
        ]);
    }

    private function serialize(?Topup $t): array
    {
        if (! $t) {
            return [];
        }

        return [
            'id'                 => $t->id,
            'amount'             => (float) $t->amount,
            'currency'           => $t->currency,
            'operator'           => $t->operator_code,
            'phone_number'       => $t->phone_number,
            'status'             => $t->status,
            'reference'          => $t->reference,
            'provider_reference' => $t->provider_reference,
            'failure_reason'     => $t->failure_reason,
            'completed_at'       => $t->completed_at?->toISOString(),
            'created_at'         => $t->created_at?->toISOString(),
        ];
    }
}
