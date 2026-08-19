<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->relaxLegacySingleItemConstraints();
        $this->createBuyerPoItemsTable();
        $this->createItemFulfilmentsTable();
        $this->addFoundationColumns();
        $this->backfillBuyerPoItems();
        $this->backfillBuyerPoItemLinks();
        $this->backfillHeaderLinks();
        $this->backfillItemFulfilments();
        $this->backfillDocumentItemLinks();
    }

    public function down(): void
    {
        $this->dropFoundationColumns();

        Schema::dropIfExists('item_fulfilments');
        Schema::dropIfExists('buyer_po_items');

        $this->restoreLegacySingleItemConstraints();
    }

    private function relaxLegacySingleItemConstraints(): void
    {
        if (Schema::hasTable('buyer_pos') && ! Schema::hasIndex('buyer_pos', 'buyer_pos_quotation_id_index')) {
            Schema::table('buyer_pos', function (Blueprint $table): void {
                $table->index('quotation_id');
            });
        }

        if (Schema::hasTable('buyer_pos') && Schema::hasIndex('buyer_pos', 'buyer_pos_quotation_id_unique')) {
            Schema::table('buyer_pos', function (Blueprint $table): void {
                $table->dropUnique('buyer_pos_quotation_id_unique');
            });
        }

        if (Schema::hasTable('supplier_po_lines') && ! Schema::hasIndex('supplier_po_lines', 'supplier_po_lines_quotation_item_id_index')) {
            Schema::table('supplier_po_lines', function (Blueprint $table): void {
                $table->index('quotation_item_id');
            });
        }

        if (Schema::hasTable('supplier_po_lines') && Schema::hasIndex('supplier_po_lines', 'supplier_po_lines_quotation_item_id_unique')) {
            Schema::table('supplier_po_lines', function (Blueprint $table): void {
                $table->dropUnique('supplier_po_lines_quotation_item_id_unique');
            });
        }

        if (Schema::hasTable('follow_up_items') && ! Schema::hasIndex('follow_up_items', 'follow_up_items_supplier_po_id_quotation_item_id_index')) {
            Schema::table('follow_up_items', function (Blueprint $table): void {
                $table->index(['supplier_po_id', 'quotation_item_id']);
            });
        }

        if (Schema::hasTable('follow_up_items') && Schema::hasIndex('follow_up_items', 'follow_up_items_supplier_po_id_quotation_item_id_unique')) {
            Schema::table('follow_up_items', function (Blueprint $table): void {
                $table->dropUnique('follow_up_items_supplier_po_id_quotation_item_id_unique');
            });
        }

        if (Schema::hasTable('packing_lists') && ! Schema::hasIndex('packing_lists', 'packing_lists_follow_up_item_id_index')) {
            Schema::table('packing_lists', function (Blueprint $table): void {
                $table->index('follow_up_item_id');
            });
        }

        if (Schema::hasTable('packing_lists') && Schema::hasIndex('packing_lists', 'packing_lists_follow_up_item_id_unique')) {
            Schema::table('packing_lists', function (Blueprint $table): void {
                $table->dropUnique('packing_lists_follow_up_item_id_unique');
            });
        }

        if (Schema::hasTable('delivery_orders') && ! Schema::hasIndex('delivery_orders', 'delivery_orders_follow_up_item_id_index')) {
            Schema::table('delivery_orders', function (Blueprint $table): void {
                $table->index('follow_up_item_id');
            });
        }

        if (Schema::hasTable('delivery_orders') && Schema::hasIndex('delivery_orders', 'delivery_orders_follow_up_item_id_unique')) {
            Schema::table('delivery_orders', function (Blueprint $table): void {
                $table->dropUnique('delivery_orders_follow_up_item_id_unique');
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasIndex('invoices', 'invoices_follow_up_item_id_index')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->index('follow_up_item_id');
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasIndex('invoices', 'invoices_follow_up_item_id_unique')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropUnique('invoices_follow_up_item_id_unique');
            });
        }
    }

    private function createBuyerPoItemsTable(): void
    {
        if (Schema::hasTable('buyer_po_items')) {
            return;
        }

        Schema::create('buyer_po_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_po_id')->constrained('buyer_pos')->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_number');
            $table->string('buyer_item_code', 100)->nullable();
            $table->longText('item_description')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('uom', 24);
            $table->decimal('unit_price', 15, 3)->default(0);
            $table->decimal('total_amount', 15, 3)->default(0);
            $table->string('currency', 8);
            $table->string('status', 32)->default('open');
            $table->timestamps();

            $table->unique(['buyer_po_id', 'line_number']);
            $table->index(['quotation_id', 'buyer_po_id']);
            $table->index(['quotation_item_id', 'status']);
            $table->index('buyer_item_code');
        });
    }

    private function createItemFulfilmentsTable(): void
    {
        if (Schema::hasTable('item_fulfilments')) {
            return;
        }

        Schema::create('item_fulfilments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_po_id')->nullable()->constrained('buyer_pos')->nullOnDelete();
            $table->foreignId('buyer_po_item_id')->nullable()->constrained('buyer_po_items')->nullOnDelete();
            $table->foreignId('supplier_po_id')->nullable()->constrained('supplier_pos')->nullOnDelete();
            $table->foreignId('supplier_po_line_id')->nullable()->unique()->constrained('supplier_po_lines')->nullOnDelete();
            $table->foreignId('follow_up_item_id')->nullable()->unique()->constrained('follow_up_items')->nullOnDelete();
            $table->decimal('ordered_quantity', 15, 3)->default(0);
            $table->decimal('ordered_amount', 15, 3)->default(0);
            $table->string('buyer_currency', 8)->nullable();
            $table->decimal('supplier_quantity', 15, 3)->default(0);
            $table->decimal('supplier_amount', 15, 3)->default(0);
            $table->string('supplier_currency', 8)->nullable();
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->decimal('delivered_quantity', 15, 3)->default(0);
            $table->decimal('invoiced_amount', 15, 3)->default(0);
            $table->decimal('paid_amount', 15, 3)->default(0);
            $table->string('status', 48)->default('pending_supplier_po');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['quotation_id', 'status']);
            $table->index(['quotation_item_id', 'status']);
            $table->index(['buyer_po_item_id', 'status']);
            $table->index(['supplier_po_id', 'status']);
        });
    }

    private function addFoundationColumns(): void
    {
        $this->addForeignIdIfMissing('supplier_po_lines', 'buyer_po_item_id', 'buyer_po_id', 'buyer_po_items');
        $this->addForeignIdIfMissing('follow_up_items', 'buyer_po_item_id', 'buyer_po_id', 'buyer_po_items');
        $this->addForeignIdIfMissing('logistics_cases', 'item_fulfilment_id', 'follow_up_item_id', 'item_fulfilments');

        $this->addHeaderLinks('packing_lists');
        $this->addHeaderLinks('delivery_orders');
        $this->addHeaderLinks('invoices');

        $this->addItemLinks('packing_list_items', 'buyer_po_id');
        $this->addItemLinks('delivery_order_items', 'buyer_po_id');
        $this->addItemLinks('invoice_items', 'buyer_po_id');

        $this->addForeignIdIfMissing('payments', 'item_fulfilment_id', 'follow_up_item_id', 'item_fulfilments');
    }

    private function addHeaderLinks(string $tableName): void
    {
        $this->addForeignIdIfMissing($tableName, 'quotation_id', 'follow_up_item_id', 'quotations');
        $this->addForeignIdIfMissing($tableName, 'buyer_po_id', 'quotation_id', 'buyer_pos');
    }

    private function addItemLinks(string $tableName, string $after): void
    {
        $this->addForeignIdIfMissing($tableName, 'buyer_po_item_id', $after, 'buyer_po_items');
        $this->addForeignIdIfMissing($tableName, 'item_fulfilment_id', 'buyer_po_item_id', 'item_fulfilments');
    }

    private function addForeignIdIfMissing(string $tableName, string $columnName, string $afterColumn, string $foreignTable): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $columnName, $afterColumn, $foreignTable): void {
            $column = $table->foreignId($columnName)->nullable();

            if (Schema::hasColumn($tableName, $afterColumn)) {
                $column->after($afterColumn);
            }

            $column->constrained($foreignTable)->nullOnDelete();
        });
    }

    private function backfillBuyerPoItems(): void
    {
        if (! Schema::hasTable('buyer_po_items')) {
            return;
        }

        $now = now();

        DB::table('buyer_pos')
            ->select(['id', 'quotation_id', 'currency'])
            ->orderBy('id')
            ->chunkById(100, function ($buyerPos) use ($now): void {
                $quotationIds = $buyerPos->pluck('quotation_id')->unique()->values();
                $itemsByQuotation = DB::table('quotation_items')
                    ->whereIn('quotation_id', $quotationIds)
                    ->orderBy('line_number')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('quotation_id');

                foreach ($buyerPos as $buyerPo) {
                    $items = $itemsByQuotation->get($buyerPo->quotation_id, collect());
                    $usedLineNumbers = DB::table('buyer_po_items')
                        ->where('buyer_po_id', $buyerPo->id)
                        ->pluck('line_number')
                        ->map(fn ($lineNumber): int => (int) $lineNumber)
                        ->all();
                    $nextLineNumber = empty($usedLineNumbers) ? 1 : max($usedLineNumbers) + 1;

                    foreach ($items as $item) {
                        $exists = DB::table('buyer_po_items')
                            ->where('buyer_po_id', $buyerPo->id)
                            ->where('quotation_item_id', $item->id)
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        $lineNumber = (int) $item->line_number;
                        if (in_array($lineNumber, $usedLineNumbers, true)) {
                            $lineNumber = $nextLineNumber++;
                        }
                        $usedLineNumbers[] = $lineNumber;

                        DB::table('buyer_po_items')->insert([
                            'buyer_po_id' => $buyerPo->id,
                            'quotation_id' => $buyerPo->quotation_id,
                            'quotation_item_id' => $item->id,
                            'line_number' => $lineNumber,
                            'buyer_item_code' => null,
                            'item_description' => $item->buyer_description ?: $item->title,
                            'quantity' => $item->quantity,
                            'uom' => $item->uom,
                            'unit_price' => $item->unit_price,
                            'total_amount' => $item->total_price,
                            'currency' => $buyerPo->currency,
                            'status' => 'open',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }, 'id');
    }

    private function backfillBuyerPoItemLinks(): void
    {
        $this->backfillBuyerPoItemColumn('supplier_po_lines');
        $this->backfillBuyerPoItemColumn('follow_up_items');
        $this->backfillBuyerPoItemColumn('packing_list_items');
        $this->backfillBuyerPoItemColumn('delivery_order_items');
        $this->backfillBuyerPoItemColumn('invoice_items');
    }

    private function backfillBuyerPoItemColumn(string $tableName): void
    {
        if (! Schema::hasColumn($tableName, 'buyer_po_item_id')) {
            return;
        }

        DB::table($tableName)
            ->select(['id', 'buyer_po_id', 'quotation_item_id'])
            ->whereNull('buyer_po_item_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($tableName): void {
                foreach ($rows as $row) {
                    $buyerPoItemId = DB::table('buyer_po_items')
                        ->where('buyer_po_id', $row->buyer_po_id)
                        ->where('quotation_item_id', $row->quotation_item_id)
                        ->orderBy('id')
                        ->value('id');

                    if ($buyerPoItemId) {
                        DB::table($tableName)->where('id', $row->id)->update([
                            'buyer_po_item_id' => $buyerPoItemId,
                            'updated_at' => now(),
                        ]);
                    }
                }
            }, 'id');
    }

    private function backfillHeaderLinks(): void
    {
        foreach (['packing_lists', 'delivery_orders', 'invoices'] as $tableName) {
            if (
                ! Schema::hasColumn($tableName, 'quotation_id')
                || ! Schema::hasColumn($tableName, 'buyer_po_id')
                || ! Schema::hasColumn($tableName, 'follow_up_item_id')
            ) {
                continue;
            }

            DB::table($tableName)
                ->select([
                    "{$tableName}.id as id",
                    'follow_up_items.quotation_id',
                    'follow_up_items.buyer_po_id',
                ])
                ->join('follow_up_items', "{$tableName}.follow_up_item_id", '=', 'follow_up_items.id')
                ->whereNull("{$tableName}.quotation_id")
                ->orderBy("{$tableName}.id")
                ->chunkById(200, function ($rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)->where('id', $row->id)->update([
                            'quotation_id' => $row->quotation_id,
                            'buyer_po_id' => $row->buyer_po_id,
                            'updated_at' => now(),
                        ]);
                    }
                }, "{$tableName}.id", 'id');
        }
    }

    private function backfillItemFulfilments(): void
    {
        if (! Schema::hasTable('item_fulfilments')) {
            return;
        }

        $now = now();

        DB::table('supplier_po_lines')
            ->select([
                'supplier_po_lines.*',
                'buyer_po_items.quantity as ordered_quantity',
                'buyer_po_items.total_amount as ordered_amount',
                'buyer_po_items.currency as buyer_currency',
                'supplier_pos.accepted_invoice_currency as supplier_currency',
                'follow_up_items.id as follow_up_item_id',
                'follow_up_items.status as follow_up_status',
            ])
            ->leftJoin('buyer_po_items', 'supplier_po_lines.buyer_po_item_id', '=', 'buyer_po_items.id')
            ->leftJoin('supplier_pos', 'supplier_po_lines.supplier_po_id', '=', 'supplier_pos.id')
            ->leftJoin('follow_up_items', 'supplier_po_lines.id', '=', 'follow_up_items.supplier_po_line_id')
            ->orderBy('supplier_po_lines.id')
            ->chunkById(100, function ($lines) use ($now): void {
                foreach ($lines as $line) {
                    $existingQuery = DB::table('item_fulfilments')
                        ->where('supplier_po_line_id', $line->id);

                    if ($line->follow_up_item_id) {
                        $existingQuery->orWhere('follow_up_item_id', $line->follow_up_item_id);
                    }

                    $existing = $existingQuery->first();

                    $values = [
                        'quotation_id' => $line->quotation_id,
                        'quotation_item_id' => $line->quotation_item_id,
                        'buyer_po_id' => $line->buyer_po_id,
                        'buyer_po_item_id' => $line->buyer_po_item_id,
                        'supplier_po_id' => $line->supplier_po_id,
                        'supplier_po_line_id' => $line->id,
                        'follow_up_item_id' => $line->follow_up_item_id,
                        'ordered_quantity' => $line->ordered_quantity ?? $line->quantity,
                        'ordered_amount' => $line->ordered_amount ?? $line->total_cost,
                        'buyer_currency' => $line->buyer_currency,
                        'supplier_quantity' => $line->quantity,
                        'supplier_amount' => $line->total_cost,
                        'supplier_currency' => $line->supplier_currency,
                        'status' => $line->follow_up_status ?: 'supplier_po_created',
                        'updated_at' => $now,
                    ];

                    if ($existing) {
                        DB::table('item_fulfilments')->where('id', $existing->id)->update($values);
                    } else {
                        DB::table('item_fulfilments')->insert([
                            ...$values,
                            'received_quantity' => 0,
                            'delivered_quantity' => 0,
                            'invoiced_amount' => 0,
                            'paid_amount' => 0,
                            'metadata' => null,
                            'created_at' => $now,
                        ]);
                    }
                }
            }, 'supplier_po_lines.id', 'id');

        if (Schema::hasColumn('follow_up_items', 'buyer_po_item_id')) {
            DB::table('item_fulfilments')
                ->select(['id', 'follow_up_item_id', 'buyer_po_item_id'])
                ->whereNotNull('follow_up_item_id')
                ->whereNotNull('buyer_po_item_id')
                ->orderBy('id')
                ->chunkById(200, function ($fulfilments): void {
                    foreach ($fulfilments as $fulfilment) {
                        DB::table('follow_up_items')
                            ->where('id', $fulfilment->follow_up_item_id)
                            ->whereNull('buyer_po_item_id')
                            ->update([
                                'buyer_po_item_id' => $fulfilment->buyer_po_item_id,
                                'updated_at' => now(),
                            ]);
                    }
                }, 'id');
        }

        if (Schema::hasColumn('logistics_cases', 'item_fulfilment_id')) {
            DB::table('logistics_cases')
                ->select(['id', 'follow_up_item_id'])
                ->whereNull('item_fulfilment_id')
                ->orderBy('id')
                ->chunkById(200, function ($cases): void {
                    foreach ($cases as $case) {
                        $fulfilmentId = DB::table('item_fulfilments')
                            ->where('follow_up_item_id', $case->follow_up_item_id)
                            ->value('id');

                        if ($fulfilmentId) {
                            DB::table('logistics_cases')->where('id', $case->id)->update([
                                'item_fulfilment_id' => $fulfilmentId,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }, 'id');
        }
    }

    private function backfillDocumentItemLinks(): void
    {
        foreach (['packing_list_items', 'delivery_order_items', 'invoice_items'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'item_fulfilment_id')) {
                continue;
            }

            $headerTable = match ($tableName) {
                'packing_list_items' => 'packing_lists',
                'delivery_order_items' => 'delivery_orders',
                default => 'invoices',
            };
            $headerIdColumn = match ($tableName) {
                'packing_list_items' => 'packing_list_id',
                'delivery_order_items' => 'delivery_order_id',
                default => 'invoice_id',
            };

            DB::table($tableName)
                ->select([
                    "{$tableName}.id as id",
                    'item_fulfilments.id as item_fulfilment_id',
                ])
                ->join($headerTable, "{$tableName}.{$headerIdColumn}", '=', "{$headerTable}.id")
                ->join('item_fulfilments', "{$headerTable}.follow_up_item_id", '=', 'item_fulfilments.follow_up_item_id')
                ->whereNull("{$tableName}.item_fulfilment_id")
                ->orderBy("{$tableName}.id")
                ->chunkById(200, function ($rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)->where('id', $row->id)->update([
                            'item_fulfilment_id' => $row->item_fulfilment_id,
                            'updated_at' => now(),
                        ]);
                    }
                }, "{$tableName}.id", 'id');
        }

        if (Schema::hasColumn('payments', 'item_fulfilment_id')) {
            DB::table('payments')
                ->select([
                    'payments.id as id',
                    'item_fulfilments.id as item_fulfilment_id',
                ])
                ->join('item_fulfilments', 'payments.follow_up_item_id', '=', 'item_fulfilments.follow_up_item_id')
                ->whereNull('payments.item_fulfilment_id')
                ->orderBy('payments.id')
                ->chunkById(200, function ($payments): void {
                    foreach ($payments as $payment) {
                        DB::table('payments')->where('id', $payment->id)->update([
                            'item_fulfilment_id' => $payment->item_fulfilment_id,
                            'updated_at' => now(),
                        ]);
                    }
                }, 'payments.id', 'id');
        }
    }

    private function dropFoundationColumns(): void
    {
        $this->dropConstrainedColumnIfPresent('payments', 'item_fulfilment_id');

        foreach (['packing_list_items', 'delivery_order_items', 'invoice_items'] as $tableName) {
            $this->dropConstrainedColumnIfPresent($tableName, 'item_fulfilment_id');
            $this->dropConstrainedColumnIfPresent($tableName, 'buyer_po_item_id');
        }

        foreach (['packing_lists', 'delivery_orders', 'invoices'] as $tableName) {
            $this->dropConstrainedColumnIfPresent($tableName, 'buyer_po_id');
            $this->dropConstrainedColumnIfPresent($tableName, 'quotation_id');
        }

        $this->dropConstrainedColumnIfPresent('logistics_cases', 'item_fulfilment_id');
        $this->dropConstrainedColumnIfPresent('follow_up_items', 'buyer_po_item_id');
        $this->dropConstrainedColumnIfPresent('supplier_po_lines', 'buyer_po_item_id');
    }

    private function dropConstrainedColumnIfPresent(string $tableName, string $columnName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName): void {
            $table->dropConstrainedForeignId($columnName);
        });
    }

    private function restoreLegacySingleItemConstraints(): void
    {
        $this->restoreUniqueIfNoDuplicates('invoices', ['follow_up_item_id'], 'invoices_follow_up_item_id_unique');
        $this->restoreUniqueIfNoDuplicates('delivery_orders', ['follow_up_item_id'], 'delivery_orders_follow_up_item_id_unique');
        $this->restoreUniqueIfNoDuplicates('packing_lists', ['follow_up_item_id'], 'packing_lists_follow_up_item_id_unique');
        $this->restoreUniqueIfNoDuplicates('follow_up_items', ['supplier_po_id', 'quotation_item_id'], 'follow_up_items_supplier_po_id_quotation_item_id_unique');
        $this->restoreUniqueIfNoDuplicates('supplier_po_lines', ['quotation_item_id'], 'supplier_po_lines_quotation_item_id_unique');
        $this->restoreUniqueIfNoDuplicates('buyer_pos', ['quotation_id'], 'buyer_pos_quotation_id_unique');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function restoreUniqueIfNoDuplicates(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        $duplicates = DB::table($tableName)
            ->select($columns)
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->unique($columns, $indexName);
        });
    }
};
