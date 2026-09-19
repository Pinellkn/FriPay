<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Complaint\CreateComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ComplaintController extends Controller
{
    /**
     * GET /api/v1/complaints — liste des plaintes de l'utilisateur connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Complaint::where('user_id', $request->user()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->get('size', 20), 100);
        $complaints = $query->paginate($perPage);

        return $this->paginatedResponse($complaints, fn ($c) => new ComplaintResource($c));
    }

    /**
     * POST /api/v1/complaints — ouverture d'une nouvelle plainte.
     * Le remboursement est automatiquement marqué "requested" pour les
     * motifs éligibles (wrong_transfer, not_received, duplicate_charge),
     * en miroir de la logique déjà présente côté mobile (mock).
     */
    public function store(CreateComplaintRequest $request): JsonResponse
    {
        $data = $request->validated();

        $refundEligible = in_array($data['reason'], Complaint::REFUND_ELIGIBLE_REASONS, true);

        $refundAmount = null;
        if ($refundEligible && !empty($data['linked_transaction_id'])) {
            $transaction = Transaction::find($data['linked_transaction_id']);
            $refundAmount = $transaction?->amount;
        }

        $complaint = Complaint::create([
            'reference' => 'TCK-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'status' => 'open',
            'linked_transaction_id' => $data['linked_transaction_id'] ?? null,
            'refund_requested' => $refundEligible,
            'refund_status' => $refundEligible ? 'requested' : 'not_applicable',
            'refund_amount' => $refundAmount,
        ]);

        return response()->json(new ComplaintResource($complaint), 201);
    }

    /**
     * GET /api/v1/complaints/{id} — détail d'une plainte.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $complaint = Complaint::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json(new ComplaintResource($complaint));
    }
}
