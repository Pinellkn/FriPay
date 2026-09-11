<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Services\OperatorDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        private readonly OperatorDetectionService $operatorDetection,
    ) {}

    /**
     * GET /api/v1/users/me/contacts
     */
    public function index(Request $request): JsonResponse
    {
        $contacts = $request->user()->contacts;
        return response()->json([
            'data' => ContactResource::collection($contacts),
        ]);
    }

    /**
     * POST /api/v1/users/me/contacts
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_phone' => 'required|string|regex:/^\+229\d{10}$/',
            'contact_name' => 'required|string|max:150',
        ]);

        $phone = $this->operatorDetection->normalize($data['contact_phone']);
        $operator = $this->operatorDetection->detect($phone);

        $contact = $request->user()->contacts()->create([
            'contact_phone' => $phone,
            'contact_name' => $data['contact_name'],
            'detected_operator_id' => $operator?->id,
        ]);

        return response()->json(new ContactResource($contact), 201);
    }

    /**
     * DELETE /api/v1/users/me/contacts/{contact_id}
     */
    public function destroy(Request $request, string $contactId): JsonResponse
    {
        $contact = $request->user()->contacts()->findOrFail($contactId);
        $contact->delete();

        return response()->json(null, 204);
    }
}
