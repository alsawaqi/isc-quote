<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('key', 100)->nullable();
            $table->string('title');
            $table->text('description');
            $table->boolean('is_required_default')->default(false);
            $table->timestamps();

            $table->unique(['quotation_id', 'line_number']);
            $table->index(['quotation_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_terms');
    }
};
