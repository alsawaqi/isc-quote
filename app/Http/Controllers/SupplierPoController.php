<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Incoterm;
use App\Models\QuotationActivityLog;
use App\Models\QuotationItem;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Services\FollowUpItemService;
use App\Services\SupplierPoDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierPoController extends Controller
{
    private const PERIOD_UNITS = ['days', 'weeks', 'months'];

    private const DELIVERY_TYPES = ['working', 'calendar'];

    private const DEFAULT_CURRENCIES = ['OMR', 'USD', 'EUR', 'GBP'];

    private const DEFAULT_TERMS = [
        ['key' => 'acknowledgment', 'title' => 'Acknowledgment'],
        ['key' => 'delivery_terms', 'title' => 'Delivery Terms'],
        ['key' => 'documents', 'title' => 'Documents'],
        ['key' => 'warranty', 'title' => 'Warranty'],
        ['key' => 'bank_details', 'title' => 'Bank details'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSupplierPo($request);

        $query = SupplierPo::query()
            ->with(['supplierCompany', 'supplierContact', 'buyerCompany', 'buyerContact', 'incoterm', 'creator'])
            ->withCount('lines')
            ->latest('id');

        if (! $request->user()?->hasRole('admin')) {
            $query->where('created_by', $request->user()?->id);
        }

        return response()->json([
            'data' => $query
                ->limit(250)
                ->get()
                ->map(fn (SupplierPo $supplierPo): array => $this->transformSupplierPoSummary($supplierPo))
                ->values(),
        ]);
    }

    public function createOptions(Request $request): JsonResponse
    {
        $this->authorizeSupplierPo($request);
        $selectedSupplier = $request->filled('supplier_id')
            ? $this->activeSupplierQuery()->findOrFail($request->integer('supplier_id'))
            : null;
        $pendingItemFilters = $this->pendingItemFilters($request);

        return response()->json([
            'buyer' => $this->resolveInternalBuyer($request),
            'suppliers' => $this->activeSupplierQuery()
                ->orderBy('company_id')
                ->get()
                ->map(fn (Supplier $supplier): array => $this->transformSupplier($supplier))
                ->values(),
            'supplier_contacts' => Contact::query()
                ->where('status', 'active')
                ->whereHas('company.suppliers', fn (Builder $query) => $query->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'company_id', 'name', 'email', 'mobile', 'telephone']),
            'incoterms' => Incoterm::query()
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'currencies' => $this->currencyOptions(),
            'period_units' => collect(self::PERIOD_UNITS)->map(fn (string $unit): array => [
                'id' => $unit,
                'name' => Str::ucfirst($unit),
            ])->values(),
            'delivery_types' => collect(self::DELIVERY_TYPES)->map(fn (string $type): array => [
                'id' => $type,
                'name' => Str::ucfirst($type),
            ])->values(),
            'pending_items' => $this->pendingItems($selectedSupplier, null, $pendingItemFilters)->get()->map(fn (QuotationItem $item): array => $this->transformPendingItem($item))->values(),
            'pending_item_filters' => $this->pendingItemFilterOptions($selectedSupplier),
            'term_defaults' => self::DEFAULT_TERMS,
        ]);
    }

    public function store(Request $request, SupplierPoDocumentService $documents, FollowUpItemService $followUps): JsonResponse
    {
        $this->authorizeSupplierPo($request);

        $validated = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'supplier_contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:150'],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_min' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_max' => ['required', 'integer', 'gte:delivery_period_min', 'max:3650'],
            'delivery_period_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'delivery_period_type' => ['required', Rule::in(self::DELIVERY_TYPES)],
            'accepted_invoice_currency' => ['required', Rule::in($this->currencyCodes())],
            'incoterm_id' => [
                'nullable',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'additional_charges_label' => ['nullable', 'string', 'max:150'],
            'additional_charges' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.quotation_item_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'terms' => ['required', 'array', 'min:1', 'max:50'],
            'terms.*.key' => ['nullable', 'string', 'max:100'],
            'terms.*.title' => ['required', 'string', 'max:255'],
            'terms.*.description' => ['required', 'string'],
        ]);

        $supplier = $this->activeSupplierQuery()->findOrFail($validated['supplier_id']);
        $supplierContact = Contact::query()
            ->where('id', $validated['supplier_contact_id'])
            ->where('company_id', $supplier->company_id)
            ->where('status', 'active')
            ->first();

        if (! $supplierContact) {
            throw ValidationException::withMessages([
                'supplier_contact_id' => 'The supplier contact must belong to the selected supplier company.',
            ]);
        }

        $buyer = $this->resolveInternalBuyer($request);
        $requestedItemIds = collect($validated['items'])->pluck('quotation_item_id')->map(fn ($id): int => (int) $id)->all();
        $items = $this->pendingItems($supplier)
            ->whereIn('id', $requestedItemIds)
            ->get()
            ->keyBy('id');

        if ($items->count() !== count($requestedItemIds)) {
            throw ValidationException::withMessages([
                'items' => 'One or more selected items are not ready for supplier PO or were already allocated.',
            ]);
        }

        $supplierPo = DB::transaction(function () use ($request, $validated, $supplier, $supplierContact, $buyer, $requestedItemIds, $items): SupplierPo {
            $subtotal = collect($validated['items'])->sum(function (array $line) use ($items): float {
                $item = $items[(int) $line['quotation_item_id']];

                return (float) $item->quantity * (float) $line['unit_cost'];
            });
            $additionalCharges = $this->money($validated['additional_charges'] ?? 0);

            $supplierPo = SupplierPo::query()->create([
                'po_reference' => 'PENDING',
                'supplier_id' => $supplier->id,
                'supplier_company_id' => $supplier->company_id,
                'supplier_contact_id' => $supplierContact->id,
                'buyer_company_id' => $buyer['company_id'],
                'buyer_contact_id' => $buyer['contact_id'],
                'incoterm_id' => $validated['incoterm_id'] ?? null,
                'supplier_quote_reference' => $validated['supplier_quote_reference'] ?? null,
                'payment_term_days' => $validated['payment_term_days'],
                'delivery_period_min' => $validated['delivery_period_min'],
                'delivery_period_max' => $validated['delivery_period_max'],
                'delivery_period_unit' => $validated['delivery_period_unit'],
                'delivery_period_type' => $validated['delivery_period_type'],
                'accepted_invoice_currency' => $validated['accepted_invoice_currency'],
                'additional_charges_label' => $validated['additional_charges_label'] ?? null,
                'additional_charges' => $additionalCharges,
                'subtotal' => $this->money($subtotal),
                'total_amount' => $this->money($subtotal + (float) $additionalCharges),
                'created_by' => $request->user()->id,
                'status' => 'issued',
            ]);

            $supplierPo->forceFill([
                'po_reference' => $this->referenceFor($supplierPo),
            ])->save();

            foreach ($requestedItemIds as $index => $itemId) {
                $requestLine = collect($validated['items'])->firstWhere('quotation_item_id', $itemId);
                $item = $items[$itemId];
                $buyerPo = $item->quotation->buyerPos->first();
                $unitCost = $this->money($requestLine['unit_cost']);

                $supplierPo->lines()->create([
                    'quotation_id' => $item->quotation_id,
                    'buyer_po_id' => $buyerPo->id,
                    'quotation_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'manufacturer_id' => $item->manufacturer_id,
                    'line_number' => $index + 1,
                    'product_name' => $item->product_name,
                    'title' => $item->title,
                    'item_description' => $item->manufacturer_description ?: $item->buyer_description,
                    'quantity' => $item->quantity,
                    'uom' => $item->uom,
                    'unit_cost' => $unitCost,
                    'total_cost' => $this->money((float) $item->quantity * (float) $unitCost),
                ]);
            }

            foreach ($validated['terms'] as $index => $term) {
                $supplierPo->terms()->create([
                    'line_number' => $index + 1,
                    'key' => $term['key'] ?? null,
                    'title' => trim((string) $term['title']),
                    'description' => trim((string) $term['description']),
                    'is_required_default' => filled($term['key'] ?? null),
                ]);
            }

            foreach ($supplierPo->lines()->with('quotation')->get() as $line) {
                QuotationActivityLog::query()->create([
                    'quotation_id' => $line->quotation_id,
                    'user_id' => $request->user()->id,
                    'action' => 'supplier_po.created',
                    'summary' => $request->user()->name." created supplier PO {$supplierPo->po_reference} for selected item.",
                    'properties' => [
                        'supplier_po_id' => $supplierPo->id,
                        'supplier_po_reference' => $supplierPo->po_reference,
                        'quotation_item_id' => $line->quotation_item_id,
                    ],
                ]);

                $line->quotation->forceFill(['status' => 'supplier_po_created'])->save();
            }

            return $supplierPo;
        });

        $supplierPo->load(['lines', 'terms', 'supplierCompany', 'supplierContact', 'buyerCompany', 'buyerContact', 'incoterm']);
        $followUps->syncSupplierPo($supplierPo);
        $safeReference = Str::slug($supplierPo->po_reference, '-');
        $basePath = "generated/supplier-pos/{$supplierPo->id}";
        $docxPath = "{$basePath}/{$safeReference}.docx";
        $pdfPath = "{$basePath}/{$safeReference}.pdf";
        $supplierPo->forceFill([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'finalized_at' => now(),
        ])->save();

        $snapshot = $documents->snapshot($supplierPo);
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        $supplierPo->refresh()->load([
            'supplierCompany',
            'supplierContact',
            'buyerCompany',
            'buyerContact',
            'incoterm',
            'lines.quotation',
            'lines.buyerPo',
            'lines.manufacturer',
            'terms',
            'creator',
        ]);

        return response()->json([
            'message' => 'Supplier PO created with '.$supplierPo->lines->count().' item(s).',
            'data' => $this->transformSupplierPo($supplierPo),
        ], 201);
    }

    public function show(Request $request, SupplierPo $supplierPo): JsonResponse
    {
        $this->authorizeSupplierPoRecord($request, $supplierPo);

        $supplierPo->load([
            'supplierCompany',
            'supplierContact',
            'buyerCompany',
            'buyerContact',
            'incoterm',
            'lines.quotation.buyerCompany',
            'lines.buyerPo',
            'lines.manufacturer',
            'terms',
            'creator',
        ]);

        return response()->json([
            'data' => $this->transformSupplierPo($supplierPo),
        ]);
    }

    public function update(Request $request, SupplierPo $supplierPo, SupplierPoDocumentService $documents, FollowUpItemService $followUps): JsonResponse
    {
        $this->authorizeSupplierPoRecord($request, $supplierPo);

        $validated = $request->validate([
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'supplier_contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'supplier_quote_reference' => ['nullable', 'string', 'max:150'],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_min' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_max' => ['required', 'integer', 'gte:delivery_period_min', 'max:3650'],
            'delivery_period_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'delivery_period_type' => ['required', Rule::in(self::DELIVERY_TYPES)],
            'accepted_invoice_currency' => ['required', Rule::in($this->currencyCodes())],
            'incoterm_id' => [
                'nullable',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'additional_charges_label' => ['nullable', 'string', 'max:150'],
            'additional_charges' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.quotation_item_id' => ['required', 'integer', 'distinct'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'terms' => ['required', 'array', 'min:1', 'max:50'],
            'terms.*.key' => ['nullable', 'string', 'max:100'],
            'terms.*.title' => ['required', 'string', 'max:255'],
            'terms.*.description' => ['required', 'string'],
        ]);

        $supplier = $this->activeSupplierQuery()->findOrFail($validated['supplier_id']);
        $supplierContact = Contact::query()
            ->where('id', $validated['supplier_contact_id'])
            ->where('company_id', $supplier->company_id)
            ->where('status', 'active')
            ->first();

        if (! $supplierContact) {
            throw ValidationException::withMessages([
                'supplier_contact_id' => 'The supplier contact must belong to the selected supplier company.',
            ]);
        }

        $buyer = $this->resolveInternalBuyer($request);
        $requestedItemIds = collect($validated['items'])->pluck('quotation_item_id')->map(fn ($id): int => (int) $id)->all();
        $items = $this->pendingItems($supplier, $supplierPo)
            ->whereIn('id', $requestedItemIds)
            ->get()
            ->keyBy('id');

        if ($items->count() !== count($requestedItemIds)) {
            throw ValidationException::withMessages([
                'items' => 'One or more selected items are not ready for supplier PO or belong to another supplier PO.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $supplierPo, $supplier, $supplierContact, $buyer, $requestedItemIds, $items): void {
            $subtotal = collect($validated['items'])->sum(function (array $line) use ($items): float {
                $item = $items[(int) $line['quotation_item_id']];

                return (float) $item->quantity * (float) $line['unit_cost'];
            });
            $additionalCharges = $this->money($validated['additional_charges'] ?? 0);

            $supplierPo->fill([
                'supplier_id' => $supplier->id,
                'supplier_company_id' => $supplier->company_id,
                'supplier_contact_id' => $supplierContact->id,
                'buyer_company_id' => $buyer['company_id'],
                'buyer_contact_id' => $buyer['contact_id'],
                'incoterm_id' => $validated['incoterm_id'] ?? null,
                'supplier_quote_reference' => $validated['supplier_quote_reference'] ?? null,
                'payment_term_days' => $validated['payment_term_days'],
                'delivery_period_min' => $validated['delivery_period_min'],
                'delivery_period_max' => $validated['delivery_period_max'],
                'delivery_period_unit' => $validated['delivery_period_unit'],
                'delivery_period_type' => $validated['delivery_period_type'],
                'accepted_invoice_currency' => $validated['accepted_invoice_currency'],
                'additional_charges_label' => $validated['additional_charges_label'] ?? null,
                'additional_charges' => $additionalCharges,
                'subtotal' => $this->money($subtotal),
                'total_amount' => $this->money($subtotal + (float) $additionalCharges),
                'status' => 'issued',
            ])->save();

            $supplierPo->lines()->delete();

            foreach ($requestedItemIds as $index => $itemId) {
                $requestLine = collect($validated['items'])->firstWhere('quotation_item_id', $itemId);
                $item = $items[$itemId];
                $buyerPo = $item->quotation->buyerPos->first();
                $unitCost = $this->money($requestLine['unit_cost']);

                $supplierPo->lines()->create([
                    'quotation_id' => $item->quotation_id,
                    'buyer_po_id' => $buyerPo->id,
                    'quotation_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'manufacturer_id' => $item->manufacturer_id,
                    'line_number' => $index + 1,
                    'product_name' => $item->product_name,
                    'title' => $item->title,
                    'item_description' => $item->manufacturer_description ?: $item->buyer_description,
                    'quantity' => $item->quantity,
                    'uom' => $item->uom,
                    'unit_cost' => $unitCost,
                    'total_cost' => $this->money((float) $item->quantity * (float) $unitCost),
                ]);
            }

            $supplierPo->terms()->delete();

            foreach ($validated['terms'] as $index => $term) {
                $supplierPo->terms()->create([
                    'line_number' => $index + 1,
                    'key' => $term['key'] ?? null,
                    'title' => trim((string) $term['title']),
                    'description' => trim((string) $term['description']),
                    'is_required_default' => filled($term['key'] ?? null),
                ]);
            }

            foreach ($supplierPo->lines()->with('quotation')->get() as $line) {
                QuotationActivityLog::query()->create([
                    'quotation_id' => $line->quotation_id,
                    'user_id' => $request->user()->id,
                    'action' => 'supplier_po.updated',
                    'summary' => $request->user()->name." updated supplier PO {$supplierPo->po_reference}.",
                    'properties' => [
                        'supplier_po_id' => $supplierPo->id,
                        'supplier_po_reference' => $supplierPo->po_reference,
                        'quotation_item_id' => $line->quotation_item_id,
                    ],
                ]);

                $line->quotation->forceFill(['status' => 'supplier_po_created'])->save();
            }
        });

        $supplierPo->load('lines');
        $followUps->syncSupplierPo($supplierPo);

        $this->writeDocuments($supplierPo, $documents);

        $supplierPo->refresh()->load([
            'supplierCompany',
            'supplierContact',
            'buyerCompany',
            'buyerContact',
            'incoterm',
            'lines.quotation.buyerCompany',
            'lines.buyerPo',
            'lines.manufacturer',
            'terms',
            'creator',
        ]);

        return response()->json([
            'message' => 'Supplier PO updated successfully.',
            'data' => $this->transformSupplierPo($supplierPo),
        ]);
    }

    public function download(Request $request, SupplierPo $supplierPo, string $format, SupplierPoDocumentService $documents): BinaryFileResponse
    {
        $this->authorizeSupplierPoRecord($request, $supplierPo);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $path = $format === 'docx' ? $supplierPo->docx_path : $supplierPo->pdf_path;

        if (! $path) {
            abort(404);
        }

        if ($format === 'docx' && ! $documents->docxXmlPartsAreParseable($path)) {
            $documents->writeDocx($documents->snapshot($supplierPo), $path);
        }

        if ($format === 'pdf' && ! Storage::disk('local')->exists($path)) {
            $documents->writePdf($documents->snapshot($supplierPo), $path);
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';

        return response()->download(Storage::disk('local')->path($path), Str::slug($supplierPo->po_reference, '-').".{$format}", [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * @return Collection<int, array{id: string, code: string, name: string, exchange_rate?: string|null}>
     */
    private function currencyOptions(): Collection
    {
        $configured = Currency::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['code', 'name', 'exchange_rate'])
            ->map(fn (Currency $currency): array => [
                'id' => $currency->code,
                'code' => $currency->code,
                'name' => $currency->name,
                'exchange_rate' => $currency->exchange_rate,
            ]);

        $defaults = collect(self::DEFAULT_CURRENCIES)->map(fn (string $currency): array => [
            'id' => $currency,
            'code' => $currency,
            'name' => $currency,
            'exchange_rate' => null,
        ]);

        return $configured->concat($defaults)->unique('id')->values();
    }

    /**
     * @return array<int, string>
     */
    private function currencyCodes(): array
    {
        return $this->currencyOptions()
            ->pluck('id')
            ->map(fn (mixed $currency): string => (string) $currency)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function pendingItems(?Supplier $supplier = null, ?SupplierPo $supplierPo = null, array $filters = []): Builder
    {
        $query = QuotationItem::query()
            ->with(['manufacturer', 'quotation.buyerCompany', 'quotation.buyerPos'])
            ->whereHas('quotation.buyerPos')
            ->where(function (Builder $builder) use ($supplierPo): void {
                $builder->whereDoesntHave('supplierPoLines');

                if ($supplierPo) {
                    $builder->orWhereHas('supplierPoLines', fn (Builder $lineQuery) => $lineQuery->where('supplier_po_id', $supplierPo->id));
                }
            })
            ->latest('id');

        if ($supplier?->manufacturer_id) {
            $query->where('manufacturer_id', $supplier->manufacturer_id);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder
                    ->where('product_name', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('buyer_description', 'like', $like)
                    ->orWhere('manufacturer_description', 'like', $like)
                    ->orWhereHas('manufacturer', fn (Builder $manufacturerQuery) => $manufacturerQuery->where('name', 'like', $like))
                    ->orWhereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery
                        ->where('quotation_reference', 'like', $like)
                        ->orWhere('rfq_number', 'like', $like)
                        ->orWhere('pr_number', 'like', $like)
                        ->orWhereHas('buyerCompany', fn (Builder $buyerQuery) => $buyerQuery
                            ->where('name', 'like', $like)
                            ->orWhere('company_code', 'like', $like))
                        ->orWhereHas('buyerPos', fn (Builder $buyerPoQuery) => $buyerPoQuery->where('po_number', 'like', $like)));
            });
        }

        $quotationReference = trim((string) ($filters['quotation_reference'] ?? ''));
        if ($quotationReference !== '') {
            $query->whereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery->where('quotation_reference', 'like', '%'.$quotationReference.'%'));
        }

        if (filled($filters['buyer_id'] ?? null)) {
            $query->whereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery->where('buyer_company_id', (int) $filters['buyer_id']));
        }

        if (filled($filters['manufacturer_id'] ?? null)) {
            $query->where('manufacturer_id', (int) $filters['manufacturer_id']);
        }

        if (filled($filters['buyer_po_date_from'] ?? null)) {
            $query->whereHas('quotation.buyerPos', fn (Builder $buyerPoQuery) => $buyerPoQuery->whereDate('po_date', '>=', (string) $filters['buyer_po_date_from']));
        }

        if (filled($filters['buyer_po_date_to'] ?? null)) {
            $query->whereHas('quotation.buyerPos', fn (Builder $buyerPoQuery) => $buyerPoQuery->whereDate('po_date', '<=', (string) $filters['buyer_po_date_to']));
        }

        if ((bool) ($filters['current_only'] ?? false)) {
            $query->whereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery->whereNotIn('status', ['closed', 'cancelled', 'rejected', 'lost']));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingItemFilters(Request $request): array
    {
        return [
            'search' => trim((string) $request->query('search', '')),
            'quotation_reference' => trim((string) $request->query('quotation_reference', '')),
            'buyer_id' => $request->filled('buyer_id') ? $request->integer('buyer_id') : null,
            'manufacturer_id' => $request->filled('manufacturer_id') ? $request->integer('manufacturer_id') : null,
            'buyer_po_date_from' => $request->filled('buyer_po_date_from') ? (string) $request->query('buyer_po_date_from') : null,
            'buyer_po_date_to' => $request->filled('buyer_po_date_to') ? (string) $request->query('buyer_po_date_to') : null,
            'current_only' => $request->boolean('current_only'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingItemFilterOptions(?Supplier $supplier): array
    {
        $items = $this->pendingItems($supplier)->get();

        return [
            'buyers' => $items
                ->map(fn (QuotationItem $item): ?array => $item->quotation?->buyerCompany ? [
                    'value' => (string) $item->quotation->buyerCompany->id,
                    'label' => $item->quotation->buyerCompany->name,
                ] : null)
                ->filter()
                ->unique('value')
                ->sortBy('label')
                ->values(),
            'manufacturers' => $items
                ->map(fn (QuotationItem $item): ?array => $item->manufacturer ? [
                    'value' => (string) $item->manufacturer->id,
                    'label' => $item->manufacturer->name,
                ] : null)
                ->filter()
                ->unique('value')
                ->sortBy('label')
                ->values(),
            'quotations' => $items
                ->map(fn (QuotationItem $item): ?array => $item->quotation ? [
                    'value' => (string) $item->quotation->quotation_reference,
                    'label' => $item->quotation->quotation_reference,
                ] : null)
                ->filter()
                ->unique('value')
                ->sortBy('label')
                ->values(),
        ];
    }

    private function writeDocuments(SupplierPo $supplierPo, SupplierPoDocumentService $documents): void
    {
        $supplierPo->load(['lines', 'terms', 'supplierCompany', 'supplierContact', 'buyerCompany', 'buyerContact', 'incoterm']);
        $safeReference = Str::slug($supplierPo->po_reference, '-');
        $basePath = "generated/supplier-pos/{$supplierPo->id}";
        $docxPath = $supplierPo->docx_path ?: "{$basePath}/{$safeReference}.docx";
        $pdfPath = $supplierPo->pdf_path ?: "{$basePath}/{$safeReference}.pdf";

        $supplierPo->forceFill([
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'finalized_at' => now(),
        ])->save();

        $snapshot = $documents->snapshot($supplierPo);
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);
    }

    private function activeSupplierQuery(): Builder
    {
        return Supplier::query()
            ->with(['company', 'primaryContact', 'manufacturer'])
            ->where('status', 'active')
            ->whereHas('company', fn (Builder $query) => $query
                ->whereIn('company_type', ['supplier', 'manufacturer', 'mixed'])
                ->where('status', 'active'));
    }

    private function authorizeSupplierPo(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin') || $user->hasRole('salesperson') || $user->hasPermission('create-supplier-pos')) {
            return;
        }

        abort(403);
    }

    private function authorizeSupplierPoRecord(Request $request, SupplierPo $supplierPo): void
    {
        $this->authorizeSupplierPo($request);

        $user = $request->user();

        if ($user?->hasRole('admin') || $supplierPo->created_by === $user?->id) {
            return;
        }

        abort(403);
    }

    /**
     * @return array{company_id: int, company_name: string, contact_id: int, contact_name: string}
     */
    private function resolveInternalBuyer(Request $request): array
    {
        $user = $request->user();
        $user?->loadMissing('contact.company');

        $contact = $user?->contact;
        $company = $contact?->company;

        if (! $contact || ! $company) {
            $company = $this->defaultInternalCompany();
            $contact = Contact::query()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->first();
        }

        if (! $contact || ! $company) {
            throw ValidationException::withMessages([
                'buyer_contact_id' => 'Internal buyer company contact is not configured for supplier POs.',
            ]);
        }

        return [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'contact_id' => $contact->id,
            'contact_name' => $contact->name,
        ];
    }

    private function defaultInternalCompany(): Company
    {
        $company = Company::query()
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->where('company_code', 'ISC')
                    ->orWhere('company_type', 'internal');
            })
            ->orderByRaw("case when company_code = 'ISC' then 0 else 1 end")
            ->orderBy('id')
            ->first();

        if (! $company) {
            throw ValidationException::withMessages([
                'buyer_company_id' => 'Internal buyer company is not configured for supplier POs.',
            ]);
        }

        return $company;
    }

    private function referenceFor(SupplierPo $supplierPo): string
    {
        $supplierPo->loadMissing('supplierCompany');
        $supplierCode = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $supplierPo->supplierCompany?->company_code) ?: 'SUP');

        return sprintf('ISC-COR-PO-%03d-%s-%s', $supplierPo->id, $supplierCode, now()->format('y'));
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSupplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'company_id' => $supplier->company_id,
            'company_name' => $supplier->company?->name,
            'company_code' => $supplier->company?->company_code,
            'primary_contact_id' => $supplier->primary_contact_id,
            'primary_contact_name' => $supplier->primaryContact?->name,
            'manufacturer_id' => $supplier->manufacturer_id,
            'manufacturer_name' => $supplier->manufacturer?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPendingItem(QuotationItem $item): array
    {
        $buyerPo = $item->quotation->buyerPos->first();

        return [
            'quotation_item_id' => $item->id,
            'quotation_id' => $item->quotation_id,
            'quotation_reference' => $item->quotation?->quotation_reference,
            'quotation_status' => $item->quotation?->status,
            'quotation_closing_at' => $item->quotation?->closing_at?->toDateTimeString(),
            'buyer_company_name' => $item->quotation?->buyerCompany?->name,
            'buyer_po_id' => $buyerPo?->id,
            'buyer_po_number' => $buyerPo?->po_number,
            'buyer_po_date' => $buyerPo?->po_date?->toDateString(),
            'manufacturer_id' => $item->manufacturer_id,
            'manufacturer_name' => $item->manufacturer?->name,
            'product_name' => $item->product_name,
            'title' => $item->title,
            'description' => $item->manufacturer_description ?: $item->buyer_description,
            'quantity' => $this->money($item->quantity),
            'uom' => $item->uom,
            'quotation_unit_price' => $this->money($item->unit_price),
            'quotation_total_price' => $this->money($item->total_price),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSupplierPo(SupplierPo $supplierPo): array
    {
        return [
            'id' => $supplierPo->id,
            'po_reference' => $supplierPo->po_reference,
            'supplier_id' => $supplierPo->supplier_id,
            'supplier_contact_id' => $supplierPo->supplier_contact_id,
            'incoterm_id' => $supplierPo->incoterm_id,
            'supplier_company_name' => $supplierPo->supplierCompany?->name,
            'supplier_contact_name' => $supplierPo->supplierContact?->name,
            'buyer_company_name' => $supplierPo->buyerCompany?->name,
            'buyer_contact_name' => $supplierPo->buyerContact?->name,
            'supplier_quote_reference' => $supplierPo->supplier_quote_reference,
            'payment_term_days' => $supplierPo->payment_term_days,
            'delivery_period_min' => $supplierPo->delivery_period_min,
            'delivery_period_max' => $supplierPo->delivery_period_max,
            'delivery_period_unit' => $supplierPo->delivery_period_unit,
            'delivery_period_type' => $supplierPo->delivery_period_type,
            'accepted_invoice_currency' => $supplierPo->accepted_invoice_currency,
            'additional_charges_label' => $supplierPo->additional_charges_label,
            'additional_charges' => $this->money($supplierPo->additional_charges),
            'subtotal' => $this->money($supplierPo->subtotal),
            'total_amount' => $this->money($supplierPo->total_amount),
            'docx_path' => $supplierPo->docx_path,
            'pdf_path' => $supplierPo->pdf_path,
            'created_by_name' => $supplierPo->creator?->name,
            'finalized_at' => $supplierPo->finalized_at?->toDateTimeString(),
            'lines' => $supplierPo->lines->map(fn (SupplierPoLine $line): array => [
                'id' => $line->id,
                'quotation_id' => $line->quotation_id,
                'quotation_reference' => $line->quotation?->quotation_reference,
                'buyer_company_name' => $line->quotation?->buyerCompany?->name,
                'buyer_po_id' => $line->buyer_po_id,
                'buyer_po_number' => $line->buyerPo?->po_number,
                'quotation_item_id' => $line->quotation_item_id,
                'manufacturer_id' => $line->manufacturer_id,
                'manufacturer_name' => $line->manufacturer?->name,
                'product_name' => $line->product_name,
                'title' => $line->title,
                'description' => $line->item_description,
                'quantity' => $this->money($line->quantity),
                'uom' => $line->uom,
                'unit_cost' => $this->money($line->unit_cost),
                'total_cost' => $this->money($line->total_cost),
            ])->values(),
            'terms' => $supplierPo->terms->map(fn ($term): array => [
                'id' => $term->id,
                'key' => $term->key,
                'title' => $term->title,
                'description' => $term->description,
                'line_number' => $term->line_number,
            ])->values(),
            'downloads' => [
                'docx' => "/api/supplier-pos/{$supplierPo->id}/download/docx",
                'pdf' => "/api/supplier-pos/{$supplierPo->id}/download/pdf",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSupplierPoSummary(SupplierPo $supplierPo): array
    {
        return [
            'id' => $supplierPo->id,
            'po_reference' => $supplierPo->po_reference,
            'supplier_company_name' => $supplierPo->supplierCompany?->name,
            'supplier_contact_name' => $supplierPo->supplierContact?->name,
            'buyer_company_name' => $supplierPo->buyerCompany?->name,
            'buyer_contact_name' => $supplierPo->buyerContact?->name,
            'incoterm_code' => $supplierPo->incoterm?->code,
            'accepted_invoice_currency' => $supplierPo->accepted_invoice_currency,
            'lines_count' => (int) ($supplierPo->lines_count ?? 0),
            'total_amount' => $this->money($supplierPo->total_amount),
            'status' => $supplierPo->status,
            'created_by_name' => $supplierPo->creator?->name,
            'finalized_at' => $supplierPo->finalized_at?->toDateTimeString(),
            'downloads' => [
                'docx' => "/api/supplier-pos/{$supplierPo->id}/download/docx",
                'pdf' => "/api/supplier-pos/{$supplierPo->id}/download/pdf",
            ],
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
