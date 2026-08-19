<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('rfq_title')->nullable()->after('pr_number');
            $table->text('payment_terms_extra')->nullable()->after('payment_term_days');
        });

        Schema::table('currencies', function (Blueprint $table): void {
            $table->string('symbol', 16)->nullable()->after('name');
        });

        foreach ($this->currencySymbols() as $code => $symbol) {
            DB::table('currencies')
                ->where('code', $code)
                ->update(['symbol' => $symbol]);
        }
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn(['rfq_title', 'payment_terms_extra']);
        });

        Schema::table('currencies', function (Blueprint $table): void {
            $table->dropColumn('symbol');
        });
    }

    /**
     * @return array<string, string>
     */
    private function currencySymbols(): array
    {
        return [
            'AED' => 'د.إ',
            'EUR' => '€',
            'GBP' => '£',
            'OMR' => 'ر.ع.',
            'SAR' => '﷼',
            'USD' => '$',
        ];
    }
};
