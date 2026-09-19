<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LinkedAccountResource;
use App\Models\LinkedAccount;
use App\Services\OperatorDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkedAccountController extends Controller
{
    public function __construct(
        private readonly OperatorDetectionService $operatorDetection,
    ) {}

    /**
     * GET /api/v1/users/me/accounts
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()->linkedAccounts;
        return response()->json([
            'data' => LinkedAccountResource::collection($accounts),
        ]);
    }

    /**
     * POST /api/v1/users/me/accounts
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'msisdn' => 'required|string|regex:/^\+229\d{10}$/',
        ]);

        $msisdn = $this->operatorDetection->normalize($data['msisdn']);
        $operator = $this->operatorDetection->detect($msisdn);

        if (!$operator) {
            return $this->errorResponse(
                'OPERATOR_NOT_SUPPORTED',
                'Opérateur non supporté',
                422,
                'Ce numéro n\'est pas associé à un opérateur pris en charge.',
                $request
            );
        }

        $exists = $request->user()->linkedAccounts()
            ->where('msisdn', $msisdn)
            ->exists();

        if ($exists) {
            return $this->errorResponse(
                'ACCOUNT_ALREADY_LINKED',
                'Compte déjà lié',
                409,
                'Ce numéro est déjà lié à votre compte.',
                $request
            );
        }

        $isFirst = $request->user()->linkedAccounts()->count() === 0;

        $account = $request->user()->linkedAccounts()->create([
            'operator_id' => $operator->id,
            'msisdn' => $msisdn,
            'is_primary' => $isFirst,
            'status' => 'active',
        ]);

        return response()->json(new LinkedAccountResource($account), 201);
    }

    /**
     * DELETE /api/v1/users/me/accounts/{account_id}
     */
    public function destroy(Request $request, string $accountId): JsonResponse
    {
        $account = $request->user()->linkedAccounts()->findOrFail($accountId);

        if ($request->user()->linkedAccounts()->count() <= 1) {
            return $this->errorResponse(
                'CANNOT_DELETE_LAST_ACCOUNT',
                'Dernier compte',
                409,
                'Vous ne pouvez pas supprimer votre dernier compte lié.',
                $request
            );
        }

        $account->delete();

        return response()->json(null, 204);
    }
}
