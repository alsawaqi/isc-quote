<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('quotation_items', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('uom');
            }

            if (! Schema::hasColumn('quotation_items', 'incoterm_id')) {
                $table->foreignId('incoterm_id')
                    ->nullable()
                    ->after('delivery_date')
                    ->constrained('incoterms')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasColumn('quotation_items', 'incoterm_id')) {
            DB::table('quotation_items')
                ->select([
                    'quotation_items.id',
                    'quotations.incoterm_id',
                ])
                ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
                ->whereNull('quotation_items.incoterm_id')
                ->orderBy('quotation_items.id')
                ->chunkById(200, function ($items): void {
                    foreach ($items as $item) {
                        DB::table('quotation_items')->where('id', $item->id)->update([
                            'incoterm_id' => $item->incoterm_id,
                            'updated_at' => now(),
                        ]);
                    }
                }, 'quotation_items.id', 'id');
        }
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table): void {
            if (Schema::hasColumn('quotation_items', 'incoterm_id')) {
                $table->dropConstrainedForeignId('incoterm_id');
            }

            if (Schema::hasColumn('quotation_items', 'delivery_date')) {
                $table->dropColumn('delivery_date');
            }
        });
    }
};
