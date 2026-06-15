<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('quotation_reference');
            $table->json('snapshot');
            $table->string('docx_path');
            $table->string('pdf_path');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->unique(['quotation_id', 'version_number']);
            $table->index(['quotation_id', 'finalized_at']);
        });

        Schema::create('quotation_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('summary');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['quotation_id', 'created_at']);
            $table->index(['action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_activity_logs');
        Schema::dropIfExists('quotation_versions');
    }
};
