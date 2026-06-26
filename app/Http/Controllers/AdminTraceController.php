<?php

namespace App\Http\Controllers;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\FollowUpAuditLog;
use App\Models\FollowUpComment;
use App\Models\FollowUpItem;
use App\Models\Manufacturer;
use App\Models\Quotation;
use App\Models\QuotationActivityLog;
use App\Models\QuotationItem;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminTraceController extends Controller
{
    private const COMMENT_STAGES = [
        'acknowledgement' => 'Order Acknowledgement',
        'shipping' => 'Shipping Details',
        'logistics' => 'ETA / Logistics',
        'delivery' => 'Delivery / Receipt',
        'invoice' => 'Invoice',
        'payment' => 'Payment / Close',
        'quotation' => 'Quotation',
    ];

    private const CLOSED_STATUSES = ['closed'];

    public function quotations(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $this->filters($request);
        $query = $this->applyQuotationFilters(Quotation::query(), $filters);
        $summary = $this->quotationSummary($query);
        $quotations = (clone $query)
            ->with($this->quotationRelations())
            ->withCount('items')
            ->withSum('items', 'total_price')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'summary' => $summary,
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
            'data' => $quotations
                ->map(fn (Quotation $quotation): array => $this->transformQuotationSummary($quotation))
                ->values(),
        ]);
    }

    public function quotation(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $quotation->load($this->quotationRelations(includeTimeline: true));

        return response()->json([
            'data' => $this->transformQuotationDetail($quotation),
        ]);
    }

    public function exportQuotations(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        $filters = $this->filters($request);
        $query = $this->applyQuotationFilters(Quotation::query(), $filters);

        return $this->streamCsv(
            'quotation-trace-'.now()->format('Y-m-d').'.csv',
            ['Quotation Reference', 'Buyer', 'Buyer PO', 'Supplier PO', 'Status', 'Stage', 'Items', 'Total', 'Latest Comment', 'Updated At'],
            function ($handle) use ($query): void {
                (clone $query)
                    ->with($this->quotationRelations())
                    ->withCount('items')
                    ->withSum('items', 'total_price')
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->get()
                    ->each(function (Quotation $quotation) use ($handle): void {
                        $record = $this->transformQuotationSummary($quotation);

                        fputcsv($handle, $this->csvRow([
                            $record['quotation_reference'],
                            $record['buyer_company_name'],
                            $record['buyer_po_numbers'],
                            $record['supplier_po_references'],
                            $record['status_label'],
                            $record['current_stage_label'],
                            $record['items_count'],
                            $record['items_sum_total_price'],
                            $record['latest_comment']['comment'] ?? null,
                            $record['updated_at'],
                        ]));
                    });
            },
        );
    }

    public function items(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $this->filters($request);
        $query = $this->applyItemFilters(QuotationItem::query(), $filters);
        $summary = $this->itemSummary($query);
        $items = (clone $query)
            ->with($this->itemRelations(includeTimeline: true))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json([
            'summary' => $summary,
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
            'data' => $items
                ->map(fn (QuotationItem $item): array => $this->transformTraceItem($item, includeComments: false, includeTimeline: true))
                ->values(),
        ]);
    }

    public function exportItems(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        $filters = $this->filters($request);
        $query = $this->applyItemFilters(QuotationItem::query(), $filters);

        return $this->streamCsv(
            'item-trace-'.now()->format('Y-m-d').'.csv',
            ['Item', 'Quotation', 'Buyer', 'Buyer PO', 'Supplier', 'Supplier PO', 'Manufacturer', 'Status', 'Stage', 'Quantity', 'UOM', 'Latest Comment', 'Next Follow-Up'],
            function ($handle) use ($query): void {
                (clone $query)
                    ->with($this->itemRelations())
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->get()
                    ->each(function (QuotationItem $item) use ($handle): void {
                        $record = $this->transformTraceItem($item, includeComments: false, includeTimeline: false);

                        fputcsv($handle, $this->csvRow([
                            $record['title'] ?? $record['product_name'],
                            $record['quotation_reference'],
                            $record['buyer_company_name'],
                            $record['buyer_po_number'],
                            $record['supplier_company_name'],
                            $record['supplier_po_reference'],
                            $record['manufacturer_name'],
                            $record['status_label'],
                            $record['current_stage_label'],
                            $record['quantity'],
                            $record['uom'],
                            $record['latest_comment']['comment'] ?? null,
                            $record['next_follow_up_at'],
                        ]));
                    });
            },
        );
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user?->hasRole('admin')) {
            return;
        }

        abort($user ? 403 : 401);
    }

    /**
     * @return array{search: string, status: string, buyer_id: string, supplier_id: string, manufacturer_id: string, follow_up_status: string}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => Str::limit(trim((string) $request->query('search', '')), 140, ''),
            'status' => Str::limit(trim((string) $request->query('status', 'all')), 64, ''),
            'buyer_id' => Str::limit(trim((string) $request->query('buyer_id', 'all')), 32, ''),
            'supplier_id' => Str::limit(trim((string) $request->query('supplier_id', 'all')), 32, ''),
            'manufacturer_id' => Str::limit(trim((string) $request->query('manufacturer_id', 'all')), 32, ''),
            'follow_up_status' => Str::limit(trim((string) $request->query('follow_up_status', 'all')), 64, ''),
        ];
    }

    /**
     * @param  array{search: string, status: string, buyer_id: string, supplier_id: string, manufacturer_id: string, follow_up_status: string}  $filters
     */
    private function applyQuotationFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $like = $this->like($filters['search']);
            $query->where(function (Builder $search) use ($like): void {
                $search
                    ->where('quotation_reference', 'like', $like)
                    ->orWhere('rfq_number', 'like', $like)
                    ->orWhere('pr_number', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('buyerCompany', fn (Builder $relation): Builder => $relation
                        ->where('name', 'like', $like)
                        ->orWhere('company_code', 'like', $like), '>=', 1)
                    ->orWhereHas('buyerPos', fn (Builder $relation): Builder => $relation->where('po_number', 'like', $like), '>=', 1)
                    ->orWhereHas('items', fn (Builder $relation): Builder => $relation
                        ->where('product_name', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('buyer_description', 'like', $like)
                        ->orWhere('manufacturer_description', 'like', $like), '>=', 1)
                    ->orWhereHas('items.manufacturer', fn (Builder $relation): Builder => $relation->where('name', 'like', $like), '>=', 1)
                    ->orWhereHas('items.supplierPoLines.supplierPo', fn (Builder $relation): Builder => $relation->where('po_reference', 'like', $like), '>=', 1)
                    ->orWhereHas('items.supplierPoLines.supplierPo.supplierCompany', fn (Builder $relation): Builder => $relation->where('name', 'like', $like), '>=', 1);
            });
        }

        if ($filters['status'] !== '' && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (ctype_digit($filters['buyer_id'])) {
            $query->where('buyer_company_id', (int) $filters['buyer_id']);
        }

        if (ctype_digit($filters['manufacturer_id'])) {
            $query->whereHas('items', fn (Builder $relation): Builder => $relation->where('manufacturer_id', (int) $filters['manufacturer_id']), '>=', 1);
        }

        if (ctype_digit($filters['supplier_id'])) {
            $query->whereHas('items.supplierPoLines.supplierPo', fn (Builder $relation): Builder => $relation->where('supplier_id', (int) $filters['supplier_id']), '>=', 1);
        }

        if ($filters['follow_up_status'] !== '' && $filters['follow_up_status'] !== 'all') {
            $query->whereHas('items.supplierPoLines.followUpItem', fn (Builder $relation): Builder => $relation->where('status', $filters['follow_up_status']), '>=', 1);
        }

        return $query;
    }

    /**
     * @param  array{search: string, status: string, buyer_id: string, supplier_id: string, manufacturer_id: string, follow_up_status: string}  $filters
     */
    private function applyItemFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $like = $this->like($filters['search']);
            $query->where(function (Builder $search) use ($like): void {
                $search
                    ->where('product_name', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('buyer_description', 'like', $like)
                    ->orWhere('manufacturer_description', 'like', $like)
                    ->orWhereHas('quotation', fn (Builder $relation): Builder => $relation
                        ->where('quotation_reference', 'like', $like)
                        ->orWhere('rfq_number', 'like', $like)
                        ->orWhere('pr_number', 'like', $like), '>=', 1)
                    ->orWhereHas('quotation.buyerCompany', fn (Builder $relation): Builder => $relation->where('name', 'like', $like), '>=', 1)
                    ->orWhereHas('quotation.buyerPos', fn (Builder $relation): Builder => $relation->where('po_number', 'like', $like), '>=', 1)
                    ->orWhereHas('manufacturer', fn (Builder $relation): Builder => $relation->where('name', 'like', $like), '>=', 1)
                    ->orWhereHas('supplierPoLines.supplierPo', fn (Builder $relation): Builder => $relation->where('po_reference', 'like', $like), '>=', 1)
                    ->orWhereHas('supplierPoLines.supplierPo.supplierCompany', fn (Builder $relation): Builder => $relation->where('name', 'like', $like), '>=', 1);
            });
        }

        if ($filters['status'] !== '' && $filters['status'] !== 'all') {
            $query->whereHas('supplierPoLines.followUpItem', fn (Builder $relation): Builder => $relation->where('status', $filters['status']), '>=', 1);
        }

        if (ctype_digit($filters['buyer_id'])) {
            $query->whereHas('quotation', fn (Builder $relation): Builder => $relation->where('buyer_company_id', (int) $filters['buyer_id']), '>=', 1);
        }

        if (ctype_digit($filters['manufacturer_id'])) {
            $query->where('manufacturer_id', (int) $filters['manufacturer_id']);
        }

        if (ctype_digit($filters['supplier_id'])) {
            $query->whereHas('supplierPoLines.supplierPo', fn (Builder $relation): Builder => $relation->where('supplier_id', (int) $filters['supplier_id']), '>=', 1);
        }

        if ($filters['follow_up_status'] !== '' && $filters['follow_up_status'] !== 'all') {
            $query->whereHas('supplierPoLines.followUpItem', fn (Builder $relation): Builder => $relation->where('status', $filters['follow_up_status']), '>=', 1);
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private function quotationRelations(bool $includeTimeline = false): array
    {
        return [
            'buyerCompany',
            'buyerContact',
            'supplierCompany',
            'supplierContact',
            'incoterm',
            'salesperson',
            'buyerPos.quotationVersion',
            ...$this->prefixedItemRelations('items.', $includeTimeline),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function itemRelations(bool $includeTimeline = false): array
    {
        return $this->prefixedItemRelations('', $includeTimeline);
    }

    /**
     * @return array<int, string>
     */
    private function prefixedItemRelations(string $prefix, bool $includeTimeline): array
    {
        $relations = [
            "{$prefix}manufacturer",
            "{$prefix}quotation.buyerCompany",
            "{$prefix}quotation.buyerContact",
            "{$prefix}quotation.salesperson",
            "{$prefix}quotation.buyerPos.quotationVersion",
            "{$prefix}supplierPoLines.buyerPo",
            "{$prefix}supplierPoLines.supplierPo.supplierCompany",
            "{$prefix}supplierPoLines.supplierPo.supplierContact",
            "{$prefix}supplierPoLines.supplierPo.creator",
            "{$prefix}supplierPoLines.followUpItem.assignee",
            "{$prefix}supplierPoLines.followUpItem.comments.user",
        ];

        if ($includeTimeline) {
            $relations[] = "{$prefix}quotation.activityLogs.user";
            $relations[] = "{$prefix}supplierPoLines.followUpItem.auditLogs.user";
        }

        return $relations;
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationSummary(Builder $query): array
    {
        $quotationIds = (clone $query)->pluck('id');

        return [
            'quotations' => $quotationIds->count(),
            'items' => QuotationItem::query()->whereIn('quotation_id', $quotationIds)->count(),
            'buyer_pos' => $quotationIds->isEmpty() ? 0 : BuyerPo::query()->whereIn('quotation_id', $quotationIds)->count(),
            'supplier_pos' => $quotationIds->isEmpty() ? 0 : SupplierPo::query()
                ->whereHas('lines', fn (Builder $line): Builder => $line->whereIn('quotation_id', $quotationIds), '>=', 1)
                ->count(),
            'open_follow_ups' => $quotationIds->isEmpty() ? 0 : FollowUpItem::query()
                ->whereIn('quotation_id', $quotationIds)
                ->whereNotIn('status', self::CLOSED_STATUSES)
                ->count(),
            'overdue_follow_ups' => $quotationIds->isEmpty() ? 0 : FollowUpItem::query()
                ->whereIn('quotation_id', $quotationIds)
                ->whereNotIn('status', self::CLOSED_STATUSES)
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', now()->startOfDay())
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemSummary(Builder $query): array
    {
        $itemIds = (clone $query)->pluck('id');

        return [
            'items' => $itemIds->count(),
            'quotations' => $itemIds->isEmpty() ? 0 : QuotationItem::query()->whereIn('id', $itemIds)->distinct('quotation_id')->count('quotation_id'),
            'supplier_pos' => $itemIds->isEmpty() ? 0 : SupplierPoLine::query()->whereIn('quotation_item_id', $itemIds)->distinct('supplier_po_id')->count('supplier_po_id'),
            'open_follow_ups' => $itemIds->isEmpty() ? 0 : FollowUpItem::query()
                ->whereIn('quotation_item_id', $itemIds)
                ->whereNotIn('status', self::CLOSED_STATUSES)
                ->count(),
            'overdue_follow_ups' => $itemIds->isEmpty() ? 0 : FollowUpItem::query()
                ->whereIn('quotation_item_id', $itemIds)
                ->whereNotIn('status', self::CLOSED_STATUSES)
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', now()->startOfDay())
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'buyer_id' => Company::query()
                ->whereIn('company_type', ['buyer', 'mixed'])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Company $company): array => ['value' => (string) $company->id, 'label' => $company->name])
                ->values(),
            'supplier_id' => Supplier::query()
                ->with('company')
                ->orderBy('id')
                ->get()
                ->map(fn (Supplier $supplier): array => ['value' => (string) $supplier->id, 'label' => $supplier->company?->name ?? "Supplier {$supplier->id}"])
                ->values(),
            'manufacturer_id' => Manufacturer::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Manufacturer $manufacturer): array => ['value' => (string) $manufacturer->id, 'label' => $manufacturer->name])
                ->values(),
            'quotation_status' => Quotation::query()
                ->select('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->map(fn (string $status): array => ['value' => $status, 'label' => $this->statusLabel($status)])
                ->values(),
            'follow_up_status' => FollowUpItem::query()
                ->select('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->map(fn (string $status): array => ['value' => $status, 'label' => $this->statusLabel($status)])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformQuotationSummary(Quotation $quotation): array
    {
        $traceItems = $quotation->items
            ->map(fn (QuotationItem $item): array => $this->transformTraceItem($item, includeComments: false, includeTimeline: false))
            ->values();
        $followUps = $this->followUpsForQuotation($quotation);
        $currentFollowUp = $followUps->sortByDesc(fn (FollowUpItem $item): int => $item->updated_at?->getTimestamp() ?? 0)->first();
        $latestComment = $followUps
            ->flatMap(fn (FollowUpItem $item): Collection => $item->relationLoaded('comments') ? $item->comments : collect())
            ->sortByDesc(fn (FollowUpComment $comment): int => $comment->created_at?->getTimestamp() ?? 0)
            ->first();

        return [
            'id' => $quotation->id,
            'quotation_reference' => $quotation->quotation_reference,
            'buyer_company_id' => $quotation->buyer_company_id,
            'buyer_company_name' => $quotation->buyerCompany?->name,
            'buyer_contact_name' => $quotation->buyerContact?->name,
            'salesperson_name' => $quotation->salesperson?->name,
            'rfq_number' => $quotation->rfq_number,
            'pr_number' => $quotation->pr_number,
            'status' => $quotation->status,
            'status_label' => $this->statusLabel($quotation->status),
            'current_stage_label' => $currentFollowUp ? $this->stageLabelForStatus($currentFollowUp->status) : $this->statusLabel($quotation->status),
            'accepted_invoice_currency' => $quotation->accepted_invoice_currency,
            'items_count' => (int) ($quotation->items_count ?? $quotation->items->count()),
            'items_sum_total_price' => $quotation->items_sum_total_price !== null ? $this->money($quotation->items_sum_total_price) : $this->money($quotation->items->sum(fn (QuotationItem $item): float => (float) $item->total_price)),
            'buyer_po_numbers' => $quotation->buyerPos->pluck('po_number')->filter()->unique()->values(),
            'supplier_po_references' => $quotation->items
                ->flatMap(fn (QuotationItem $item): Collection => $item->supplierPoLines->pluck('supplierPo.po_reference'))
                ->filter()
                ->unique()
                ->values(),
            'latest_comment' => $latestComment ? $this->transformComment($latestComment) : null,
            'open_items' => $followUps->whereNotIn('status', self::CLOSED_STATUSES)->count(),
            'elapsed_label' => $this->elapsedFrom($quotation->created_at),
            'detail_url' => "/admin/trace/quotations/{$quotation->id}",
            'items' => $traceItems,
            'created_at' => $quotation->created_at?->toDateTimeString(),
            'updated_at' => $quotation->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformQuotationDetail(Quotation $quotation): array
    {
        return [
            ...$this->transformQuotationSummary($quotation),
            'supplier_company_name' => $quotation->supplierCompany?->name,
            'supplier_contact_name' => $quotation->supplierContact?->name,
            'incoterm_code' => $quotation->incoterm?->code,
            'closing_at' => $quotation->closing_at?->toDateTimeString(),
            'buyer_pos' => $quotation->buyerPos
                ->map(fn ($po): array => [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'po_date' => $po->po_date?->toDateString(),
                    'po_value' => $this->money($po->po_value),
                    'currency' => $po->currency,
                    'status' => $po->status,
                    'status_label' => $this->statusLabel($po->status),
                    'quotation_version_number' => $po->quotationVersion?->version_number,
                ])
                ->values(),
            'items' => $quotation->items
                ->map(fn (QuotationItem $item): array => $this->transformTraceItem($item, includeComments: true, includeTimeline: true))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTraceItem(QuotationItem $item, bool $includeComments, bool $includeTimeline): array
    {
        $line = $item->supplierPoLines->first();
        $followUpItem = $line?->followUpItem;
        $buyerPo = $line?->buyerPo ?? $item->quotation?->buyerPos?->first();
        $status = $followUpItem?->status ?? $item->quotation?->status ?? 'draft';
        $comments = $followUpItem && $followUpItem->relationLoaded('comments')
            ? $followUpItem->comments->map(fn (FollowUpComment $comment): array => $this->transformComment($comment))->values()
            : collect();

        $data = [
            'id' => $followUpItem?->id ?? $item->id,
            'quotation_item_id' => $item->id,
            'quotation_id' => $item->quotation_id,
            'quotation_reference' => $item->quotation?->quotation_reference,
            'buyer_company_id' => $item->quotation?->buyer_company_id,
            'buyer_company_name' => $item->quotation?->buyerCompany?->name,
            'buyer_contact_name' => $item->quotation?->buyerContact?->name,
            'buyer_po_id' => $buyerPo?->id,
            'buyer_po_number' => $buyerPo?->po_number,
            'supplier_po_id' => $line?->supplier_po_id,
            'supplier_po_reference' => $line?->supplierPo?->po_reference,
            'supplier_company_name' => $line?->supplierPo?->supplierCompany?->name,
            'supplier_contact_name' => $line?->supplierPo?->supplierContact?->name,
            'salesperson_name' => $item->quotation?->salesperson?->name,
            'assigned_to_name' => $followUpItem?->assignee?->name,
            'manufacturer_id' => $item->manufacturer_id,
            'manufacturer_name' => $item->manufacturer?->name,
            'product_name' => $line?->product_name ?? $item->product_name,
            'title' => $line?->title ?? $item->title,
            'buyer_description' => $item->buyer_description,
            'manufacturer_description' => $item->manufacturer_description,
            'supplier_description' => $line?->item_description,
            'quantity' => $this->money($line?->quantity ?? $item->quantity),
            'uom' => $line?->uom ?? $item->uom,
            'unit_price' => $this->money($item->unit_price),
            'total_price' => $this->money($item->total_price),
            'unit_cost' => $line ? $this->money($line->unit_cost) : null,
            'total_cost' => $line ? $this->money($line->total_cost) : null,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'current_stage_label' => $followUpItem ? $this->stageLabelForStatus($status) : $this->statusLabel($status),
            'next_follow_up_at' => $followUpItem?->next_follow_up_at?->toDateTimeString(),
            'last_comment_at' => $followUpItem?->last_comment_at?->toDateTimeString(),
            'latest_comment' => $comments->first(),
            'comments_count' => $comments->count(),
            'elapsed_label' => $this->elapsedFrom($item->quotation?->created_at),
            'quotation_url' => "/admin/trace/quotations/{$item->quotation_id}",
            'follow_up_url' => $followUpItem ? "/follow-up/{$followUpItem->id}" : null,
        ];

        if ($includeComments) {
            $data['comments'] = $comments;
        }

        if ($includeTimeline) {
            $data['timeline_events'] = $this->timelineEventsFor($item);
        }

        return $data;
    }

    /**
     * @return Collection<int, FollowUpItem>
     */
    private function followUpsForQuotation(Quotation $quotation): Collection
    {
        return $quotation->items
            ->flatMap(fn (QuotationItem $item): Collection => $item->supplierPoLines
                ->map(fn ($line): ?FollowUpItem => $line->followUpItem)
                ->filter())
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timelineEventsFor(QuotationItem $item): array
    {
        $events = collect();

        if ($item->relationLoaded('quotation') && $item->quotation?->relationLoaded('activityLogs')) {
            foreach ($item->quotation->activityLogs as $log) {
                $events->push($this->transformQuotationTimelineEvent($log));
            }
        }

        foreach ($item->supplierPoLines as $line) {
            $followUpItem = $line->followUpItem;

            if (! $followUpItem || ! $followUpItem->relationLoaded('auditLogs')) {
                continue;
            }

            foreach ($followUpItem->auditLogs as $log) {
                $events->push($this->transformAuditTimelineEvent($log));
            }
        }

        $previous = null;

        return $events
            ->filter(fn (array $event): bool => $event['_occurred_at'] instanceof Carbon)
            ->sortBy(fn (array $event): int => $event['_occurred_at']->getTimestamp())
            ->values()
            ->map(function (array $event) use (&$previous): array {
                $occurredAt = $event['_occurred_at'];
                $elapsedSeconds = $previous ? (int) $previous->diffInSeconds($occurredAt) : null;
                $previous = $occurredAt;
                unset($event['_occurred_at']);

                return [
                    ...$event,
                    'occurred_at' => $occurredAt->toDateTimeString(),
                    'elapsed_from_previous_seconds' => $elapsedSeconds,
                    'elapsed_from_previous_label' => $elapsedSeconds === null ? null : $this->elapsedLabel($elapsedSeconds).' after previous event',
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function transformQuotationTimelineEvent(QuotationActivityLog $log): array
    {
        return [
            'id' => 'quotation-'.$log->id,
            'source' => 'quotation',
            'stage' => 'quotation',
            'stage_label' => 'Quotation',
            'action' => $log->action,
            'summary' => $log->summary,
            'user_name' => $log->user?->name,
            'properties' => $log->properties,
            '_occurred_at' => $log->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAuditTimelineEvent(FollowUpAuditLog $log): array
    {
        return [
            'id' => 'follow-up-'.$log->id,
            'source' => 'follow_up',
            'stage' => $log->stage,
            'stage_label' => self::COMMENT_STAGES[$log->stage] ?? $this->statusLabel($log->stage),
            'action' => $log->action,
            'summary' => $log->summary,
            'user_name' => $log->user?->name,
            'properties' => $log->properties,
            '_occurred_at' => $log->occurred_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformComment(FollowUpComment $comment): array
    {
        return [
            'id' => $comment->id,
            'stage' => $comment->stage,
            'stage_label' => self::COMMENT_STAGES[$comment->stage] ?? $this->statusLabel($comment->stage),
            'comment' => $comment->comment,
            'communication_type' => $comment->communication_type,
            'contacted_person' => $comment->contacted_person,
            'next_action' => $comment->next_action,
            'created_by_name' => $comment->user?->name,
            'created_at' => $comment->created_at?->toDateTimeString(),
        ];
    }

    private function stageLabelForStatus(string $status): string
    {
        return self::COMMENT_STAGES[$this->stageForStatus($status)] ?? $this->statusLabel($status);
    }

    private function stageForStatus(string $status): string
    {
        return match ($status) {
            'awaiting_acknowledgement' => 'acknowledgement',
            'acknowledged',
            'shipping_documents_complete' => 'shipping',
            'logistics_eta_recorded' => 'logistics',
            'documents_sent_to_buyer_agent',
            'documents_sent_to_agent',
            'arrived',
            'ready_for_delivery_order',
            'delivery_order_created' => 'delivery',
            'ready_for_invoice',
            'invoice_created' => 'invoice',
            'invoice_sent',
            'payment_pending',
            'partially_paid',
            'paid',
            'closed' => 'payment',
            default => 'quotation',
        };
    }

    private function statusLabel(?string $status): string
    {
        if (! $status) {
            return '-';
        }

        return (string) Str::of($status)->replace('_', ' ')->title();
    }

    private function elapsedFrom(?Carbon $date): ?string
    {
        if (! $date) {
            return null;
        }

        return $this->elapsedLabel((int) $date->diffInSeconds(now())).' open';
    }

    private function elapsedLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Immediately';
        }

        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;

        $parts = [];

        foreach ([
            'day' => $days,
            'hour' => $hours,
            'minute' => $minutes,
            'second' => $seconds,
        ] as $unit => $value) {
            if ($value > 0) {
                $parts[] = $value.' '.$unit.($value === 1 ? '' : 's');
            }
        }

        return implode(' ', array_slice($parts, 0, 3));
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['%', '_'], ['\\%', '\\_'], $value).'%';
    }

    /**
     * @param  array<int, string>  $headings
     */
    private function streamCsv(string $filename, array $headings, callable $writeRows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $writeRows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, $headings);
            $writeRows($handle);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function csvRow(array $values): array
    {
        return array_map(fn (mixed $value): string => $this->csvCell($value), $values);
    }

    private function csvCell(mixed $value): string
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $value = implode(', ', array_filter($value, fn (mixed $item): bool => $item !== null && $item !== ''));
        }

        $text = trim(strip_tags((string) ($value ?? '')));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if ($text !== '' && preg_match('/^[=+\-@]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
