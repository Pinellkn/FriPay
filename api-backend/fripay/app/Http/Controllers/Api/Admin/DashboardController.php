<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/admin/dashboard/kpis
     */
    public function kpis(Request $request): JsonResponse
    {
        $period = $request->get('period', '7d');
        $since = match ($period) {
            '1d' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            '90d' => now()->subMonths(3),
            default => now()->subWeek(),
        };

        $stats = Transaction::where('initiated_at', '>=', $since)
            ->selectRaw('
                COUNT(*) as total_count,
                COUNT(*) FILTER (WHERE status = ?) as success_count,
                COALESCE(SUM(total_debited) FILTER (WHERE status = ?), 0) as total_volume,
                COALESCE(AVG(
                    CASE WHEN completed_at IS NOT NULL AND initiated_at IS NOT NULL
                    THEN EXTRACT(EPOCH FROM (completed_at - initiated_at))
                    END
                ), 0) as avg_delivery_seconds
            ', ['succeeded', 'succeeded'])
            ->first();

        $byRail = Transaction::where('initiated_at', '>=', $since)
            ->select('rail_used', DB::raw('COUNT(*) as count'))
            ->groupBy('rail_used')
            ->pluck('count', 'rail_used');

        $successRate = $stats->total_count > 0
            ? round($stats->success_count / $stats->total_count, 3)
            : 0;

        return response()->json([
            'success_rate' => $successRate,
            'total_volume_xof' => (float) $stats->total_volume,
            'transactions_count' => (int) $stats->total_count,
            'avg_delivery_seconds' => (int) round($stats->avg_delivery_seconds),
            'by_rail' => [
                'pispi' => (int) ($byRail['pispi'] ?? 0),
                'aggregator' => (int) ($byRail['aggregator'] ?? 0),
            ],
        ]);
    }
}
