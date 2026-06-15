<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->timestamp('sent_at')->nullable()->after('invoice_date');
            $table->timestamp('closed_at')->nullable()->after('status');
        });

        Schema::table('follow_up_items', function (Blueprint $table): void {
            $table->text('closed_notes')->nullable()->after('closed_at');
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 3);
            $table->string('currency', 8);
            $table->date('payment_date');
            $table->string('payment_reference')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'payment_date']);
            $table->index(['follow_up_item_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('follow_up_items', function (Blueprint $table): void {
            $table->dropColumn('closed_notes');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['sent_at', 'closed_at']);
        });
    }
};
