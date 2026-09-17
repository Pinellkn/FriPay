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
