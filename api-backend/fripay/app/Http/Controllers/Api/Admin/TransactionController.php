<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\TransactionStatusHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * GET /api/v1/admin/transactions
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with('recipientOperator', 'sender');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('operator')) {
            $query->whereHas('recipientOperator', fn ($q) => $q->where('code', $request->operator));
        }

        if ($request->has('date_from')) {
            $query->where('initiated_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('initiated_at', '<=', $request->date_to);
        }

        $perPage = min((int) $request->get('size', 20), 100);
        $transactions = $query->orderBy('initiated_at', 'desc')->paginate($perPage);

        return $this->paginatedResponse($transactions);
    }

    /**
     * GET /api/v1/admin/transactions/{transaction_id}
     */
    public function show(string $transactionId): JsonResponse
    {
        $transaction = Transaction::with([
            'recipientOperator', 'sender', 'senderAccount', 'corridor',
            'statusHistory' => fn ($q) => $q->orderBy('created_at', 'desc'),
        ])->findOrFail($transactionId);

        return response()->json([
            'data' => new TransactionResource($transaction),
            'status_history' => $transaction->statusHistory->map(fn ($h) => [
                'previous_status' => $h->previous_status,
                'new_status' => $h->new_status,
                'source' => $h->source,
                'note' => $h->note,
                'created_at' => $h->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /api/v1/admin/transactions/{transaction_id}/retry
     */
    public function retry(Request $request, string $transactionId): JsonResponse
    {
        $transaction = Transaction::findOrFail($transactionId);

        if ($transaction->status !== 'failed') {
            return $this->errorResponse(
                'TRANSACTION_NOT_RETRYABLE',
                'Transaction non relançable',
                409,
                'Seules les transactions en échec peuvent être relancées.',
                $request
            );
        }

        $transaction->update([
            'status' => 'processing',
            'failure_reason' => null,
        ]);

        TransactionStatusHistory::create([
            'transaction_id' => $transaction->id,
            'previous_status' => 'failed',
            'new_status' => 'processing',
            'source' => 'manual',
            'note' => 'Relance manuelle depuis le back-office',
        ]);

        return response()->json(new TransactionResource($transaction->fresh()));
    }
}
