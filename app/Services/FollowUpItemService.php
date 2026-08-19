<?php

namespace App\Services;

use App\Models\FollowUpItem;
use App\Models\BuyerPoItem;
use App\Models\ItemFulfilment;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\User;

class FollowUpItemService
{
    public function __construct(private readonly PaymentPlanFollowUpService $paymentPlanFollowUps)
    {
    }

    public function syncSupplierPo(SupplierPo $supplierPo): void
    {
        $supplierPo->loadMissing('lines');
        $defaultAssigneeId = $this->defaultFollowUpAssigneeId();
        $activeQuotationItemIds = [];

        foreach ($supplierPo->lines as $line) {
            $activeQuotationItemIds[] = $line->quotation_item_id;
            $this->syncLine($line, $defaultAssigneeId);
        }

        FollowUpItem::query()
            ->where('supplier_po_id', $supplierPo->id)
            ->whereNotIn('quotation_item_id', $activeQuotationItemIds)
            ->whereNull('closed_at')
            ->with('itemFulfilment')
            ->get()
            ->each(function (FollowUpItem $item): void {
                $hasStarted = $item->comments()->exists() || $item->acknowledgement_received_at !== null;

                $item->forceFill([
                    'supplier_po_line_id' => null,
                    'status' => $hasStarted ? 'line_removed' : 'cancelled',
                    'closed_at' => now(),
                ])->save();

                $item->itemFulfilment?->forceFill([
                    'supplier_po_line_id' => null,
                    'status' => $item->status,
                ])->save();
            });
    }

    private function syncLine(SupplierPoLine $line, ?int $defaultAssigneeId): FollowUpItem
    {
        $buyerPoItem = $this->ensureBuyerPoItem($line);

        $followUpItem = FollowUpItem::query()
            ->where('supplier_po_id', $line->supplier_po_id)
            ->where('quotation_item_id', $line->quotation_item_id)
            ->first() ?? new FollowUpItem([
                'supplier_po_id' => $line->supplier_po_id,
                'quotation_item_id' => $line->quotation_item_id,
                'assigned_to' => $defaultAssigneeId,
                'status' => 'awaiting_acknowledgement',
            ]);

        $followUpItem->fill([
            'supplier_po_line_id' => $line->id,
            'supplier_po_id' => $line->supplier_po_id,
            'quotation_id' => $line->quotation_id,
            'buyer_po_id' => $line->buyer_po_id,
            'buyer_po_item_id' => $buyerPoItem?->id,
            'quotation_item_id' => $line->quotation_item_id,
        ]);

        if (! $followUpItem->exists) {
            $followUpItem->assigned_to = $defaultAssigneeId;
            $followUpItem->status = 'awaiting_acknowledgement';
        }

        $followUpItem->save();
        $this->ensureItemFulfilment($line, $followUpItem, $buyerPoItem);
        $this->paymentPlanFollowUps->ensureForItem($followUpItem);

        return $followUpItem;
    }

    private function ensureBuyerPoItem(SupplierPoLine $line): ?BuyerPoItem
    {
        if ($line->buyer_po_item_id) {
            return $line->buyerPoItem ?: BuyerPoItem::query()->find($line->buyer_po_item_id);
        }

        $line->loadMissing(['buyerPo', 'quotationItem']);

        if (! $line->buyerPo || ! $line->quotationItem) {
            return null;
        }

        $existing = BuyerPoItem::query()
            ->where('buyer_po_id', $line->buyer_po_id)
            ->where('quotation_item_id', $line->quotation_item_id)
            ->orderBy('id')
            ->first();

        if (! $existing) {
            $usedLineNumbers = BuyerPoItem::query()
                ->where('buyer_po_id', $line->buyer_po_id)
                ->pluck('line_number')
                ->map(fn ($lineNumber): int => (int) $lineNumber)
                ->all();
            $lineNumber = (int) $line->quotationItem->line_number;

            if (in_array($lineNumber, $usedLineNumbers, true)) {
                $lineNumber = empty($usedLineNumbers) ? 1 : max($usedLineNumbers) + 1;
            }

            $existing = BuyerPoItem::query()->create([
                'buyer_po_id' => $line->buyer_po_id,
                'quotation_id' => $line->quotation_id,
                'quotation_item_id' => $line->quotation_item_id,
                'line_number' => $lineNumber,
                'buyer_item_code' => null,
                'item_description' => $line->quotationItem->buyer_description ?: $line->quotationItem->title,
                'quantity' => $line->quotationItem->quantity,
                'uom' => $line->quotationItem->uom,
                'unit_price' => $line->quotationItem->unit_price,
                'total_amount' => $line->quotationItem->total_price,
                'currency' => $line->buyerPo->currency,
                'status' => 'open',
            ]);
        }

        $line->forceFill(['buyer_po_item_id' => $existing->id])->save();

        return $existing;
    }

    private function ensureItemFulfilment(SupplierPoLine $line, FollowUpItem $followUpItem, ?BuyerPoItem $buyerPoItem): ItemFulfilment
    {
        $line->loadMissing('supplierPo');

        $query = ItemFulfilment::query()
            ->where('supplier_po_line_id', $line->id);

        if ($followUpItem->id) {
            $query->orWhere('follow_up_item_id', $followUpItem->id);
        }

        $fulfilment = $query->first() ?? new ItemFulfilment([
            'received_quantity' => 0,
            'delivered_quantity' => 0,
            'invoiced_amount' => 0,
            'paid_amount' => 0,
        ]);

        $fulfilment->fill([
            'quotation_id' => $line->quotation_id,
            'quotation_item_id' => $line->quotation_item_id,
            'buyer_po_id' => $line->buyer_po_id,
            'buyer_po_item_id' => $buyerPoItem?->id,
            'supplier_po_id' => $line->supplier_po_id,
            'supplier_po_line_id' => $line->id,
            'follow_up_item_id' => $followUpItem->id,
            'ordered_quantity' => $buyerPoItem?->quantity ?? $line->quantity,
            'ordered_amount' => $buyerPoItem?->total_amount ?? $line->total_cost,
            'buyer_currency' => $buyerPoItem?->currency,
            'supplier_quantity' => $line->quantity,
            'supplier_amount' => $line->total_cost,
            'supplier_currency' => $line->supplierPo?->accepted_invoice_currency,
            'status' => $followUpItem->status,
        ]);
        $fulfilment->save();

        return $fulfilment;
    }

    private function defaultFollowUpAssigneeId(): ?int
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('slug', 'follow-up'))
            ->orderBy('id')
            ->value('id');
    }
}
