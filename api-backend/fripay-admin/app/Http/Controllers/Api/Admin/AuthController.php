<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/v1/admin/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $staff = StaffUser::with('role.permissions')
            ->where('email', $data['email'])
            ->where('active', true)
            ->first();

        if (!$staff || !Hash::check($data['password'], $staff->password_hash)) {
            return $this->errorResponse(
                'INVALID_CREDENTIALS',
                'Identifiants invalides',
                401,
                'Email ou mot de passe incorrect.',
                $request
            );
        }

        $token = $staff->createToken('admin-token', ['*'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'role' => $staff->role->name,
            'permissions' => $staff->role->permissions->pluck('code'),
        ]);
    }
}
