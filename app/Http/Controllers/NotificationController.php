<?php

namespace App\Http\Controllers;

use App\Models\FollowUpItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    private const CLOSED_STATUSES = ['closed'];

    private const STAGE_LABELS = [
        'awaiting_acknowledgement' => 'Order Acknowledgement',
        'acknowledged' => 'Shipping Details',
        'shipping_documents_complete' => 'ETA / Logistics',
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
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->hasRole('admin') && ! $user?->hasRole('follow-up')) {
            return response()->json([
                'unread_count' => 0,
                'data' => [],
            ]);
        }

        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();

        $items = $this->visibleFollowUps($request)
            ->with([
                'supplierPo.supplierCompany',
                'quotation.buyerCompany',
                'buyerPo',
                'supplierPoLine.manufacturer',
                'quotationItem.manufacturer',
            ])
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $todayEnd)
            ->orderBy('next_follow_up_at')
            ->limit(12)
            ->get();

        $notifications = $items
            ->map(fn (FollowUpItem $item): array => $this->transformNotification($item, $todayStart))
            ->values()
            ->all();

        return response()->json([
            'unread_count' => count($notifications),
            'data' => $notifications,
        ]);
    }

    private function visibleFollowUps(Request $request): Builder
    {
        $query = FollowUpItem::query();

        if (! $request->user()?->hasRole('admin')) {
            $query->where('assigned_to', $request->user()?->id);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformNotification(FollowUpItem $item, Carbon $todayStart): array
    {
        $dueAt = $item->next_follow_up_at;
        $isOverdue = $dueAt && $dueAt->lt($todayStart);
        $title = $isOverdue ? 'Overdue Follow-Up' : 'Follow-Up Due Today';
        $product = $item->supplierPoLine?->title
            ?? $item->quotationItem?->title
            ?? $item->supplierPoLine?->product_name
            ?? $item->quotationItem?->product_name
            ?? $item->supplierPo?->po_reference
            ?? 'Follow-up item';

        return [
            'id' => $item->id,
            'type' => $isOverdue ? 'overdue' : 'due_today',
            'title' => $title,
            'body' => trim($product.' - '.($item->quotation?->buyerCompany?->name ?? 'No buyer')),
            'stage_label' => $this->stageLabel($item->status),
            'status_label' => $this->statusLabel($item->status),
            'supplier_po_reference' => $item->supplierPo?->po_reference,
            'buyer_po_number' => $item->buyerPo?->po_number,
            'buyer_company_name' => $item->quotation?->buyerCompany?->name,
            'supplier_company_name' => $item->supplierPo?->supplierCompany?->name,
            'due_at' => $dueAt?->toDateTimeString(),
            'action_url' => "/follow-up/{$item->id}",
            'severity' => $isOverdue ? 'danger' : 'warning',
        ];
    }

    private function stageLabel(string $status): string
    {
        return self::STAGE_LABELS[$status] ?? $this->statusLabel($status);
    }

    private function statusLabel(string $status): string
    {
        return (string) Str::of($status)->replace('_', ' ')->title();
    }
}
