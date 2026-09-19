<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminRbac
{
    /**
     * Handle an incoming request - verify the staff user has the required permission.
     * Uses the dedicated 'staff' Sanctum guard (separate from regular users).
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var StaffUser|null $staff */
        $staff = $request->user('staff');

        if (!$staff) {
            return response()->json([
                'type' => 'INSUFFICIENT_PERMISSIONS',
                'title' => 'Authentification requise',
                'status' => 401,
                'detail' => 'Un jeton d\'accès staff valide est requis.',
                'request_id' => $request->header('X-Request-Id', ''),
            ], 401);
        }

        if (!$staff instanceof StaffUser) {
            return response()->json([
                'type' => 'INSUFFICIENT_PERMISSIONS',
                'title' => 'Permissions insuffisantes',
                'status' => 403,
                'detail' => 'Accès réservé au personnel autorisé.',
                'request_id' => $request->header('X-Request-Id', ''),
            ], 403);
        }

        if (!$staff->active) {
            return response()->json([
                'type' => 'ACCOUNT_DISABLED',
                'title' => 'Compte désactivé',
                'status' => 403,
                'detail' => 'Ce compte staff a été désactivé.',
                'request_id' => $request->header('X-Request-Id', ''),
            ], 403);
        }

        if (!$staff->hasPermission($permission)) {
            return response()->json([
                'type' => 'INSUFFICIENT_PERMISSIONS',
                'title' => 'Permissions insuffisantes',
                'status' => 403,
                'detail' => "Vous n'avez pas la permission: {$permission}",
                'request_id' => $request->header('X-Request-Id', ''),
            ], 403);
        }

        return $next($request);
    }
}
