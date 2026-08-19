<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_pos', function (Blueprint $table): void {
            if (! Schema::hasColumn('supplier_pos', 'revision_number')) {
                $table->unsignedInteger('revision_number')->default(1)->after('po_reference');
            }
        });

        if (! Schema::hasTable('supplier_po_revisions')) {
            Schema::create('supplier_po_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_po_id')->constrained('supplier_pos')->cascadeOnDelete();
                $table->unsignedInteger('revision_number');
                $table->string('po_reference');
                $table->json('snapshot');
                $table->string('docx_path')->nullable();
                $table->string('pdf_path')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->unique(['supplier_po_id', 'revision_number']);
                $table->index(['supplier_po_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_po_revisions');

        Schema::table('supplier_pos', function (Blueprint $table): void {
            if (Schema::hasColumn('supplier_pos', 'revision_number')) {
                $table->dropColumn('revision_number');
            }
        });
    }
};
