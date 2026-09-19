<?php

namespace Database\Seeders;

use App\Models\Operator;
use App\Models\PhonePrefix;
use Illuminate\Database\Seeder;

/**
 * Opérateurs + préfixes — plan national de numérotation du Bénin.
 *
 * Depuis la réforme entrée en vigueur le 30/11/2024, tout numéro mobile
 * béninois s'écrit sur 10 chiffres significatifs : « 01 » + 8 chiffres
 * (le « 01 » fait partie intégrante du numéro). En E.164 avec l'indicatif :
 * +229 01 XX XX XX XX.
 *
 * Source : réforme de numérotation (ARCEP Bénin), liste consolidée —
 * alignée sur le module mobile `lib/services/network_prefixes.dart` (§4).
 *
 * Le préfixe stocké couvre « 22901 » + les 2 chiffres opérateurs
 * (ex. `2290197`) : OperatorDetectionService trie par longueur
 * décroissante, donc « 2290197 » est testé avant « 22901 » et la
 * détection distingue correctement les opérateurs.
 *
 * ⚠️ Portabilité des numéros : la détection par préfixe reste une
 * présomption (un abonné peut changer d'opérateur en gardant son numéro).
 */
class OperatorSeeder extends Seeder
{
    public function run(): void
    {
        // MTN Bénin — préfixes à 2 chiffres après « 01 » (ex. 01 97 …)
        $mtn = Operator::create(['code' => 'MTN', 'name' => 'MTN Bénin', 'country_code' => 'BJ', 'active' => true]);
        foreach (['42', '46', '50', '51', '52', '53', '54', '56', '57', '59', '61', '62', '66', '67', '69', '90', '91', '96', '97'] as $p) {
            PhonePrefix::create(['operator_id' => $mtn->id, 'prefix' => '22901' . $p, 'country_code' => 'BJ']);
        }

        // Moov Africa Bénin
        $moov = Operator::create(['code' => 'MOOV', 'name' => 'Moov Africa Bénin', 'country_code' => 'BJ', 'active' => true]);
        foreach (['45', '55', '58', '60', '63', '64', '65', '68', '94', '95', '98', '99'] as $p) {
            PhonePrefix::create(['operator_id' => $moov->id, 'prefix' => '22901' . $p, 'country_code' => 'BJ']);
        }

        // Celtiis Bénin (SBIN)
        $celtiis = Operator::create(['code' => 'CELTIIS', 'name' => 'Celtiis Bénin', 'country_code' => 'BJ', 'active' => true]);
        foreach (['20', '21', '22', '23', '24', '28', '29', '40', '41', '43', '44', '47', '48', '49', '92', '93'] as $p) {
            PhonePrefix::create(['operator_id' => $celtiis->id, 'prefix' => '22901' . $p, 'country_code' => 'BJ']);
        }
    }
}
