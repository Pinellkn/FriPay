<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Operator;
use App\Models\PhonePrefix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §8 — Interface technique : gestion des préfixes réseau (MTN/Moov/Celtiis).
 *
 * Le module de détection (packages/fripay-common) lit cette même table
 * `phone_prefixes` — toute modification ici est donc immédiatement prise
 * en compte par OperatorDetectionService dans les 3 services (users,
 * payments, admin), sans redéploiement.
 *
 * Format attendu : E.164 sans « + » — `229` + `01` + 2 chiffres opérateur
 * (ex. `2290197`), conformément au plan national de numérotation en
 * vigueur depuis le 30/11/2024 (réforme ARCEP Bénin).
 */
class PhonePrefixController extends Controller
{
    /**
     * GET /api/v1/admin/phone-prefixes
     */
    public function index(): JsonResponse
    {
        $prefixes = PhonePrefix::with('operator')
            ->orderBy('operator_id')
            ->orderBy('prefix')
            ->get();

        return response()->json(['data' => $prefixes]);
    }

    /**
     * POST /api/v1/admin/phone-prefixes
     * Body : { operator_code: "MTN"|"MOOV"|"CELTIIS", prefix: "2290197" }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operator_code' => ['required', 'string', 'exists:operators,code'],
            'prefix'        => ['required', 'string', 'regex:/^22901\d{2}$/', 'unique:phone_prefixes,prefix'],
        ], [
            'prefix.regex' => 'Le préfixe doit être au format 229 01 XX (ex. 2290197) — plan de numérotation du 30/11/2024.',
            'prefix.unique' => 'Ce préfixe existe déjà.',
        ]);

        $operator = Operator::where('code', $validated['operator_code'])->first();

        $prefix = PhonePrefix::create([
            'operator_id'  => $operator->id,
            'prefix'       => $validated['prefix'],
            'country_code' => 'BJ',
        ]);

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id'   => (string) $request->user()->id,
            'action'     => 'phone_prefix.created',
            'entity_type' => 'phone_prefix',
            'entity_id'  => (string) $prefix->id,
            'payload'    => $validated,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['data' => $prefix->load('operator')], 201);
    }

    /**
     * DELETE /api/v1/admin/phone-prefixes/{prefixId}
     */
    public function destroy(Request $request, string $prefixId): JsonResponse
    {
        $prefix = PhonePrefix::find($prefixId);

        if (! $prefix) {
            return $this->errorResponse(
                'PREFIX_NOT_FOUND', 'Préfixe introuvable', 404,
                'Aucun préfixe correspondant.', $request
            );
        }

        $details = ['prefix' => $prefix->prefix, 'operator_id' => $prefix->operator_id];
        $prefix->delete();

        AuditLog::create([
            'actor_type' => 'staff',
            'actor_id'   => (string) $request->user()->id,
            'action'     => 'phone_prefix.deleted',
            'entity_type' => 'phone_prefix',
            'entity_id'  => (string) $prefixId,
            'payload'    => $details,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['status' => 'ok']);
    }
}
