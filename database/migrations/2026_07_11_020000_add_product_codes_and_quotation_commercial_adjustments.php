<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('products', 'products_manufacturer_id_index')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->index('manufacturer_id');
            });
        }

        if (Schema::hasIndex('products', 'products_manufacturer_id_name_title_unique')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropUnique('products_manufacturer_id_name_title_unique');
            });
        }

        if (! Schema::hasColumn('products', 'product_code')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->string('product_code', 100)->nullable()->after('manufacturer_id');
            });
        }

        if (! Schema::hasIndex('products', 'products_manufacturer_id_product_code_unique')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->unique(['manufacturer_id', 'product_code']);
            });
        }

        if (! Schema::hasColumn('quotation_items', 'product_code')) {
            Schema::table('quotation_items', function (Blueprint $table): void {
                $table->string('product_code', 100)->nullable()->after('line_number');
            });
        }

        if (! Schema::hasColumn('supplier_po_lines', 'product_code')) {
            Schema::table('supplier_po_lines', function (Blueprint $table): void {
                $table->string('product_code', 100)->nullable()->after('line_number');
            });
        }

        if (! Schema::hasColumn('quotations', 'vat_pricing')) {
            Schema::table('quotations', function (Blueprint $table): void {
                $table->string('vat_pricing', 16)->default('exclusive')->after('accepted_invoice_currency');
            });
        }

        if (! Schema::hasTable('quotation_charges')) {
            Schema::create('quotation_charges', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('line_number');
                $table->string('label');
                $table->decimal('amount', 15, 3)->default(0);
                $table->timestamps();

                $table->unique(['quotation_id', 'line_number']);
            });
        }

        if (! Schema::hasTable('quotation_discounts')) {
            Schema::create('quotation_discounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('line_number');
                $table->string('label');
                $table->string('discount_type', 16)->default('fixed');
                $table->decimal('amount', 15, 3)->default(0);
                $table->timestamps();

                $table->unique(['quotation_id', 'line_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_discounts');
        Schema::dropIfExists('quotation_charges');

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('vat_pricing');
        });

        Schema::table('supplier_po_lines', function (Blueprint $table): void {
            $table->dropColumn('product_code');
        });

        Schema::table('quotation_items', function (Blueprint $table): void {
            $table->dropColumn('product_code');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['manufacturer_id', 'product_code']);
            $table->dropColumn('product_code');
            $table->unique(['manufacturer_id', 'name', 'title']);
            $table->dropIndex(['manufacturer_id']);
        });
    }
};
