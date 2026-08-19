<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_po_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('supplier_po_lines', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('uom');
            }

            if (! Schema::hasColumn('supplier_po_lines', 'incoterm_id')) {
                $table->foreignId('incoterm_id')
                    ->nullable()
                    ->after('delivery_date')
                    ->constrained('incoterms')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasColumn('supplier_po_lines', 'incoterm_id')) {
            DB::table('supplier_po_lines')
                ->select([
                    'supplier_po_lines.id',
                    'supplier_pos.incoterm_id',
                    'quotation_items.delivery_date',
                ])
                ->join('supplier_pos', 'supplier_po_lines.supplier_po_id', '=', 'supplier_pos.id')
                ->leftJoin('quotation_items', 'supplier_po_lines.quotation_item_id', '=', 'quotation_items.id')
                ->where(function ($query): void {
                    $query
                        ->whereNull('supplier_po_lines.incoterm_id')
                        ->orWhereNull('supplier_po_lines.delivery_date');
                })
                ->orderBy('supplier_po_lines.id')
                ->chunkById(200, function ($lines): void {
                    foreach ($lines as $line) {
                        $values = ['updated_at' => now()];

                        if ($line->incoterm_id) {
                            $values['incoterm_id'] = $line->incoterm_id;
                        }

                        if ($line->delivery_date) {
                            $values['delivery_date'] = $line->delivery_date;
                        }

                        if (count($values) > 1) {
                            DB::table('supplier_po_lines')->where('id', $line->id)->update($values);
                        }
                    }
                }, 'supplier_po_lines.id', 'id');
        }

        if (! Schema::hasTable('supplier_po_line_origins')) {
            Schema::create('supplier_po_line_origins', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_po_line_id')->constrained('supplier_po_lines')->cascadeOnDelete();
                $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
                $table->string('country_name', 120)->nullable();
                $table->decimal('amount', 15, 3)->nullable();
                $table->string('location')->nullable();
                $table->unsignedSmallInteger('line_number');
                $table->timestamps();

                $table->unique(['supplier_po_line_id', 'line_number'], 'spo_line_origins_line_number_unique');
                $table->index(['country_id', 'supplier_po_line_id'], 'spo_line_origins_country_line_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_po_line_origins');

        Schema::table('supplier_po_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('supplier_po_lines', 'incoterm_id')) {
                $table->dropConstrainedForeignId('incoterm_id');
            }

            if (Schema::hasColumn('supplier_po_lines', 'delivery_date')) {
                $table->dropColumn('delivery_date');
            }
        });
    }
};
