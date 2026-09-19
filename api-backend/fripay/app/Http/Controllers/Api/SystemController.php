<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    /**
     * §8 - Interface technique : liste des préfixes réseau (MTN/Moov/Celtiis)
     * groupés par opérateur, pour affichage/suivi dans l'app.
     */
    public function prefixes(): JsonResponse
    {
        $operators = Operator::with('phonePrefixes')
            ->orderBy('name')
            ->get()
            ->map(function (Operator $operator) {
                return [
                    'code' => $operator->code,
                    'name' => $operator->name,
                    'active' => $operator->active,
                    'prefixes' => $operator->phonePrefixes
                        ->pluck('prefix')
                        ->sort()
                        ->values(),
                ];
            });

        return response()->json([
            'operators' => $operators,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
