<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bill\PayBillRequest;
use App\Http\Resources\BillerResource;
use App\Http\Resources\BillPaymentResource;
use App\Models\Biller;
use App\Models\BillPayment;
use App\Services\AuthService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly AuthService $authService,
    ) {}

    /**
     * GET /api/v1/bills/billers — catalogue des fournisseurs disponibles.
     */
    public function billers(): JsonResponse
    {
        $billers = Biller::where('active', true)->orderBy('name')->get();

        return response()->json(BillerResource::collection($billers));
    }

    /**
     * POST /api/v1/bills/pay — paiement d'une facture depuis le solde FriPay.
     */
    public function pay(PayBillRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (!$this->authService->verifyPin($user, $data['pin'])) {
            return $this->errorResponse(
                'INVALID_PIN', 'PIN invalide', 401,
                'Le code PIN est incorrect.', $request
            );
        }

        $biller = Biller::where('id', $data['biller_id'])->where('active', true)->first();
        if (!$biller) {
            return $this->errorResponse(
                'BILLER_NOT_FOUND', 'Fournisseur introuvable', 404, '', $request
            );
        }

        try {
            $payment = DB::transaction(function () use ($user, $biller, $data) {
                $wallet = $this->walletService->debit(
                    $user->id,
                    (float) $data['amount'],
                    null,
                    'bill_payment',
                    "Facture {$biller->name} — {$data['subscriber_reference']}"
                );

                $ledgerEntry = $wallet->ledgerEntries()->latest('created_at')->first();

                return BillPayment::create([
                    'reference' => 'FCT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                    'user_id' => $user->id,
                    'biller_id' => $biller->id,
                    'subscriber_reference' => $data['subscriber_reference'],
                    'amount' => $data['amount'],
                    'status' => 'paid',
                    'wallet_ledger_entry_id' => $ledgerEntry?->id,
                    'paid_at' => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'INSUFFICIENT_FUNDS') {
                return $this->errorResponse(
                    'INSUFFICIENT_FUNDS', 'Solde insuffisant', 422,
                    'Votre solde FriPay est insuffisant pour ce paiement.', $request
                );
            }
            throw $e;
        }

        return response()->json(
            new BillPaymentResource($payment->load('biller')),
            201
        );
    }

    /**
     * GET /api/v1/bills — historique des paiements de factures de l'utilisateur.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BillPayment::where('user_id', $request->user()->id)->with('biller');
        $query->orderByDesc('created_at');

        $perPage = min((int) $request->get('size', 20), 100);
        $payments = $query->paginate($perPage);

        return $this->paginatedResponse($payments, fn ($p) => new BillPaymentResource($p));
    }

    /**
     * GET /api/v1/bills/{id} — détail d'un paiement de facture.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $payment = BillPayment::where('user_id', $request->user()->id)
            ->with('biller')
            ->findOrFail($id);

        return response()->json(new BillPaymentResource($payment));
    }
}
