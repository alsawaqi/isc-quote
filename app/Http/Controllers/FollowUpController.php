<?php

namespace App\Http\Controllers;

use App\Models\DeliveryOrder;
use App\Models\CompanyLocation;
use App\Models\FollowUpAuditLog;
use App\Models\FollowUpComment;
use App\Models\FollowUpItem;
use App\Models\Invoice;
use App\Models\LogisticsCase;
use App\Models\LogisticsEvent;
use App\Models\PackingList;
use App\Models\Payment;
use App\Models\PaymentPlanFollowUp;
use App\Models\PaymentPlanFollowUpAttachment;
use App\Models\Quotation;
use App\Models\QuotationPaymentSchedule;
use App\Models\ShippingDocument;
use App\Models\User;
use App\Services\DeliveryOrderDocumentService;
use App\Services\InvoiceDocumentService;
use App\Services\PackingListDocumentService;
use App\Services\PaymentPlanFollowUpService;
use App\Services\PrivateUploadDownloadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FollowUpController extends Controller
{
    public function __construct(private readonly PaymentPlanFollowUpService $paymentPlanFollowUps)
    {
    }

    private const REMINDER_UNITS = ['days', 'weeks', 'months', 'custom'];

    private const DELIVERY_RESPONSIBILITIES = ['isc', 'buyer_agent', 'supplier'];

    private const COMMENT_STAGES = [
        'acknowledgement' => 'Order Acknowledgement',
        'shipping' => 'Shipping Details',
        'logistics' => 'ETA / Logistics',
        'delivery' => 'Delivery / Receipt',
        'invoice' => 'Invoice',
        'payment' => 'Payment / Close',
    ];

    private const CLOSED_FOLLOW_UP_STATUSES = ['closed'];

    private const REQUIRED_SHIPPING_DOCUMENTS = [
        'supplier_invoice' => 'Supplier Invoice',
        'certificate_of_origin' => 'Certificate of Origin',
        'packing_list' => 'Packing List',
    ];

    private const OPTIONAL_SHIPPING_DOCUMENTS = [
        'bill_of_lading' => 'Bill of Lading',
        'airway_bill' => 'Airway Bill',
        'land_transport' => 'Land Transport',
        'carrier' => 'Carrier',
    ];

    private const GROUP_BY_OPTIONS = [
        'action' => 'Required Action',
        'job' => 'Job / Quotation',
        'buyer' => 'Buyer',
        'supplier_po' => 'Supplier PO',
        'buyer_po' => 'Buyer PO',
        'manufacturer' => 'Manufacturer',
        'stage' => 'Workflow Stage',
    ];

    private const ACTION_GROUP_LABELS = [
        'overdue' => 'Overdue',
        'due_today' => 'Due Today',
        'due_next_7_days' => 'Due Next 7 Days',
        'awaiting_acknowledgement' => 'Awaiting Acknowledgement',
        'shipping_documents_pending' => 'Shipping Documents Pending',
        'eta_logistics_pending' => 'ETA / Logistics Pending',
        'ready_for_delivery_order' => 'Ready for Delivery Order',
        'ready_for_invoice' => 'Ready for Invoice',
        'payment_follow_up' => 'Payment Follow-Up',
        'closed' => 'Closed',
        'other' => 'Other',
    ];

    private const STAGE_FILTER_LABELS = [
        'acknowledgement' => 'Order Acknowledgement',
        'shipping' => 'Shipping Details',
        'logistics' => 'ETA / Logistics',
        'delivery' => 'Delivery / Receipt',
        'invoice' => 'Invoice',
        'payment' => 'Payment / Close',
    ];

    private const STAGE_STATUS_BUCKETS = [
        'acknowledgement' => ['awaiting_acknowledgement'],
        'shipping' => ['acknowledged', 'shipping_documents_complete'],
        'logistics' => ['logistics_eta_recorded'],
        'delivery' => ['documents_sent_to_buyer_agent', 'documents_sent_to_agent', 'arrived', 'partially_received', 'ready_for_delivery_order', 'delivery_order_created', 'partially_delivered'],
        'invoice' => ['ready_for_invoice', 'invoice_created', 'partially_invoiced'],
        'payment' => ['invoice_sent', 'payment_pending', 'partially_paid', 'paid', 'closed'],
    ];

    private const BULK_QUOTATION_ACTIONS = [
        'reminder_update',
        'comment_add',
        'acknowledgement_record',
        'shipping_documents_complete',
        'eta_update',
        'documents_sent',
        'arrival_record',
        'warehouse_received',
        'buyer_received',
    ];

    private const FOLLOW_UP_STATUS_ORDER = [
        'awaiting_acknowledgement' => 10,
        'acknowledged' => 20,
        'shipping_documents_complete' => 30,
        'logistics_eta_recorded' => 40,
        'documents_sent_to_agent' => 50,
        'documents_sent_to_buyer_agent' => 50,
        'arrived' => 60,
        'partially_received' => 65,
        'ready_for_delivery_order' => 70,
        'delivery_order_created' => 80,
        'partially_delivered' => 85,
        'ready_for_invoice' => 90,
        'invoice_created' => 100,
        'partially_invoiced' => 105,
        'invoice_sent' => 110,
        'payment_pending' => 120,
        'partially_paid' => 130,
        'paid' => 140,
        'closed' => 150,
        'cancelled' => 160,
        'line_removed' => 160,
    ];

    private const LOGISTICS_CASE_STATUS_ORDER = [
        'eta_recorded' => 10,
        'documents_sent_to_agent' => 20,
        'documents_sent_to_buyer_agent' => 20,
        'arrived' => 30,
        'warehouse_partially_received' => 35,
        'buyer_partially_received' => 35,
        'warehouse_received' => 40,
        'buyer_received' => 40,
        'supplier_received' => 40,
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeFollowUp($request);

        $filters = $this->followUpFilters($request);
        $baseQuery = $this->applyFollowUpFilters($this->visibleItemsQuery($request), $filters);
        $includeTimeline = $this->canViewFullTimeline($request);
        $items = (clone $baseQuery)
            ->with($this->itemRelations($includeTimeline))
            ->latest('id')
            ->limit(500)
            ->get();
        $transformedItems = $items
            ->map(fn (FollowUpItem $item): array => $this->transformFollowUpItem($item, $includeTimeline))
            ->values();

        return response()->json([
            'summary' => $this->summaryFor($baseQuery),
            'group_by' => $filters['group_by'],
            'filters' => [
                'search' => $filters['search'],
                'action' => $filters['action'],
                'stage' => $filters['stage'],
            ],
            'filter_options' => $this->followUpFilterOptions(),
            'groups' => $this->groupsFor($items, $transformedItems, $filters['group_by']),
            'due_reminders' => $this->dueRemindersFor($baseQuery, $includeTimeline),
            'data' => $transformedItems,
        ]);
    }

    public function show(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        return response()->json([
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem, false),
        ]);
    }

    public function quotationIndex(Request $request): JsonResponse
    {
        $this->authorizeFollowUp($request);

        $items = $this->visibleItemsQuery($request)
            ->with($this->itemRelations(false))
            ->latest('id')
            ->limit(750)
            ->get();

        return response()->json([
            'data' => $items
                ->groupBy('quotation_id')
                ->map(fn (Collection $quotationItems): array => $this->transformQuotationSummary($quotationItems))
                ->sortByDesc('latest_follow_up_item_id')
                ->values(),
        ]);
    }

    public function quotationShow(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeFollowUp($request);

        return response()->json([
            'data' => $this->transformQuotationWorkspace($request, $quotation),
        ]);
    }

    public function storeQuotationGroup(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeFollowUp($request);

        $validated = $request->validate([
            'group_name' => ['required', 'string', 'max:150'],
            'workflow_mode' => ['required', Rule::in(['shared', 'individual'])],
            'follow_up_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'follow_up_item_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $ids = collect($validated['follow_up_item_ids'])->map(fn ($id): int => (int) $id)->values();
        $items = FollowUpItem::query()
            ->where('quotation_id', $quotation->id)
            ->whereIn('id', $ids)
            ->get();

        if ($items->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'follow_up_item_ids' => 'All selected items must belong to this quotation follow-up workspace.',
            ]);
        }

        $this->authorizeFollowUpItems($request, $items);

        $groupKey = (string) Str::uuid();
        FollowUpItem::query()
            ->whereIn('id', $ids)
            ->update([
                'follow_up_group_key' => $groupKey,
                'follow_up_group_name' => trim((string) $validated['group_name']),
                'follow_up_group_mode' => $validated['workflow_mode'],
                'updated_at' => now(),
            ]);

        foreach ($items as $item) {
            $this->logFollowUpAudit($item, $request, $this->workflowStageFor($item), 'follow_up.grouped', 'Follow-up item added to quotation group.', [
                'follow_up_group_key' => $groupKey,
                'follow_up_group_name' => trim((string) $validated['group_name']),
                'follow_up_group_mode' => $validated['workflow_mode'],
            ]);
        }

        return response()->json([
            'message' => 'Follow-up group saved.',
            'data' => $this->transformQuotationWorkspace($request, $quotation),
        ], 201);
    }

    public function splitQuotationGroup(Request $request, Quotation $quotation, string $groupKey): JsonResponse
    {
        $this->authorizeFollowUp($request);

        $items = FollowUpItem::query()
            ->where('quotation_id', $quotation->id)
            ->where('follow_up_group_key', $groupKey)
            ->get();

        if ($items->isEmpty()) {
            abort(404);
        }

        $this->authorizeFollowUpItems($request, $items);

        FollowUpItem::query()
            ->whereIn('id', $items->pluck('id'))
            ->update([
                'follow_up_group_key' => null,
                'follow_up_group_name' => null,
                'follow_up_group_mode' => 'individual',
                'updated_at' => now(),
            ]);

        foreach ($items as $item) {
            $this->logFollowUpAudit($item, $request, $this->workflowStageFor($item), 'follow_up.group_split', 'Follow-up group split into individual items.', [
                'follow_up_group_key' => $groupKey,
            ]);
        }

        return response()->json([
            'message' => 'Follow-up group split into individual items.',
            'data' => $this->transformQuotationWorkspace($request, $quotation),
        ]);
    }

    public function bulkQuotationAction(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeFollowUp($request);

        $base = $request->validate([
            'action' => ['required', Rule::in(self::BULK_QUOTATION_ACTIONS)],
            'follow_up_item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'follow_up_item_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $validated = [
            ...$base,
            ...$request->validate($this->bulkQuotationActionRules($base['action'])),
        ];

        $ids = collect($validated['follow_up_item_ids'])->map(fn ($id): int => (int) $id)->values();
        $items = FollowUpItem::query()
            ->where('quotation_id', $quotation->id)
            ->whereIn('id', $ids)
            ->with(['itemFulfilment', 'logisticsCase'])
            ->get();

        if ($items->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'follow_up_item_ids' => 'All selected items must belong to this quotation follow-up workspace.',
            ]);
        }

        $this->authorizeFollowUpItems($request, $items);

        DB::transaction(function () use ($items, $request, $validated): void {
            foreach ($items as $item) {
                match ($validated['action']) {
                    'reminder_update' => $this->applyReminderUpdate($item, $request, $validated),
                    'comment_add' => $this->applyCommentAdd($item, $request, $validated),
                    'acknowledgement_record' => $this->applyAcknowledgementRecord($item, $request, $validated),
                    'shipping_documents_complete' => $this->applyShippingDocumentsComplete($item, $request),
                    'eta_update' => $this->applyEtaUpdate($item, $request, $validated),
                    'documents_sent' => $this->applyDocumentsSent($item, $request, $validated),
                    'arrival_record' => $this->applyArrivalRecord($item, $request, $validated),
                    'warehouse_received' => $this->applyWarehouseReceived($item, $request, $validated),
                    'buyer_received' => $this->applyBuyerReceived($item, $request, $validated),
                };
            }
        });

        return response()->json([
            'message' => $this->bulkQuotationActionMessage($validated['action'], $items->count()),
            'updated_item_ids' => $ids->all(),
            'data' => $this->transformQuotationWorkspace($request, $quotation),
        ]);
    }

    public function updateReminder(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'reminder_interval_value' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reminder_interval_unit' => ['required', Rule::in(self::REMINDER_UNITS)],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);

        $this->applyReminderUpdate($followUpItem, $request, $validated);

        return response()->json([
            'message' => 'Follow-up reminder updated.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storeComment(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'comment' => ['required', 'string'],
            'stage' => ['required', Rule::in(array_keys(self::COMMENT_STAGES))],
            'communication_type' => ['nullable', 'string', 'max:32'],
            'contacted_person' => ['nullable', 'string', 'max:255'],
            'next_action' => ['nullable', 'string'],
        ]);

        $this->applyCommentAdd($followUpItem, $request, $validated);

        return response()->json([
            'message' => 'Follow-up comment added.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], 201);
    }

    public function acknowledge(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);

        $validated = $request->validate([
            'acknowledgement_received_at' => ['required', 'date'],
            'acknowledgement_notes' => ['nullable', 'string'],
            'acknowledgement_file' => ['nullable', 'file', 'max:10240'],
        ]);

        $this->applyAcknowledgementRecord($followUpItem, $request, $validated);

        return response()->json([
            'message' => 'Order acknowledgement recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function downloadAcknowledgementUpload(
        Request $request,
        FollowUpItem $followUpItem,
        PrivateUploadDownloadService $downloads,
    ): BinaryFileResponse
    {
        $this->authorizeFollowUpEvidence($request, $followUpItem);

        return $downloads->download(
            $followUpItem->acknowledgement_file_path,
            $followUpItem->acknowledgement_original_file_name,
            "follow-up/{$followUpItem->id}/acknowledgements",
        );
    }

    public function shippingDocuments(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureShippingDocuments($followUpItem);

        return response()->json([
            'complete' => $this->shippingDocumentsComplete($followUpItem),
            'data' => $this->shippingDocumentsFor($followUpItem)
                ->map(fn (ShippingDocument $document): array => $this->transformShippingDocument($document))
                ->values(),
        ]);
    }

    public function uploadShippingDocument(Request $request, FollowUpItem $followUpItem, string $documentType): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureShippingDocuments($followUpItem);

        if (! array_key_exists($documentType, $this->shippingDocumentLabels())) {
            abort(404);
        }

        $validated = $request->validate([
            'document_file' => ['required', 'file', 'max:10240'],
            'document_number' => ['nullable', 'string', 'max:150'],
            'document_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $file = $request->file('document_file');
        $filePath = $file->store("follow-up/{$followUpItem->id}/shipping-documents/{$documentType}", 'local');
        $document = ShippingDocument::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->where('document_type', $documentType)
            ->firstOrFail();

        $document->forceFill([
            'status' => 'uploaded',
            'document_number' => $validated['document_number'] ?? null,
            'document_date' => $validated['document_date'] ?? null,
            'file_path' => $filePath,
            'original_file_name' => $file->getClientOriginalName(),
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
            'remarks' => $validated['remarks'] ?? null,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'shipping', 'shipping_document.uploaded', $document->label.' uploaded.', [
            'document_type' => $document->document_type,
            'document_label' => $document->label,
            'document_number' => $document->document_number,
            'document_date' => $document->document_date?->toDateString(),
            'original_file_name' => $document->original_file_name,
            'remarks' => $document->remarks,
        ]);

        return response()->json([
            'message' => $document->label.' uploaded.',
            'complete' => $this->shippingDocumentsComplete($followUpItem),
            'data' => $this->transformShippingDocument($document->refresh()->load('uploader')),
        ]);
    }

    public function downloadShippingDocumentUpload(
        Request $request,
        FollowUpItem $followUpItem,
        ShippingDocument $shippingDocument,
        PrivateUploadDownloadService $downloads,
    ): BinaryFileResponse
    {
        $this->authorizeFollowUpEvidence($request, $followUpItem);

        if ((int) $shippingDocument->follow_up_item_id !== (int) $followUpItem->id) {
            abort(404);
        }

        return $downloads->download(
            $shippingDocument->file_path,
            $shippingDocument->original_file_name,
            "follow-up/{$followUpItem->id}/shipping-documents/{$shippingDocument->document_type}",
        );
    }

    public function completeShippingDocuments(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->applyShippingDocumentsComplete($followUpItem, $request);

        return response()->json([
            'message' => 'Shipping documents completed.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storePackingList(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        throw ValidationException::withMessages([
            'packing_list' => 'Upload the carrier packing list from Shipping Documents instead of generating one.',
        ]);
    }

    public function downloadPackingList(Request $request, PackingList $packingList, string $format, PackingListDocumentService $documents): BinaryFileResponse
    {
        $packingList->loadMissing('followUpItem');
        $this->authorizeFollowUpItem($request, $packingList->followUpItem);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $path = $format === 'docx' ? $packingList->docx_path : $packingList->pdf_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            $safeReference = Str::slug($packingList->packing_list_reference, '-');
            $basePath = "generated/packing-lists/{$packingList->id}";
            $path = $format === 'docx' ? "{$basePath}/{$safeReference}.docx" : "{$basePath}/{$safeReference}.pdf";
            $packingList->forceFill([$format === 'docx' ? 'docx_path' : 'pdf_path' => $path])->save();

            $snapshot = $documents->snapshot($packingList);
            $format === 'docx'
                ? $documents->writeDocx($snapshot, $path)
                : $documents->writePdf($snapshot, $path);
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';

        $filename = Str::slug($packingList->packing_list_reference, '-').".{$format}";
        $filename = Str::upper($filename);

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    public function logistics(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $followUpItem->load('logisticsCase.events.user', 'logisticsCase.creator');

        return response()->json([
            'data' => $followUpItem->logisticsCase
                ? $this->transformLogisticsCase($followUpItem->logisticsCase)
                : null,
        ]);
    }

    public function recordEta(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'delivery_responsibility' => ['required', Rule::in(self::DELIVERY_RESPONSIBILITIES)],
            'eta_at' => ['required', 'date'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $recorded = $this->applyEtaUpdate($followUpItem, $request, $validated);

        return response()->json([
            'message' => $recorded ? 'ETA recorded.' : 'ETA updated.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markDocumentsSent(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'documents_sent_at' => ['required', 'date'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $this->applyDocumentsSent($followUpItem, $request, $validated);

        return response()->json([
            'message' => 'Shipping documents handoff recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markArrived(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'arrived_at' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $isSupplierResponsibility = $this->applyArrivalRecord($followUpItem, $request, $validated);

        return response()->json([
            'message' => $isSupplierResponsibility ? 'Supplier receipt recorded.' : 'Shipment arrival recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markWarehouseReceived(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'warehouse_received_at' => ['required', 'date'],
            'received_location' => ['required', 'string', 'max:255'],
            'received_quantity' => ['required', 'numeric', 'min:0.001'],
            'goods_condition' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $this->applyWarehouseReceived($followUpItem, $request, $validated);

        return response()->json([
            'message' => 'Warehouse receipt recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markBuyerReceived(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'buyer_received_at' => ['required', 'date'],
            'received_quantity' => ['required', 'numeric', 'min:0.001'],
            'goods_condition' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $this->applyBuyerReceived($followUpItem, $request, $validated);

        return response()->json([
            'message' => 'Buyer receipt recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storeDeliveryOrder(Request $request, FollowUpItem $followUpItem, DeliveryOrderDocumentService $documents): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);
        $this->requireDeliveryOrderReady($followUpItem);

        $validated = $request->validate([
            'delivery_place' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'terms' => ['nullable', 'string'],
        ]);

        $followUpItem->loadMissing(['supplierPoLine', 'quotationItem', 'buyerPo', 'buyerPoItem', 'itemFulfilment', 'quotation.buyerCompany', 'deliveryOrders.items']);
        $line = $followUpItem->supplierPoLine;

        if (! $line) {
            throw ValidationException::withMessages([
                'delivery_order' => 'This follow-up item is not linked to an active supplier PO line.',
            ]);
        }

        $remainingQuantity = $this->remainingDeliveryQuantity($followUpItem);

        if ($remainingQuantity <= 0.0005) {
            throw ValidationException::withMessages([
                'quantity' => 'There is no received quantity remaining for a delivery order.',
            ]);
        }

        $deliveryQuantity = array_key_exists('quantity', $validated) && $validated['quantity'] !== null
            ? $this->quantity($validated['quantity'])
            : $remainingQuantity;

        if ($deliveryQuantity > $remainingQuantity + 0.0005) {
            throw ValidationException::withMessages([
                'quantity' => 'Delivery order quantity cannot exceed the remaining received quantity of '.$this->money($remainingQuantity).' '.$line->uom.'.',
            ]);
        }

        $deliveryOrder = $this->activeDraftDeliveryOrderFor($followUpItem) ?? new DeliveryOrder(['follow_up_item_id' => $followUpItem->id]);
        $wasRecentlyCreated = ! $deliveryOrder->exists;
        $deliveryOrder->fill([
            'follow_up_item_id' => $followUpItem->id,
            'quotation_id' => $followUpItem->quotation_id,
            'buyer_po_id' => $followUpItem->buyer_po_id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference ?: $this->deliveryOrderReferenceFor($followUpItem),
            'delivery_order_date' => now()->toDateString(),
            'delivery_place' => $validated['delivery_place'],
            'terms' => $validated['terms'] ?? null,
            'status' => 'issued',
            'created_by' => $request->user()->id,
        ])->save();

        $deliveryOrder->items()->delete();
        $deliveryOrder->items()->create([
            'quotation_item_id' => $followUpItem->quotation_item_id,
            'buyer_po_id' => $followUpItem->buyer_po_id,
            'buyer_po_item_id' => $followUpItem->buyer_po_item_id,
            'item_fulfilment_id' => $followUpItem->itemFulfilment?->id,
            'line_number' => 10,
            'item_description' => $followUpItem->quotationItem?->buyer_description ?: $line->item_description,
            'quantity' => $deliveryQuantity,
            'uom' => $line->uom,
        ]);

        $safeReference = Str::slug($deliveryOrder->delivery_order_reference, '-');
        $basePath = "generated/delivery-orders/{$deliveryOrder->id}";
        $docxPath = "{$basePath}/{$safeReference}.docx";
        $pdfPath = "{$basePath}/{$safeReference}.pdf";
        $deliveryOrder->forceFill([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
        ])->save();

        $snapshot = $documents->snapshot($deliveryOrder->refresh()->load(['items.buyerPo', 'items.buyerPoItem', 'creator']));
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        if ($this->statusCanAdvance($followUpItem->refresh(), 'delivery_order_created')) {
            $this->advanceFollowUpStatus($followUpItem, 'delivery_order_created');
        }

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'delivery_order.generated', 'Delivery order generated.', [
            'delivery_order_id' => $deliveryOrder->id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference,
            'delivery_place' => $deliveryOrder->delivery_place,
            'quantity' => $this->money($deliveryQuantity),
            'remaining_delivery_quantity' => $this->money(max($remainingQuantity - $deliveryQuantity, 0)),
            'status' => $deliveryOrder->status,
        ]);

        return response()->json([
            'message' => 'Delivery order generated.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], $wasRecentlyCreated ? 201 : 200);
    }

    public function uploadSignedDeliveryOrder(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);
        $deliveryOrder = $this->deliveryOrderForSigning($followUpItem);

        return $this->storeSignedDeliveryOrderUpload($request, $followUpItem, $deliveryOrder);
    }

    public function uploadSpecificSignedDeliveryOrder(Request $request, FollowUpItem $followUpItem, DeliveryOrder $deliveryOrder): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);
        $deliveryOrder = $this->deliveryOrderForItem($followUpItem, $deliveryOrder);

        return $this->storeSignedDeliveryOrderUpload($request, $followUpItem, $deliveryOrder);
    }

    public function downloadSignedDeliveryOrderUpload(
        Request $request,
        FollowUpItem $followUpItem,
        PrivateUploadDownloadService $downloads,
    ): BinaryFileResponse
    {
        $this->authorizeFollowUpEvidence($request, $followUpItem);
        $deliveryOrder = $this->signedDeliveryOrderForDownload($followUpItem);

        return $downloads->download(
            $deliveryOrder->signed_file_path,
            $deliveryOrder->signed_original_file_name,
            "follow-up/{$followUpItem->id}/signed-delivery-orders",
        );
    }

    public function downloadSpecificSignedDeliveryOrderUpload(
        Request $request,
        FollowUpItem $followUpItem,
        DeliveryOrder $deliveryOrder,
        PrivateUploadDownloadService $downloads,
    ): BinaryFileResponse
    {
        $this->authorizeFollowUpEvidence($request, $followUpItem);
        $deliveryOrder = $this->deliveryOrderForItem($followUpItem, $deliveryOrder);

        return $downloads->download(
            $deliveryOrder->signed_file_path,
            $deliveryOrder->signed_original_file_name,
            "follow-up/{$followUpItem->id}/signed-delivery-orders",
        );
    }

    public function downloadDeliveryOrder(Request $request, DeliveryOrder $deliveryOrder, string $format, DeliveryOrderDocumentService $documents): BinaryFileResponse
    {
        $deliveryOrder->loadMissing('followUpItem');
        $this->authorizeFollowUpItem($request, $deliveryOrder->followUpItem);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $path = $format === 'docx' ? $deliveryOrder->docx_path : $deliveryOrder->pdf_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            $safeReference = Str::slug($deliveryOrder->delivery_order_reference, '-');
            $basePath = "generated/delivery-orders/{$deliveryOrder->id}";
            $path = $format === 'docx' ? "{$basePath}/{$safeReference}.docx" : "{$basePath}/{$safeReference}.pdf";
            $deliveryOrder->forceFill([$format === 'docx' ? 'docx_path' : 'pdf_path' => $path])->save();

            $snapshot = $documents->snapshot($deliveryOrder);
            $format === 'docx'
                ? $documents->writeDocx($snapshot, $path)
                : $documents->writePdf($snapshot, $path);
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';

        $filename = Str::slug($deliveryOrder->delivery_order_reference, '-').".{$format}";
        $filename = Str::upper($filename);

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    public function storeInvoice(Request $request, FollowUpItem $followUpItem, InvoiceDocumentService $documents): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);
        $this->requireInvoiceReady($followUpItem);

        $validated = $request->validate([
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'delivery_order_id' => ['nullable', 'integer', 'exists:delivery_orders,id'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_exception_reason' => ['nullable', 'string'],
            'bank_details' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $followUpItem->loadMissing(['quotationItem', 'buyerPo', 'buyerPoItem', 'itemFulfilment', 'quotation.buyerCompany', 'quotation.paymentSchedules', 'deliveryOrders.items', 'invoices.items']);
        $quotationItem = $followUpItem->quotationItem;

        if (! $quotationItem) {
            throw ValidationException::withMessages([
                'invoice' => 'This follow-up item is not linked to a quotation item.',
            ]);
        }

        $invoice = $this->activeDraftInvoiceFor($followUpItem) ?? new Invoice(['follow_up_item_id' => $followUpItem->id]);
        $wasRecentlyCreated = ! $invoice->exists;
        $remainingInvoiceableQuantity = $this->remainingInvoiceableQuantity($followUpItem, $invoice->exists ? (int) $invoice->id : null);

        if ($remainingInvoiceableQuantity <= 0.0005) {
            throw ValidationException::withMessages([
                'quantity' => 'There is no delivered quantity remaining for invoicing.',
            ]);
        }

        $invoiceQuantity = array_key_exists('quantity', $validated) && $validated['quantity'] !== null
            ? $this->quantity($validated['quantity'])
            : $remainingInvoiceableQuantity;

        if ($invoiceQuantity > $remainingInvoiceableQuantity + 0.0005) {
            throw ValidationException::withMessages([
                'quantity' => 'Invoice quantity cannot exceed the remaining delivered quantity of '.$this->money($remainingInvoiceableQuantity).' '.$quotationItem->uom.'.',
            ]);
        }

        $deliveryOrder = null;

        if (! empty($validated['delivery_order_id'])) {
            $deliveryOrder = DeliveryOrder::query()->findOrFail((int) $validated['delivery_order_id']);
            $deliveryOrder = $this->deliveryOrderForItem($followUpItem, $deliveryOrder);

            if ($deliveryOrder->status !== 'signed' && $deliveryOrder->signed_at === null) {
                throw ValidationException::withMessages([
                    'delivery_order_id' => 'Only a signed delivery order can be linked to an invoice.',
                ]);
            }
        } else {
            $deliveryOrder = DeliveryOrder::query()
                ->where('follow_up_item_id', $followUpItem->id)
                ->where(function (Builder $query): void {
                    $query->where('status', 'signed')
                        ->orWhereNotNull('signed_at');
                })
                ->latest('signed_at')
                ->latest('id')
                ->first();
        }

        $orderedQuantity = $this->orderedQuantityFor($followUpItem);
        $lineSubtotal = $this->quotationSubtotalFor($followUpItem);
        $unitPrice = $orderedQuantity > 0
            ? round($lineSubtotal / $orderedQuantity, 3)
            : round((float) $quotationItem->unit_price, 3);
        $subtotal = round($unitPrice * $invoiceQuantity, 3);
        $vatRate = round((float) ($quotationItem->vat_rate ?? 0), 3);
        $vatAmount = array_key_exists('vat_amount', $validated) && $validated['vat_amount'] !== null
            ? round((float) $validated['vat_amount'], 3)
            : round($subtotal * ($vatRate / 100), 3);

        if ($vatRate > 0 && $vatAmount === 0.0 && empty($validated['vat_exception_reason'])) {
            throw ValidationException::withMessages([
                'vat_amount' => 'VAT amount is zero while VAT rate is greater than zero. Enter a reason or correct the VAT amount.',
            ]);
        }

        $invoiceDate = now()->toDateString();
        $paymentTermDays = array_key_exists('payment_term_days', $validated) && $validated['payment_term_days'] !== null
            ? (int) $validated['payment_term_days']
            : (int) ($followUpItem->quotation?->payment_term_days ?? 0);
        $invoice->fill([
            'follow_up_item_id' => $followUpItem->id,
            'quotation_id' => $followUpItem->quotation_id,
            'buyer_po_id' => $followUpItem->buyer_po_id,
            'delivery_order_id' => $deliveryOrder?->id,
            'invoice_reference' => $invoice->invoice_reference ?: $this->invoiceReferenceFor($followUpItem),
            'invoice_date' => $invoiceDate,
            'payment_term_days' => $paymentTermDays,
            'due_date' => now()->copy()->addDays($paymentTermDays)->toDateString(),
            'currency' => $followUpItem->buyerPo?->currency ?: $followUpItem->quotation?->accepted_invoice_currency ?: 'OMR',
            'subtotal' => $subtotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_amount' => round($subtotal + $vatAmount, 3),
            'vat_exception_reason' => $validated['vat_exception_reason'] ?? null,
            'bank_details' => $validated['bank_details'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'status' => 'issued',
            'created_by' => $request->user()->id,
        ])->save();

        $invoice->items()->delete();
        $invoice->items()->create([
            'quotation_item_id' => $followUpItem->quotation_item_id,
            'buyer_po_id' => $followUpItem->buyer_po_id,
            'buyer_po_item_id' => $followUpItem->buyer_po_item_id,
            'item_fulfilment_id' => $followUpItem->itemFulfilment?->id,
            'delivery_order_id' => $deliveryOrder?->id,
            'line_number' => 10,
            'item_description' => $quotationItem->buyer_description,
            'quantity' => $invoiceQuantity,
            'uom' => $quotationItem->uom,
            'unit_price' => $unitPrice,
            'total_price' => $subtotal,
        ]);

        $safeReference = Str::slug($invoice->invoice_reference, '-');
        $basePath = "generated/invoices/{$invoice->id}";
        $docxPath = "{$basePath}/{$safeReference}.docx";
        $pdfPath = "{$basePath}/{$safeReference}.pdf";
        $invoice->forceFill([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
        ])->save();

        $snapshot = $documents->snapshot($invoice->refresh()->load(['items.buyerPo', 'items.buyerPoItem', 'deliveryOrder', 'creator']));
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        $followUpItem->unsetRelation('invoices');
        $followUpItem->load('invoices.items', 'invoices.payments');
        $this->syncFulfilmentFinancials($followUpItem, 'invoice_generated');
        $targetStatus = $this->remainingInvoiceableQuantity($followUpItem) > 0.0005 ? 'partially_invoiced' : 'invoice_created';

        if ($this->statusCanAdvance($followUpItem->refresh(), $targetStatus)) {
            $this->advanceFollowUpStatus($followUpItem, $targetStatus);
        }

        $this->paymentPlanFollowUps->ensureForItem($followUpItem);
        $this->syncPaidPaymentPlanFollowUpsToInvoice($followUpItem, $request);

        $this->logFollowUpAudit($followUpItem, $request, 'invoice', 'invoice.generated', 'Invoice generated.', [
            'invoice_id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
            'quantity' => $this->money($invoiceQuantity),
            'remaining_invoice_quantity' => $this->money($this->remainingInvoiceableQuantity($followUpItem)),
            'total_amount' => $this->money($invoice->total_amount),
            'currency' => $invoice->currency,
            'due_date' => $invoice->due_date?->toDateString(),
        ]);

        return response()->json([
            'message' => 'Invoice generated.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], $wasRecentlyCreated ? 201 : 200);
    }

    public function downloadInvoice(Request $request, Invoice $invoice, string $format, InvoiceDocumentService $documents): BinaryFileResponse
    {
        $invoice->loadMissing('followUpItem');
        $this->authorizeFollowUpItem($request, $invoice->followUpItem);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $path = $format === 'docx' ? $invoice->docx_path : $invoice->pdf_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            $safeReference = Str::slug($invoice->invoice_reference, '-');
            $basePath = "generated/invoices/{$invoice->id}";
            $path = $format === 'docx' ? "{$basePath}/{$safeReference}.docx" : "{$basePath}/{$safeReference}.pdf";
            $invoice->forceFill([$format === 'docx' ? 'docx_path' : 'pdf_path' => $path])->save();

            $snapshot = $documents->snapshot($invoice);
            $format === 'docx'
                ? $documents->writeDocx($snapshot, $path)
                : $documents->writePdf($snapshot, $path);
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';

        $filename = Str::slug($invoice->invoice_reference, '-').".{$format}";
        $filename = Str::upper($filename);

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    public function markInvoiceSent(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);

        $validated = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'sent_at' => ['required', 'date'],
        ]);

        $invoice = $this->invoiceForRequest($followUpItem, $request);
        $this->ensureInvoiceAcceptsPayment($invoice);
        $this->ensureInvoiceCanBeMarkedSent($invoice);

        $invoice->forceFill([
            'status' => 'sent',
            'sent_at' => Carbon::parse($validated['sent_at']),
        ])->save();

        $this->paymentPlanFollowUps->ensureForItem($followUpItem);
        $this->syncPaidPaymentPlanFollowUpsToInvoice($followUpItem, $request);
        $this->refreshInvoiceStatusAfterPayments($followUpItem, $invoice);

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'invoice.sent', 'Invoice marked as sent.', [
            'invoice_id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
            'sent_at' => Carbon::parse($validated['sent_at'])->toDateTimeString(),
        ]);

        return response()->json([
            'message' => 'Invoice marked as sent.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storePayment(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);

        $validated = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'exists:invoices,id'],
            'amount' => ['required', 'numeric', 'min:0.001'],
            'payment_date' => ['required', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $invoice = $this->invoiceForRequest($followUpItem, $request);
        $this->ensureInvoiceAcceptsPayment($invoice);

        $summary = $this->paymentSummaryFor($invoice);
        $amount = round((float) $validated['amount'], 3);

        if ($amount > $summary['balance'] + 0.0005) {
            throw ValidationException::withMessages([
                'amount' => 'Payment amount cannot exceed the remaining invoice balance.',
            ]);
        }

        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'follow_up_item_id' => $followUpItem->id,
            'item_fulfilment_id' => $followUpItem->itemFulfilment?->id,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'payment_date' => Carbon::parse($validated['payment_date'])->toDateString(),
            'payment_reference' => $validated['payment_reference'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        $invoice->refresh()->load('payments');
        $updatedSummary = $this->paymentSummaryFor($invoice);
        $invoiceStatus = $updatedSummary['balance'] <= 0.0005 ? 'paid' : 'partially_paid';

        $invoice->forceFill(['status' => $invoiceStatus])->save();
        $this->refreshInvoiceStatusAfterPayments($followUpItem, $invoice);
        $followUpItem->refresh();
        $this->syncFulfilmentFinancials($followUpItem, $followUpItem->status);

        if ($followUpItem->status === 'paid') {
            $this->markOutstandingPaymentPlanFollowUpsPaid(
                $followUpItem,
                $request,
                Carbon::parse($validated['payment_date']),
                $validated['payment_reference'] ?? null,
                $validated['remarks'] ?? null,
            );
        }

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'payment.recorded', $invoiceStatus === 'paid' ? 'Invoice paid in full.' : 'Payment recorded.', [
            'invoice_id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
            'amount' => $this->money($amount),
            'currency' => $invoice->currency,
            'payment_date' => Carbon::parse($validated['payment_date'])->toDateString(),
            'payment_reference' => $validated['payment_reference'] ?? null,
            'payment_status' => $invoiceStatus,
            'balance_amount' => $this->money($updatedSummary['balance']),
            'item_paid_amount' => $this->money($this->paidAmountFor($followUpItem)),
            'item_invoice_balance_amount' => $this->money($this->totalInvoiceBalanceFor($followUpItem)),
        ]);

        return response()->json([
            'message' => $invoiceStatus === 'paid' ? 'Invoice paid in full.' : 'Payment recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], 201);
    }

    public function markPaymentPlanFollowUpPaid(Request $request, FollowUpItem $followUpItem, PaymentPlanFollowUp $paymentPlanFollowUp): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureFollowUpItemOpen($followUpItem);
        $paymentPlanFollowUp = $this->paymentPlanFollowUpForItem($followUpItem, $paymentPlanFollowUp);
        $followUpItem->loadMissing('invoice');

        if ($followUpItem->invoice) {
            $this->ensureInvoiceAcceptsPayment($followUpItem->invoice);
        }

        $validated = $request->validate([
            'paid_at' => ['nullable', 'date'],
            'paid_amount' => ['nullable', 'numeric', 'min:0.001'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'attachment_file' => ['nullable', 'file', 'max:10240'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
            'attachment_remarks' => ['nullable', 'string'],
        ]);

        $paidAt = empty($validated['paid_at']) ? now() : Carbon::parse($validated['paid_at']);
        $paidAmount = array_key_exists('paid_amount', $validated) && $validated['paid_amount'] !== null
            ? round((float) $validated['paid_amount'], 3)
            : round((float) ($paymentPlanFollowUp->expected_amount ?? 0), 3);

        if ($paidAmount <= 0) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Enter the amount paid for this payment plan line.',
            ]);
        }

        $this->ensurePaymentPlanAmountFitsInvoiceBalance($paymentPlanFollowUp, $paidAmount);

        $paymentPlanFollowUp->forceFill([
            'status' => 'paid',
            'paid_at' => $paidAt,
            'paid_amount' => $paidAmount,
            'payment_reference' => $validated['payment_reference'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'recorded_by' => $request->user()->id,
        ])->save();

        $attachments = $this->storePaymentPlanAttachmentsFromRequest($request, $paymentPlanFollowUp);
        $this->syncPaymentPlanPayment($paymentPlanFollowUp, $request);

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'payment_plan.paid', 'Payment plan instalment marked paid.', [
            'payment_plan_follow_up_id' => $paymentPlanFollowUp->id,
            'quotation_payment_schedule_id' => $paymentPlanFollowUp->quotation_payment_schedule_id,
            'paid_at' => $paidAt->toDateTimeString(),
            'paid_amount' => $this->money($paidAmount),
            'currency' => $paymentPlanFollowUp->currency,
            'payment_reference' => $validated['payment_reference'] ?? null,
            'attachments' => $attachments->pluck('original_file_name')->values()->all(),
        ]);

        return response()->json([
            'message' => 'Payment plan instalment marked as paid.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storePaymentPlanFollowUpAttachment(Request $request, FollowUpItem $followUpItem, PaymentPlanFollowUp $paymentPlanFollowUp): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $paymentPlanFollowUp = $this->paymentPlanFollowUpForItem($followUpItem, $paymentPlanFollowUp);

        $request->validate([
            'attachment_file' => ['required', 'file', 'max:10240'],
            'attachment_remarks' => ['nullable', 'string'],
        ]);

        $attachments = $this->storePaymentPlanAttachmentsFromRequest($request, $paymentPlanFollowUp);

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'payment_plan.attachment_uploaded', 'Payment plan document uploaded.', [
            'payment_plan_follow_up_id' => $paymentPlanFollowUp->id,
            'attachments' => $attachments->pluck('original_file_name')->values()->all(),
        ]);

        return response()->json([
            'message' => 'Payment plan document uploaded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], 201);
    }

    public function downloadPaymentPlanFollowUpAttachment(
        Request $request,
        FollowUpItem $followUpItem,
        PaymentPlanFollowUp $paymentPlanFollowUp,
        PaymentPlanFollowUpAttachment $paymentPlanFollowUpAttachment,
        PrivateUploadDownloadService $downloads,
    ): BinaryFileResponse
    {
        $this->authorizeFollowUpEvidence($request, $followUpItem);
        $paymentPlanFollowUp = $this->paymentPlanFollowUpForItem($followUpItem, $paymentPlanFollowUp);

        if ((int) $paymentPlanFollowUpAttachment->payment_plan_follow_up_id !== (int) $paymentPlanFollowUp->id) {
            abort(404);
        }

        return $downloads->download(
            $paymentPlanFollowUpAttachment->file_path,
            $paymentPlanFollowUpAttachment->original_file_name,
            "follow-up/{$followUpItem->id}/payment-plan/{$paymentPlanFollowUp->id}",
        );
    }

    public function closeFollowUpItem(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $followUpItem->loadMissing('invoices.payments');

        if ($followUpItem->invoices->isEmpty()) {
            throw ValidationException::withMessages([
                'invoice' => 'Create the invoice before closing the job.',
            ]);
        }

        if ($this->hasQuantityTrackingFor($followUpItem)
            && ($this->remainingReceiveQuantity($followUpItem) > 0.0005
                || $this->remainingDeliveryQuantity($followUpItem) > 0.0005
                || $this->remainingInvoiceableQuantity($followUpItem) > 0.0005)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'All quantities must be received, delivered, and invoiced before the job can be closed.',
            ]);
        }

        if ($this->totalInvoiceBalanceFor($followUpItem) > 0.0005) {
            throw ValidationException::withMessages([
                'payment' => 'All invoices must be fully paid before the job can be closed.',
            ]);
        }

        $validated = $request->validate([
            'closed_notes' => ['nullable', 'string'],
        ]);

        foreach ($followUpItem->invoices as $invoice) {
            $invoice->forceFill([
                'status' => 'closed',
                'closed_at' => now(),
            ])->save();
        }

        $followUpItem->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_notes' => $validated['closed_notes'] ?? null,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'job.closed', 'Job closed.', [
            'invoice_ids' => $followUpItem->invoices->pluck('id')->values()->all(),
            'closed_notes' => $validated['closed_notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Job closed.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function assign(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $user = $request->user();

        if (! $user?->hasRole('admin')) {
            abort(403);
        }

        $validated = $request->validate([
            'assigned_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
        ]);

        $assignee = User::query()
            ->where('id', $validated['assigned_to'])
            ->whereHas('roles', fn (Builder $query) => $query->where('slug', 'follow-up'))
            ->first();

        if (! $assignee) {
            throw ValidationException::withMessages([
                'assigned_to' => 'The selected user must have the follow-up role.',
            ]);
        }

        $followUpItem->forceFill(['assigned_to' => $assignee->id])->save();

        $this->logFollowUpAudit($followUpItem, $request, $this->workflowStageFor($followUpItem), 'follow_up.assigned', 'Follow-up item assigned.', [
            'assigned_to' => $assignee->id,
            'assigned_to_name' => $assignee->name,
        ]);

        return response()->json([
            'message' => 'Follow-up item assigned.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    private function authorizeFollowUp(Request $request): void
    {
        $user = $request->user();

        if ($user?->hasRole('admin') || $user?->hasRole('follow-up') || $user?->hasPermission('manage-follow-ups')) {
            return;
        }

        abort($user ? 403 : 401);
    }

    private function authorizeFollowUpItem(Request $request, FollowUpItem $followUpItem): void
    {
        $this->authorizeFollowUp($request);

        $user = $request->user();

        if ($user?->hasRole('admin') || $followUpItem->assigned_to === $user?->id) {
            return;
        }

        abort(403);
    }

    /**
     * @param  Collection<int, FollowUpItem>  $items
     */
    private function authorizeFollowUpItems(Request $request, Collection $items): void
    {
        $this->authorizeFollowUp($request);

        $user = $request->user();

        if ($user?->hasRole('admin')) {
            return;
        }

        if ($items->every(fn (FollowUpItem $item): bool => (int) $item->assigned_to === (int) $user?->id)) {
            return;
        }

        abort(403);
    }

    private function authorizeFollowUpEvidence(Request $request, FollowUpItem $followUpItem): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin') || (int) $followUpItem->assigned_to === (int) $user->id) {
            return;
        }

        $followUpItem->loadMissing('quotation');

        if (
            (int) $followUpItem->quotation?->salesperson_id === (int) $user->id
            && ($user->hasRole('salesperson') || $user->hasPermission('create-quotations'))
        ) {
            return;
        }

        abort(403);
    }

    private function visibleItemsQuery(Request $request): Builder
    {
        $query = FollowUpItem::query();

        if (! $request->user()?->hasRole('admin')) {
            $query->where('assigned_to', $request->user()?->id);
        }

        return $query;
    }

    /**
     * @return array{group_by: string, search: string, action: string, stage: string}
     */
    private function followUpFilters(Request $request): array
    {
        $groupBy = (string) $request->query('group_by', 'action');
        $action = (string) $request->query('action', 'all');
        $stage = (string) $request->query('stage', 'all');

        return [
            'group_by' => array_key_exists($groupBy, self::GROUP_BY_OPTIONS) ? $groupBy : 'action',
            'search' => Str::limit(trim((string) $request->query('search', '')), 120, ''),
            'action' => $action === 'all' || array_key_exists($action, self::ACTION_GROUP_LABELS) ? $action : 'all',
            'stage' => $stage === 'all' || array_key_exists($stage, self::STAGE_FILTER_LABELS) ? $stage : 'all',
        ];
    }

    /**
     * @param  array{group_by: string, search: string, action: string, stage: string}  $filters
     */
    private function applyFollowUpFilters(Builder $query, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $this->applyFollowUpSearch($query, $filters['search']);
        }

        if ($filters['stage'] !== 'all') {
            $query->whereIn('status', self::STAGE_STATUS_BUCKETS[$filters['stage']] ?? []);
        }

        if ($filters['action'] !== 'all') {
            $this->applyActionFilter($query, $filters['action']);
        }

        return $query;
    }

    private function applyFollowUpSearch(Builder $query, string $search): void
    {
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

        $query->where(function (Builder $searchQuery) use ($like): void {
            $searchQuery
                ->where('status', 'like', $like)
                ->orWhereHas('supplierPo', fn (Builder $relation): Builder => $relation->where('po_reference', 'like', $like))
                ->orWhereHas('supplierPo.supplierCompany', fn (Builder $relation): Builder => $relation->where('name', 'like', $like))
                ->orWhereHas('quotation', fn (Builder $relation): Builder => $relation->where('quotation_reference', 'like', $like))
                ->orWhereHas('quotation.buyerCompany', fn (Builder $relation): Builder => $relation->where('name', 'like', $like))
                ->orWhereHas('buyerPo', fn (Builder $relation): Builder => $relation->where('po_number', 'like', $like))
                ->orWhereHas('supplierPoLine', function (Builder $relation) use ($like): Builder {
                    return $relation
                        ->where('product_name', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('item_description', 'like', $like);
                })
                ->orWhereHas('supplierPoLine.manufacturer', fn (Builder $relation): Builder => $relation->where('name', 'like', $like))
                ->orWhereHas('quotationItem', function (Builder $relation) use ($like): Builder {
                    return $relation
                        ->where('product_name', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('buyer_description', 'like', $like)
                        ->orWhere('manufacturer_description', 'like', $like);
                })
                ->orWhereHas('quotationItem.manufacturer', fn (Builder $relation): Builder => $relation->where('name', 'like', $like))
                ->orWhereHas('assignee', fn (Builder $relation): Builder => $relation->where('name', 'like', $like));
        });
    }

    private function applyActionFilter(Builder $query, string $action): void
    {
        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();
        $nextSevenDaysEnd = now()->copy()->addDays(7)->endOfDay();

        match ($action) {
            'overdue' => $query
                ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $todayStart),
            'due_today' => $query
                ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
                ->whereBetween('next_follow_up_at', [$todayStart, $todayEnd]),
            'due_next_7_days' => $query
                ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
                ->where('next_follow_up_at', '>', $todayEnd)
                ->where('next_follow_up_at', '<=', $nextSevenDaysEnd),
            'awaiting_acknowledgement' => $query->where('status', 'awaiting_acknowledgement'),
            'shipping_documents_pending' => $query->where('status', 'acknowledged'),
            'eta_logistics_pending' => $query->whereIn('status', ['shipping_documents_complete', 'logistics_eta_recorded', 'documents_sent_to_agent', 'documents_sent_to_buyer_agent']),
            'ready_for_delivery_order' => $query->whereIn('status', ['arrived', 'partially_received', 'ready_for_delivery_order', 'delivery_order_created', 'partially_delivered']),
            'ready_for_invoice' => $query->whereIn('status', ['ready_for_invoice', 'partially_invoiced']),
            'payment_follow_up' => $query->whereIn('status', ['invoice_created', 'invoice_sent', 'payment_pending', 'partially_paid', 'paid']),
            'closed' => $query->where('status', 'closed'),
            default => null,
        };
    }

    /**
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    private function followUpFilterOptions(): array
    {
        return [
            'group_by' => collect(self::GROUP_BY_OPTIONS)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values()
                ->all(),
            'action' => collect(['all' => 'All Actions'] + self::ACTION_GROUP_LABELS)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values()
                ->all(),
            'stage' => collect(['all' => 'All Stages'] + self::STAGE_FILTER_LABELS)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, FollowUpItem>  $items
     * @param  Collection<int, array<string, mixed>>  $transformedItems
     * @return array<int, array<string, mixed>>
     */
    private function groupsFor($items, $transformedItems, string $groupBy): array
    {
        $rows = $items
            ->values()
            ->map(function (FollowUpItem $item, int $index) use ($groupBy, $transformedItems): array {
                $data = $transformedItems[$index];
                $group = $this->groupDescriptorFor($item, $data, $groupBy);

                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'sort' => $group['sort'],
                    'data' => $data,
                    'due_state' => $this->dueStateForItem($item),
                    'next_follow_up_at' => $item->next_follow_up_at,
                ];
            });

        return $rows
            ->groupBy('key')
            ->map(function ($groupRows): array {
                $first = $groupRows->first();
                $nextFollowUpDates = $groupRows
                    ->pluck('next_follow_up_at')
                    ->filter()
                    ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
                    ->values();

                return [
                    'key' => $first['key'],
                    'label' => $first['label'],
                    'count' => $groupRows->count(),
                    'overdue_count' => $groupRows->where('due_state', 'overdue')->count(),
                    'due_today_count' => $groupRows->where('due_state', 'due_today')->count(),
                    'oldest_next_follow_up_at' => $nextFollowUpDates->first()?->toDateTimeString(),
                    'items' => $groupRows->pluck('data')->values()->all(),
                    '_sort' => $first['sort'],
                ];
            })
            ->sortBy(fn (array $group): string => is_int($group['_sort'])
                ? str_pad((string) $group['_sort'], 4, '0', STR_PAD_LEFT).'-'.$group['label']
                : '9000-'.$group['label'])
            ->values()
            ->map(function (array $group): array {
                unset($group['_sort']);

                return $group;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{key: string, label: string, sort: int|string}
     */
    private function groupDescriptorFor(FollowUpItem $item, array $data, string $groupBy): array
    {
        if ($groupBy === 'action') {
            $key = $this->actionGroupFor($item);

            return [
                'key' => $key,
                'label' => self::ACTION_GROUP_LABELS[$key],
                'sort' => array_search($key, array_keys(self::ACTION_GROUP_LABELS), true),
            ];
        }

        $label = match ($groupBy) {
            'job' => $data['quotation_reference'] ?? 'No Quotation',
            'buyer' => $data['buyer_company_name'] ?? 'No Buyer',
            'supplier_po' => $data['supplier_po_reference'] ?? 'No Supplier PO',
            'buyer_po' => $data['buyer_po_number'] ?? 'No Buyer PO',
            'manufacturer' => $data['manufacturer_name'] ?? 'No Manufacturer',
            'stage' => $data['current_stage_label'] ?? 'No Stage',
            default => 'Other',
        };

        return [
            'key' => Str::slug($groupBy.'-'.$label),
            'label' => $label,
            'sort' => $label,
        ];
    }

    private function actionGroupFor(FollowUpItem $item): string
    {
        $dueState = $this->dueStateForItem($item);

        if ($dueState !== 'none') {
            return $dueState;
        }

        return match ($item->status) {
            'awaiting_acknowledgement' => 'awaiting_acknowledgement',
            'acknowledged' => 'shipping_documents_pending',
            'shipping_documents_complete',
            'logistics_eta_recorded',
            'documents_sent_to_agent',
            'documents_sent_to_buyer_agent' => 'eta_logistics_pending',
            'arrived',
            'partially_received',
            'ready_for_delivery_order',
            'partially_delivered',
            'delivery_order_created' => 'ready_for_delivery_order',
            'ready_for_invoice',
            'partially_invoiced' => 'ready_for_invoice',
            'invoice_created',
            'invoice_sent',
            'payment_pending',
            'partially_paid',
            'paid' => 'payment_follow_up',
            'closed' => 'closed',
            default => 'other',
        };
    }

    private function dueStateForItem(FollowUpItem $item): string
    {
        if (! $item->next_follow_up_at || in_array($item->status, self::CLOSED_FOLLOW_UP_STATUSES, true)) {
            return 'none';
        }

        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();
        $nextSevenDaysEnd = now()->copy()->addDays(7)->endOfDay();

        if ($item->next_follow_up_at->lt($todayStart)) {
            return 'overdue';
        }

        if ($item->next_follow_up_at->betweenIncluded($todayStart, $todayEnd)) {
            return 'due_today';
        }

        if ($item->next_follow_up_at->gt($todayEnd) && $item->next_follow_up_at->lte($nextSevenDaysEnd)) {
            return 'due_next_7_days';
        }

        return 'none';
    }

    private function canViewFullTimeline(Request $request): bool
    {
        return (bool) $request->user()?->hasRole('admin');
    }

    /**
     * @return array<string, mixed>
     */
    private function transformStoredFollowUpItem(Request $request, FollowUpItem $followUpItem, bool $refresh = true): array
    {
        $includeTimeline = $this->canViewFullTimeline($request);
        $item = $refresh ? $followUpItem->refresh() : $followUpItem;
        $this->paymentPlanFollowUps->ensureForItem($item);

        return $this->transformFollowUpItem($item->load($this->itemRelations($includeTimeline)), $includeTimeline);
    }

    /**
     * @return array<int, string>
     */
    private function itemRelations(bool $includeTimeline = false): array
    {
        $relations = [
            'supplierPo.supplierCompany',
            'supplierPo.supplierContact',
            'supplierPo.buyerCompany',
            'supplierPo.buyerContact',
            'supplierPo.creator',
            'supplierPo.incoterm',
            'supplierPoLine.manufacturer',
            'supplierPoLine.companyLocation.country',
            'supplierPoLine.companyLocation.manufacturer',
            'supplierPoLine.incoterm',
            'quotation.buyerCompany',
            'quotation.buyerContact',
            'quotation.incoterm',
            'quotation.paymentSchedules',
            'quotation.salesperson',
            'buyerPo',
            'quotationItem.manufacturer',
            'assignee',
            'acknowledger',
            'comments.user',
            'shippingDocuments.uploader',
            'packingList.items.buyerPo',
            'logisticsCase.creator',
            'logisticsCase.events.user',
            'deliveryOrder.items.buyerPo',
            'deliveryOrder.items.buyerPoItem',
            'deliveryOrders.items.buyerPo',
            'deliveryOrders.items.buyerPoItem',
            'deliveryOrders.creator',
            'invoice.items.buyerPo',
            'invoice.items.buyerPoItem',
            'invoice.deliveryOrder',
            'invoice.payments.recorder',
            'invoices.items.buyerPo',
            'invoices.items.buyerPoItem',
            'invoices.deliveryOrder',
            'invoices.payments.recorder',
            'invoices.creator',
            'itemFulfilment',
            'paymentPlanFollowUps.attachments.uploader',
            'paymentPlanFollowUps.invoice',
            'paymentPlanFollowUps.quotationPaymentSchedule',
            'paymentPlanFollowUps.recorder',
        ];

        if ($includeTimeline) {
            $relations[] = 'quotation.activityLogs.user';
            $relations[] = 'auditLogs.user';
        }

        return $relations;
    }

    /**
     * @param  Collection<int, FollowUpItem>  $items
     * @return array<string, mixed>
     */
    private function transformQuotationSummary(Collection $items): array
    {
        /** @var FollowUpItem $first */
        $first = $items->first();
        $groups = $this->quotationGroupsFor($items, false);

        return [
            'quotation_id' => $first->quotation_id,
            'quotation_reference' => $first->quotation?->quotation_reference,
            'buyer_company_name' => $first->quotation?->buyerCompany?->name,
            'buyer_contact_name' => $first->quotation?->buyerContact?->name,
            'buyer_po_numbers' => $items->pluck('buyerPo.po_number')->filter()->unique()->values()->all(),
            'supplier_po_references' => $items->pluck('supplierPo.po_reference')->filter()->unique()->values()->all(),
            'manufacturers' => $items->map(fn (FollowUpItem $item): ?string => $item->supplierPoLine?->manufacturer?->name ?? $item->quotationItem?->manufacturer?->name)->filter()->unique()->values()->all(),
            'follow_up_items_count' => $items->count(),
            'open_groups_count' => $groups->count(),
            'ready_for_invoice_count' => $items->where('status', 'ready_for_invoice')->count(),
            'invoiced_count' => $items->filter(fn (FollowUpItem $item): bool => (bool) $item->invoice)->count(),
            'oldest_next_follow_up_at' => $items->pluck('next_follow_up_at')->filter()->sort()->first()?->toDateTimeString(),
            'latest_follow_up_item_id' => (int) $items->max('id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformQuotationWorkspace(Request $request, Quotation $quotation): array
    {
        $quotation->load([
            'buyerCompany',
            'buyerContact',
            'supplierCompany',
            'supplierContact',
            'salesperson',
            'incoterm',
            'paymentSchedules',
            'terms',
            'buyerPos',
        ]);

        $items = $this->visibleItemsQuery($request)
            ->where('quotation_id', $quotation->id)
            ->with($this->itemRelations($this->canViewFullTimeline($request)))
            ->orderBy('quotation_item_id')
            ->get();

        if ($items->isEmpty()) {
            abort(404);
        }

        $transformedItems = $items
            ->map(fn (FollowUpItem $item): array => $this->transformFollowUpItem($item, $this->canViewFullTimeline($request)))
            ->values();

        return [
            'quotation' => $this->transformQuotationHeader($quotation),
            'buyer_pos' => $quotation->buyerPos->map(fn ($buyerPo): array => [
                'id' => $buyerPo->id,
                'po_number' => $buyerPo->po_number,
                'po_date' => $buyerPo->po_date?->toDateString(),
                'po_value' => $this->money($buyerPo->po_value),
                'currency' => $buyerPo->currency,
                'status' => $buyerPo->status,
            ])->values(),
            'supplier_pos' => $items
                ->map(fn (FollowUpItem $item): ?array => $item->supplierPo ? [
                    'id' => $item->supplierPo->id,
                    'po_reference' => $item->supplierPo->po_reference,
                    'supplier_company_name' => $item->supplierPo->supplierCompany?->name,
                ] : null)
                ->filter()
                ->unique('id')
                ->values(),
            'terms' => $quotation->terms->map(fn ($term): array => [
                'id' => $term->id,
                'key' => $term->key,
                'title' => $term->title,
                'description' => $term->description,
            ])->values(),
            'items' => $transformedItems,
            'groups' => $this->quotationGroupsFor($items, true)->values(),
            'invoice_scope' => $this->invoiceScopeFor($items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformQuotationHeader(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'quotation_reference' => $quotation->quotation_reference,
            'buyer_company_name' => $quotation->buyerCompany?->name,
            'buyer_contact_name' => $quotation->buyerContact?->name,
            'supplier_company_name' => $quotation->supplierCompany?->name,
            'supplier_contact_name' => $quotation->supplierContact?->name,
            'salesperson_name' => $quotation->salesperson?->name,
            'rfq_number' => $quotation->rfq_number,
            'pr_number' => $quotation->pr_number,
            'rfq_title' => $quotation->rfq_title,
            'closing_at' => $quotation->closing_at?->toDateTimeString(),
            'payment_term_days' => $quotation->payment_term_days,
            'payment_terms_extra' => $quotation->payment_terms_extra,
            'payment_customer_type' => $quotation->payment_customer_type ?? 'credit',
            'payment_customer_type_label' => $this->paymentCustomerTypeLabel($quotation->payment_customer_type ?? 'credit'),
            'payment_schedule_summary' => $this->paymentScheduleSummary($quotation),
            'payment_schedules' => $quotation->paymentSchedules
                ->map(fn (QuotationPaymentSchedule $schedule): array => $this->transformPaymentSchedule($schedule))
                ->values(),
            'delivery_period_min' => $quotation->delivery_period_min,
            'delivery_period_max' => $quotation->delivery_period_max,
            'delivery_period_unit' => $quotation->delivery_period_unit,
            'delivery_period_type' => $quotation->delivery_period_type,
            'accepted_invoice_currency' => $quotation->accepted_invoice_currency,
            'incoterm_code' => $quotation->incoterm?->code,
            'incoterm_name' => $quotation->incoterm?->name,
            'delivery_responsibility' => $quotation->delivery_responsibility,
            'status' => $quotation->status,
        ];
    }

    /**
     * @param  Collection<int, FollowUpItem>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function quotationGroupsFor(Collection $items, bool $includeItems): Collection
    {
        return $items
            ->groupBy(fn (FollowUpItem $item): string => $item->follow_up_group_key ?: 'item-'.$item->id)
            ->map(function (Collection $groupItems, string $groupKey) use ($includeItems): array {
                /** @var FollowUpItem $first */
                $first = $groupItems->first();
                $isPersistedGroup = filled($first->follow_up_group_key);
                $name = $isPersistedGroup
                    ? $first->follow_up_group_name
                    : ($first->supplierPoLine?->title ?? $first->quotationItem?->title ?? 'Individual item');

                return [
                    'group_key' => $groupKey,
                    'group_name' => $name,
                    'workflow_mode' => $first->follow_up_group_mode ?: 'individual',
                    'is_persisted_group' => $isPersistedGroup,
                    'item_count' => $groupItems->count(),
                    'status_labels' => $groupItems->map(fn (FollowUpItem $item): string => $this->statusLabel($item->status))->unique()->values()->all(),
                    'stage_labels' => $groupItems->map(fn (FollowUpItem $item): string => self::COMMENT_STAGES[$this->workflowStageFor($item)])->unique()->values()->all(),
                    'manufacturer_names' => $groupItems->map(fn (FollowUpItem $item): ?string => $item->supplierPoLine?->manufacturer?->name ?? $item->quotationItem?->manufacturer?->name)->filter()->unique()->values()->all(),
                    'factory_names' => $groupItems
                        ->map(fn (FollowUpItem $item): ?string => $this->factoryLabel($item->supplierPoLine?->companyLocation))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'supplier_po_references' => $groupItems->pluck('supplierPo.po_reference')->filter()->unique()->values()->all(),
                    'next_follow_up_at' => $groupItems->pluck('next_follow_up_at')->filter()->sort()->first()?->toDateTimeString(),
                    'items' => $includeItems
                        ? $groupItems->map(fn (FollowUpItem $item): array => [
                            'id' => $item->id,
                            'quotation_item_id' => $item->quotation_item_id,
                            'product_code' => $item->supplierPoLine?->product_code ?? $item->quotationItem?->product_code,
                            'title' => $item->supplierPoLine?->title ?? $item->quotationItem?->title,
                            'manufacturer_name' => $item->supplierPoLine?->manufacturer?->name ?? $item->quotationItem?->manufacturer?->name,
                            'factory_name' => $item->supplierPoLine?->companyLocation?->name,
                            'factory_location' => $item->supplierPoLine?->companyLocation?->location,
                            'status' => $item->status,
                            'status_label' => $this->statusLabel($item->status),
                            'current_stage_label' => self::COMMENT_STAGES[$this->workflowStageFor($item)],
                            'supplier_po_reference' => $item->supplierPo?->po_reference,
                            'eta_at' => $item->logisticsCase?->eta_at?->toDateTimeString(),
                        ])->values()
                        : [],
                ];
            })
            ->sortBy([
                ['is_persisted_group', 'desc'],
                ['group_name', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, FollowUpItem>  $items
     * @return array<string, mixed>
     */
    private function invoiceScopeFor(Collection $items): array
    {
        $readyStatuses = ['ready_for_invoice', 'invoice_created', 'invoice_sent', 'payment_pending', 'partially_paid', 'paid', 'closed'];

        return [
            'quotation_total_items' => $items->count(),
            'ready_for_invoice_items' => $items->filter(fn (FollowUpItem $item): bool => in_array($item->status, $readyStatuses, true))->count(),
            'invoiced_items' => $items->filter(fn (FollowUpItem $item): bool => (bool) $item->invoice)->count(),
            'open_invoice_groups' => $items
                ->filter(fn (FollowUpItem $item): bool => in_array($item->status, $readyStatuses, true) && ! $item->invoice)
                ->groupBy(fn (FollowUpItem $item): string => $item->follow_up_group_key ?: 'item-'.$item->id)
                ->count(),
            'supports_partial_invoices' => true,
            'supports_full_quotation_invoice' => false,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summaryFor(Builder $query): array
    {
        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();

        return [
            'total' => (clone $query)->count(),
            'awaiting_acknowledgement' => (clone $query)->where('status', 'awaiting_acknowledgement')->count(),
            'acknowledged' => (clone $query)->where('status', 'acknowledged')->count(),
            'due_today' => (clone $query)
                ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
                ->whereBetween('next_follow_up_at', [$todayStart, $todayEnd])
                ->count(),
            'overdue' => (clone $query)
                ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
                ->whereNotNull('next_follow_up_at')
                ->where('next_follow_up_at', '<', $todayStart)
                ->count(),
            'upcoming' => (clone $query)
                ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
                ->where('next_follow_up_at', '>', $todayEnd)
                ->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dueRemindersFor(Builder $query, bool $includeTimeline): array
    {
        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();

        return (clone $query)
            ->whereNotIn('status', self::CLOSED_FOLLOW_UP_STATUSES)
            ->whereNotNull('next_follow_up_at')
            ->where('next_follow_up_at', '<=', $todayEnd)
            ->with($this->itemRelations($includeTimeline))
            ->orderBy('next_follow_up_at')
            ->limit(25)
            ->get()
            ->map(function (FollowUpItem $item) use ($includeTimeline, $todayStart): array {
                $data = $this->transformFollowUpItem($item, $includeTimeline);
                $data['due_state'] = $item->next_follow_up_at && $item->next_follow_up_at->lt($todayStart)
                    ? 'overdue'
                    : 'due_today';
                $data['days_overdue'] = $item->next_follow_up_at && $item->next_follow_up_at->lt($todayStart)
                    ? $item->next_follow_up_at->copy()->startOfDay()->diffInDays($todayStart)
                    : 0;

                return $data;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function nextFollowUpAt(array $validated): Carbon
    {
        $unit = (string) $validated['reminder_interval_unit'];

        if ($unit === 'custom') {
            if (empty($validated['next_follow_up_at'])) {
                throw ValidationException::withMessages([
                    'next_follow_up_at' => 'A custom follow-up date is required.',
                ]);
            }

            return Carbon::parse($validated['next_follow_up_at']);
        }

        if (empty($validated['reminder_interval_value'])) {
            throw ValidationException::withMessages([
                'reminder_interval_value' => 'The reminder interval value is required.',
            ]);
        }

        return $this->addInterval(now(), (int) $validated['reminder_interval_value'], $unit);
    }

    private function nextFollowUpFromSavedInterval(FollowUpItem $followUpItem): ?Carbon
    {
        if (
            ! $followUpItem->reminder_interval_value ||
            ! $followUpItem->reminder_interval_unit ||
            $followUpItem->reminder_interval_unit === 'custom'
        ) {
            return null;
        }

        return $this->addInterval(now(), $followUpItem->reminder_interval_value, $followUpItem->reminder_interval_unit);
    }

    private function addInterval(Carbon $date, int $value, string $unit): Carbon
    {
        return match ($unit) {
            'days' => $date->copy()->addDays($value),
            'weeks' => $date->copy()->addWeeks($value),
            'months' => $date->copy()->addMonthsNoOverflow($value),
            default => $date,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function bulkQuotationActionRules(string $action): array
    {
        return match ($action) {
            'reminder_update' => [
                'reminder_interval_value' => ['nullable', 'integer', 'min:1', 'max:3650'],
                'reminder_interval_unit' => ['required', Rule::in(self::REMINDER_UNITS)],
                'next_follow_up_at' => ['nullable', 'date'],
            ],
            'comment_add' => [
                'comment' => ['required', 'string'],
                'stage' => ['required', Rule::in(array_keys(self::COMMENT_STAGES))],
                'communication_type' => ['nullable', 'string', 'max:32'],
                'contacted_person' => ['nullable', 'string', 'max:255'],
                'next_action' => ['nullable', 'string'],
            ],
            'acknowledgement_record' => [
                'acknowledgement_received_at' => ['required', 'date'],
                'acknowledgement_notes' => ['nullable', 'string'],
            ],
            'eta_update' => [
                'delivery_responsibility' => ['required', Rule::in(self::DELIVERY_RESPONSIBILITIES)],
                'eta_at' => ['required', 'date'],
                'agent_name' => ['nullable', 'string', 'max:255'],
                'agent_contact' => ['nullable', 'string', 'max:255'],
                'remarks' => ['nullable', 'string'],
            ],
            'documents_sent' => [
                'documents_sent_at' => ['required', 'date'],
                'agent_name' => ['nullable', 'string', 'max:255'],
                'agent_contact' => ['nullable', 'string', 'max:255'],
                'remarks' => ['nullable', 'string'],
            ],
            'arrival_record' => [
                'arrived_at' => ['required', 'date'],
                'remarks' => ['nullable', 'string'],
            ],
            'warehouse_received' => [
                'warehouse_received_at' => ['required', 'date'],
                'received_location' => ['required', 'string', 'max:255'],
                'received_quantity' => ['nullable', 'numeric', 'min:0.001'],
                'goods_condition' => ['required', 'string', 'max:255'],
                'remarks' => ['nullable', 'string'],
            ],
            'buyer_received' => [
                'buyer_received_at' => ['required', 'date'],
                'received_quantity' => ['nullable', 'numeric', 'min:0.001'],
                'goods_condition' => ['required', 'string', 'max:255'],
                'remarks' => ['nullable', 'string'],
            ],
            default => [],
        };
    }

    private function bulkQuotationActionMessage(string $action, int $count): string
    {
        $itemLabel = $count === 1 ? 'item' : 'items';

        return match ($action) {
            'reminder_update' => "Reminder updated for {$count} {$itemLabel}.",
            'comment_add' => "Comment added to {$count} {$itemLabel}.",
            'acknowledgement_record' => "Order acknowledgement recorded for {$count} {$itemLabel}.",
            'shipping_documents_complete' => "Shipping documents completed for {$count} {$itemLabel}.",
            'eta_update' => "ETA updated for {$count} {$itemLabel}.",
            'documents_sent' => "Shipping document handoff recorded for {$count} {$itemLabel}.",
            'arrival_record' => "Arrival recorded for {$count} {$itemLabel}.",
            'warehouse_received' => "Warehouse receipt recorded for {$count} {$itemLabel}.",
            'buyer_received' => "Buyer receipt recorded for {$count} {$itemLabel}.",
            default => "Follow-up updated for {$count} {$itemLabel}.",
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyReminderUpdate(FollowUpItem $followUpItem, Request $request, array $validated): void
    {
        $nextFollowUpAt = $this->nextFollowUpAt($validated);

        $followUpItem->forceFill([
            'reminder_interval_value' => $validated['reminder_interval_unit'] === 'custom'
                ? null
                : (int) $validated['reminder_interval_value'],
            'reminder_interval_unit' => $validated['reminder_interval_unit'],
            'next_follow_up_at' => $nextFollowUpAt,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, $this->workflowStageFor($followUpItem), 'follow_up.reminder_updated', 'Follow-up reminder updated.', [
            'reminder_interval_value' => $validated['reminder_interval_unit'] === 'custom' ? null : (int) $validated['reminder_interval_value'],
            'reminder_interval_unit' => $validated['reminder_interval_unit'],
            'next_follow_up_at' => $nextFollowUpAt->toDateTimeString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyCommentAdd(FollowUpItem $followUpItem, Request $request, array $validated): void
    {
        FollowUpComment::query()->create([
            'follow_up_item_id' => $followUpItem->id,
            'user_id' => $request->user()->id,
            'comment' => trim((string) $validated['comment']),
            'stage' => $validated['stage'],
            'communication_type' => $validated['communication_type'] ?? null,
            'contacted_person' => $validated['contacted_person'] ?? null,
            'next_action' => $validated['next_action'] ?? null,
        ]);

        $this->logFollowUpAudit($followUpItem, $request, $validated['stage'], 'follow_up.comment_added', 'Stage comment added.', [
            'communication_type' => $validated['communication_type'] ?? null,
            'contacted_person' => $validated['contacted_person'] ?? null,
            'next_action' => $validated['next_action'] ?? null,
        ]);

        $updates = ['last_comment_at' => now()];
        $nextFollowUpAt = $this->nextFollowUpFromSavedInterval($followUpItem);

        if ($nextFollowUpAt) {
            $updates['next_follow_up_at'] = $nextFollowUpAt;
        }

        $followUpItem->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyAcknowledgementRecord(FollowUpItem $followUpItem, Request $request, array $validated): void
    {
        $this->ensureFollowUpItemOpen($followUpItem);

        $filePath = $followUpItem->acknowledgement_file_path;
        $originalName = $followUpItem->acknowledgement_original_file_name;

        if ($request->hasFile('acknowledgement_file')) {
            $file = $request->file('acknowledgement_file');
            $filePath = $file->store("follow-up/{$followUpItem->id}/acknowledgements", 'local');
            $originalName = $file->getClientOriginalName();

            $followUpItem->attachments()->create([
                'uploaded_by' => $request->user()->id,
                'document_type' => 'order_acknowledgement',
                'file_path' => $filePath,
                'original_file_name' => $originalName,
            ]);
        }

        $updates = [
            'acknowledgement_received_at' => Carbon::parse($validated['acknowledgement_received_at']),
            'acknowledgement_file_path' => $filePath,
            'acknowledgement_original_file_name' => $originalName,
            'acknowledgement_notes' => $validated['acknowledgement_notes'] ?? null,
            'acknowledged_by' => $request->user()->id,
        ];
        $statusAdvanced = false;

        if ($this->statusCanAdvance($followUpItem, 'acknowledged')) {
            $updates['status'] = 'acknowledged';
            $statusAdvanced = true;
        }

        $followUpItem->forceFill($updates)->save();

        if ($statusAdvanced) {
            $this->syncItemFulfilmentStatus($followUpItem, 'acknowledged');
        }

        $this->logFollowUpAudit($followUpItem, $request, 'acknowledgement', 'acknowledgement.recorded', 'Order acknowledgement recorded.', [
            'acknowledgement_received_at' => Carbon::parse($validated['acknowledgement_received_at'])->toDateTimeString(),
            'original_file_name' => $originalName,
            'notes' => $validated['acknowledgement_notes'] ?? null,
        ]);
    }

    private function applyShippingDocumentsComplete(FollowUpItem $followUpItem, Request $request): void
    {
        $this->ensureFollowUpItemOpen($followUpItem);
        $this->ensureShippingDocuments($followUpItem);

        if (! $this->shippingDocumentsComplete($followUpItem)) {
            throw ValidationException::withMessages([
                'shipping_documents' => 'Required shipping documents must be uploaded before moving to logistics.',
            ]);
        }

        if ($this->statusCanAdvance($followUpItem, 'shipping_documents_complete')) {
            $followUpItem->forceFill(['status' => 'shipping_documents_complete'])->save();
            $this->syncItemFulfilmentStatus($followUpItem, 'shipping_documents_complete');
        }

        $this->logFollowUpAudit($followUpItem, $request, 'shipping', 'shipping_documents.completed', 'Shipping documents completed.', [
            'required_documents' => array_keys(self::REQUIRED_SHIPPING_DOCUMENTS),
            'optional_documents' => array_keys(self::OPTIONAL_SHIPPING_DOCUMENTS),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyEtaUpdate(FollowUpItem $followUpItem, Request $request, array $validated): bool
    {
        $this->ensureFollowUpItemOpen($followUpItem);

        $etaAt = Carbon::parse($validated['eta_at']);
        $followUpItem->loadMissing(['itemFulfilment', 'supplierPoLine.incoterm', 'supplierPo.incoterm', 'quotation.incoterm']);
        $case = LogisticsCase::query()->firstOrNew(['follow_up_item_id' => $followUpItem->id]);
        $previousEtaAt = $case->exists ? $case->eta_at?->copy() : null;
        $isNewEta = $previousEtaAt === null;

        if (! $case->exists) {
            $case->created_by = $request->user()->id;
        }

        $caseStatus = $this->logisticsCaseStatusCanAdvance($case->status, 'eta_recorded')
            ? 'eta_recorded'
            : $case->status;

        $case->fill([
            'item_fulfilment_id' => $followUpItem->itemFulfilment?->id,
            'delivery_responsibility' => $validated['delivery_responsibility'],
            'status' => $caseStatus,
            'eta_at' => $etaAt,
            'agent_name' => array_key_exists('agent_name', $validated) ? $validated['agent_name'] : $case->agent_name,
            'agent_contact' => array_key_exists('agent_contact', $validated) ? $validated['agent_contact'] : $case->agent_contact,
            'remarks' => array_key_exists('remarks', $validated) ? $validated['remarks'] : $case->remarks,
        ])->save();

        $nextFollowUpAt = $this->nextFollowUpFromEta($followUpItem, $etaAt);
        $followUpUpdates = ['next_follow_up_at' => $nextFollowUpAt];
        $statusAdvanced = false;

        if (
            in_array($followUpItem->status, ['shipping_documents_complete', 'logistics_eta_recorded'], true)
            && $this->statusCanAdvance($followUpItem, 'logistics_eta_recorded')
        ) {
            $followUpUpdates['status'] = 'logistics_eta_recorded';
            $statusAdvanced = true;
        }

        $followUpItem->forceFill($followUpUpdates)->save();

        if ($statusAdvanced) {
            $this->syncItemFulfilmentStatus($followUpItem, 'logistics_eta_recorded');
        }

        $eventType = $isNewEta ? 'eta_recorded' : 'eta_updated';
        $eventTitle = $isNewEta ? 'ETA recorded' : 'ETA updated';
        $auditAction = $isNewEta ? 'logistics.eta_recorded' : 'logistics.eta_updated';
        $auditSummary = $isNewEta ? 'ETA recorded.' : 'ETA updated.';

        $this->appendLogisticsEvent($case, $request, $eventType, $eventTitle, $validated['remarks'] ?? null, [
            'eta_at' => $etaAt->toDateTimeString(),
            'previous_eta_at' => $previousEtaAt?->toDateTimeString(),
            'delivery_responsibility' => $validated['delivery_responsibility'],
            'next_follow_up_at' => $nextFollowUpAt->toDateTimeString(),
            'stage_advanced' => $statusAdvanced,
        ]);

        $this->logFollowUpAudit($followUpItem, $request, 'logistics', $auditAction, $auditSummary, [
            'eta_at' => $etaAt->toDateTimeString(),
            'previous_eta_at' => $previousEtaAt?->toDateTimeString(),
            'delivery_responsibility' => $validated['delivery_responsibility'],
            'agent_name' => $validated['agent_name'] ?? null,
            'agent_contact' => $validated['agent_contact'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'next_follow_up_at' => $nextFollowUpAt->toDateTimeString(),
            'stage_advanced' => $statusAdvanced,
        ]);

        return $isNewEta;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyDocumentsSent(FollowUpItem $followUpItem, Request $request, array $validated): void
    {
        $this->ensureFollowUpItemOpen($followUpItem);
        if (! $this->statusHasReached($followUpItem, 'logistics_eta_recorded')) {
            $this->requireCompletedShippingDocuments($followUpItem);
        }
        $case = $this->logisticsCaseOrFail($followUpItem);

        $documentsSentAt = Carbon::parse($validated['documents_sent_at']);
        $status = $case->delivery_responsibility === 'buyer_agent'
            ? 'documents_sent_to_buyer_agent'
            : 'documents_sent_to_agent';
        $caseStatus = $this->logisticsCaseStatusCanAdvance($case->status, $status)
            ? $status
            : $case->status;

        $case->forceFill([
            'status' => $caseStatus,
            'documents_sent_at' => $documentsSentAt,
            'agent_name' => $validated['agent_name'] ?? $case->agent_name,
            'agent_contact' => $validated['agent_contact'] ?? $case->agent_contact,
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, $status, 'Shipping documents sent to agent', $validated['remarks'] ?? null, [
            'documents_sent_at' => $documentsSentAt->toDateTimeString(),
        ], $documentsSentAt);

        $this->advanceFollowUpStatus($followUpItem, $status);

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'logistics.documents_sent', 'Shipping documents sent to agent.', [
            'documents_sent_at' => $documentsSentAt->toDateTimeString(),
            'delivery_responsibility' => $case->delivery_responsibility,
            'agent_name' => $validated['agent_name'] ?? $case->agent_name,
            'agent_contact' => $validated['agent_contact'] ?? $case->agent_contact,
            'remarks' => $validated['remarks'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyArrivalRecord(FollowUpItem $followUpItem, Request $request, array $validated): bool
    {
        $this->ensureFollowUpItemOpen($followUpItem);
        if (! $this->statusHasReached($followUpItem, 'logistics_eta_recorded')) {
            $this->requireCompletedShippingDocuments($followUpItem);
        }
        $case = $this->logisticsCaseOrFail($followUpItem);

        $arrivedAt = Carbon::parse($validated['arrived_at']);
        $isSupplierResponsibility = $case->delivery_responsibility === 'supplier';
        $caseStatus = $isSupplierResponsibility ? 'supplier_received' : 'arrived';
        $eventType = $isSupplierResponsibility ? 'supplier_received' : 'arrived';
        $eventTitle = $isSupplierResponsibility ? 'Supplier confirmed receipt' : 'Shipment arrived';
        $followUpStatus = $isSupplierResponsibility ? 'ready_for_invoice' : 'arrived';
        $auditAction = $isSupplierResponsibility ? 'delivery.supplier_received' : 'logistics.arrived';
        $auditSummary = $isSupplierResponsibility ? 'Supplier confirmed receipt.' : 'Shipment arrived.';

        $caseUpdates = [
            'status' => $this->logisticsCaseStatusCanAdvance($case->status, $caseStatus) ? $caseStatus : $case->status,
            'arrived_at' => $arrivedAt,
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ];

        if ($isSupplierResponsibility) {
            $caseUpdates['received_quantity'] = $this->supplierQuantityFor($followUpItem);
        }

        $case->forceFill($caseUpdates)->save();

        $this->appendLogisticsEvent($case, $request, $eventType, $eventTitle, $validated['remarks'] ?? null, [
            'arrived_at' => $arrivedAt->toDateTimeString(),
        ], $arrivedAt);

        $this->advanceFollowUpStatus($followUpItem, $followUpStatus);

        if ($isSupplierResponsibility) {
            $followUpItem->loadMissing('itemFulfilment');
            $quantity = $this->supplierQuantityFor($followUpItem);
            $followUpItem->itemFulfilment?->forceFill([
                'received_quantity' => max($this->receivedQuantityFor($followUpItem), $quantity),
                'delivered_quantity' => max($this->deliveredQuantityFor($followUpItem), $quantity),
                'status' => $followUpItem->refresh()->status,
            ])->save();
        }

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', $auditAction, $auditSummary, [
            'arrived_at' => $arrivedAt->toDateTimeString(),
            'delivery_responsibility' => $case->delivery_responsibility,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return $isSupplierResponsibility;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyWarehouseReceived(FollowUpItem $followUpItem, Request $request, array $validated): void
    {
        $this->ensureFollowUpItemOpen($followUpItem);
        if (! $this->statusHasReached($followUpItem, 'arrived')) {
            $this->requireCompletedShippingDocuments($followUpItem);
        }
        $case = $this->logisticsCaseOrFail($followUpItem);

        $followUpItem->loadMissing(['supplierPoLine', 'itemFulfilment']);
        $orderedQuantity = $this->supplierQuantityFor($followUpItem);

        if ($orderedQuantity <= 0) {
            throw ValidationException::withMessages([
                'received_quantity' => 'The ordered supplier PO quantity could not be verified for this follow-up item.',
            ]);
        }

        $receiptQuantity = $this->quantity($validated['received_quantity'] ?? $orderedQuantity);
        $currentReceivedQuantity = $this->receivedQuantityFor($followUpItem);
        $remainingReceiveQuantity = max($this->quantity($orderedQuantity - $currentReceivedQuantity), 0.0);

        if ($receiptQuantity > $remainingReceiveQuantity + 0.0005) {
            throw ValidationException::withMessages([
                'received_quantity' => 'Received quantity cannot exceed the remaining supplier PO quantity of '.$this->money($remainingReceiveQuantity).' '.$followUpItem->supplierPoLine?->uom.'.',
            ]);
        }

        $totalReceivedQuantity = $this->quantity($currentReceivedQuantity + $receiptQuantity);
        $caseStatus = $totalReceivedQuantity >= $orderedQuantity - 0.0005 ? 'warehouse_received' : 'warehouse_partially_received';
        $followUpStatus = $caseStatus === 'warehouse_received' ? 'ready_for_delivery_order' : 'partially_received';
        $receivedAt = Carbon::parse($validated['warehouse_received_at']);
        $case->forceFill([
            'status' => $this->logisticsCaseStatusCanAdvance($case->status, $caseStatus) ? $caseStatus : $case->status,
            'warehouse_received_at' => $receivedAt,
            'received_location' => $validated['received_location'],
            'received_quantity' => $totalReceivedQuantity,
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, $caseStatus, $caseStatus === 'warehouse_received' ? 'Goods received at ISC warehouse' : 'Partial goods received at ISC warehouse', $validated['remarks'] ?? null, [
            'warehouse_received_at' => $receivedAt->toDateTimeString(),
            'received_location' => $validated['received_location'],
            'receipt_quantity' => $this->money($receiptQuantity),
            'received_quantity' => $this->money($totalReceivedQuantity),
            'remaining_receive_quantity' => $this->money(max($orderedQuantity - $totalReceivedQuantity, 0)),
            'goods_condition' => $validated['goods_condition'],
        ], $receivedAt);

        $this->advanceFollowUpStatus($followUpItem, $followUpStatus);
        $followUpItem->itemFulfilment?->forceFill([
            'received_quantity' => $totalReceivedQuantity,
            'status' => $followUpItem->refresh()->status,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', $caseStatus === 'warehouse_received' ? 'delivery.warehouse_received' : 'delivery.warehouse_partially_received', $caseStatus === 'warehouse_received' ? 'Goods received at ISC warehouse.' : 'Partial goods received at ISC warehouse.', [
            'warehouse_received_at' => $receivedAt->toDateTimeString(),
            'received_location' => $validated['received_location'],
            'receipt_quantity' => $this->money($receiptQuantity),
            'received_quantity' => $this->money($totalReceivedQuantity),
            'remaining_receive_quantity' => $this->money(max($orderedQuantity - $totalReceivedQuantity, 0)),
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyBuyerReceived(FollowUpItem $followUpItem, Request $request, array $validated): void
    {
        $this->ensureFollowUpItemOpen($followUpItem);
        if (! $this->statusHasReached($followUpItem, 'documents_sent_to_buyer_agent')) {
            $this->requireCompletedShippingDocuments($followUpItem);
        }
        $case = $this->logisticsCaseOrFail($followUpItem);

        $followUpItem->loadMissing(['supplierPoLine', 'itemFulfilment']);
        $orderedQuantity = $this->supplierQuantityFor($followUpItem);
        $receiptQuantity = $this->quantity($validated['received_quantity'] ?? $orderedQuantity);
        $currentReceivedQuantity = $this->receivedQuantityFor($followUpItem);
        $remainingReceiveQuantity = max($this->quantity($orderedQuantity - $currentReceivedQuantity), 0.0);

        if ($orderedQuantity <= 0) {
            throw ValidationException::withMessages([
                'received_quantity' => 'The ordered supplier PO quantity could not be verified for this follow-up item.',
            ]);
        }

        if ($receiptQuantity > $remainingReceiveQuantity + 0.0005) {
            throw ValidationException::withMessages([
                'received_quantity' => 'Received quantity cannot exceed the remaining supplier PO quantity of '.$this->money($remainingReceiveQuantity).' '.$followUpItem->supplierPoLine?->uom.'.',
            ]);
        }

        $totalReceivedQuantity = $this->quantity($currentReceivedQuantity + $receiptQuantity);
        $caseStatus = $totalReceivedQuantity >= $orderedQuantity - 0.0005 ? 'buyer_received' : 'buyer_partially_received';
        $receivedAt = Carbon::parse($validated['buyer_received_at']);
        $case->forceFill([
            'status' => $this->logisticsCaseStatusCanAdvance($case->status, $caseStatus) ? $caseStatus : $case->status,
            'buyer_received_at' => $receivedAt,
            'received_quantity' => $totalReceivedQuantity,
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, $caseStatus, $caseStatus === 'buyer_received' ? 'Buyer confirmed goods received' : 'Buyer confirmed partial goods received', $validated['remarks'] ?? null, [
            'buyer_received_at' => $receivedAt->toDateTimeString(),
            'receipt_quantity' => $this->money($receiptQuantity),
            'received_quantity' => $this->money($totalReceivedQuantity),
            'remaining_receive_quantity' => $this->money(max($orderedQuantity - $totalReceivedQuantity, 0)),
            'goods_condition' => $validated['goods_condition'],
        ], $receivedAt);

        $this->advanceFollowUpStatus($followUpItem, 'ready_for_invoice');
        $followUpItem->itemFulfilment?->forceFill([
            'received_quantity' => $totalReceivedQuantity,
            'delivered_quantity' => $totalReceivedQuantity,
            'status' => $followUpItem->refresh()->status,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', $caseStatus === 'buyer_received' ? 'delivery.buyer_received' : 'delivery.buyer_partially_received', $caseStatus === 'buyer_received' ? 'Buyer confirmed goods received.' : 'Buyer confirmed partial goods received.', [
            'buyer_received_at' => $receivedAt->toDateTimeString(),
            'receipt_quantity' => $this->money($receiptQuantity),
            'received_quantity' => $this->money($totalReceivedQuantity),
            'remaining_receive_quantity' => $this->money(max($orderedQuantity - $totalReceivedQuantity, 0)),
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? null,
        ]);
    }

    private function nextFollowUpFromEta(FollowUpItem $followUpItem, Carbon $etaAt): Carbon
    {
        $followUpItem->loadMissing(['supplierPoLine.incoterm', 'supplierPo.incoterm', 'quotation.incoterm']);

        $daysBeforeEta = $followUpItem->supplierPoLine?->incoterm?->reminder_days_before_delivery
            ?? $followUpItem->supplierPo?->incoterm?->reminder_days_before_delivery
            ?? $followUpItem->quotation?->incoterm?->reminder_days_before_delivery
            ?? 7;

        return $etaAt->copy()->subDays(max(0, (int) $daysBeforeEta));
    }

    private function statusCanAdvance(FollowUpItem $followUpItem, string $targetStatus): bool
    {
        return (self::FOLLOW_UP_STATUS_ORDER[$targetStatus] ?? 0) >= (self::FOLLOW_UP_STATUS_ORDER[$followUpItem->status] ?? 0);
    }

    private function statusHasReached(FollowUpItem $followUpItem, string $targetStatus): bool
    {
        return (self::FOLLOW_UP_STATUS_ORDER[$followUpItem->status] ?? 0) >= (self::FOLLOW_UP_STATUS_ORDER[$targetStatus] ?? 0);
    }

    private function advanceFollowUpStatus(FollowUpItem $followUpItem, string $targetStatus): bool
    {
        if (! $this->statusCanAdvance($followUpItem, $targetStatus)) {
            return false;
        }

        $followUpItem->forceFill(['status' => $targetStatus])->save();
        $this->syncItemFulfilmentStatus($followUpItem, $targetStatus);

        return true;
    }

    private function syncItemFulfilmentStatus(FollowUpItem $followUpItem, string $status): void
    {
        $followUpItem->loadMissing('itemFulfilment');
        $followUpItem->itemFulfilment?->forceFill(['status' => $status])->save();
    }

    private function logisticsCaseStatusCanAdvance(?string $currentStatus, string $targetStatus): bool
    {
        return (self::LOGISTICS_CASE_STATUS_ORDER[$targetStatus] ?? 0) >= (self::LOGISTICS_CASE_STATUS_ORDER[$currentStatus ?? ''] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformFollowUpItem(FollowUpItem $item, bool $includeTimeline = false): array
    {
        $paymentPlanFollowUps = $this->paymentPlanFollowUps->ensureForItem($item);
        $line = $item->supplierPoLine;
        $factory = $line?->companyLocation;
        $latestComment = $item->comments->first();
        $comments = $item->comments
            ->map(fn (FollowUpComment $comment): array => $this->transformComment($comment))
            ->values();
        $commentsByStage = collect(array_keys(self::COMMENT_STAGES))
            ->mapWithKeys(fn (string $stage): array => [$stage => $comments->where('stage', $stage)->values()->all()])
            ->all();
        $currentStage = $this->workflowStageFor($item);

        $data = [
            'id' => $item->id,
            'supplier_po_line_id' => $item->supplier_po_line_id,
            'supplier_po_id' => $item->supplier_po_id,
            'supplier_po_reference' => $item->supplierPo?->po_reference,
            'quotation_id' => $item->quotation_id,
            'quotation_reference' => $item->quotation?->quotation_reference,
            'quotation_delivery_responsibility' => $item->quotation?->delivery_responsibility,
            'quotation_payment_term_days' => $item->quotation?->payment_term_days,
            'quotation_payment_customer_type' => $item->quotation?->payment_customer_type ?? 'credit',
            'quotation_payment_customer_type_label' => $this->paymentCustomerTypeLabel($item->quotation?->payment_customer_type ?? 'credit'),
            'quotation_payment_schedule_summary' => $item->quotation ? $this->paymentScheduleSummary($item->quotation) : null,
            'quotation_payment_schedules' => $item->quotation?->paymentSchedules
                ->map(fn (QuotationPaymentSchedule $schedule): array => $this->transformPaymentSchedule($schedule))
                ->values() ?? [],
            'payment_plan_follow_ups' => $paymentPlanFollowUps
                ->map(fn (PaymentPlanFollowUp $paymentPlanFollowUp): array => $this->transformPaymentPlanFollowUp($paymentPlanFollowUp))
                ->values(),
            'buyer_po_id' => $item->buyer_po_id,
            'buyer_po_number' => $item->buyerPo?->po_number,
            'buyer_po_date' => $item->buyerPo?->po_date?->toDateString(),
            'buyer_po_original_file_name' => $item->buyerPo?->original_file_name,
            'buyer_po_download_url' => $item->buyerPo?->po_file_path
                ? "/api/quotations/{$item->quotation_id}/buyer-po/{$item->buyer_po_id}/download"
                : null,
            'follow_up_group_key' => $item->follow_up_group_key,
            'follow_up_group_name' => $item->follow_up_group_name,
            'follow_up_group_mode' => $item->follow_up_group_mode ?: 'individual',
            'buyer_company_name' => $item->quotation?->buyerCompany?->name,
            'buyer_contact_name' => $item->quotation?->buyerContact?->name,
            'supplier_company_name' => $item->supplierPo?->supplierCompany?->name,
            'supplier_contact_name' => $item->supplierPo?->supplierContact?->name,
            'company_location_id' => $factory?->id,
            'factory_name' => $factory?->name,
            'factory_location' => $factory?->location,
            'factory_country_name' => $factory?->country?->name,
            'factory_manufacturer_name' => $factory?->manufacturer?->name,
            'salesperson_name' => $item->quotation?->salesperson?->name,
            'assigned_to' => $item->assigned_to,
            'assigned_to_name' => $item->assignee?->name,
            'status' => $item->status,
            'status_label' => $this->statusLabel($item->status),
            'current_stage' => $currentStage,
            'current_stage_label' => self::COMMENT_STAGES[$currentStage],
            'product_code' => $line?->product_code ?? $item->quotationItem?->product_code,
            'product_name' => $line?->product_name ?? $item->quotationItem?->product_name,
            'title' => $line?->title ?? $item->quotationItem?->title,
            'description' => $line?->item_description ?? $item->quotationItem?->manufacturer_description ?? $item->quotationItem?->buyer_description,
            'quantity' => $line ? $this->money($line->quantity) : $this->money($item->quotationItem?->quantity),
            'uom' => $line?->uom ?? $item->quotationItem?->uom,
            'quotation_item_vat_rate' => $this->money($item->quotationItem?->vat_rate),
            'manufacturer_name' => $line?->manufacturer?->name ?? $item->quotationItem?->manufacturer?->name,
            'reminder_interval_value' => $item->reminder_interval_value,
            'reminder_interval_unit' => $item->reminder_interval_unit,
            'next_follow_up_at' => $item->next_follow_up_at?->toDateTimeString(),
            'last_comment_at' => $item->last_comment_at?->toDateTimeString(),
            'acknowledgement_received_at' => $item->acknowledgement_received_at?->toDateTimeString(),
            'acknowledgement_original_file_name' => $item->acknowledgement_original_file_name,
            'acknowledgement_download_url' => $item->acknowledgement_file_path
                ? "/api/follow-up/{$item->id}/acknowledgement/download"
                : null,
            'acknowledgement_notes' => $item->acknowledgement_notes,
            'acknowledged_by_name' => $item->acknowledger?->name,
            'closed_at' => $item->closed_at?->toDateTimeString(),
            'closed_notes' => $item->closed_notes,
            'latest_comment' => $latestComment ? $this->transformComment($latestComment) : null,
            'comments' => $comments,
            'comments_by_stage' => $commentsByStage,
            'shipping_documents' => $this->shippingDocumentsFor($item)
                ->map(fn (ShippingDocument $document): array => $this->transformShippingDocument($document))
                ->values(),
            'shipping_documents_complete' => $this->shippingDocumentsComplete($item),
            'packing_list' => $item->packingList ? $this->transformPackingList($item->packingList) : null,
            'logistics_case' => $item->logisticsCase ? $this->transformLogisticsCase($item->logisticsCase) : null,
            'fulfilment' => $this->fulfilmentSummaryFor($item),
            'delivery_order' => $item->deliveryOrder ? $this->transformDeliveryOrder($item->deliveryOrder) : null,
            'delivery_orders' => $item->deliveryOrders
                ->map(fn (DeliveryOrder $deliveryOrder): array => $this->transformDeliveryOrder($deliveryOrder))
                ->values(),
            'invoice' => $item->invoice ? $this->transformInvoice($item->invoice) : null,
            'invoices' => $item->invoices
                ->map(fn (Invoice $invoice): array => $this->transformInvoice($invoice))
                ->values(),
        ];

        if ($includeTimeline) {
            $data['timeline_events'] = $this->timelineEventsFor($item);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformComment(FollowUpComment $comment): array
    {
        return [
            'id' => $comment->id,
            'stage' => $comment->stage,
            'stage_label' => self::COMMENT_STAGES[$comment->stage] ?? (string) Str::of($comment->stage)->replace('_', ' ')->title(),
            'comment' => $comment->comment,
            'communication_type' => $comment->communication_type,
            'contacted_person' => $comment->contacted_person,
            'next_action' => $comment->next_action,
            'created_by_name' => $comment->user?->name,
            'created_at' => $comment->created_at?->toDateTimeString(),
        ];
    }

    private function workflowStageFor(FollowUpItem $item): string
    {
        return match ($item->status) {
            'awaiting_acknowledgement' => 'acknowledgement',
            'acknowledged',
            'shipping_documents_complete' => 'shipping',
            'logistics_eta_recorded' => 'logistics',
            'documents_sent_to_buyer_agent',
            'documents_sent_to_agent',
            'arrived',
            'partially_received',
            'ready_for_delivery_order',
            'partially_delivered',
            'delivery_order_created' => 'delivery',
            'ready_for_invoice',
            'invoice_created',
            'partially_invoiced' => 'invoice',
            'invoice_sent',
            'payment_pending',
            'partially_paid',
            'paid',
            'closed' => 'payment',
            default => 'acknowledgement',
        };
    }

    private function statusLabel(string $status): string
    {
        return (string) Str::of($status)->replace('_', ' ')->title();
    }

    private function factoryLabel(?CompanyLocation $factory): ?string
    {
        if (! $factory) {
            return null;
        }

        return collect([$factory->name, $factory->location])
            ->filter()
            ->implode(' - ');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timelineEventsFor(FollowUpItem $item): array
    {
        $events = collect();

        if ($item->relationLoaded('quotation') && $item->quotation?->relationLoaded('activityLogs')) {
            foreach ($item->quotation->activityLogs as $log) {
                $events->push([
                    'id' => 'quotation-'.$log->id,
                    'source' => 'quotation',
                    'stage' => 'quotation',
                    'stage_label' => 'Quotation',
                    'action' => $log->action,
                    'summary' => $log->summary,
                    'user_name' => $log->user?->name,
                    'properties' => $log->properties,
                    '_occurred_at' => $log->created_at,
                ]);
            }
        }

        $auditLogs = $item->relationLoaded('auditLogs') ? $item->auditLogs : collect();

        foreach ($auditLogs as $log) {
            $events->push($this->transformAuditTimelineEvent($log));
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
    private function transformAuditTimelineEvent(FollowUpAuditLog $log): array
    {
        return [
            'id' => 'follow-up-'.$log->id,
            'source' => 'follow_up',
            'stage' => $log->stage,
            'stage_label' => self::COMMENT_STAGES[$log->stage] ?? (string) Str::of($log->stage)->replace('_', ' ')->title(),
            'action' => $log->action,
            'summary' => $log->summary,
            'user_name' => $log->user?->name,
            'properties' => $log->properties,
            '_occurred_at' => $log->occurred_at,
        ];
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

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logFollowUpAudit(FollowUpItem $followUpItem, Request $request, string $stage, string $action, string $summary, array $properties = []): void
    {
        FollowUpAuditLog::query()->create([
            'follow_up_item_id' => $followUpItem->id,
            'user_id' => $request->user()?->id,
            'stage' => $stage,
            'action' => $action,
            'summary' => $summary,
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    private function paymentPlanFollowUpForItem(FollowUpItem $followUpItem, PaymentPlanFollowUp $paymentPlanFollowUp): PaymentPlanFollowUp
    {
        $this->paymentPlanFollowUps->ensureForItem($followUpItem);

        if ((int) $paymentPlanFollowUp->follow_up_item_id !== (int) $followUpItem->id) {
            abort(404);
        }

        return $paymentPlanFollowUp->refresh()->load(['attachments.uploader', 'invoice', 'quotationPaymentSchedule', 'recorder']);
    }

    /**
     * @return Collection<int, PaymentPlanFollowUpAttachment>
     */
    private function storePaymentPlanAttachmentsFromRequest(Request $request, PaymentPlanFollowUp $paymentPlanFollowUp): Collection
    {
        $files = collect();

        if ($request->hasFile('attachment_file')) {
            $files->push($request->file('attachment_file'));
        }

        foreach ((array) $request->file('attachments', []) as $file) {
            if ($file) {
                $files->push($file);
            }
        }

        return $files
            ->filter()
            ->map(function ($file) use ($request, $paymentPlanFollowUp): PaymentPlanFollowUpAttachment {
                $filePath = $file->store("follow-up/{$paymentPlanFollowUp->follow_up_item_id}/payment-plan/{$paymentPlanFollowUp->id}", 'local');

                return $paymentPlanFollowUp->attachments()->create([
                    'uploaded_by' => $request->user()->id,
                    'file_path' => $filePath,
                    'original_file_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'remarks' => (string) $request->string('attachment_remarks')->trim() ?: null,
                ]);
            })
            ->values();
    }

    private function ensurePaymentPlanAmountFitsInvoiceBalance(PaymentPlanFollowUp $paymentPlanFollowUp, float $amount): void
    {
        $paymentPlanFollowUp->loadMissing('followUpItem.invoice.payments');
        $invoice = $paymentPlanFollowUp->followUpItem?->invoice;

        if (! $invoice) {
            return;
        }

        $existingPayment = Payment::query()
            ->where('payment_plan_follow_up_id', $paymentPlanFollowUp->id)
            ->first();
        $summary = $this->paymentSummaryFor($invoice);
        $availableBalance = round($summary['balance'] + ($existingPayment ? (float) $existingPayment->amount : 0), 3);

        if ($amount > $availableBalance + 0.0005) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Payment amount cannot exceed the remaining invoice balance.',
            ]);
        }
    }

    private function syncPaymentPlanPayment(PaymentPlanFollowUp $paymentPlanFollowUp, Request $request): void
    {
        $paymentPlanFollowUp->loadMissing('followUpItem.invoice.payments');
        $followUpItem = $paymentPlanFollowUp->followUpItem;
        $invoice = $followUpItem?->invoice;
        $amount = round((float) ($paymentPlanFollowUp->paid_amount ?? 0), 3);

        if (! $followUpItem || ! $invoice || $amount <= 0) {
            return;
        }

        $existingPayment = Payment::query()
            ->where('payment_plan_follow_up_id', $paymentPlanFollowUp->id)
            ->first();
        $summary = $this->paymentSummaryFor($invoice);
        $availableBalance = round($summary['balance'] + ($existingPayment ? (float) $existingPayment->amount : 0), 3);

        if ($amount > $availableBalance + 0.0005) {
            throw ValidationException::withMessages([
                'paid_amount' => 'Payment amount cannot exceed the remaining invoice balance.',
            ]);
        }

        $payment = $existingPayment ?? new Payment([
            'invoice_id' => $invoice->id,
            'follow_up_item_id' => $followUpItem->id,
            'payment_plan_follow_up_id' => $paymentPlanFollowUp->id,
        ]);

        $payment->fill([
            'invoice_id' => $invoice->id,
            'follow_up_item_id' => $followUpItem->id,
            'payment_plan_follow_up_id' => $paymentPlanFollowUp->id,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'payment_date' => ($paymentPlanFollowUp->paid_at ?? now())->toDateString(),
            'payment_reference' => $paymentPlanFollowUp->payment_reference,
            'remarks' => $paymentPlanFollowUp->remarks,
            'recorded_by' => $request->user()->id,
        ])->save();

        $this->refreshInvoiceStatusAfterPayments($followUpItem, $invoice);

        if ($invoice->refresh()->status === 'paid') {
            $this->markOutstandingPaymentPlanFollowUpsPaid(
                $followUpItem,
                $request,
                $paymentPlanFollowUp->paid_at ?? now(),
                $paymentPlanFollowUp->payment_reference,
                $paymentPlanFollowUp->remarks,
            );
        }
    }

    private function syncPaidPaymentPlanFollowUpsToInvoice(FollowUpItem $followUpItem, Request $request): void
    {
        $this->paymentPlanFollowUps->ensureForItem($followUpItem);
        $followUpItem->load('paymentPlanFollowUps', 'invoice.payments');

        if (! $followUpItem->invoice) {
            return;
        }

        foreach ($followUpItem->paymentPlanFollowUps->where('status', 'paid') as $paymentPlanFollowUp) {
            $hasLinkedPayment = Payment::query()
                ->where('payment_plan_follow_up_id', $paymentPlanFollowUp->id)
                ->exists();

            // A generic full-invoice payment can close all schedule reminders
            // without manufacturing duplicate per-instalment payments.
            if (! $hasLinkedPayment && $this->paymentSummaryFor($followUpItem->invoice)['balance'] <= 0.0005) {
                continue;
            }

            $this->syncPaymentPlanPayment($paymentPlanFollowUp, $request);
        }
    }

    private function refreshInvoiceStatusAfterPayments(FollowUpItem $followUpItem, Invoice $invoice): void
    {
        if ($invoice->status === 'closed' || $followUpItem->status === 'closed' || $followUpItem->closed_at !== null) {
            return;
        }

        $invoice->refresh()->load('payments');
        $summary = $this->paymentSummaryFor($invoice);
        $invoiceStatus = match (true) {
            $summary['balance'] <= 0.0005 => 'paid',
            $summary['paid'] > 0 => 'partially_paid',
            $invoice->sent_at !== null => 'sent',
            default => 'issued',
        };

        $invoice->forceFill(['status' => $invoiceStatus])->save();
        $followUpItem->unsetRelation('invoices');
        $followUpItem->loadMissing(['itemFulfilment', 'supplierPoLine', 'quotationItem', 'deliveryOrders.items', 'invoices.items', 'invoices.payments']);

        $followUpStatus = match ($invoiceStatus) {
            'paid' => $this->hasOpenFulfilmentWork($followUpItem) ? 'partially_paid' : 'paid',
            'partially_paid' => 'partially_paid',
            'sent' => 'payment_pending',
            default => $this->remainingInvoiceableQuantity($followUpItem) > 0.0005 ? 'partially_invoiced' : 'invoice_created',
        };

        $followUpItem->forceFill(['status' => $followUpStatus])->save();
        $this->syncFulfilmentFinancials($followUpItem, $followUpStatus);
    }

    private function markOutstandingPaymentPlanFollowUpsPaid(
        FollowUpItem $followUpItem,
        Request $request,
        Carbon $paidAt,
        ?string $paymentReference,
        ?string $remarks,
    ): void
    {
        $trackers = $this->paymentPlanFollowUps->ensureForItem($followUpItem);
        $outstanding = $trackers->where('status', '!=', 'paid')->values();

        if ($outstanding->isEmpty()) {
            return;
        }

        $followUpItem->load('invoice.payments');
        $remainingGenericAmount = round((float) ($followUpItem->invoice?->payments
            ->whereNull('payment_plan_follow_up_id')
            ->sum(fn (Payment $payment): float => (float) $payment->amount) ?? 0), 3);

        foreach ($outstanding as $index => $tracker) {
            $isLast = $index === $outstanding->count() - 1;
            $expectedAmount = max(0, round((float) ($tracker->expected_amount ?? 0), 3));
            $allocatedAmount = $isLast
                ? $remainingGenericAmount
                : min($expectedAmount, $remainingGenericAmount);
            $remainingGenericAmount = max(0, round($remainingGenericAmount - $allocatedAmount, 3));

            $tracker->forceFill([
                'status' => 'paid',
                'paid_at' => $paidAt,
                'paid_amount' => $allocatedAmount,
                'payment_reference' => $paymentReference,
                'remarks' => $remarks,
                'recorded_by' => $request->user()->id,
            ])->save();
        }
    }

    private function ensureFollowUpItemOpen(FollowUpItem $followUpItem): void
    {
        if ($followUpItem->status === 'closed' || $followUpItem->closed_at !== null) {
            abort(409, 'This follow-up item is closed and its workflow state cannot be moved backward.');
        }

        if ($followUpItem->status !== 'paid' || $this->hasOpenFulfilmentWork($followUpItem)) {
            return;
        }

        abort(409, 'This follow-up item is paid and its workflow state cannot be moved backward.');
    }

    private function ensureInvoiceCanBeRegenerated(FollowUpItem $followUpItem): void
    {
        $followUpItem->loadMissing('invoice.payments');
        $invoice = $followUpItem->invoice;

        if (! $invoice) {
            return;
        }

        if ($invoice->sent_at === null
            && $invoice->status === 'issued'
            && $invoice->payments->isEmpty()) {
            return;
        }

        abort(409, 'A sent, paid, or closed invoice cannot be regenerated. Create a controlled adjustment instead.');
    }

    private function ensureInvoiceCanBeMarkedSent(Invoice $invoice): void
    {
        $invoice->loadMissing('payments');

        if (! in_array($invoice->status, ['paid', 'closed'], true)
            && $invoice->sent_at === null
            && $this->paymentSummaryFor($invoice)['balance'] > 0.0005) {
            return;
        }

        abort(409, 'This invoice has already been sent, paid, or closed and cannot be sent again.');
    }

    private function ensureInvoiceAcceptsPayment(Invoice $invoice): void
    {
        if (! in_array($invoice->status, ['paid', 'closed'], true)) {
            return;
        }

        abort(409, 'This invoice is already paid or closed and cannot accept another payment.');
    }

    private function paymentPlanDueState(PaymentPlanFollowUp $paymentPlanFollowUp): string
    {
        if ($paymentPlanFollowUp->status === 'paid') {
            return 'paid';
        }

        if (! $paymentPlanFollowUp->due_date) {
            return 'unscheduled';
        }

        $due = $paymentPlanFollowUp->due_date->copy()->startOfDay();
        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();

        if ($due->lt($todayStart)) {
            return 'overdue';
        }

        if ($due->betweenIncluded($todayStart, $todayEnd)) {
            return 'due_today';
        }

        return 'upcoming';
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function quantity(mixed $value): float
    {
        return round((float) ($value ?? 0), 3);
    }

    private function supplierQuantityFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing(['itemFulfilment', 'supplierPoLine', 'quotationItem']);

        return $this->quantity(
            $followUpItem->itemFulfilment?->supplier_quantity
                ?? $followUpItem->supplierPoLine?->quantity
                ?? $followUpItem->quotationItem?->quantity
                ?? 0
        );
    }

    private function orderedQuantityFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing(['itemFulfilment', 'quotationItem', 'supplierPoLine']);

        return $this->quantity(
            $followUpItem->itemFulfilment?->ordered_quantity
                ?? $followUpItem->quotationItem?->quantity
                ?? $followUpItem->supplierPoLine?->quantity
                ?? 0
        );
    }

    private function receivedQuantityFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing(['itemFulfilment', 'logisticsCase']);

        return max(
            $this->quantity($followUpItem->itemFulfilment?->received_quantity),
            $this->quantity($followUpItem->logisticsCase?->received_quantity),
        );
    }

    private function deliveredQuantityFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing(['itemFulfilment', 'deliveryOrders.items']);
        $signedDeliveryOrderQuantity = $followUpItem->deliveryOrders
            ->filter(fn (DeliveryOrder $deliveryOrder): bool => $deliveryOrder->status === 'signed' || $deliveryOrder->signed_at !== null)
            ->sum(fn (DeliveryOrder $deliveryOrder): float => $deliveryOrder->items->sum(fn ($item): float => $this->quantity($item->quantity)));

        return max(
            $this->quantity($followUpItem->itemFulfilment?->delivered_quantity),
            $this->quantity($signedDeliveryOrderQuantity),
        );
    }

    private function invoiceQuantityFor(FollowUpItem $followUpItem, ?int $exceptInvoiceId = null): float
    {
        $followUpItem->loadMissing('invoices.items');

        return $this->quantity($followUpItem->invoices
            ->reject(fn (Invoice $invoice): bool => $exceptInvoiceId !== null && (int) $invoice->id === $exceptInvoiceId)
            ->reject(fn (Invoice $invoice): bool => in_array($invoice->status, ['void', 'cancelled'], true))
            ->sum(fn (Invoice $invoice): float => $invoice->items->sum(fn ($item): float => $this->quantity($item->quantity))));
    }

    private function invoiceAmountFor(FollowUpItem $followUpItem, ?int $exceptInvoiceId = null): float
    {
        $followUpItem->loadMissing('invoices');

        return $this->quantity($followUpItem->invoices
            ->reject(fn (Invoice $invoice): bool => $exceptInvoiceId !== null && (int) $invoice->id === $exceptInvoiceId)
            ->reject(fn (Invoice $invoice): bool => in_array($invoice->status, ['void', 'cancelled'], true))
            ->sum(fn (Invoice $invoice): float => (float) $invoice->total_amount));
    }

    private function paidAmountFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing('invoices.payments');

        return $this->quantity($followUpItem->invoices
            ->sum(fn (Invoice $invoice): float => $invoice->payments->sum(fn (Payment $payment): float => (float) $payment->amount)));
    }

    private function quotationSubtotalFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing('quotationItem');

        return $this->quantity($followUpItem->quotationItem?->total_price);
    }

    private function quotationTotalWithVatFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing('quotationItem');
        $subtotal = $this->quotationSubtotalFor($followUpItem);
        $vatRate = $this->quantity($followUpItem->quotationItem?->vat_rate);

        return $this->quantity($subtotal + ($subtotal * ($vatRate / 100)));
    }

    private function remainingReceiveQuantity(FollowUpItem $followUpItem): float
    {
        return max($this->quantity($this->supplierQuantityFor($followUpItem) - $this->receivedQuantityFor($followUpItem)), 0.0);
    }

    private function remainingDeliveryQuantity(FollowUpItem $followUpItem): float
    {
        return max($this->quantity($this->receivedQuantityFor($followUpItem) - $this->deliveredQuantityFor($followUpItem)), 0.0);
    }

    private function remainingInvoiceableQuantity(FollowUpItem $followUpItem, ?int $exceptInvoiceId = null): float
    {
        return max($this->quantity($this->deliveredQuantityFor($followUpItem) - $this->invoiceQuantityFor($followUpItem, $exceptInvoiceId)), 0.0);
    }

    private function hasQuantityTrackingFor(FollowUpItem $followUpItem): bool
    {
        $followUpItem->loadMissing(['itemFulfilment', 'invoices.items']);

        return $followUpItem->itemFulfilment !== null
            || $followUpItem->invoices->contains(fn (Invoice $invoice): bool => $invoice->items->isNotEmpty());
    }

    /**
     * @return array<string, string|null>
     */
    private function fulfilmentSummaryFor(FollowUpItem $followUpItem): array
    {
        $followUpItem->loadMissing(['buyerPo', 'quotation', 'itemFulfilment', 'supplierPoLine', 'quotationItem', 'deliveryOrders.items', 'invoices.items', 'invoices.payments']);

        $orderedQuantity = $this->orderedQuantityFor($followUpItem);
        $supplierQuantity = $this->supplierQuantityFor($followUpItem);
        $receivedQuantity = $this->receivedQuantityFor($followUpItem);
        $deliveredQuantity = $this->deliveredQuantityFor($followUpItem);
        $invoicedQuantity = $this->invoiceQuantityFor($followUpItem);
        $invoiceAmount = $this->invoiceAmountFor($followUpItem);
        $paidAmount = $this->paidAmountFor($followUpItem);
        $quotationTotal = $this->quotationTotalWithVatFor($followUpItem);

        if (! $this->hasQuantityTrackingFor($followUpItem) && $followUpItem->invoices->isNotEmpty()) {
            $receivedQuantity = $supplierQuantity;
            $deliveredQuantity = $supplierQuantity;
            $invoicedQuantity = $supplierQuantity;
        }

        return [
            'ordered_quantity' => $this->money($orderedQuantity),
            'supplier_quantity' => $this->money($supplierQuantity),
            'received_quantity' => $this->money($receivedQuantity),
            'remaining_receive_quantity' => $this->money(max($supplierQuantity - $receivedQuantity, 0)),
            'delivered_quantity' => $this->money($deliveredQuantity),
            'remaining_delivery_quantity' => $this->money(max($receivedQuantity - $deliveredQuantity, 0)),
            'invoiced_quantity' => $this->money($invoicedQuantity),
            'remaining_invoice_quantity' => $this->money(max($deliveredQuantity - $invoicedQuantity, 0)),
            'quotation_total_amount' => $this->money($quotationTotal),
            'invoiced_amount' => $this->money($invoiceAmount),
            'paid_amount' => $this->money($paidAmount),
            'balance_amount' => $this->money(max($invoiceAmount - $paidAmount, 0)),
            'remaining_quotation_amount' => $this->money(max($quotationTotal - $paidAmount, 0)),
            'uom' => $followUpItem->supplierPoLine?->uom ?? $followUpItem->quotationItem?->uom,
            'currency' => $followUpItem->buyerPo?->currency ?? $followUpItem->quotation?->accepted_invoice_currency,
        ];
    }

    private function activeDraftDeliveryOrderFor(FollowUpItem $followUpItem): ?DeliveryOrder
    {
        return DeliveryOrder::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->where('status', 'issued')
            ->whereNull('signed_at')
            ->whereNull('signed_file_path')
            ->latest('delivery_order_date')
            ->latest('id')
            ->first();
    }

    private function activeDraftInvoiceFor(FollowUpItem $followUpItem): ?Invoice
    {
        return Invoice::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->where('status', 'issued')
            ->whereNull('sent_at')
            ->whereDoesntHave('payments')
            ->latest('invoice_date')
            ->latest('id')
            ->first();
    }

    private function deliveryOrderForItem(FollowUpItem $followUpItem, DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        if ((int) $deliveryOrder->follow_up_item_id !== (int) $followUpItem->id) {
            abort(404);
        }

        return $deliveryOrder->refresh();
    }

    private function deliveryOrderForSigning(FollowUpItem $followUpItem): DeliveryOrder
    {
        $deliveryOrder = $this->activeDraftDeliveryOrderFor($followUpItem)
            ?? DeliveryOrder::query()
                ->where('follow_up_item_id', $followUpItem->id)
                ->latest('delivery_order_date')
                ->latest('id')
                ->first();

        if ($deliveryOrder) {
            return $deliveryOrder;
        }

        throw ValidationException::withMessages([
            'delivery_order' => 'Create the delivery order before uploading the signed copy.',
        ]);
    }

    private function signedDeliveryOrderForDownload(FollowUpItem $followUpItem): DeliveryOrder
    {
        $deliveryOrder = DeliveryOrder::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->whereNotNull('signed_file_path')
            ->latest('signed_at')
            ->latest('id')
            ->first();

        if ($deliveryOrder) {
            return $deliveryOrder;
        }

        throw ValidationException::withMessages([
            'delivery_order' => 'Upload the signed delivery order before downloading it.',
        ]);
    }

    private function storeSignedDeliveryOrderUpload(Request $request, FollowUpItem $followUpItem, DeliveryOrder $deliveryOrder): JsonResponse
    {
        $validated = $request->validate([
            'signed_at' => ['required', 'date'],
            'signed_file' => ['required', 'file', 'max:10240'],
        ]);

        $deliveryOrder->loadMissing('items');
        $wasAlreadySigned = $deliveryOrder->status === 'signed' || $deliveryOrder->signed_at !== null;
        $deliveryQuantity = $this->quantity($deliveryOrder->items->sum(fn ($item): float => (float) $item->quantity));
        $currentDeliveredQuantity = $this->deliveredQuantityFor($followUpItem);

        if (! $wasAlreadySigned) {
            $remainingDeliveryQuantity = max($this->quantity($this->receivedQuantityFor($followUpItem) - $currentDeliveredQuantity), 0.0);

            if ($deliveryQuantity > $remainingDeliveryQuantity + 0.0005) {
                throw ValidationException::withMessages([
                    'signed_file' => 'Signed delivery quantity cannot exceed the remaining received quantity of '.$this->money($remainingDeliveryQuantity).'.',
                ]);
            }
        }

        $file = $request->file('signed_file');
        $filePath = $file->store("follow-up/{$followUpItem->id}/signed-delivery-orders", 'local');

        $deliveryOrder->forceFill([
            'status' => 'signed',
            'signed_at' => Carbon::parse($validated['signed_at']),
            'signed_file_path' => $filePath,
            'signed_original_file_name' => $file->getClientOriginalName(),
        ])->save();

        if (! $wasAlreadySigned) {
            $followUpItem->loadMissing('itemFulfilment');
            $deliveredQuantity = $this->quantity($currentDeliveredQuantity + $deliveryQuantity);
            $followUpItem->itemFulfilment?->forceFill([
                'delivered_quantity' => $deliveredQuantity,
                'status' => $followUpItem->status,
            ])->save();
        }

        $this->advanceFollowUpStatus($followUpItem->refresh(), 'ready_for_invoice');

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'delivery_order.signed_uploaded', $wasAlreadySigned ? 'Signed delivery order replaced.' : 'Signed delivery order uploaded.', [
            'delivery_order_id' => $deliveryOrder->id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference,
            'signed_at' => Carbon::parse($validated['signed_at'])->toDateTimeString(),
            'quantity' => $this->money($deliveryQuantity),
            'original_file_name' => $file->getClientOriginalName(),
        ]);

        return response()->json([
            'message' => $wasAlreadySigned ? 'Signed delivery order replaced.' : 'Signed delivery order uploaded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    private function invoiceForRequest(FollowUpItem $followUpItem, Request $request): Invoice
    {
        $invoiceId = $request->integer('invoice_id') ?: null;

        if ($invoiceId) {
            $invoice = Invoice::query()
                ->where('follow_up_item_id', $followUpItem->id)
                ->whereKey($invoiceId)
                ->with('payments')
                ->first();

            if ($invoice) {
                return $invoice;
            }

            abort(404);
        }

        $invoice = $this->currentInvoiceFor($followUpItem);

        if ($invoice) {
            return $invoice;
        }

        throw ValidationException::withMessages([
            'invoice' => 'Create the invoice before tracking payment.',
        ]);
    }

    private function currentInvoiceFor(FollowUpItem $followUpItem): ?Invoice
    {
        $followUpItem->loadMissing('invoices.payments');

        return $followUpItem->invoices
            ->first(fn (Invoice $invoice): bool => $this->paymentSummaryFor($invoice)['balance'] > 0.0005)
            ?? $followUpItem->invoices->first();
    }

    private function totalInvoiceBalanceFor(FollowUpItem $followUpItem): float
    {
        $followUpItem->loadMissing('invoices.payments');

        return $this->quantity($followUpItem->invoices
            ->sum(fn (Invoice $invoice): float => $this->paymentSummaryFor($invoice)['balance']));
    }

    private function hasOpenFulfilmentWork(FollowUpItem $followUpItem): bool
    {
        if (! $this->hasQuantityTrackingFor($followUpItem)) {
            $followUpItem->loadMissing('invoices.payments');

            return $followUpItem->invoices
                ->contains(fn (Invoice $invoice): bool => ! in_array($invoice->status, ['paid', 'closed'], true)
                    && $this->paymentSummaryFor($invoice)['balance'] > 0.0005);
        }

        return $this->remainingReceiveQuantity($followUpItem) > 0.0005
            || $this->remainingDeliveryQuantity($followUpItem) > 0.0005
            || $this->remainingInvoiceableQuantity($followUpItem) > 0.0005
            || $this->totalInvoiceBalanceFor($followUpItem) > 0.0005;
    }

    private function syncFulfilmentFinancials(FollowUpItem $followUpItem, ?string $status = null): void
    {
        $followUpItem->loadMissing('itemFulfilment');

        if (! $followUpItem->itemFulfilment) {
            return;
        }

        $updates = [
            'invoiced_amount' => $this->invoiceAmountFor($followUpItem),
            'paid_amount' => $this->paidAmountFor($followUpItem),
        ];

        if ($status !== null) {
            $updates['status'] = $status;
        }

        $followUpItem->itemFulfilment->forceFill($updates)->save();
    }

    private function ensureShippingDocuments(FollowUpItem $followUpItem): void
    {
        foreach ($this->shippingDocumentLabels() as $documentType => $label) {
            ShippingDocument::query()->firstOrCreate([
                'follow_up_item_id' => $followUpItem->id,
                'document_type' => $documentType,
            ], [
                'label' => $label,
                'status' => 'pending',
            ]);
        }
    }

    private function shippingDocumentsFor(FollowUpItem $followUpItem)
    {
        $this->ensureShippingDocuments($followUpItem);
        $followUpItem->unsetRelation('shippingDocuments');
        $followUpItem->load('shippingDocuments.uploader');

        $documents = $followUpItem->shippingDocuments;

        return collect(array_keys($this->shippingDocumentLabels()))
            ->map(fn (string $documentType) => $documents->firstWhere('document_type', $documentType))
            ->filter()
            ->values();
    }

    private function shippingDocumentsComplete(FollowUpItem $followUpItem): bool
    {
        $this->ensureShippingDocuments($followUpItem);
        $completeStatuses = ['uploaded', 'approved', 'generated', 'waived'];

        return $this->shippingDocumentsFor($followUpItem)
            ->filter(fn (ShippingDocument $document): bool => $this->shippingDocumentIsRequired($document->document_type))
            ->every(fn (ShippingDocument $document): bool => in_array($document->status, $completeStatuses, true));
    }

    private function requireCompletedShippingDocuments(FollowUpItem $followUpItem): void
    {
        if ($this->shippingDocumentsComplete($followUpItem)) {
            return;
        }

        throw ValidationException::withMessages([
            'shipping_documents' => 'Required shipping documents must be uploaded before delivery and logistics milestones can continue.',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function shippingDocumentLabels(): array
    {
        return self::REQUIRED_SHIPPING_DOCUMENTS + self::OPTIONAL_SHIPPING_DOCUMENTS;
    }

    private function shippingDocumentIsRequired(string $documentType): bool
    {
        return array_key_exists($documentType, self::REQUIRED_SHIPPING_DOCUMENTS);
    }

    private function logisticsCaseOrFail(FollowUpItem $followUpItem): LogisticsCase
    {
        $followUpItem->load('logisticsCase');

        if ($followUpItem->logisticsCase) {
            return $followUpItem->logisticsCase;
        }

        throw ValidationException::withMessages([
            'logistics' => 'Record the ETA before moving to this logistics step.',
        ]);
    }

    private function requireDeliveryOrderReady(FollowUpItem $followUpItem): void
    {
        $followUpItem->loadMissing(['deliveryOrders.items', 'itemFulfilment']);

        if ($this->activeDraftDeliveryOrderFor($followUpItem)
            || $this->remainingDeliveryQuantity($followUpItem) > 0.0005
            || in_array($followUpItem->status, ['ready_for_delivery_order', 'delivery_order_created'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'delivery_order' => 'Goods must be received at ISC warehouse before creating the delivery order.',
        ]);
    }

    private function deliveryOrderOrFail(FollowUpItem $followUpItem): DeliveryOrder
    {
        $followUpItem->load('deliveryOrder');

        if ($followUpItem->deliveryOrder) {
            return $followUpItem->deliveryOrder;
        }

        throw ValidationException::withMessages([
            'delivery_order' => 'Create the delivery order before uploading the signed copy.',
        ]);
    }

    private function requireInvoiceReady(FollowUpItem $followUpItem): void
    {
        $followUpItem->loadMissing(['invoice', 'invoices.items', 'deliveryOrders.items', 'itemFulfilment']);

        if ($this->activeDraftInvoiceFor($followUpItem)
            || $this->remainingInvoiceableQuantity($followUpItem) > 0.0005
            || $followUpItem->status === 'ready_for_invoice') {
            return;
        }

        throw ValidationException::withMessages([
            'invoice' => 'Invoice can only be created after signed delivery order or buyer receipt confirmation.',
        ]);
    }

    private function invoiceOrFail(FollowUpItem $followUpItem): Invoice
    {
        $invoice = $this->currentInvoiceFor($followUpItem);

        if ($invoice) {
            return $invoice;
        }

        throw ValidationException::withMessages([
            'invoice' => 'Create the invoice before tracking payment.',
        ]);
    }

    /**
     * @return array{paid: float, balance: float, payment_status: string}
     */
    private function paymentSummaryFor(Invoice $invoice): array
    {
        $invoice->loadMissing('payments');
        $paid = round($invoice->payments->sum(fn (Payment $payment): float => (float) $payment->amount), 3);
        $total = round((float) $invoice->total_amount, 3);
        $balance = max(round($total - $paid, 3), 0.0);

        $paymentStatus = match (true) {
            $invoice->status === 'closed' => 'closed',
            $balance <= 0.0005 => 'paid',
            $paid > 0 => 'partially_paid',
            default => 'pending',
        };

        return [
            'paid' => $paid,
            'balance' => $balance,
            'payment_status' => $paymentStatus,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function appendLogisticsEvent(LogisticsCase $case, Request $request, string $eventType, string $title, ?string $notes = null, ?array $metadata = null, ?Carbon $eventAt = null): LogisticsEvent
    {
        return LogisticsEvent::query()->create([
            'logistics_case_id' => $case->id,
            'user_id' => $request->user()->id,
            'event_type' => $eventType,
            'title' => $title,
            'event_at' => $eventAt ?? now(),
            'notes' => $notes,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformShippingDocument(ShippingDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'label' => $document->label,
            'is_required' => $this->shippingDocumentIsRequired($document->document_type),
            'status' => $document->status,
            'document_number' => $document->document_number,
            'document_date' => $document->document_date?->toDateString(),
            'original_file_name' => $document->original_file_name,
            'download_url' => $document->file_path
                ? "/api/follow-up/{$document->follow_up_item_id}/shipping-documents/{$document->id}/download"
                : null,
            'uploaded_by_name' => $document->uploader?->name,
            'uploaded_at' => $document->uploaded_at?->toDateTimeString(),
            'remarks' => $document->remarks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPackingList(PackingList $packingList): array
    {
        $packingList->loadMissing(['items.buyerPo', 'creator']);

        return [
            'id' => $packingList->id,
            'packing_list_reference' => $packingList->packing_list_reference,
            'packing_list_date' => $packingList->packing_list_date?->toDateString(),
            'package_size' => $packingList->package_size,
            'gross_weight' => $packingList->gross_weight,
            'net_weight' => $packingList->net_weight,
            'remarks' => $packingList->remarks,
            'docx_path' => $packingList->docx_path,
            'pdf_path' => $packingList->pdf_path,
            'created_by_name' => $packingList->creator?->name,
            'items' => $packingList->items->map(fn ($item): array => [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'item_description' => $item->item_description,
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'package_size' => $item->package_size,
                'gross_weight' => $item->gross_weight,
                'net_weight' => $item->net_weight,
                'buyer_po_number' => $item->buyerPo?->po_number,
            ])->values(),
            'downloads' => [
                'docx' => "/api/packing-lists/{$packingList->id}/download/docx",
                'pdf' => "/api/packing-lists/{$packingList->id}/download/pdf",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformLogisticsCase(LogisticsCase $case): array
    {
        $case->loadMissing(['creator', 'events.user']);

        return [
            'id' => $case->id,
            'delivery_responsibility' => $case->delivery_responsibility,
            'status' => $case->status,
            'eta_at' => $case->eta_at?->toDateTimeString(),
            'agent_name' => $case->agent_name,
            'agent_contact' => $case->agent_contact,
            'documents_sent_at' => $case->documents_sent_at?->toDateTimeString(),
            'arrived_at' => $case->arrived_at?->toDateTimeString(),
            'warehouse_received_at' => $case->warehouse_received_at?->toDateTimeString(),
            'buyer_received_at' => $case->buyer_received_at?->toDateTimeString(),
            'received_quantity' => $case->received_quantity === null ? null : $this->money($case->received_quantity),
            'goods_condition' => $case->goods_condition,
            'received_location' => $case->received_location,
            'remarks' => $case->remarks,
            'created_by_name' => $case->creator?->name,
            'events' => $case->events
                ->map(fn (LogisticsEvent $event): array => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'title' => $event->title,
                    'event_at' => $event->event_at?->toDateTimeString(),
                    'notes' => $event->notes,
                    'metadata' => $event->metadata,
                    'created_by_name' => $event->user?->name,
                ])
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDeliveryOrder(DeliveryOrder $deliveryOrder): array
    {
        $deliveryOrder->loadMissing(['items.buyerPo', 'items.buyerPoItem', 'creator']);

        return [
            'id' => $deliveryOrder->id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference,
            'delivery_order_date' => $deliveryOrder->delivery_order_date?->toDateString(),
            'delivery_place' => $deliveryOrder->delivery_place,
            'terms' => $deliveryOrder->terms,
            'status' => $deliveryOrder->status,
            'docx_path' => $deliveryOrder->docx_path,
            'pdf_path' => $deliveryOrder->pdf_path,
            'signed_original_file_name' => $deliveryOrder->signed_original_file_name,
            'signed_download_url' => $deliveryOrder->signed_file_path
                ? "/api/follow-up/{$deliveryOrder->follow_up_item_id}/delivery-orders/{$deliveryOrder->id}/signed/download"
                : null,
            'signed_at' => $deliveryOrder->signed_at?->toDateTimeString(),
            'created_by_name' => $deliveryOrder->creator?->name,
            'total_quantity' => $this->money($deliveryOrder->items->sum(fn ($item): float => (float) $item->quantity)),
            'items' => $deliveryOrder->items->map(fn ($item): array => [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'item_description' => $item->item_description,
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'buyer_po_number' => $item->buyerPo?->po_number,
                'buyer_item_code' => $item->buyerPoItem?->buyer_item_code,
            ])->values(),
            'downloads' => [
                'docx' => "/api/delivery-orders/{$deliveryOrder->id}/download/docx",
                'pdf' => "/api/delivery-orders/{$deliveryOrder->id}/download/pdf",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['items.buyerPo', 'items.buyerPoItem', 'deliveryOrder', 'creator', 'followUpItem.buyerPo', 'payments.recorder']);
        $paymentSummary = $this->paymentSummaryFor($invoice);

        return [
            'id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'sent_at' => $invoice->sent_at?->toDateTimeString(),
            'payment_term_days' => $invoice->payment_term_days,
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'subtotal' => $this->money($invoice->subtotal),
            'vat_rate' => $this->money($invoice->vat_rate),
            'vat_amount' => $this->money($invoice->vat_amount),
            'total_amount' => $this->money($invoice->total_amount),
            'paid_amount' => $this->money($paymentSummary['paid']),
            'balance_amount' => $this->money($paymentSummary['balance']),
            'payment_status' => $paymentSummary['payment_status'],
            'vat_exception_reason' => $invoice->vat_exception_reason,
            'bank_details' => $invoice->bank_details,
            'remarks' => $invoice->remarks,
            'status' => $invoice->status,
            'closed_at' => $invoice->closed_at?->toDateTimeString(),
            'docx_path' => $invoice->docx_path,
            'pdf_path' => $invoice->pdf_path,
            'buyer_po_number' => $invoice->followUpItem?->buyerPo?->po_number,
            'delivery_order_reference' => $invoice->deliveryOrder?->delivery_order_reference,
            'created_by_name' => $invoice->creator?->name,
            'total_quantity' => $this->money($invoice->items->sum(fn ($item): float => (float) $item->quantity)),
            'items' => $invoice->items->map(fn ($item): array => [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'item_description' => $item->item_description,
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'unit_price' => $this->money($item->unit_price),
                'total_price' => $this->money($item->total_price),
                'buyer_po_number' => $item->buyerPo?->po_number,
                'buyer_item_code' => $item->buyerPoItem?->buyer_item_code,
            ])->values(),
            'payments' => $invoice->payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'amount' => $this->money($payment->amount),
                'currency' => $payment->currency,
                'payment_date' => $payment->payment_date?->toDateString(),
                'payment_reference' => $payment->payment_reference,
                'remarks' => $payment->remarks,
                'recorded_by_name' => $payment->recorder?->name,
                'created_at' => $payment->created_at?->toDateTimeString(),
            ])->values(),
            'downloads' => [
                'docx' => "/api/invoices/{$invoice->id}/download/docx",
                'pdf' => "/api/invoices/{$invoice->id}/download/pdf",
            ],
        ];
    }

    private function paymentCustomerTypeLabel(string $type): string
    {
        return match ($type) {
            'paying' => 'Full Paying Customer',
            default => 'Credit Customer',
        };
    }

    private function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'cheque' => 'Check / Cheque',
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'card' => 'Card',
            'letter_of_credit' => 'Letter of Credit',
            'other' => 'Other',
            default => '-',
        };
    }

    private function dueEventLabel(?string $event): string
    {
        return match ($event) {
            'before_delivery' => 'before delivery',
            'on_delivery' => 'on delivery',
            'after_delivery' => 'after delivery',
            'before_arrival' => 'before arrival',
            'on_arrival' => 'on arrival',
            'after_arrival' => 'after arrival',
            'before_dispatch' => 'before dispatch',
            'on_invoice' => 'on invoice',
            'after_invoice' => 'after invoice',
            default => '-',
        };
    }

    private function scheduleDueText(QuotationPaymentSchedule $schedule): string
    {
        if ($schedule->due_timing === 'fixed_date') {
            return 'On '.$schedule->due_date?->toDateString();
        }

        $days = (int) ($schedule->due_offset_days ?? 0);
        $event = $this->dueEventLabel($schedule->due_event);

        return str_starts_with((string) $schedule->due_event, 'on_') || $days === 0
            ? ucfirst($event)
            : "{$days} days {$event}";
    }

    private function paymentScheduleLineSummary(QuotationPaymentSchedule $schedule): string
    {
        $percentage = rtrim(rtrim($this->money($schedule->payment_percentage), '0'), '.');

        return "{$schedule->label}: {$percentage}% by {$this->paymentMethodLabel($schedule->payment_method)}, {$this->scheduleDueText($schedule)}";
    }

    private function paymentScheduleSummary(Quotation $quotation): string
    {
        $base = "Within {$quotation->payment_term_days} days from the date of Invoice.";
        $extra = trim((string) $quotation->payment_terms_extra);

        return $extra !== '' ? $base.' '.$extra : $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPaymentSchedule(QuotationPaymentSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'quotation_id' => $schedule->quotation_id,
            'line_number' => $schedule->line_number,
            'label' => $schedule->label,
            'payment_method' => $schedule->payment_method,
            'payment_method_label' => $this->paymentMethodLabel($schedule->payment_method),
            'payment_percentage' => $this->money($schedule->payment_percentage),
            'due_timing' => $schedule->due_timing,
            'due_event' => $schedule->due_event,
            'due_event_label' => $this->dueEventLabel($schedule->due_event),
            'due_offset_days' => $schedule->due_offset_days,
            'due_date' => $schedule->due_date?->toDateString(),
            'notes' => $schedule->notes,
            'due_text' => $this->scheduleDueText($schedule),
            'summary' => $this->paymentScheduleLineSummary($schedule),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPaymentPlanFollowUp(PaymentPlanFollowUp $paymentPlanFollowUp): array
    {
        $paymentPlanFollowUp->loadMissing(['attachments.uploader', 'quotationPaymentSchedule', 'recorder']);
        $schedule = $paymentPlanFollowUp->quotationPaymentSchedule;

        return [
            'id' => $paymentPlanFollowUp->id,
            'quotation_payment_schedule_id' => $paymentPlanFollowUp->quotation_payment_schedule_id,
            'schedule' => $schedule ? $this->transformPaymentSchedule($schedule) : null,
            'label' => $schedule?->label ?? 'Payment',
            'payment_method_label' => $this->paymentMethodLabel($schedule?->payment_method),
            'payment_percentage' => $this->money($paymentPlanFollowUp->expected_percentage),
            'expected_amount' => $paymentPlanFollowUp->expected_amount === null ? null : $this->money($paymentPlanFollowUp->expected_amount),
            'currency' => $paymentPlanFollowUp->currency,
            'due_text' => $schedule ? $this->scheduleDueText($schedule) : '-',
            'due_date' => $paymentPlanFollowUp->due_date?->toDateString(),
            'due_state' => $this->paymentPlanDueState($paymentPlanFollowUp),
            'status' => $paymentPlanFollowUp->status,
            'paid_at' => $paymentPlanFollowUp->paid_at?->toDateTimeString(),
            'paid_amount' => $paymentPlanFollowUp->paid_amount === null ? null : $this->money($paymentPlanFollowUp->paid_amount),
            'payment_reference' => $paymentPlanFollowUp->payment_reference,
            'remarks' => $paymentPlanFollowUp->remarks,
            'recorded_by_name' => $paymentPlanFollowUp->recorder?->name,
            'attachments' => $paymentPlanFollowUp->attachments
                ->map(fn (PaymentPlanFollowUpAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'original_file_name' => $attachment->original_file_name,
                    'download_url' => $attachment->file_path
                        ? "/api/follow-up/{$paymentPlanFollowUp->follow_up_item_id}/payment-plan/{$paymentPlanFollowUp->id}/attachments/{$attachment->id}/download"
                        : null,
                    'mime_type' => $attachment->mime_type,
                    'file_size' => $attachment->file_size,
                    'remarks' => $attachment->remarks,
                    'uploaded_by_name' => $attachment->uploader?->name,
                    'created_at' => $attachment->created_at?->toDateTimeString(),
                ])
                ->values(),
        ];
    }

    private function packingListReferenceFor(FollowUpItem $followUpItem): string
    {
        $followUpItem->loadMissing('quotation.buyerCompany');
        $buyerCode = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $followUpItem->quotation?->buyerCompany?->company_code) ?: 'BUY');

        return sprintf('PL-COR-%03d-%s-%s', $followUpItem->id, $buyerCode, now()->format('y'));
    }

    private function deliveryOrderReferenceFor(FollowUpItem $followUpItem): string
    {
        $followUpItem->loadMissing('quotation.buyerCompany');
        $buyerCode = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $followUpItem->quotation?->buyerCompany?->company_code) ?: 'BUY');
        $base = sprintf('DO-COR-%03d-%s-%s', $followUpItem->id, $buyerCode, now()->format('y'));
        $existingCount = DeliveryOrder::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->count();

        return $existingCount === 0 ? $base : $base.'-'.str_pad((string) ($existingCount + 1), 2, '0', STR_PAD_LEFT);
    }

    private function invoiceReferenceFor(FollowUpItem $followUpItem): string
    {
        $base = sprintf('INV-COR-%03d-%s', $followUpItem->id, now()->format('y'));
        $existingCount = Invoice::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->count();

        return $existingCount === 0 ? $base : $base.'-'.str_pad((string) ($existingCount + 1), 2, '0', STR_PAD_LEFT);
    }
}
