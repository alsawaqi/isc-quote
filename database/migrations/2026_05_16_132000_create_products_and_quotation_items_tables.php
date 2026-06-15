<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('title');
            $table->longText('buyer_description')->nullable();
            $table->longText('manufacturer_description')->nullable();
            $table->string('last_uom', 24)->nullable();
            $table->decimal('last_unit_price', 15, 3)->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['manufacturer_id', 'name', 'title']);
            $table->index(['name', 'title']);
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('manufacturer_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('product_name');
            $table->string('title');
            $table->longText('buyer_description')->nullable();
            $table->longText('manufacturer_description')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('uom', 24);
            $table->decimal('unit_price', 15, 3);
            $table->decimal('total_price', 15, 3);
            $table->timestamps();

            $table->unique(['quotation_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('products');
    }
};
