<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();

        foreach ([
            ['code' => 'EA', 'name' => 'Each'],
            ['code' => 'PCS', 'name' => 'Pieces'],
            ['code' => 'SET', 'name' => 'Set'],
            ['code' => 'LOT', 'name' => 'Lot'],
            ['code' => 'MTR', 'name' => 'Meter'],
            ['code' => 'KG', 'name' => 'Kilogram'],
            ['code' => 'BOX', 'name' => 'Box'],
            ['code' => 'NOS', 'name' => 'Numbers'],
            ['code' => 'PAIR', 'name' => 'Pair'],
            ['code' => 'ROLL', 'name' => 'Roll'],
            ['code' => 'LTR', 'name' => 'Liter'],
        ] as $uom) {
            if (! DB::table('uoms')->where('code', $uom['code'])->exists()) {
                DB::table('uoms')->insert([
                    ...$uom,
                    'status' => 'active',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }

        foreach ([
            ['code' => 'OMR', 'name' => 'Omani Rial', 'symbol' => "\u{0631}.\u{0639}.", 'exchange_rate' => '1.000000'],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => '2.600000'],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => "\u{20AC}", 'exchange_rate' => '2.820000'],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => "\u{00A3}", 'exchange_rate' => '3.300000'],
        ] as $currency) {
            if (! DB::table('currencies')->where('code', $currency['code'])->exists()) {
                DB::table('currencies')->insert([
                    ...$currency,
                    'status' => 'active',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }
    }

    public function down(): void
    {
        // These records are user-managed master data and must not be deleted on rollback.
    }
};
