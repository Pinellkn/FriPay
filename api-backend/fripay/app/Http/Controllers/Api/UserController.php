<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetPinRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * GET /api/v1/users/me
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()));
    }

    /**
     * PUT /api/v1/users/me
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:150|unique:users,email,' . $request->user()->id,
        ]);

        $request->user()->update(array_filter($data));

        return response()->json(new UserResource($request->user()->fresh()));
    }

    /**
     * POST /api/v1/users/me/pin
     */
    public function setPin(SetPinRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        // If user already has a PIN, verify current PIN first
        if ($user->pin_hash && isset($data['current_pin'])) {
            if (!$this->authService->verifyPin($user, $data['current_pin'])) {
                return $this->errorResponse(
                    'INVALID_CURRENT_PIN',
                    'PIN actuel invalide',
                    401,
                    'Le code PIN actuel est incorrect.',
                    $request
                );
            }
        }

        $this->authService->setPin($user, $data['new_pin']);

        return response()->json(null, 204);
    }
}
