<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_plan_migration_recovers_from_partially_applied_ddl(): void
    {
        Schema::drop('payment_plan_follow_up_attachments');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payments_ppfu_id_unique');
            $table->dropForeign(['payment_plan_follow_up_id']);
            $table->dropColumn('payment_plan_follow_up_id');
        });

        $migration = require database_path('migrations/2026_07_11_000000_create_payment_plan_follow_ups.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('payment_plan_follow_ups'));
        $this->assertTrue(Schema::hasTable('payment_plan_follow_up_attachments'));
        $this->assertTrue(Schema::hasColumn('payments', 'payment_plan_follow_up_id'));

        $migration->up();
        $this->assertTrue(Schema::hasIndex('payments', 'payments_ppfu_id_unique'));
    }

    public function test_product_commercial_migration_recovers_after_the_first_index_was_committed(): void
    {
        Schema::drop('quotation_discounts');
        Schema::drop('quotation_charges');
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
            $table->dropUnique('products_manufacturer_id_product_code_unique');
            $table->dropColumn('product_code');
            $table->unique(['manufacturer_id', 'name', 'title']);
        });

        $this->assertTrue(Schema::hasIndex('products', 'products_manufacturer_id_index'));

        $migration = require database_path('migrations/2026_07_11_020000_add_product_codes_and_quotation_commercial_adjustments.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('products', 'product_code'));
        $this->assertTrue(Schema::hasColumn('quotation_items', 'product_code'));
        $this->assertTrue(Schema::hasColumn('supplier_po_lines', 'product_code'));
        $this->assertTrue(Schema::hasColumn('quotations', 'vat_pricing'));
        $this->assertTrue(Schema::hasTable('quotation_charges'));
        $this->assertTrue(Schema::hasTable('quotation_discounts'));

        $migration->up();
        $this->assertTrue(Schema::hasIndex('products', 'products_manufacturer_id_product_code_unique'));
    }
}
