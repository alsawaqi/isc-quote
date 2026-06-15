<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_pos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('buyer_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('po_number', 100);
            $table->date('po_date');
            $table->decimal('po_value', 15, 3);
            $table->string('currency', 8);
            $table->string('po_file_path');
            $table->string('original_file_name')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('received');
            $table->timestamps();

            $table->unique('quotation_id');
            $table->unique(['buyer_company_id', 'po_number']);
            $table->index(['quotation_version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_pos');
    }
};
