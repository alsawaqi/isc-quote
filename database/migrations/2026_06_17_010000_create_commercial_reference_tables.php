<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uoms', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['status', 'code']);
        });

        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['status', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('uoms');
    }
};
