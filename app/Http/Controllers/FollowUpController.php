<?php

namespace App\Http\Controllers;

use App\Models\FollowUpComment;
use App\Models\FollowUpAuditLog;
use App\Models\FollowUpItem;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\LogisticsCase;
use App\Models\LogisticsEvent;
use App\Models\PackingList;
use App\Models\Payment;
use App\Models\ShippingDocument;
use App\Services\DeliveryOrderDocumentService;
use App\Services\InvoiceDocumentService;
use App\Services\PackingListDocumentService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FollowUpController extends Controller
{
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
        'bill_of_lading' => 'Bill of Lading',
        'airway_bill' => 'Airway Bill',
        'certificate_of_origin' => 'Certificate of Origin',
        'packing_list' => 'Packing List',
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
        'delivery' => ['documents_sent_to_buyer_agent', 'documents_sent_to_agent', 'arrived', 'ready_for_delivery_order', 'delivery_order_created'],
        'invoice' => ['ready_for_invoice', 'invoice_created'],
        'payment' => ['invoice_sent', 'payment_pending', 'partially_paid', 'paid', 'closed'],
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

    public function updateReminder(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'reminder_interval_value' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reminder_interval_unit' => ['required', Rule::in(self::REMINDER_UNITS)],
            'next_follow_up_at' => ['nullable', 'date'],
        ]);

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

        return response()->json([
            'message' => 'Follow-up comment added.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], 201);
    }

    public function acknowledge(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'acknowledgement_received_at' => ['required', 'date'],
            'acknowledgement_notes' => ['nullable', 'string'],
            'acknowledgement_file' => ['nullable', 'file', 'max:10240'],
        ]);

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

        $followUpItem->forceFill([
            'status' => 'acknowledged',
            'acknowledgement_received_at' => Carbon::parse($validated['acknowledgement_received_at']),
            'acknowledgement_file_path' => $filePath,
            'acknowledgement_original_file_name' => $originalName,
            'acknowledgement_notes' => $validated['acknowledgement_notes'] ?? null,
            'acknowledged_by' => $request->user()->id,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'acknowledgement', 'acknowledgement.recorded', 'Order acknowledgement recorded.', [
            'acknowledgement_received_at' => Carbon::parse($validated['acknowledgement_received_at'])->toDateTimeString(),
            'original_file_name' => $originalName,
            'notes' => $validated['acknowledgement_notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Order acknowledgement recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
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

        if (! array_key_exists($documentType, self::REQUIRED_SHIPPING_DOCUMENTS)) {
            abort(404);
        }

        if ($documentType === 'packing_list') {
            throw ValidationException::withMessages([
                'document_type' => 'Packing list must be generated from packing list entry.',
            ]);
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

    public function completeShippingDocuments(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->ensureShippingDocuments($followUpItem);

        if (! $this->shippingDocumentsComplete($followUpItem)) {
            throw ValidationException::withMessages([
                'shipping_documents' => 'All required shipping documents must be uploaded or generated before moving to logistics.',
            ]);
        }

        $followUpItem->forceFill(['status' => 'shipping_documents_complete'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'shipping', 'shipping_documents.completed', 'Shipping documents completed.', [
            'required_documents' => array_keys(self::REQUIRED_SHIPPING_DOCUMENTS),
        ]);

        return response()->json([
            'message' => 'Shipping documents completed.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storePackingList(Request $request, FollowUpItem $followUpItem, PackingListDocumentService $documents): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);

        $validated = $request->validate([
            'package_size' => ['required', 'string', 'max:150'],
            'gross_weight' => ['required', 'string', 'max:100'],
            'net_weight' => ['required', 'string', 'max:100'],
            'remarks' => ['nullable', 'string'],
        ]);

        $followUpItem->loadMissing([
            'supplierPoLine',
            'quotationItem',
            'buyerPo',
            'quotation.buyerCompany',
        ]);
        $line = $followUpItem->supplierPoLine;

        if (! $line) {
            throw ValidationException::withMessages([
                'packing_list' => 'This follow-up item is not linked to an active supplier PO line.',
            ]);
        }

        $packingList = PackingList::query()->firstOrNew(['follow_up_item_id' => $followUpItem->id]);
        $wasRecentlyCreated = ! $packingList->exists;
        $packingList->fill([
            'packing_list_reference' => $packingList->packing_list_reference ?: $this->packingListReferenceFor($followUpItem),
            'packing_list_date' => now()->toDateString(),
            'package_size' => $validated['package_size'],
            'gross_weight' => $validated['gross_weight'],
            'net_weight' => $validated['net_weight'],
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => $request->user()->id,
        ])->save();

        $description = $followUpItem->quotationItem?->buyer_description ?: $line->item_description;
        $packingList->items()->delete();
        $packingList->items()->create([
            'quotation_item_id' => $followUpItem->quotation_item_id,
            'buyer_po_id' => $followUpItem->buyer_po_id,
            'line_number' => 10,
            'item_description' => $description,
            'quantity' => $line->quantity,
            'uom' => $line->uom,
            'package_size' => $validated['package_size'],
            'gross_weight' => $validated['gross_weight'],
            'net_weight' => $validated['net_weight'],
        ]);

        $safeReference = Str::slug($packingList->packing_list_reference, '-');
        $basePath = "generated/packing-lists/{$packingList->id}";
        $docxPath = "{$basePath}/{$safeReference}.docx";
        $pdfPath = "{$basePath}/{$safeReference}.pdf";
        $packingList->forceFill([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
        ])->save();

        $snapshot = $documents->snapshot($packingList);
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        $this->ensureShippingDocuments($followUpItem);
        ShippingDocument::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->where('document_type', 'packing_list')
            ->firstOrFail()
            ->forceFill([
                'status' => 'generated',
                'document_number' => $packingList->packing_list_reference,
                'document_date' => $packingList->packing_list_date,
                'file_path' => $docxPath,
                'original_file_name' => "{$safeReference}.docx",
                'uploaded_by' => $request->user()->id,
                'uploaded_at' => now(),
            ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'shipping', 'packing_list.generated', 'Packing List generated.', [
            'packing_list_id' => $packingList->id,
            'packing_list_reference' => $packingList->packing_list_reference,
            'package_size' => $packingList->package_size,
            'gross_weight' => $packingList->gross_weight,
            'net_weight' => $packingList->net_weight,
        ]);

        return response()->json([
            'message' => 'Packing list generated.',
            'data' => $this->transformPackingList($packingList->refresh()->load(['items.buyerPo', 'creator'])),
        ], $wasRecentlyCreated ? 201 : 200);
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

        return response()->download(Storage::disk('local')->path($path), Str::slug($packingList->packing_list_reference, '-').".{$format}", [
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
        $this->requireCompletedShippingDocuments($followUpItem);

        $validated = $request->validate([
            'delivery_responsibility' => ['required', Rule::in(self::DELIVERY_RESPONSIBILITIES)],
            'eta_at' => ['required', 'date'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $etaAt = Carbon::parse($validated['eta_at']);
        $case = LogisticsCase::query()->firstOrNew(['follow_up_item_id' => $followUpItem->id]);

        if (! $case->exists) {
            $case->created_by = $request->user()->id;
        }

        $case->fill([
            'delivery_responsibility' => $validated['delivery_responsibility'],
            'status' => 'eta_recorded',
            'eta_at' => $etaAt,
            'agent_name' => $validated['agent_name'] ?? null,
            'agent_contact' => $validated['agent_contact'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ])->save();

        $this->appendLogisticsEvent($case, $request, 'eta_recorded', 'ETA recorded', $validated['remarks'] ?? null, [
            'eta_at' => $etaAt->toDateTimeString(),
            'delivery_responsibility' => $validated['delivery_responsibility'],
        ]);

        $followUpItem->forceFill(['status' => 'logistics_eta_recorded'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'logistics', 'logistics.eta_recorded', 'ETA recorded.', [
            'eta_at' => $etaAt->toDateTimeString(),
            'delivery_responsibility' => $validated['delivery_responsibility'],
            'agent_name' => $validated['agent_name'] ?? null,
            'agent_contact' => $validated['agent_contact'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'ETA recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markDocumentsSent(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $case = $this->logisticsCaseOrFail($followUpItem);

        $validated = $request->validate([
            'documents_sent_at' => ['required', 'date'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $documentsSentAt = Carbon::parse($validated['documents_sent_at']);
        $status = $case->delivery_responsibility === 'buyer_agent'
            ? 'documents_sent_to_buyer_agent'
            : 'documents_sent_to_agent';

        $case->forceFill([
            'status' => $status,
            'documents_sent_at' => $documentsSentAt,
            'agent_name' => $validated['agent_name'] ?? $case->agent_name,
            'agent_contact' => $validated['agent_contact'] ?? $case->agent_contact,
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, 'documents_sent_to_agent', 'Shipping documents sent to agent', $validated['remarks'] ?? null, [
            'documents_sent_at' => $documentsSentAt->toDateTimeString(),
        ], $documentsSentAt);

        $followUpItem->forceFill(['status' => $status])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'logistics.documents_sent', 'Shipping documents sent to agent.', [
            'documents_sent_at' => $documentsSentAt->toDateTimeString(),
            'delivery_responsibility' => $case->delivery_responsibility,
            'agent_name' => $validated['agent_name'] ?? $case->agent_name,
            'agent_contact' => $validated['agent_contact'] ?? $case->agent_contact,
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Shipping documents handoff recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markArrived(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $case = $this->logisticsCaseOrFail($followUpItem);

        $validated = $request->validate([
            'arrived_at' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        $arrivedAt = Carbon::parse($validated['arrived_at']);
        $case->forceFill([
            'status' => 'arrived',
            'arrived_at' => $arrivedAt,
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, 'arrived', 'Shipment arrived', $validated['remarks'] ?? null, [
            'arrived_at' => $arrivedAt->toDateTimeString(),
        ], $arrivedAt);

        $followUpItem->forceFill(['status' => 'arrived'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'logistics.arrived', 'Shipment arrived.', [
            'arrived_at' => $arrivedAt->toDateTimeString(),
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Shipment arrival recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markWarehouseReceived(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $case = $this->logisticsCaseOrFail($followUpItem);

        $validated = $request->validate([
            'warehouse_received_at' => ['required', 'date'],
            'received_location' => ['required', 'string', 'max:255'],
            'received_quantity' => ['required', 'numeric', 'min:0.001'],
            'goods_condition' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $receivedAt = Carbon::parse($validated['warehouse_received_at']);
        $case->forceFill([
            'status' => 'warehouse_received',
            'warehouse_received_at' => $receivedAt,
            'received_location' => $validated['received_location'],
            'received_quantity' => $validated['received_quantity'],
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, 'warehouse_received', 'Goods received at ISC warehouse', $validated['remarks'] ?? null, [
            'warehouse_received_at' => $receivedAt->toDateTimeString(),
            'received_location' => $validated['received_location'],
            'received_quantity' => $this->money($validated['received_quantity']),
            'goods_condition' => $validated['goods_condition'],
        ], $receivedAt);

        $followUpItem->forceFill(['status' => 'ready_for_delivery_order'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'delivery.warehouse_received', 'Goods received at ISC warehouse.', [
            'warehouse_received_at' => $receivedAt->toDateTimeString(),
            'received_location' => $validated['received_location'],
            'received_quantity' => $this->money($validated['received_quantity']),
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Warehouse receipt recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function markBuyerReceived(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $case = $this->logisticsCaseOrFail($followUpItem);

        $validated = $request->validate([
            'buyer_received_at' => ['required', 'date'],
            'received_quantity' => ['required', 'numeric', 'min:0.001'],
            'goods_condition' => ['required', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

        $receivedAt = Carbon::parse($validated['buyer_received_at']);
        $case->forceFill([
            'status' => 'buyer_received',
            'buyer_received_at' => $receivedAt,
            'received_quantity' => $validated['received_quantity'],
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? $case->remarks,
        ])->save();

        $this->appendLogisticsEvent($case, $request, 'buyer_received', 'Buyer confirmed goods received', $validated['remarks'] ?? null, [
            'buyer_received_at' => $receivedAt->toDateTimeString(),
            'received_quantity' => $this->money($validated['received_quantity']),
            'goods_condition' => $validated['goods_condition'],
        ], $receivedAt);

        $followUpItem->forceFill(['status' => 'ready_for_invoice'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'delivery.buyer_received', 'Buyer confirmed goods received.', [
            'buyer_received_at' => $receivedAt->toDateTimeString(),
            'received_quantity' => $this->money($validated['received_quantity']),
            'goods_condition' => $validated['goods_condition'],
            'remarks' => $validated['remarks'] ?? null,
        ]);

        return response()->json([
            'message' => 'Buyer receipt recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
    }

    public function storeDeliveryOrder(Request $request, FollowUpItem $followUpItem, DeliveryOrderDocumentService $documents): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->requireDeliveryOrderReady($followUpItem);

        $validated = $request->validate([
            'delivery_place' => ['required', 'string', 'max:255'],
            'terms' => ['nullable', 'string'],
        ]);

        $followUpItem->loadMissing(['supplierPoLine', 'quotationItem', 'buyerPo', 'quotation.buyerCompany']);
        $line = $followUpItem->supplierPoLine;

        if (! $line) {
            throw ValidationException::withMessages([
                'delivery_order' => 'This follow-up item is not linked to an active supplier PO line.',
            ]);
        }

        $deliveryOrder = DeliveryOrder::query()->firstOrNew(['follow_up_item_id' => $followUpItem->id]);
        $wasRecentlyCreated = ! $deliveryOrder->exists;
        $deliveryOrder->fill([
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference ?: $this->deliveryOrderReferenceFor($followUpItem),
            'delivery_order_date' => now()->toDateString(),
            'delivery_place' => $validated['delivery_place'],
            'terms' => $validated['terms'] ?? null,
            'status' => $deliveryOrder->status === 'signed' ? 'signed' : 'issued',
            'created_by' => $request->user()->id,
        ])->save();

        $deliveryOrder->items()->delete();
        $deliveryOrder->items()->create([
            'quotation_item_id' => $followUpItem->quotation_item_id,
            'buyer_po_id' => $followUpItem->buyer_po_id,
            'line_number' => 10,
            'item_description' => $followUpItem->quotationItem?->buyer_description ?: $line->item_description,
            'quantity' => $line->quantity,
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

        $snapshot = $documents->snapshot($deliveryOrder->refresh()->load(['items.buyerPo', 'creator']));
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        if ($deliveryOrder->status !== 'signed') {
            $followUpItem->forceFill(['status' => 'delivery_order_created'])->save();
        }

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'delivery_order.generated', 'Delivery order generated.', [
            'delivery_order_id' => $deliveryOrder->id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference,
            'delivery_place' => $deliveryOrder->delivery_place,
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
        $deliveryOrder = $this->deliveryOrderOrFail($followUpItem);

        $validated = $request->validate([
            'signed_at' => ['required', 'date'],
            'signed_file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('signed_file');
        $filePath = $file->store("follow-up/{$followUpItem->id}/signed-delivery-orders", 'local');

        $deliveryOrder->forceFill([
            'status' => 'signed',
            'signed_at' => Carbon::parse($validated['signed_at']),
            'signed_file_path' => $filePath,
            'signed_original_file_name' => $file->getClientOriginalName(),
        ])->save();

        $followUpItem->forceFill(['status' => 'ready_for_invoice'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'delivery', 'delivery_order.signed_uploaded', 'Signed delivery order uploaded.', [
            'delivery_order_id' => $deliveryOrder->id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference,
            'signed_at' => Carbon::parse($validated['signed_at'])->toDateTimeString(),
            'original_file_name' => $file->getClientOriginalName(),
        ]);

        return response()->json([
            'message' => 'Signed delivery order uploaded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ]);
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

        return response()->download(Storage::disk('local')->path($path), Str::slug($deliveryOrder->delivery_order_reference, '-').".{$format}", [
            'Content-Type' => $contentType,
        ]);
    }

    public function storeInvoice(Request $request, FollowUpItem $followUpItem, InvoiceDocumentService $documents): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $this->requireInvoiceReady($followUpItem);

        $validated = $request->validate([
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'vat_exception_reason' => ['nullable', 'string'],
            'bank_details' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        $followUpItem->loadMissing(['quotationItem', 'buyerPo', 'quotation.buyerCompany', 'deliveryOrder']);
        $quotationItem = $followUpItem->quotationItem;

        if (! $quotationItem) {
            throw ValidationException::withMessages([
                'invoice' => 'This follow-up item is not linked to a quotation item.',
            ]);
        }

        $subtotal = round((float) $quotationItem->total_price, 3);
        $vatRate = round((float) $validated['vat_rate'], 3);
        $vatAmount = array_key_exists('vat_amount', $validated) && $validated['vat_amount'] !== null
            ? round((float) $validated['vat_amount'], 3)
            : round($subtotal * ($vatRate / 100), 3);

        if ($vatRate > 0 && $vatAmount === 0.0 && empty($validated['vat_exception_reason'])) {
            throw ValidationException::withMessages([
                'vat_amount' => 'VAT amount is zero while VAT rate is greater than zero. Enter a reason or correct the VAT amount.',
            ]);
        }

        $invoice = Invoice::query()->firstOrNew(['follow_up_item_id' => $followUpItem->id]);
        $wasRecentlyCreated = ! $invoice->exists;
        $invoiceDate = now()->toDateString();
        $invoice->fill([
            'delivery_order_id' => $followUpItem->deliveryOrder?->id,
            'invoice_reference' => $invoice->invoice_reference ?: $this->invoiceReferenceFor($followUpItem),
            'invoice_date' => $invoiceDate,
            'payment_term_days' => $validated['payment_term_days'],
            'due_date' => now()->copy()->addDays((int) $validated['payment_term_days'])->toDateString(),
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
            'delivery_order_id' => $followUpItem->deliveryOrder?->id,
            'line_number' => 10,
            'item_description' => $quotationItem->buyer_description,
            'quantity' => $quotationItem->quantity,
            'uom' => $quotationItem->uom,
            'unit_price' => $quotationItem->unit_price,
            'total_price' => $quotationItem->total_price,
        ]);

        $safeReference = Str::slug($invoice->invoice_reference, '-');
        $basePath = "generated/invoices/{$invoice->id}";
        $docxPath = "{$basePath}/{$safeReference}.docx";
        $pdfPath = "{$basePath}/{$safeReference}.pdf";
        $invoice->forceFill([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
        ])->save();

        $snapshot = $documents->snapshot($invoice->refresh()->load(['items.buyerPo', 'deliveryOrder', 'creator']));
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        $followUpItem->forceFill(['status' => 'invoice_created'])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'invoice', 'invoice.generated', 'Invoice generated.', [
            'invoice_id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
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

        return response()->download(Storage::disk('local')->path($path), Str::slug($invoice->invoice_reference, '-').".{$format}", [
            'Content-Type' => $contentType,
        ]);
    }

    public function markInvoiceSent(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $invoice = $this->invoiceOrFail($followUpItem);

        $validated = $request->validate([
            'sent_at' => ['required', 'date'],
        ]);

        $invoice->forceFill([
            'status' => 'sent',
            'sent_at' => Carbon::parse($validated['sent_at']),
        ])->save();

        $followUpItem->forceFill(['status' => 'payment_pending'])->save();

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
        $invoice = $this->invoiceOrFail($followUpItem);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'payment_date' => ['required', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ]);

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
            'amount' => $amount,
            'currency' => $invoice->currency,
            'payment_date' => Carbon::parse($validated['payment_date'])->toDateString(),
            'payment_reference' => $validated['payment_reference'] ?? null,
            'remarks' => $validated['remarks'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        $invoice->refresh()->load('payments');
        $updatedSummary = $this->paymentSummaryFor($invoice);
        $status = $updatedSummary['balance'] <= 0.0005 ? 'paid' : 'partially_paid';

        $invoice->forceFill(['status' => $status])->save();
        $followUpItem->forceFill(['status' => $status])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'payment.recorded', $status === 'paid' ? 'Invoice paid in full.' : 'Payment recorded.', [
            'invoice_id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
            'amount' => $this->money($amount),
            'currency' => $invoice->currency,
            'payment_date' => Carbon::parse($validated['payment_date'])->toDateString(),
            'payment_reference' => $validated['payment_reference'] ?? null,
            'payment_status' => $status,
            'balance_amount' => $this->money($updatedSummary['balance']),
        ]);

        return response()->json([
            'message' => $status === 'paid' ? 'Invoice paid in full.' : 'Payment recorded.',
            'data' => $this->transformStoredFollowUpItem($request, $followUpItem),
        ], 201);
    }

    public function closeFollowUpItem(Request $request, FollowUpItem $followUpItem): JsonResponse
    {
        $this->authorizeFollowUpItem($request, $followUpItem);
        $invoice = $this->invoiceOrFail($followUpItem);
        $summary = $this->paymentSummaryFor($invoice);

        if ($summary['balance'] > 0.0005) {
            throw ValidationException::withMessages([
                'payment' => 'Invoice must be fully paid before the job can be closed.',
            ]);
        }

        $validated = $request->validate([
            'closed_notes' => ['nullable', 'string'],
        ]);

        $invoice->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        $followUpItem->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_notes' => $validated['closed_notes'] ?? null,
        ])->save();

        $this->logFollowUpAudit($followUpItem, $request, 'payment', 'job.closed', 'Job closed.', [
            'invoice_id' => $invoice->id,
            'invoice_reference' => $invoice->invoice_reference,
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

        if ($user?->hasRole('admin') || $user?->hasRole('follow-up')) {
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
     * @param array{group_by: string, search: string, action: string, stage: string} $filters
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
            'ready_for_delivery_order' => $query->whereIn('status', ['arrived', 'ready_for_delivery_order', 'delivery_order_created']),
            'ready_for_invoice' => $query->where('status', 'ready_for_invoice'),
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
     * @param \Illuminate\Support\Collection<int, FollowUpItem> $items
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $transformedItems
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
     * @param array<string, mixed> $data
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
            'ready_for_delivery_order',
            'delivery_order_created' => 'ready_for_delivery_order',
            'ready_for_invoice' => 'ready_for_invoice',
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
            'supplierPoLine.manufacturer',
            'quotation.buyerCompany',
            'quotation.buyerContact',
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
            'invoice.items.buyerPo',
            'invoice.deliveryOrder',
            'invoice.payments.recorder',
        ];

        if ($includeTimeline) {
            $relations[] = 'quotation.activityLogs.user';
            $relations[] = 'auditLogs.user';
        }

        return $relations;
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
     * @param array<string, mixed> $validated
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
    private function transformFollowUpItem(FollowUpItem $item, bool $includeTimeline = false): array
    {
        $line = $item->supplierPoLine;
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
            'buyer_po_id' => $item->buyer_po_id,
            'buyer_po_number' => $item->buyerPo?->po_number,
            'buyer_po_date' => $item->buyerPo?->po_date?->toDateString(),
            'buyer_company_name' => $item->quotation?->buyerCompany?->name,
            'buyer_contact_name' => $item->quotation?->buyerContact?->name,
            'supplier_company_name' => $item->supplierPo?->supplierCompany?->name,
            'supplier_contact_name' => $item->supplierPo?->supplierContact?->name,
            'salesperson_name' => $item->quotation?->salesperson?->name,
            'assigned_to' => $item->assigned_to,
            'assigned_to_name' => $item->assignee?->name,
            'status' => $item->status,
            'status_label' => $this->statusLabel($item->status),
            'current_stage' => $currentStage,
            'current_stage_label' => self::COMMENT_STAGES[$currentStage],
            'product_name' => $line?->product_name ?? $item->quotationItem?->product_name,
            'title' => $line?->title ?? $item->quotationItem?->title,
            'description' => $line?->item_description ?? $item->quotationItem?->manufacturer_description ?? $item->quotationItem?->buyer_description,
            'quantity' => $line ? $this->money($line->quantity) : $this->money($item->quotationItem?->quantity),
            'uom' => $line?->uom ?? $item->quotationItem?->uom,
            'manufacturer_name' => $line?->manufacturer?->name ?? $item->quotationItem?->manufacturer?->name,
            'reminder_interval_value' => $item->reminder_interval_value,
            'reminder_interval_unit' => $item->reminder_interval_unit,
            'next_follow_up_at' => $item->next_follow_up_at?->toDateTimeString(),
            'last_comment_at' => $item->last_comment_at?->toDateTimeString(),
            'acknowledgement_received_at' => $item->acknowledgement_received_at?->toDateTimeString(),
            'acknowledgement_file_path' => $item->acknowledgement_file_path,
            'acknowledgement_original_file_name' => $item->acknowledgement_original_file_name,
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
            'delivery_order' => $item->deliveryOrder ? $this->transformDeliveryOrder($item->deliveryOrder) : null,
            'invoice' => $item->invoice ? $this->transformInvoice($item->invoice) : null,
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
            'ready_for_delivery_order',
            'delivery_order_created' => 'delivery',
            'ready_for_invoice',
            'invoice_created' => 'invoice',
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
     * @param array<string, mixed> $properties
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

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function ensureShippingDocuments(FollowUpItem $followUpItem): void
    {
        foreach (self::REQUIRED_SHIPPING_DOCUMENTS as $documentType => $label) {
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

        return collect(array_keys(self::REQUIRED_SHIPPING_DOCUMENTS))
            ->map(fn (string $documentType) => $documents->firstWhere('document_type', $documentType))
            ->filter()
            ->values();
    }

    private function shippingDocumentsComplete(FollowUpItem $followUpItem): bool
    {
        $this->ensureShippingDocuments($followUpItem);
        $completeStatuses = ['uploaded', 'approved', 'generated', 'waived'];

        return $this->shippingDocumentsFor($followUpItem)
            ->every(fn (ShippingDocument $document): bool => in_array($document->status, $completeStatuses, true));
    }

    private function requireCompletedShippingDocuments(FollowUpItem $followUpItem): void
    {
        if ($this->shippingDocumentsComplete($followUpItem)) {
            return;
        }

        throw ValidationException::withMessages([
            'shipping_documents' => 'All required shipping documents must be completed before ETA and logistics tracking can start.',
        ]);
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
        $followUpItem->loadMissing('deliveryOrder');

        if ($followUpItem->deliveryOrder || in_array($followUpItem->status, ['ready_for_delivery_order', 'delivery_order_created'], true)) {
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
        $followUpItem->loadMissing('invoice');

        if ($followUpItem->invoice || $followUpItem->status === 'ready_for_invoice') {
            return;
        }

        throw ValidationException::withMessages([
            'invoice' => 'Invoice can only be created after signed delivery order or buyer receipt confirmation.',
        ]);
    }

    private function invoiceOrFail(FollowUpItem $followUpItem): Invoice
    {
        $followUpItem->load('invoice.payments');

        if ($followUpItem->invoice) {
            return $followUpItem->invoice;
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
     * @param array<string, mixed>|null $metadata
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
            'status' => $document->status,
            'document_number' => $document->document_number,
            'document_date' => $document->document_date?->toDateString(),
            'file_path' => $document->file_path,
            'original_file_name' => $document->original_file_name,
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
        $deliveryOrder->loadMissing(['items.buyerPo', 'creator']);

        return [
            'id' => $deliveryOrder->id,
            'delivery_order_reference' => $deliveryOrder->delivery_order_reference,
            'delivery_order_date' => $deliveryOrder->delivery_order_date?->toDateString(),
            'delivery_place' => $deliveryOrder->delivery_place,
            'terms' => $deliveryOrder->terms,
            'status' => $deliveryOrder->status,
            'docx_path' => $deliveryOrder->docx_path,
            'pdf_path' => $deliveryOrder->pdf_path,
            'signed_file_path' => $deliveryOrder->signed_file_path,
            'signed_original_file_name' => $deliveryOrder->signed_original_file_name,
            'signed_at' => $deliveryOrder->signed_at?->toDateTimeString(),
            'created_by_name' => $deliveryOrder->creator?->name,
            'items' => $deliveryOrder->items->map(fn ($item): array => [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'item_description' => $item->item_description,
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'buyer_po_number' => $item->buyerPo?->po_number,
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
        $invoice->loadMissing(['items.buyerPo', 'deliveryOrder', 'creator', 'followUpItem.buyerPo', 'payments.recorder']);
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
            'items' => $invoice->items->map(fn ($item): array => [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'item_description' => $item->item_description,
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'unit_price' => $this->money($item->unit_price),
                'total_price' => $this->money($item->total_price),
                'buyer_po_number' => $item->buyerPo?->po_number,
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

        return sprintf('DO-COR-%03d-%s-%s', $followUpItem->id, $buyerCode, now()->format('y'));
    }

    private function invoiceReferenceFor(FollowUpItem $followUpItem): string
    {
        return sprintf('INV-COR-%03d-%s', $followUpItem->id, now()->format('y'));
    }
}
