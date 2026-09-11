<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transfer\InitiateTransferRequest;
use App\Http\Requests\Transfer\QuoteRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\AuthService;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $transferService,
        private readonly AuthService $authService,
    ) {}

    public function quote(QuoteRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $quote = $this->transferService->calculateQuote(
                $data['sender_account_id'],
                $data['recipient_phone'],
                (float) $data['amount']
            );
        } catch (\RuntimeException $e) {
            $type = $e->getMessage();
            $title = match ($type) {
                'NO_ROUTE_AVAILABLE' => 'Aucun itinéraire disponible',
                'AMOUNT_OUT_OF_RANGE' => 'Montant hors limites',
                default => 'Erreur',
            };
            $status = match ($type) {
                'NO_ROUTE_AVAILABLE' => 422,
                'AMOUNT_OUT_OF_RANGE' => 422,
                default => 400,
            };

            return $this->errorResponse($type, $title, $status, '', $request);
        }

        return response()->json($quote);
    }

    public function initiate(InitiateTransferRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!$this->authService->verifyPin($request->user(), $data['pin'])) {
            return $this->errorResponse(
                'INVALID_PIN', 'PIN invalide', 401,
                'Le code PIN est incorrect.', $request
            );
        }

        try {
            $transaction = $this->transferService->initiate(
                $data['quote_token'],
                $data['sender_account_id'],
                $data['recipient_phone'],
                (float) $data['amount']
            );
        } catch (\RuntimeException $e) {
            $type = $e->getMessage();
            $title = match ($type) {
                'QUOTE_EXPIRED' => 'Devis expiré',
                'QUOTE_MISMATCH' => 'Données du devis modifiées',
                'INSUFFICIENT_FUNDS' => 'Fonds insuffisants',
                default => 'Erreur',
            };
            $status = match ($type) {
                'QUOTE_EXPIRED' => 410,
                'QUOTE_MISMATCH' => 422,
                'INSUFFICIENT_FUNDS' => 422,
                default => 400,
            };

            return $this->errorResponse($type, $title, $status, '', $request);
        }

        return response()->json([
            'transaction_id' => $transaction->id,
            'reference' => $transaction->reference,
            'status' => $transaction->status,
        ], 202);
    }

    public function show(Request $request, string $transactionId): JsonResponse
    {
        // Mode offline : tente de traiter la file d'attente avant de répondre,
        // pour que le statut soit à jour dès qu'un connecteur est disponible.
        $this->transferService->processPendingTransfers();

        $transaction = Transaction::where('sender_user_id', $request->user()->id)
            ->with('recipientOperator')
            ->findOrFail($transactionId);

        return response()->json(new TransactionResource($transaction));
    }

    public function index(Request $request): JsonResponse
    {
        // Idem : flush opportuniste de la file d'attente.
        $this->transferService->processPendingTransfers();

        $query = Transaction::where('sender_user_id', $request->user()->id)
            ->with('recipientOperator');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Whitelist des colonnes autorisees pour le tri (anti-injection SQL)
        $allowedSortColumns = ['initiated_at', 'amount', 'status', 'completed_at'];

        $sort = $request->get('sort', '-initiated_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $column = in_array($column, $allowedSortColumns, true) ? $column : 'initiated_at';
        $query->orderBy($column, $direction);

        $perPage = min((int) $request->get('size', 20), 100);
        $transactions = $query->paginate($perPage);

        return $this->paginatedResponse($transactions,
            fn ($t) => new TransactionResource($t)
        );
    }

    public function cancel(Request $request, string $transactionId): JsonResponse
    {
        $transaction = Transaction::where('sender_user_id', $request->user()->id)
            ->findOrFail($transactionId);

        try {
            $this->transferService->cancel($transaction);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'TRANSACTION_NOT_CANCELLABLE', 'Annulation impossible', 409,
                'Cette transaction ne peut plus être annulée.', $request
            );
        }

        return response()->json(new TransactionResource($transaction->fresh()));
    }
}
