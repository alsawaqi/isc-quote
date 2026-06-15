<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->string('delivery_responsibility', 32);
            $table->string('status', 48)->default('eta_recorded');
            $table->timestamp('eta_at')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('agent_contact')->nullable();
            $table->timestamp('documents_sent_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('warehouse_received_at')->nullable();
            $table->timestamp('buyer_received_at')->nullable();
            $table->decimal('received_quantity', 15, 3)->nullable();
            $table->string('goods_condition')->nullable();
            $table->string('received_location')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('follow_up_item_id');
            $table->index(['delivery_responsibility', 'status']);
            $table->index('eta_at');
        });

        Schema::create('logistics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('title');
            $table->timestamp('event_at');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['logistics_case_id', 'event_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_events');
        Schema::dropIfExists('logistics_cases');
    }
};
