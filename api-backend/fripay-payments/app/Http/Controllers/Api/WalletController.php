<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\TopupRequest;
use App\Http\Requests\Wallet\WithdrawRequest;
use App\Services\AuthService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuthService $authService,
    ) {}

    /**
     * GET /api/v1/wallet — solde courant.
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->getOrCreate($request->user()->id);

        return response()->json([
            'balance'  => (float) $wallet->balance,
            'currency' => $wallet->currency,
            'status'   => $wallet->status,
        ]);
    }

    /**
     * GET /api/v1/wallet/transactions — historique paginé des mouvements (ledger).
     */
    public function transactions(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('size', 20), 100);
        $entries = $this->wallets->history($request->user()->id, $perPage);

        return $this->paginatedResponse($entries, fn ($e) => [
            'id'            => $e->id,
            'type'          => $e->type,
            'amount'        => (float) $e->amount,
            'balance_after' => (float) $e->balance_after,
            'reason'        => $e->reason,
            'description'   => $e->description,
            'transaction_id' => $e->transaction_id,
            'created_at'    => $e->created_at,
        ]);
    }

    /**
     * POST /api/v1/wallet/topup — dépôt manuel (TEMPORAIRE, tant qu'aucun
     * vrai rail de cash-in agent/opérateur n'est branché — `agent_code`
     * n'est donc pas validé contre un registre réel, juste enregistré à
     * titre indicatif dans le libellé du mouvement).
     */
    public function topup(TopupRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (!$this->authService->verifyPin($user, $data['pin'])) {
            return $this->errorResponse(
                'INVALID_PIN', 'PIN invalide', 401,
                'Le code PIN est incorrect.', $request
            );
        }

        $description = 'Dépôt manuel (dev/test)'
            . (!empty($data['agent_code']) ? " — agent {$data['agent_code']}" : '');

        $wallet = $this->wallets->credit(
            $user->id,
            (float) $data['amount'],
            null,
            'manual_topup',
            $description
        );

        return response()->json([
            'balance'  => (float) $wallet->balance,
            'currency' => $wallet->currency,
        ], 201);
    }

    /**
     * POST /api/v1/wallet/withdraw — retrait manuel (TEMPORAIRE, même
     * logique que topup : pas de vrai réseau d'agents pour l'instant, le
     * cash est supposé remis par un agent hors-app une fois le débit
     * confirmé côté FriPay).
     */
    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (!$this->authService->verifyPin($user, $data['pin'])) {
            return $this->errorResponse(
                'INVALID_PIN', 'PIN invalide', 401,
                'Le code PIN est incorrect.', $request
            );
        }

        $description = 'Retrait manuel (dev/test)'
            . (!empty($data['agent_code']) ? " — agent {$data['agent_code']}" : '');

        try {
            $wallet = $this->wallets->debit(
                $user->id,
                (float) $data['amount'],
                null,
                'manual_withdraw',
                $description
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_FUNDS') {
                return $this->errorResponse(
                    'INSUFFICIENT_FUNDS', 'Solde insuffisant', 422,
                    'Votre solde FriPay est insuffisant pour ce retrait.', $request
                );
            }
            throw $e;
        }

        return response()->json([
            'balance'  => (float) $wallet->balance,
            'currency' => $wallet->currency,
        ], 201);
    }
}
