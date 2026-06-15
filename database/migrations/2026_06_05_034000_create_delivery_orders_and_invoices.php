<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_order_reference')->unique();
            $table->date('delivery_order_date');
            $table->string('delivery_place');
            $table->text('terms')->nullable();
            $table->string('status', 32)->default('issued');
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('signed_file_path')->nullable();
            $table->string('signed_original_file_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('follow_up_item_id');
            $table->index(['status', 'delivery_order_date']);
        });

        Schema::create('delivery_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_po_id')->constrained('buyer_pos')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->text('item_description');
            $table->decimal('quantity', 15, 3);
            $table->string('uom', 24);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_reference')->unique();
            $table->date('invoice_date');
            $table->unsignedSmallInteger('payment_term_days');
            $table->date('due_date');
            $table->string('currency', 8);
            $table->decimal('subtotal', 15, 3);
            $table->decimal('vat_rate', 8, 3)->default(0);
            $table->decimal('vat_amount', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3);
            $table->text('vat_exception_reason')->nullable();
            $table->text('bank_details')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 32)->default('issued');
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('follow_up_item_id');
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_po_id')->constrained('buyer_pos')->restrictOnDelete();
            $table->foreignId('delivery_order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->text('item_description');
            $table->decimal('quantity', 15, 3);
            $table->string('uom', 24);
            $table->decimal('unit_price', 15, 3);
            $table->decimal('total_price', 15, 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('delivery_order_items');
        Schema::dropIfExists('delivery_orders');
    }
};
