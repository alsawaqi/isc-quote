<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 64);
            $table->string('label');
            $table->string('status', 32)->default('pending');
            $table->string('document_number')->nullable();
            $table->date('document_date')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_file_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['follow_up_item_id', 'document_type']);
            $table->index(['document_type', 'status']);
        });

        Schema::create('packing_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->string('packing_list_reference')->unique();
            $table->date('packing_list_date');
            $table->string('package_size');
            $table->string('gross_weight');
            $table->string('net_weight');
            $table->text('remarks')->nullable();
            $table->string('docx_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('follow_up_item_id');
        });

        Schema::create('packing_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('packing_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_po_id')->constrained('buyer_pos')->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->text('item_description');
            $table->decimal('quantity', 15, 3);
            $table->string('uom', 24);
            $table->string('package_size');
            $table->string('gross_weight');
            $table->string('net_weight');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_list_items');
        Schema::dropIfExists('packing_lists');
        Schema::dropIfExists('shipping_documents');
    }
};
