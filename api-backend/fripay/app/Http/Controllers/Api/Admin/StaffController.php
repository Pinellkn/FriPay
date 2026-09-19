<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StaffUser;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * GET /api/v1/admin/staff
     */
    public function index(): JsonResponse
    {
        $staff = StaffUser::with('role')->get();

        return response()->json(['data' => $staff->map(fn ($s) => [
            'id' => $s->id,
            'email' => $s->email,
            'first_name' => $s->first_name,
            'last_name' => $s->last_name,
            'role' => $s->role->name,
            'active' => $s->active,
            'created_at' => $s->created_at?->toIso8601String(),
        ])]);
    }

    /**
     * POST /api/v1/admin/staff
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|unique:staff_users,email',
            'password' => 'required|string|min:8',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'role_id' => 'required|integer|exists:roles,id',
        ]);

        $staff = StaffUser::create([
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'role_id' => $data['role_id'],
            'active' => true,
        ]);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => (string) $request->user()->id,
            'action' => 'staff.created',
            'entity_type' => 'staff_user',
            'entity_id' => (string) $staff->id,
            'payload' => [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'role_id' => $data['role_id'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'id' => $staff->id,
            'email' => $staff->email,
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'role' => $staff->role->name,
            'active' => $staff->active,
        ], 201);
    }

    /**
     * PUT /api/v1/admin/staff/{id}/role
     */
    public function updateRole(Request $request, int $staffId): JsonResponse
    {
        $data = $request->validate([
            'role_id' => 'required|integer|exists:roles,id',
        ]);

        $staff = StaffUser::findOrFail($staffId);
        $staff->update(['role_id' => $data['role_id']]);

        return response()->json(['message' => 'Rôle mis à jour']);
    }
}
