<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CorridorRequest;
use App\Models\AuditLog;
use App\Models\Corridor;
use App\Models\Operator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorridorController extends Controller
{
    /**
     * GET /api/v1/admin/corridors
     */
    public function index(): JsonResponse
    {
        $corridors = Corridor::with(['sourceOperator', 'destinationOperator'])
            ->orderBy('priority')
            ->get();

        return response()->json(['data' => $corridors]);
    }

    /**
     * POST /api/v1/admin/corridors
     */
    public function store(CorridorRequest $request): JsonResponse
    {
        $data = $request->validated();

        $sourceOp = Operator::where('code', $data['source_operator'])->firstOrFail();
        $destOp = Operator::where('code', $data['destination_operator'])->firstOrFail();

        $corridor = Corridor::create([
            'source_operator_id' => $sourceOp->id,
            'destination_operator_id' => $destOp->id,
            'rail' => $data['rail'],
            'aggregator_provider' => $data['aggregator_provider'] ?? null,
            'priority' => $data['priority'],
            'fee_type' => $data['fee_type'],
            'fee_value' => $data['fee_value'],
            'fee_cap' => $data['fee_cap'] ?? null,
            'min_amount' => $data['min_amount'],
            'max_amount' => $data['max_amount'],
            'active' => $data['active'] ?? true,
        ]);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => (string) $request->user()->id,
            'action' => 'corridor.created',
            'entity_type' => 'corridor',
            'entity_id' => (string) $corridor->id,
            'payload' => $data,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $corridor->load(['sourceOperator', 'destinationOperator'])], 201);
    }

    /**
     * PUT /api/v1/admin/corridors/{corridor_id}
     */
    public function update(Request $request, int $corridorId): JsonResponse
    {
        $corridor = Corridor::findOrFail($corridorId);

        // Snapshot de l'état avant modification pour l'audit trail
        $previousState = $corridor->toArray();

        $data = $request->validate([
            'rail' => 'sometimes|string|in:pispi,aggregator,manual',
            'aggregator_provider' => 'nullable|string|max:50',
            'priority' => 'sometimes|integer|min:1|max:100',
            'fee_type' => 'sometimes|string|in:fixed,percentage,tiered',
            'fee_value' => 'sometimes|numeric|min:0',
            'fee_cap' => 'nullable|numeric|min:0',
            'min_amount' => 'sometimes|numeric|min:0',
            'max_amount' => 'sometimes|numeric|min:0',
            'active' => 'sometimes|boolean',
        ]);

        $corridor->update($data);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => (string) $request->user()->id,
            'action' => 'corridor.updated',
            'entity_type' => 'corridor',
            'entity_id' => (string) $corridor->id,
            'payload' => [
                'previous' => $previousState,
                'changes' => $data,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $corridor->fresh()->load(['sourceOperator', 'destinationOperator'])]);
    }
}
