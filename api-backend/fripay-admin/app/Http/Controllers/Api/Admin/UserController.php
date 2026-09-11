<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('search')) {
            $search = $request->input('search');

            // Limiter la longueur de la recherche
            $search = mb_substr($search, 0, 100);

            // Échapper les caractères LIKE (% et _) pour éviter les patterns inattendus
            $escapedSearch = addcslashes($search, '%_');

            $query->where(function ($q) use ($escapedSearch) {
                $q->where('phone_number', 'like', "%{$escapedSearch}%")
                  ->orWhere('first_name', 'like', "%{$escapedSearch}%")
                  ->orWhere('last_name', 'like', "%{$escapedSearch}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = min((int) $request->get('size', 20), 100);
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return $this->paginatedResponse($users);
    }

    /**
     * GET /api/v1/admin/users/{user_id}
     */
    public function show(string $userId): JsonResponse
    {
        $user = User::with(['linkedAccounts', 'contacts'])->findOrFail($userId);
        return response()->json(['data' => new UserResource($user)]);
    }

    /**
     * PUT /api/v1/admin/users/{user_id}/status
     */
    public function updateStatus(Request $request, string $userId): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:active,blocked,suspended',
            'reason' => 'required|string|max:500',
        ]);

        $user = User::findOrFail($userId);
        $user->update(['status' => $data['status']]);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id' => (string) $request->user()->id,
            'action' => 'user.status_changed',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'payload' => [
                'previous_status' => $user->getOriginal('status'),
                'new_status' => $data['status'],
                'reason' => $data['reason'],
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => new UserResource($user->fresh())]);
    }
}
