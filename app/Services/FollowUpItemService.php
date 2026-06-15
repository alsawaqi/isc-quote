<?php

namespace App\Services;

use App\Models\FollowUpItem;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\User;

class FollowUpItemService
{
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
            ->get()
            ->each(function (FollowUpItem $item): void {
                $hasStarted = $item->comments()->exists() || $item->acknowledgement_received_at !== null;

                $item->forceFill([
                    'supplier_po_line_id' => null,
                    'status' => $hasStarted ? 'line_removed' : 'cancelled',
                    'closed_at' => now(),
                ])->save();
            });
    }

    private function syncLine(SupplierPoLine $line, ?int $defaultAssigneeId): FollowUpItem
    {
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
            'quotation_item_id' => $line->quotation_item_id,
        ]);

        if (! $followUpItem->exists) {
            $followUpItem->assigned_to = $defaultAssigneeId;
            $followUpItem->status = 'awaiting_acknowledgement';
        }

        $followUpItem->save();

        return $followUpItem;
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
