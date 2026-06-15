<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 32);
            $table->string('action', 100);
            $table->string('summary');
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['follow_up_item_id', 'occurred_at']);
            $table->index(['stage', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_audit_logs');
    }
};
