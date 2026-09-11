<?php

namespace Database\Seeders;

use App\Models\Biller;
use Illuminate\Database\Seeder;

class BillerSeeder extends Seeder
{
    /**
     * Miroir de `billers` dans lib/data/mock_data.dart (mobile).
     */
    public function run(): void
    {
        $billers = [
            ['code' => 'sbee', 'name' => 'SBEE', 'kind' => 'Électricité'],
            ['code' => 'soneb', 'name' => 'SONEB', 'kind' => 'Eau'],
            ['code' => 'canal', 'name' => 'Canal+ Bénin', 'kind' => 'TV'],
            ['code' => 'mtn-data', 'name' => 'Forfait MTN', 'kind' => 'Internet'],
            ['code' => 'moov-data', 'name' => 'Forfait Moov', 'kind' => 'Internet'],
            ['code' => 'scolarite', 'name' => 'Scolarité', 'kind' => 'Éducation'],
        ];

        foreach ($billers as $b) {
            Biller::updateOrCreate(['code' => $b['code']], $b + ['active' => true]);
        }
    }
}
