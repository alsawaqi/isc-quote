<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_pos', function (Blueprint $table): void {
            $table->id();
            $table->string('po_reference')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('supplier_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('buyer_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('incoterm_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_quote_reference', 150)->nullable();
            $table->unsignedSmallInteger('payment_term_days');
            $table->unsignedSmallInteger('delivery_period_min');
            $table->unsignedSmallInteger('delivery_period_max');
            $table->string('delivery_period_unit', 16);
            $table->string('delivery_period_type', 16);
            $table->string('accepted_invoice_currency', 8);
            $table->string('additional_charges_label')->nullable();
            $table->decimal('additional_charges', 15, 3)->default(0);
            $table->decimal('subtotal', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3)->default(0);
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->string('status', 32)->default('issued');
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['created_by', 'created_at']);
        });

        Schema::create('supplier_po_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_po_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_po_id')->constrained('buyer_pos')->restrictOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('product_name');
            $table->string('title');
            $table->longText('item_description')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('uom', 24);
            $table->decimal('unit_cost', 15, 3);
            $table->decimal('total_cost', 15, 3);
            $table->timestamps();

            $table->unique('quotation_item_id');
            $table->unique(['supplier_po_id', 'line_number']);
            $table->index(['quotation_id', 'buyer_po_id']);
        });

        Schema::create('supplier_po_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_po_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('key', 100)->nullable();
            $table->string('title');
            $table->text('description');
            $table->boolean('is_required_default')->default(false);
            $table->timestamps();

            $table->unique(['supplier_po_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_po_terms');
        Schema::dropIfExists('supplier_po_lines');
        Schema::dropIfExists('supplier_pos');
    }
};
