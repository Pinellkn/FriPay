<?php

namespace Database\Seeders;

use App\Models\Corridor;
use App\Models\Operator;
use Illuminate\Database\Seeder;

/**
 * Corridors par défaut : un corridor "agrégateur" par opérateur destinataire.
 * Sans cette table peuplée, TransferService::calculateQuote() lève toujours
 * NO_ROUTE_AVAILABLE et aucun transfert ne peut jamais aboutir.
 */
class CorridorSeeder extends Seeder
{
    public function run(): void
    {
        $operators = Operator::all()->keyBy('code');

        foreach (['MTN', 'MOOV', 'CELTIIS'] as $code) {
            $operator = $operators->get($code);
            if (!$operator) {
                continue;
            }

            if (Corridor::where('destination_operator_id', $operator->id)->exists()) {
                continue;
            }

            Corridor::create([
                'source_operator_id' => $operator->id,
                'destination_operator_id' => $operator->id,
                'rail' => 'aggregator',
                'aggregator_provider' => $code,
                'priority' => 10,
                'fee_type' => 'percentage',
                'fee_value' => 1.5,
                'fee_cap' => 500,
                'min_amount' => 100,
                'max_amount' => 1000000,
                'active' => true,
            ]);
        }
    }
}
