<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table): void {
            $table->string('payment_customer_type', 24)->default('credit')->after('payment_term_days');
        });

        Schema::create('quotation_payment_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('label', 120);
            $table->string('payment_method', 32)->default('bank_transfer');
            $table->decimal('payment_percentage', 8, 3);
            $table->string('due_timing', 24)->default('relative');
            $table->string('due_event', 40)->nullable();
            $table->unsignedSmallInteger('due_offset_days')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['quotation_id', 'line_number']);
            $table->index(['quotation_id', 'due_timing']);
        });

        $now = now();
        $rows = DB::table('quotations')
            ->select(['id', 'payment_term_days'])
            ->orderBy('id')
            ->get()
            ->map(fn (object $quotation): array => [
                'quotation_id' => $quotation->id,
                'line_number' => 1,
                'label' => 'Credit payment',
                'payment_method' => 'bank_transfer',
                'payment_percentage' => 100,
                'due_timing' => 'relative',
                'due_event' => 'after_invoice',
                'due_offset_days' => (int) $quotation->payment_term_days,
                'due_date' => null,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('quotation_payment_schedules')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_payment_schedules');

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropColumn('payment_customer_type');
        });
    }
};
