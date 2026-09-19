<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro FriPay (cahier des charges §1).
 *
 * Identifiant unique de 10 chiffres, préfixe "30" suivi de 8 chiffres
 * aléatoires, attribué automatiquement à l'inscription. C'est l'identité
 * publique de l'utilisateur dans FriPay, distincte de son numéro
 * d'opérateur (qui, lui, commence par "01").
 *
 * Nullable au niveau du schéma pour que la migration passe sur une base
 * qui contient déjà des comptes : ceux-ci sont backfillés juste après
 * (voir la boucle ci-dessous), et tout nouveau compte en reçoit un via
 * FripayNumberService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fripay_number', 10)->nullable()->unique()->after('phone_number');
        });

        // Backfill des comptes existants.
        $existing = DB::table('users')->whereNull('fripay_number')->pluck('id');
        $taken = DB::table('users')->whereNotNull('fripay_number')->pluck('fripay_number')->flip();

        foreach ($existing as $id) {
            do {
                $candidate = '30' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            } while (isset($taken[$candidate]));

            $taken[$candidate] = true;
            DB::table('users')->where('id', $id)->update(['fripay_number' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['fripay_number']);
            $table->dropColumn('fripay_number');
        });
    }
};
