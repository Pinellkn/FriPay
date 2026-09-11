<?php

namespace Database\Seeders;

use App\Models\Operator;
use App\Models\PhonePrefix;
use Illuminate\Database\Seeder;

class OperatorSeeder extends Seeder
{
    public function run(): void
    {
        // MTN Bénin
        $mtn = Operator::create(['code' => 'MTN', 'name' => 'MTN Bénin', 'country_code' => 'BJ', 'active' => true]);
        PhonePrefix::create(['operator_id' => $mtn->id, 'prefix' => '22901', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $mtn->id, 'prefix' => '22997', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $mtn->id, 'prefix' => '22967', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $mtn->id, 'prefix' => '22969', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $mtn->id, 'prefix' => '22999', 'country_code' => 'BJ']);

        // Moov Bénin (ex Moov Africa)
        $moov = Operator::create(['code' => 'MOOV', 'name' => 'Moov Africa Bénin', 'country_code' => 'BJ', 'active' => true]);
        PhonePrefix::create(['operator_id' => $moov->id, 'prefix' => '22941', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $moov->id, 'prefix' => '22942', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $moov->id, 'prefix' => '22943', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $moov->id, 'prefix' => '22944', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $moov->id, 'prefix' => '22945', 'country_code' => 'BJ']);

        // Celtiis Bénin
        $celtiis = Operator::create(['code' => 'CELTIIS', 'name' => 'Celtiis Bénin', 'country_code' => 'BJ', 'active' => true]);
        PhonePrefix::create(['operator_id' => $celtiis->id, 'prefix' => '22946', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $celtiis->id, 'prefix' => '22947', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $celtiis->id, 'prefix' => '22948', 'country_code' => 'BJ']);
        PhonePrefix::create(['operator_id' => $celtiis->id, 'prefix' => '22949', 'country_code' => 'BJ']);
    }
}
