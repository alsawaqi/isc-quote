<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_po_line_id')->nullable()->constrained('supplier_po_lines')->nullOnDelete();
            $table->foreignId('supplier_po_id')->constrained('supplier_pos')->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_po_id')->constrained('buyer_pos')->restrictOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 48)->default('awaiting_acknowledgement');
            $table->unsignedSmallInteger('reminder_interval_value')->nullable();
            $table->string('reminder_interval_unit', 16)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('last_comment_at')->nullable();
            $table->timestamp('acknowledgement_received_at')->nullable();
            $table->string('acknowledgement_file_path')->nullable();
            $table->string('acknowledgement_original_file_name')->nullable();
            $table->text('acknowledgement_notes')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique('supplier_po_line_id');
            $table->unique(['supplier_po_id', 'quotation_item_id']);
            $table->index(['assigned_to', 'status']);
            $table->index(['next_follow_up_at', 'status']);
        });

        Schema::create('follow_up_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('comment');
            $table->string('communication_type', 32)->nullable();
            $table->string('contacted_person')->nullable();
            $table->text('next_action')->nullable();
            $table->timestamps();

            $table->index(['follow_up_item_id', 'created_at']);
        });

        Schema::create('follow_up_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('follow_up_comment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('document_type', 64);
            $table->string('file_path');
            $table->string('original_file_name')->nullable();
            $table->timestamps();

            $table->index(['follow_up_item_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_attachments');
        Schema::dropIfExists('follow_up_comments');
        Schema::dropIfExists('follow_up_items');
    }
};
