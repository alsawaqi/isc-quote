<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_reference')->unique();
            $table->foreignId('salesperson_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('buyer_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('rfq_number', 100)->nullable();
            $table->unsignedSmallInteger('quotation_validity_value');
            $table->string('quotation_validity_unit', 16);
            $table->unsignedSmallInteger('payment_term_days');
            $table->unsignedSmallInteger('delivery_period_min');
            $table->unsignedSmallInteger('delivery_period_max');
            $table->string('delivery_period_unit', 16);
            $table->string('delivery_period_type', 16)->default('working');
            $table->string('accepted_invoice_currency', 8);
            $table->foreignId('incoterm_id')->constrained()->restrictOnDelete();
            $table->string('delivery_responsibility', 16);
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->index(['salesperson_id', 'status']);
            $table->index(['buyer_company_id', 'buyer_contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
