<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_plan_follow_ups')) {
            Schema::create('payment_plan_follow_ups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('follow_up_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('quotation_payment_schedule_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->string('status', 32)->default('pending');
                $table->date('due_date')->nullable();
                $table->decimal('expected_percentage', 8, 3);
                $table->decimal('expected_amount', 15, 3)->nullable();
                $table->string('currency', 8)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->decimal('paid_amount', 15, 3)->nullable();
                $table->string('payment_reference')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['follow_up_item_id', 'quotation_payment_schedule_id'], 'payment_plan_follow_up_unique');
                $table->index(['status', 'due_date']);
                $table->index(['follow_up_item_id', 'status']);
            });
        }

        if (! Schema::hasTable('payment_plan_follow_up_attachments')) {
            Schema::create('payment_plan_follow_up_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_plan_follow_up_id');
                $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
                $table->string('file_path');
                $table->string('original_file_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();

                $table->foreign('payment_plan_follow_up_id', 'ppfu_attachments_ppfu_id_foreign')
                    ->references('id')
                    ->on('payment_plan_follow_ups')
                    ->cascadeOnDelete();
                $table->index(['payment_plan_follow_up_id', 'created_at'], 'ppfu_attachments_ppfu_created_index');
            });
        }

        if (! Schema::hasColumn('payments', 'payment_plan_follow_up_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->foreignId('payment_plan_follow_up_id')
                    ->nullable()
                    ->after('follow_up_item_id');
                $table->foreign('payment_plan_follow_up_id', 'payments_ppfu_id_foreign')
                    ->references('id')
                    ->on('payment_plan_follow_ups')
                    ->nullOnDelete();
                $table->unique('payment_plan_follow_up_id', 'payments_ppfu_id_unique');
            });
        }

        $schedules = DB::table('quotation_payment_schedules')
            ->orderBy('line_number')
            ->get()
            ->groupBy('quotation_id');

        if ($schedules->isEmpty()) {
            return;
        }

        $now = now();
        DB::table('follow_up_items')
            ->leftJoin('quotation_items', 'quotation_items.id', '=', 'follow_up_items.quotation_item_id')
            ->leftJoin('quotations', 'quotations.id', '=', 'follow_up_items.quotation_id')
            ->leftJoin('invoices', 'invoices.follow_up_item_id', '=', 'follow_up_items.id')
            ->select([
                'follow_up_items.id',
                'follow_up_items.quotation_id',
                'quotation_items.total_price',
                'quotation_items.vat_rate',
                'quotations.accepted_invoice_currency',
                'invoices.id as invoice_id',
                'invoices.total_amount as invoice_total_amount',
                'invoices.currency as invoice_currency',
            ])
            ->orderBy('follow_up_items.id')
            ->chunk(250, function ($items) use ($schedules, $now): void {
                $rows = [];

                foreach ($items as $item) {
                    $quotationSchedules = $schedules->get($item->quotation_id, collect());

                    foreach ($quotationSchedules as $schedule) {
                        $baseAmount = $item->invoice_total_amount !== null
                            ? (float) $item->invoice_total_amount
                            : round((float) $item->total_price + ((float) $item->total_price * ((float) $item->vat_rate / 100)), 3);

                        $rows[] = [
                            'follow_up_item_id' => $item->id,
                            'quotation_payment_schedule_id' => $schedule->id,
                            'invoice_id' => $item->invoice_id,
                            'status' => 'pending',
                            'due_date' => $schedule->due_timing === 'fixed_date' ? $schedule->due_date : null,
                            'expected_percentage' => $schedule->payment_percentage,
                            'expected_amount' => round($baseAmount * ((float) $schedule->payment_percentage / 100), 3),
                            'currency' => $item->invoice_currency ?? $item->accepted_invoice_currency,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('payment_plan_follow_ups')->insertOrIgnore($chunk);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'payment_plan_follow_up_id')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropUnique('payments_ppfu_id_unique');
                $table->dropForeign('payments_ppfu_id_foreign');
                $table->dropColumn('payment_plan_follow_up_id');
            });
        }

        Schema::dropIfExists('payment_plan_follow_up_attachments');
        Schema::dropIfExists('payment_plan_follow_ups');
    }
};
