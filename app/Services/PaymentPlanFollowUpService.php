<?php

namespace App\Services;

use App\Models\FollowUpItem;
use App\Models\PaymentPlanFollowUp;
use App\Models\Quotation;
use App\Models\QuotationPaymentSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PaymentPlanFollowUpService
{
    /**
     * @return Collection<int, PaymentPlanFollowUp>
     */
    public function ensureForItem(FollowUpItem $followUpItem): Collection
    {
        $followUpItem->loadMissing([
            'buyerPo',
            'deliveryOrder',
            'invoice',
            'logisticsCase',
            'quotation.paymentSchedules',
            'quotationItem',
        ]);

        $quotation = $followUpItem->quotation;

        if (! $quotation) {
            return collect();
        }

        $this->ensureQuotationPaymentSchedules($quotation);
        $quotation->loadMissing('paymentSchedules');

        foreach ($quotation->paymentSchedules as $schedule) {
            $tracker = PaymentPlanFollowUp::query()->firstOrNew([
                'follow_up_item_id' => $followUpItem->id,
                'quotation_payment_schedule_id' => $schedule->id,
            ]);

            if (! $tracker->exists) {
                $tracker->status = 'pending';
            }

            $tracker->fill([
                'invoice_id' => $followUpItem->invoice?->id,
                'due_date' => $this->dueDateFor($followUpItem, $schedule)?->toDateString(),
                'expected_percentage' => $schedule->payment_percentage,
                'expected_amount' => $this->expectedAmountFor($followUpItem, $schedule),
                'currency' => $followUpItem->invoice?->currency
                    ?? $followUpItem->buyerPo?->currency
                    ?? $quotation->accepted_invoice_currency,
            ])->save();
        }

        $followUpItem->unsetRelation('paymentPlanFollowUps');
        $followUpItem->load([
            'paymentPlanFollowUps.attachments.uploader',
            'paymentPlanFollowUps.invoice',
            'paymentPlanFollowUps.quotationPaymentSchedule',
            'paymentPlanFollowUps.recorder',
        ]);

        return $followUpItem->paymentPlanFollowUps;
    }

    private function ensureQuotationPaymentSchedules(Quotation $quotation): void
    {
        if (! $quotation->relationLoaded('paymentSchedules')) {
            $quotation->load('paymentSchedules');
        }

        if ($quotation->paymentSchedules->isNotEmpty()) {
            return;
        }

        $quotation->paymentSchedules()->create([
            'line_number' => 1,
            'label' => ($quotation->payment_customer_type ?? 'credit') === 'paying'
                ? 'Full payment'
                : 'Credit payment',
            'payment_method' => 'bank_transfer',
            'payment_percentage' => 100,
            'due_timing' => 'relative',
            'due_event' => 'after_invoice',
            'due_offset_days' => (int) ($quotation->payment_term_days ?? 0),
            'due_date' => null,
            'notes' => null,
        ]);

        $quotation->unsetRelation('paymentSchedules');
        $quotation->load('paymentSchedules');
    }

    private function expectedAmountFor(FollowUpItem $followUpItem, QuotationPaymentSchedule $schedule): float
    {
        $baseAmount = $followUpItem->invoice
            ? (float) $followUpItem->invoice->total_amount
            : $this->quotationItemTotalWithVat($followUpItem);

        return round($baseAmount * ((float) $schedule->payment_percentage / 100), 3);
    }

    private function quotationItemTotalWithVat(FollowUpItem $followUpItem): float
    {
        $subtotal = (float) ($followUpItem->quotationItem?->total_price ?? 0);
        $vatRate = (float) ($followUpItem->quotationItem?->vat_rate ?? 0);

        return round($subtotal + ($subtotal * ($vatRate / 100)), 3);
    }

    private function dueDateFor(FollowUpItem $followUpItem, QuotationPaymentSchedule $schedule): ?Carbon
    {
        if ($schedule->due_timing === 'fixed_date') {
            return $schedule->due_date?->copy()->startOfDay();
        }

        $event = (string) $schedule->due_event;
        $base = $this->baseDateForEvent($followUpItem, $event);

        if (! $base) {
            return null;
        }

        $days = (int) ($schedule->due_offset_days ?? 0);
        $due = $base->copy()->startOfDay();

        if (str_starts_with($event, 'before_')) {
            return $due->subDays($days);
        }

        if (str_starts_with($event, 'after_')) {
            return $due->addDays($days);
        }

        return $due;
    }

    private function baseDateForEvent(FollowUpItem $followUpItem, string $event): ?Carbon
    {
        return match ($event) {
            'on_invoice', 'after_invoice' => $this->invoiceBaseDate($followUpItem),
            'before_delivery', 'on_delivery', 'after_delivery' => $this->deliveryBaseDate($followUpItem),
            'before_arrival', 'on_arrival', 'after_arrival' => $this->arrivalBaseDate($followUpItem, $event),
            'before_dispatch' => $followUpItem->logisticsCase?->eta_at?->copy(),
            default => null,
        };
    }

    private function invoiceBaseDate(FollowUpItem $followUpItem): ?Carbon
    {
        return $followUpItem->invoice?->sent_at?->copy()
            ?? $followUpItem->invoice?->invoice_date?->copy();
    }

    private function deliveryBaseDate(FollowUpItem $followUpItem): ?Carbon
    {
        return $followUpItem->deliveryOrder?->signed_at?->copy()
            ?? $followUpItem->logisticsCase?->buyer_received_at?->copy()
            ?? $followUpItem->logisticsCase?->warehouse_received_at?->copy()
            ?? $followUpItem->logisticsCase?->arrived_at?->copy()
            ?? $followUpItem->logisticsCase?->eta_at?->copy();
    }

    private function arrivalBaseDate(FollowUpItem $followUpItem, string $event): ?Carbon
    {
        return $followUpItem->logisticsCase?->arrived_at?->copy()
            ?? $followUpItem->logisticsCase?->warehouse_received_at?->copy()
            ?? $followUpItem->logisticsCase?->buyer_received_at?->copy()
            ?? ($event === 'before_arrival' ? $followUpItem->logisticsCase?->eta_at?->copy() : null);
    }
}
