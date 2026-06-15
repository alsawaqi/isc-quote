<?php

namespace App\Http\Controllers;

use App\Models\BuyerPo;
use App\Models\DeliveryOrder;
use App\Models\FollowUpItem;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\SupplierPo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    private const CLOSED_FOLLOW_UP_STATUSES = ['closed'];

    private const STAGE_LABELS = [
        'awaiting_acknowledgement' => 'Order Acknowledgement',
        'acknowledged' => 'Shipping Details',
        'shipping_documents_complete' => 'Shipping Details',
        'logistics_eta_recorded' => 'ETA / Logistics',
        'documents_sent_to_agent' => 'Delivery / Receipt',
        'documents_sent_to_buyer_agent' => 'Delivery / Receipt',
        'arrived' => 'Delivery / Receipt',
        'warehouse_received' => 'Delivery / Receipt',
        'ready_for_delivery_order' => 'Delivery / Receipt',
        'delivery_order_created' => 'Delivery / Receipt',
        'ready_for_invoice' => 'Invoice',
        'invoice_created' => 'Invoice',
        'invoice_sent' => 'Payment / Close',
        'payment_pending' => 'Payment / Close',
        'partially_paid' => 'Payment / Close',
        'paid' => 'Payment / Close',
        'closed' => 'Closed',
    ];

    public function show(Request $request): JsonResponse
    {
        $this->authorizeDashboard($request);

        $lastSevenDays = now()->copy()->subDays(7);
        $pendingQuotations = Quotation::query()
            ->whereNotIn('status', ['buyer_po_received', 'closed']);
        $pendingBuyerPos = $this->pendingBuyerPosQuery();
        $supplierPosAwaitingAck = FollowUpItem::query()
            ->where('status', 'awaiting_acknowledgement');
        $followUpsDue = $this->dueFollowUpsQuery();

        return response()->json([
            'generatedAt' => now()->toDateTimeString(),
            'metrics' => [
                $this->metric('Pending Quotations', $pendingQuotations->count(), 'FileText', 'blue', (clone $pendingQuotations)->where('created_at', '>=', $lastSevenDays)->count()),
                $this->metric('Buyer POs Pending', $pendingBuyerPos->count(), 'ShoppingCart', 'amber', (clone $pendingBuyerPos)->where('buyer_pos.created_at', '>=', $lastSevenDays)->count()),
                $this->metric('Supplier POs Awaiting Ack', $supplierPosAwaitingAck->distinct('supplier_po_id')->count('supplier_po_id'), 'Truck', 'teal', (clone $supplierPosAwaitingAck)->where('created_at', '>=', $lastSevenDays)->distinct('supplier_po_id')->count('supplier_po_id')),
                $this->metric('Follow-Ups Due', $followUpsDue->count(), 'Clock3', 'amber', (clone $followUpsDue)->where('next_follow_up_at', '>=', $lastSevenDays)->count()),
            ],
            'workflowStages' => $this->workflowStages(),
            'recentJobs' => $this->recentJobs(),
            'alerts' => $this->alerts(),
        ]);
    }

    private function authorizeDashboard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }

    private function pendingBuyerPosQuery(): Builder
    {
        return BuyerPo::query()
            ->whereHas('quotation.items', fn (Builder $query): Builder => $query->whereDoesntHave('supplierPoLines'));
    }

    private function dueFollowUpsQuery(): Builder
    {
        return FollowUpItem::query()
            ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now()->copy()->endOfDay());
    }

    /**
     * @return array<string, string>
     */
    private function metric(string $label, int $value, string $icon, string $tone, int $recentCount): array
    {
        return [
            'label' => $label,
            'value' => (string) $value,
            'icon' => $icon,
            'change' => (string) $recentCount,
            'note' => 'new last 7 days',
            'tone' => $tone,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workflowStages(): array
    {
        return [
            ['label' => 'RFQ', 'value' => (string) Quotation::query()->count(), 'icon' => 'ClipboardList', 'tone' => 'slate'],
            ['label' => 'Quotation', 'value' => (string) Quotation::query()->whereNotIn('status', ['buyer_po_received', 'closed'])->count(), 'icon' => 'FileText', 'tone' => 'teal'],
            ['label' => 'Buyer PO', 'value' => (string) BuyerPo::query()->count(), 'icon' => 'ReceiptText', 'tone' => 'amber'],
            ['label' => 'Supplier PO', 'value' => (string) SupplierPo::query()->count(), 'icon' => 'Truck', 'tone' => 'teal'],
            ['label' => 'Dispatch', 'value' => (string) DeliveryOrder::query()->count(), 'icon' => 'Truck', 'tone' => 'blue', 'dashed' => true],
            ['label' => 'Invoice', 'value' => (string) Invoice::query()->count(), 'icon' => 'FileText', 'tone' => 'blue', 'dashed' => true],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentJobs(): array
    {
        $followUpRows = FollowUpItem::query()
            ->with([
                'assignee',
                'buyerPo',
                'quotation.buyerCompany',
                'quotation.salesperson',
                'quotationItem',
                'supplierPo.supplierCompany',
                'supplierPoLine',
            ])
            ->latest('updated_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (FollowUpItem $item): array => $this->transformRecentFollowUp($item))
            ->values();

        if ($followUpRows->isNotEmpty()) {
            return $followUpRows->all();
        }

        return Quotation::query()
            ->with(['buyerCompany', 'salesperson'])
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Quotation $quotation): array => [
                'jobRef' => $quotation->quotation_reference,
                'buyer' => $quotation->buyerCompany?->name ?? '-',
                'supplier' => '-',
                'stage' => $this->humanizeStatus($quotation->status),
                'stageTone' => $quotation->status === 'draft' ? 'slate' : 'teal',
                'owner' => $quotation->salesperson?->name ?? '-',
                'ownerInitials' => $this->initials($quotation->salesperson?->name),
                'due' => $this->formatDate($quotation->closing_at),
                'dueTone' => $this->dueTone($quotation->closing_at),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function alerts(): array
    {
        return FollowUpItem::query()
            ->with(['buyerPo', 'quotation.buyerCompany', 'quotationItem', 'supplierPo', 'supplierPoLine'])
            ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', now()->copy()->addDays(7)->endOfDay())
            ->orderBy('next_follow_up_at')
            ->limit(5)
            ->get()
            ->map(fn (FollowUpItem $item): array => $this->transformAlert($item))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformRecentFollowUp(FollowUpItem $item): array
    {
        $stage = $this->stageLabel($item->status);

        return [
            'jobRef' => $item->supplierPo?->po_reference ?? $item->quotation?->quotation_reference ?? '-',
            'buyer' => $item->quotation?->buyerCompany?->name ?? '-',
            'supplier' => $item->supplierPo?->supplierCompany?->name ?? '-',
            'stage' => $stage,
            'stageTone' => $this->stageTone($stage, $item->status),
            'owner' => $item->assignee?->name ?? $item->quotation?->salesperson?->name ?? '-',
            'ownerInitials' => $this->initials($item->assignee?->name ?? $item->quotation?->salesperson?->name),
            'due' => $this->formatDate($item->next_follow_up_at),
            'dueTone' => $this->dueTone($item->next_follow_up_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAlert(FollowUpItem $item): array
    {
        $dueAt = $item->next_follow_up_at;
        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();
        $title = 'Upcoming';
        $tone = 'teal';
        $icon = 'FileText';
        $dueLabel = 'Due in';
        $dueValue = $dueAt ? $todayStart->diffInDays($dueAt->copy()->startOfDay()).' days' : '-';

        if ($dueAt && $dueAt->lt($todayStart)) {
            $title = 'Overdue';
            $tone = 'rose';
            $icon = 'Bell';
            $dueLabel = max(1, (int) $dueAt->copy()->startOfDay()->diffInDays($todayStart)).' days';
            $dueValue = 'overdue';
        } elseif ($dueAt && $dueAt->lte($todayEnd)) {
            $title = 'Due Today';
            $tone = 'amber';
            $icon = 'Clock3';
            $dueValue = '0 days';
        }

        return [
            'jobRef' => $item->supplierPo?->po_reference ?? $item->quotation?->quotation_reference ?? '-',
            'title' => $title,
            'detail' => $this->stageLabel($item->status).': '.$this->itemTitle($item),
            'dueLabel' => $dueLabel,
            'dueValue' => $dueValue,
            'icon' => $icon,
            'tone' => $tone,
        ];
    }

    private function stageLabel(string $status): string
    {
        return self::STAGE_LABELS[$status] ?? $this->humanizeStatus($status);
    }

    private function stageTone(string $stage, string $status): string
    {
        if ($status === 'closed') {
            return 'slate';
        }

        return match ($stage) {
            'Order Acknowledgement', 'Shipping Details' => 'amber',
            'ETA / Logistics', 'Delivery / Receipt' => 'blue',
            'Invoice', 'Payment / Close' => 'teal',
            default => 'slate',
        };
    }

    private function dueTone(Carbon|string|null $date): string
    {
        if (! $date) {
            return 'neutral';
        }

        $due = $date instanceof Carbon ? $date->copy() : Carbon::parse($date);
        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();

        if ($due->lt($todayStart)) {
            return 'danger';
        }

        if ($due->lte($todayEnd)) {
            return 'warning';
        }

        return 'neutral';
    }

    private function itemTitle(FollowUpItem $item): string
    {
        return $item->supplierPoLine?->title
            ?? $item->quotationItem?->title
            ?? $item->supplierPoLine?->product_name
            ?? $item->quotationItem?->product_name
            ?? 'Follow-up item';
    }

    private function formatDate(Carbon|string|null $date): string
    {
        if (! $date) {
            return '-';
        }

        $value = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $value->format('M d, Y');
    }

    private function initials(?string $name): string
    {
        if (! $name) {
            return '--';
        }

        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    private function humanizeStatus(string $status): string
    {
        return (string) Str::of($status)->replace('_', ' ')->title();
    }
}
