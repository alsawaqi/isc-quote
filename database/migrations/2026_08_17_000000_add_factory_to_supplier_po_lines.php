<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('supplier_po_lines') || ! Schema::hasTable('company_locations')) {
            return;
        }

        if (! Schema::hasColumn('supplier_po_lines', 'company_location_id')) {
            Schema::table('supplier_po_lines', function (Blueprint $table): void {
                $table->foreignId('company_location_id')
                    ->nullable()
                    ->after('manufacturer_id')
                    ->constrained('company_locations')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('supplier_pos') || ! Schema::hasColumn('supplier_pos', 'company_location_id')) {
            return;
        }

        DB::table('supplier_po_lines')
            ->select([
                'supplier_po_lines.id',
                'supplier_pos.company_location_id',
            ])
            ->join('supplier_pos', 'supplier_po_lines.supplier_po_id', '=', 'supplier_pos.id')
            ->whereNull('supplier_po_lines.company_location_id')
            ->whereNotNull('supplier_pos.company_location_id')
            ->orderBy('supplier_po_lines.id')
            ->chunkById(250, function ($lines): void {
                foreach ($lines as $line) {
                    DB::table('supplier_po_lines')
                        ->where('id', $line->id)
                        ->update(['company_location_id' => $line->company_location_id]);
                }
            }, 'supplier_po_lines.id', 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('supplier_po_lines') || ! Schema::hasColumn('supplier_po_lines', 'company_location_id')) {
            return;
        }

        Schema::table('supplier_po_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_location_id');
        });
    }
};
