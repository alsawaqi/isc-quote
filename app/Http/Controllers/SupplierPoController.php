<?php

namespace App\Http\Controllers;

use App\Models\BuyerPo;
use App\Models\BuyerPoItem;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\QuotationActivityLog;
use App\Models\QuotationItem;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\SupplierPoRevision;
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
            ->with(['supplierCompany', 'supplierContact', 'companyLocation.country', 'companyLocation.manufacturer', 'buyerCompany', 'buyerContact', 'incoterm', 'creator'])
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
        $historicalPo = null;

        if ($request->filled('supplier_po_id')) {
            $historicalPo = SupplierPo::query()
                ->with(['supplierContact', 'companyLocation.country', 'companyLocation.manufacturer', 'lines.companyLocation.country', 'lines.companyLocation.manufacturer'])
                ->findOrFail($request->integer('supplier_po_id'));
            $this->authorizeSupplierPoRecord($request, $historicalPo);
        }

        $selectedSupplierId = $request->filled('supplier_id')
            ? $request->integer('supplier_id')
            : $historicalPo?->supplier_id;
        $selectedSupplier = $selectedSupplierId
            ? $this->activeSupplierQuery()->find($selectedSupplierId)
            : null;

        if (! $selectedSupplier && $historicalPo && $historicalPo->supplier_id === $selectedSupplierId) {
            $selectedSupplier = $this->supplierQuery()->findOrFail($selectedSupplierId);
        } elseif ($selectedSupplierId && ! $selectedSupplier) {
            abort(404);
        }

        if ($selectedSupplier && $historicalPo) {
            $locations = $selectedSupplier->company?->locations ?? collect();
            $historicalLocations = collect([$historicalPo->companyLocation])
                ->merge($historicalPo->lines->pluck('companyLocation'))
                ->filter();

            foreach ($historicalLocations as $historicalLocation) {
                if (! $locations->contains('id', $historicalLocation->id)) {
                    $locations->push($historicalLocation);
                }
            }

            $selectedSupplier->company?->setRelation('locations', $locations);
        }

        $suppliers = $this->activeSupplierQuery()->orderBy('company_id')->get();

        if ($selectedSupplier && ! $suppliers->contains('id', $selectedSupplier->id)) {
            $suppliers->push($selectedSupplier);
        }

        $supplierCompanyIds = $suppliers->pluck('company_id')->unique();
        $supplierContacts = Contact::query()
            ->with([
                'locations' => fn ($query) => $query
                    ->where('company_locations.status', 'active')
                    ->where('company_locations.location_type', 'factory')
                    ->orderBy('company_locations.name'),
                'locations.country',
                'locations.manufacturer',
            ])
            ->whereIn('company_id', $supplierCompanyIds)
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->where('serves_supplier', true)
                    ->orWhere(function (Builder $legacyQuery): void {
                        $legacyQuery
                            ->whereHas('company', fn (Builder $companyQuery) => $companyQuery
                                ->whereIn('company_type', ['supplier', 'manufacturer', 'mixed']))
                            ->whereDoesntHave('company.contacts', fn (Builder $contactQuery) => $contactQuery
                                ->where('status', 'active')
                                ->where('serves_supplier', true));
                    });
            })
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'email', 'mobile', 'telephone', 'serves_supplier', 'all_locations', 'is_primary_supplier']);

        if ($historicalPo?->supplierContact && ! $supplierContacts->contains('id', $historicalPo->supplierContact->id)) {
            $historicalPo->supplierContact->load(['locations.country', 'locations.manufacturer']);
            $supplierContacts->push($historicalPo->supplierContact);
        }

        $pendingItemFilters = $this->pendingItemFilters($request);

        return response()->json([
            'buyer' => $this->resolveInternalBuyer($request),
            'suppliers' => $suppliers
                ->map(fn (Supplier $supplier): array => $this->transformSupplier($supplier))
                ->values(),
            'supplier_contacts' => $supplierContacts
                ->map(fn (Contact $contact): array => $this->transformSupplierContact($contact))
                ->values(),
            'incoterms' => Incoterm::query()
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'countries' => Country::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'country_code']),
            'currencies' => $this->currencyOptions(),
            'period_units' => collect(self::PERIOD_UNITS)->map(fn (string $unit): array => [
                'id' => $unit,
                'name' => Str::ucfirst($unit),
            ])->values(),
            'delivery_types' => collect(self::DELIVERY_TYPES)->map(fn (string $type): array => [
                'id' => $type,
                'name' => Str::ucfirst($type),
            ])->values(),
            'pending_items' => $this->pendingItems($selectedSupplier, $historicalPo, $pendingItemFilters)->get()->map(fn (QuotationItem $item): array => $this->transformPendingItem($item))->values(),
            'pending_item_filters' => $this->pendingItemFilterOptions($selectedSupplier, $historicalPo, $pendingItemFilters),
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
            'company_location_id' => ['nullable', 'integer', 'exists:company_locations,id'],
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
            'items.*.item_description' => ['nullable', 'string'],
            'items.*.company_location_id' => ['nullable', 'integer', 'exists:company_locations,id'],
            'items.*.delivery_date' => ['nullable', 'date'],
            'items.*.incoterm_id' => [
                'nullable',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.coo_entries' => ['nullable', 'array', 'max:10'],
            'items.*.coo_entries.*.country_id' => [
                'nullable',
                'integer',
                Rule::exists('countries', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.coo_entries.*.country_name' => ['nullable', 'string', 'max:120'],
            'items.*.coo_entries.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.coo_entries.*.location' => ['nullable', 'string', 'max:255'],
            'terms' => ['required', 'array', 'min:1', 'max:50'],
            'terms.*.line_number' => ['nullable', 'integer', 'min:1', 'max:999'],
            'terms.*.key' => ['nullable', 'string', 'max:100'],
            'terms.*.title' => ['required', 'string', 'max:255'],
            'terms.*.description' => ['required', 'string'],
        ]);

        $supplier = $this->activeSupplierQuery()->find($validated['supplier_id']);

        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Select an active supplier with at least one active manufacturer.',
            ]);
        }
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

        $companyLocation = $this->validateSupplierFactoryAndContact(
            $supplier,
            $supplierContact,
            $validated['company_location_id'] ?? null,
        );

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

        $lineFactoryIds = $this->validateLineFactories(
            $supplier,
            $supplierContact,
            $companyLocation,
            $validated['items'],
            $items,
        );
        $normalizedTerms = $this->normalizedTerms($validated['terms']);

        $supplierPo = DB::transaction(function () use ($request, $validated, $normalizedTerms, $supplier, $supplierContact, $companyLocation, $buyer, $requestedItemIds, $items, $lineFactoryIds): SupplierPo {
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
                'company_location_id' => $companyLocation?->id,
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
                $buyerPoItem = $this->buyerPoItemFor($item->quotation->buyerPos->first(), $item);
                $buyerPo = $buyerPoItem?->buyerPo ?? $item->quotation->buyerPos->first();

                if (! $buyerPo) {
                    throw ValidationException::withMessages([
                        'items' => 'Every selected item must have a buyer PO recorded first.',
                    ]);
                }

                $unitCost = $this->money($requestLine['unit_cost']);

                $line = $supplierPo->lines()->create([
                    'quotation_id' => $item->quotation_id,
                    'buyer_po_id' => $buyerPo->id,
                    'buyer_po_item_id' => $buyerPoItem?->id,
                    'quotation_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'manufacturer_id' => $item->manufacturer_id,
                    'company_location_id' => $lineFactoryIds[$item->id] ?? null,
                    'line_number' => $index + 1,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'title' => $item->title,
                    'item_description' => $requestLine['item_description'] ?? ($item->manufacturer_description ?: $item->buyer_description),
                    'quantity' => $item->quantity,
                    'uom' => $item->uom,
                    // Delivery dates are confirmed at the supplier-PO stage, after the buyer PO is received.
                    'delivery_date' => $requestLine['delivery_date'] ?? null,
                    'incoterm_id' => $requestLine['incoterm_id'] ?? $supplierPo->incoterm_id,
                    'unit_cost' => $unitCost,
                    'total_cost' => $this->money((float) $item->quantity * (float) $unitCost),
                ]);
                $this->syncLineOrigins($line, $requestLine['coo_entries'] ?? []);
            }

            foreach ($normalizedTerms as $term) {
                $supplierPo->terms()->create([
                    'line_number' => $term['line_number'],
                    'key' => $term['key'] ?? null,
                    'title' => $term['title'],
                    'description' => $term['description'],
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

        $supplierPo->load(['lines.incoterm', 'lines.companyLocation.country', 'lines.companyLocation.manufacturer', 'lines.origins.country', 'terms', 'supplierCompany', 'supplierContact', 'companyLocation.country', 'companyLocation.manufacturer', 'buyerCompany', 'buyerContact', 'incoterm']);
        $followUps->syncSupplierPo($supplierPo);
        $this->writeDocuments($supplierPo, $documents, $request->user()->id);

        $supplierPo->refresh()->load([
            'supplierCompany',
            'supplierContact',
            'companyLocation.country',
            'companyLocation.manufacturer',
            'buyerCompany',
            'buyerContact',
            'incoterm',
            'lines.quotation',
            'lines.buyerPo',
            'lines.buyerPoItem',
            'lines.manufacturer',
            'lines.companyLocation.country',
            'lines.companyLocation.manufacturer',
            'lines.incoterm',
            'lines.origins.country',
            'terms',
            'revisions.creator',
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
            'companyLocation.country',
            'companyLocation.manufacturer',
            'buyerCompany',
            'buyerContact',
            'incoterm',
            'lines.quotation.buyerCompany',
            'lines.buyerPo',
            'lines.buyerPoItem',
            'lines.manufacturer',
            'lines.companyLocation.country',
            'lines.companyLocation.manufacturer',
            'lines.incoterm',
            'lines.origins.country',
            'terms',
            'revisions.creator',
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
                Rule::exists('suppliers', 'id'),
            ],
            'supplier_contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'company_location_id' => ['nullable', 'integer', 'exists:company_locations,id'],
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
            'items.*.item_description' => ['nullable', 'string'],
            'items.*.company_location_id' => ['nullable', 'integer', 'exists:company_locations,id'],
            'items.*.delivery_date' => ['nullable', 'date'],
            'items.*.incoterm_id' => [
                'nullable',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.coo_entries' => ['nullable', 'array', 'max:10'],
            'items.*.coo_entries.*.country_id' => [
                'nullable',
                'integer',
                Rule::exists('countries', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.coo_entries.*.country_name' => ['nullable', 'string', 'max:120'],
            'items.*.coo_entries.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.coo_entries.*.location' => ['nullable', 'string', 'max:255'],
            'terms' => ['required', 'array', 'min:1', 'max:50'],
            'terms.*.line_number' => ['nullable', 'integer', 'min:1', 'max:999'],
            'terms.*.key' => ['nullable', 'string', 'max:100'],
            'terms.*.title' => ['required', 'string', 'max:255'],
            'terms.*.description' => ['required', 'string'],
        ]);

        $supplier = $this->activeSupplierQuery()->find($validated['supplier_id']);

        if (! $supplier && (int) $validated['supplier_id'] === (int) $supplierPo->supplier_id) {
            $supplier = $this->supplierQuery()->find($validated['supplier_id']);
        }

        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Select an active, manufacturer-linked supplier.',
            ]);
        }

        $retainingContact = (int) $validated['supplier_contact_id'] === (int) $supplierPo->supplier_contact_id
            && $supplier->id === $supplierPo->supplier_id;
        $supplierContactQuery = Contact::query()
            ->where('id', $validated['supplier_contact_id'])
            ->where('company_id', $supplier->company_id);

        if (! $retainingContact) {
            $supplierContactQuery->where('status', 'active');
        }

        $supplierContact = $supplierContactQuery->first();

        if (! $supplierContact) {
            throw ValidationException::withMessages([
                'supplier_contact_id' => 'The supplier contact must belong to the selected supplier company.',
            ]);
        }

        $companyLocation = $this->validateSupplierFactoryAndContact(
            $supplier,
            $supplierContact,
            $validated['company_location_id'] ?? null,
            $supplierPo,
        );

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

        $lineFactoryIds = $this->validateLineFactories(
            $supplier,
            $supplierContact,
            $companyLocation,
            $validated['items'],
            $items,
            $supplierPo,
        );
        $normalizedTerms = $this->normalizedTerms($validated['terms']);

        DB::transaction(function () use ($request, $validated, $normalizedTerms, $supplierPo, $supplier, $supplierContact, $companyLocation, $buyer, $requestedItemIds, $items, $lineFactoryIds): void {
            $subtotal = collect($validated['items'])->sum(function (array $line) use ($items): float {
                $item = $items[(int) $line['quotation_item_id']];

                return (float) $item->quantity * (float) $line['unit_cost'];
            });
            $additionalCharges = $this->money($validated['additional_charges'] ?? 0);

            $supplierPo->fill([
                'supplier_id' => $supplier->id,
                'supplier_company_id' => $supplier->company_id,
                'supplier_contact_id' => $supplierContact->id,
                'company_location_id' => $companyLocation?->id,
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
                $buyerPoItem = $this->buyerPoItemFor($item->quotation->buyerPos->first(), $item);
                $buyerPo = $buyerPoItem?->buyerPo ?? $item->quotation->buyerPos->first();

                if (! $buyerPo) {
                    throw ValidationException::withMessages([
                        'items' => 'Every selected item must have a buyer PO recorded first.',
                    ]);
                }

                $unitCost = $this->money($requestLine['unit_cost']);

                $line = $supplierPo->lines()->create([
                    'quotation_id' => $item->quotation_id,
                    'buyer_po_id' => $buyerPo->id,
                    'buyer_po_item_id' => $buyerPoItem?->id,
                    'quotation_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'manufacturer_id' => $item->manufacturer_id,
                    'company_location_id' => $lineFactoryIds[$item->id] ?? null,
                    'line_number' => $index + 1,
                    'product_code' => $item->product_code,
                    'product_name' => $item->product_name,
                    'title' => $item->title,
                    'item_description' => $requestLine['item_description'] ?? ($item->manufacturer_description ?: $item->buyer_description),
                    'quantity' => $item->quantity,
                    'uom' => $item->uom,
                    // Delivery dates are confirmed at the supplier-PO stage, after the buyer PO is received.
                    'delivery_date' => $requestLine['delivery_date'] ?? null,
                    'incoterm_id' => $requestLine['incoterm_id'] ?? $supplierPo->incoterm_id,
                    'unit_cost' => $unitCost,
                    'total_cost' => $this->money((float) $item->quantity * (float) $unitCost),
                ]);
                $this->syncLineOrigins($line, $requestLine['coo_entries'] ?? []);
            }

            $supplierPo->terms()->delete();

            foreach ($normalizedTerms as $term) {
                $supplierPo->terms()->create([
                    'line_number' => $term['line_number'],
                    'key' => $term['key'] ?? null,
                    'title' => $term['title'],
                    'description' => $term['description'],
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

        $this->writeDocuments($supplierPo, $documents, $request->user()->id);

        $supplierPo->refresh()->load([
            'supplierCompany',
            'supplierContact',
            'companyLocation.country',
            'companyLocation.manufacturer',
            'buyerCompany',
            'buyerContact',
            'incoterm',
            'lines.quotation.buyerCompany',
            'lines.buyerPo',
            'lines.buyerPoItem',
            'lines.manufacturer',
            'lines.companyLocation.country',
            'lines.companyLocation.manufacturer',
            'lines.incoterm',
            'lines.origins.country',
            'terms',
            'revisions.creator',
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

        $filename = $this->supplierPoDownloadFileName($supplierPo->po_reference, $format, (int) $supplierPo->revision_number);

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    public function downloadRevision(Request $request, SupplierPo $supplierPo, int $revisionNumber, string $format, SupplierPoDocumentService $documents): BinaryFileResponse
    {
        $this->authorizeSupplierPoRecord($request, $supplierPo);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $revision = $supplierPo->revisions()
            ->where('revision_number', $revisionNumber)
            ->firstOrFail();
        $path = $format === 'docx' ? $revision->docx_path : $revision->pdf_path;

        if (! $path) {
            abort(404);
        }

        if ($format === 'docx' && ! $documents->docxXmlPartsAreParseable($path)) {
            $documents->writeDocx($revision->snapshot, $path);
        }

        if ($format === 'pdf' && ! Storage::disk('local')->exists($path)) {
            $documents->writePdf($revision->snapshot, $path);
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';
        $filename = $this->supplierPoDownloadFileName($revision->po_reference, $format, $revision->revision_number);

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * @return Collection<int, array{id: string, code: string, name: string, exchange_rate?: string|null}>
     */
    private function currencyOptions(): Collection
    {
        return Currency::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['code', 'name', 'exchange_rate'])
            ->map(fn (Currency $currency): array => [
                'id' => $currency->code,
                'code' => $currency->code,
                'name' => $currency->name,
                'exchange_rate' => $currency->exchange_rate,
            ])
            ->values();
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
            ->with(['manufacturer', 'incoterm', 'buyerPoItems.buyerPo', 'quotation.buyerCompany', 'quotation.buyerPos'])
            ->where(function (Builder $builder): void {
                $builder
                    ->whereHas('buyerPoItems')
                    ->orWhereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery
                        ->whereHas('buyerPos')
                        ->whereDoesntHave('buyerPoItems'));
            })
            ->where(function (Builder $builder) use ($supplierPo): void {
                $builder->whereDoesntHave('supplierPoLines');

                if ($supplierPo) {
                    $builder->orWhereHas('supplierPoLines', fn (Builder $lineQuery) => $lineQuery->where('supplier_po_id', $supplierPo->id));
                }
            })
            ->latest('id');

        if ($supplier) {
            $manufacturerIds = $this->supplierManufacturers($supplier)->pluck('id');

            if ($supplierPo && $supplierPo->supplier_id === $supplier->id) {
                $manufacturerIds = $manufacturerIds
                    ->merge($supplierPo->lines()->pluck('manufacturer_id'))
                    ->filter()
                    ->unique()
                    ->values();
            }

            if ($manufacturerIds->isNotEmpty()) {
                $query->whereIn('manufacturer_id', $manufacturerIds);
            } else {
                $query->whereRaw('1 = 0');
            }

            if (! empty($filters['company_location_id'])) {
                $factory = CompanyLocation::query()
                    ->where('company_id', $supplier->company_id)
                    ->where('location_type', 'factory')
                    ->where(function (Builder $factoryQuery) use ($supplierPo): void {
                        $factoryQuery->where('status', 'active');

                        if ($supplierPo?->company_location_id) {
                            $factoryQuery->orWhere('id', $supplierPo->company_location_id);
                        }
                    })
                    ->find((int) $filters['company_location_id']);

                if (! $factory) {
                    $query->whereRaw('1 = 0');
                } elseif ($factory->manufacturer_id) {
                    $query->where('manufacturer_id', $factory->manufacturer_id);
                }
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder
                    ->where('product_code', 'like', $like)
                    ->orWhere('product_name', 'like', $like)
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
                        ->orWhereHas('buyerPoItems.buyerPo', fn (Builder $buyerPoQuery) => $buyerPoQuery->where('po_number', 'like', $like))
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
            $query->where(function (Builder $builder) use ($filters): void {
                $builder
                    ->whereHas('buyerPoItems.buyerPo', fn (Builder $buyerPoQuery) => $buyerPoQuery->whereDate('po_date', '>=', (string) $filters['buyer_po_date_from']))
                    ->orWhereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery
                        ->whereDoesntHave('buyerPoItems')
                        ->whereHas('buyerPos', fn (Builder $buyerPoQuery) => $buyerPoQuery->whereDate('po_date', '>=', (string) $filters['buyer_po_date_from'])));
            });
        }

        if (filled($filters['buyer_po_date_to'] ?? null)) {
            $query->where(function (Builder $builder) use ($filters): void {
                $builder
                    ->whereHas('buyerPoItems.buyerPo', fn (Builder $buyerPoQuery) => $buyerPoQuery->whereDate('po_date', '<=', (string) $filters['buyer_po_date_to']))
                    ->orWhereHas('quotation', fn (Builder $quotationQuery) => $quotationQuery
                        ->whereDoesntHave('buyerPoItems')
                        ->whereHas('buyerPos', fn (Builder $buyerPoQuery) => $buyerPoQuery->whereDate('po_date', '<=', (string) $filters['buyer_po_date_to'])));
            });
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
            'company_location_id' => $request->filled('company_location_id') ? $request->integer('company_location_id') : null,
            'buyer_po_date_from' => $request->filled('buyer_po_date_from') ? (string) $request->query('buyer_po_date_from') : null,
            'buyer_po_date_to' => $request->filled('buyer_po_date_to') ? (string) $request->query('buyer_po_date_to') : null,
            'current_only' => $request->boolean('current_only'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingItemFilterOptions(?Supplier $supplier, ?SupplierPo $supplierPo = null, array $filters = []): array
    {
        $items = $this->pendingItems($supplier, $supplierPo, [
            'company_location_id' => $filters['company_location_id'] ?? null,
        ])->get();

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

    private function writeDocuments(SupplierPo $supplierPo, SupplierPoDocumentService $documents, int $userId): void
    {
        $supplierPo->load([
            'lines.incoterm',
            'lines.companyLocation.country',
            'lines.companyLocation.manufacturer',
            'lines.origins.country',
            'terms',
            'supplierCompany',
            'supplierContact',
            'companyLocation.country',
            'companyLocation.manufacturer',
            'buyerCompany',
            'buyerContact',
            'incoterm',
        ]);

        $safeReference = Str::slug($supplierPo->po_reference, '-') ?: 'supplier-po-'.$supplierPo->id;
        $basePath = "generated/supplier-pos/{$supplierPo->id}";
        $docxPath = "{$basePath}/{$safeReference}.docx";
        $pdfPath = "{$basePath}/{$safeReference}.pdf";
        $revisionNumber = $this->nextRevisionNumber($supplierPo);
        $revisionBasePath = "{$basePath}/revisions/rev-{$revisionNumber}";
        $revisionDocxPath = "{$revisionBasePath}/{$safeReference}-rev-{$revisionNumber}.docx";
        $revisionPdfPath = "{$revisionBasePath}/{$safeReference}-rev-{$revisionNumber}.pdf";
        $finalizedAt = now();

        $supplierPo->forceFill([
            'revision_number' => $revisionNumber,
            'docx_path' => $docxPath,
            'pdf_path' => $pdfPath,
            'finalized_at' => $finalizedAt,
        ])->save();

        $snapshot = $documents->snapshot($supplierPo);
        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);
        $documents->writeDocx($snapshot, $revisionDocxPath);
        $documents->writePdf($snapshot, $revisionPdfPath);

        $supplierPo->revisions()->create([
            'revision_number' => $revisionNumber,
            'po_reference' => $supplierPo->po_reference,
            'snapshot' => $snapshot,
            'docx_path' => $revisionDocxPath,
            'pdf_path' => $revisionPdfPath,
            'created_by' => $userId,
            'finalized_at' => $finalizedAt,
        ]);
    }

    private function nextRevisionNumber(SupplierPo $supplierPo): int
    {
        $latestStoredRevision = (int) $supplierPo->revisions()->max('revision_number');

        if ($latestStoredRevision === 0 && ! $supplierPo->docx_path && ! $supplierPo->pdf_path) {
            return 1;
        }

        return max((int) $supplierPo->revision_number, $latestStoredRevision) + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $terms
     * @return Collection<int, array{line_number: int, key: string|null, title: string, description: string}>
     */
    private function normalizedTerms(array $terms): Collection
    {
        return collect($terms)
            ->map(fn (array $term, int $index): array => [
                'submitted_line_number' => filled($term['line_number'] ?? null) ? (int) $term['line_number'] : $index + 1,
                'submitted_index' => $index,
                'key' => filled($term['key'] ?? null) ? (string) $term['key'] : null,
                'title' => trim((string) $term['title']),
                'description' => trim((string) $term['description']),
            ])
            ->sortBy(fn (array $term): string => sprintf('%05d-%05d', $term['submitted_line_number'], $term['submitted_index']))
            ->values()
            ->map(fn (array $term, int $index): array => [
                'line_number' => $index + 1,
                'key' => $term['key'],
                'title' => $term['title'],
                'description' => $term['description'],
            ]);
    }

    private function supplierPoDownloadFileName(string $reference, string $format, ?int $revisionNumber = null): string
    {
        $safeReference = Str::slug($reference, '-') ?: 'supplier-po';
        $revisionSuffix = $revisionNumber !== null ? "-rev-{$revisionNumber}" : '';

        return Str::upper("{$safeReference}{$revisionSuffix}.{$format}");
    }

    private function activeSupplierQuery(): Builder
    {
        return $this->supplierQuery()
            ->where('status', 'active')
            ->whereHas('company', fn (Builder $query) => $query
                ->whereIn('company_type', ['supplier', 'manufacturer', 'mixed'])
                ->where('status', 'active'))
            ->where(function (Builder $query): void {
                $query->whereHas('manufacturers', fn (Builder $manufacturer) => $manufacturer->where('status', 'active'))
                    ->orWhereHas('manufacturer', fn (Builder $manufacturer) => $manufacturer->where('status', 'active'));
            });
    }

    private function supplierQuery(): Builder
    {
        return Supplier::query()
            ->with([
                'company',
                'company.locations' => fn ($query) => $query
                    ->where('status', 'active')
                    ->where('location_type', 'factory')
                    ->orderBy('name'),
                'company.locations.country',
                'company.locations.manufacturer',
                'primaryContact',
                'manufacturer',
                'manufacturers' => fn ($query) => $query->orderBy('name'),
            ]);
    }

    private function validateSupplierFactoryAndContact(
        Supplier $supplier,
        Contact $contact,
        mixed $companyLocationId,
        ?SupplierPo $existingSupplierPo = null,
    ): ?CompanyLocation {
        $requestedLocationId = filled($companyLocationId) ? (int) $companyLocationId : null;
        $retainingHistoricalAssociation = $existingSupplierPo
            && $existingSupplierPo->supplier_id === $supplier->id
            && $existingSupplierPo->supplier_contact_id === $contact->id
            && $existingSupplierPo->company_location_id === $requestedLocationId;

        if (
            ! $retainingHistoricalAssociation
            && ($contact->status !== 'active' || ! $this->supplierContactIsEligible($contact))
        ) {
            throw ValidationException::withMessages([
                'supplier_contact_id' => 'The selected contact is not assigned to the supplier role.',
            ]);
        }

        $factoryQuery = CompanyLocation::query()
            ->where('company_id', $supplier->company_id)
            ->where('location_type', 'factory');
        $activeFactoryQuery = (clone $factoryQuery)->where('status', 'active');

        if (! filled($companyLocationId)) {
            if ($activeFactoryQuery->exists()) {
                throw ValidationException::withMessages([
                    'company_location_id' => 'Select the supplier factory for this purchase order.',
                ]);
            }

            return null;
        }

        $companyLocation = (clone $factoryQuery)->find($requestedLocationId);
        $retainingHistoricalFactory = $existingSupplierPo
            && $existingSupplierPo->supplier_id === $supplier->id
            && $existingSupplierPo->company_location_id === $requestedLocationId;

        if (! $companyLocation || ($companyLocation->status !== 'active' && ! $retainingHistoricalFactory)) {
            throw ValidationException::withMessages([
                'company_location_id' => 'The selected factory must be an active factory of the supplier company.',
            ]);
        }

        if (
            ! $retainingHistoricalAssociation
            && ! $contact->all_locations
            && ! $contact->locations()->whereKey($companyLocation->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'supplier_contact_id' => 'The selected contact is not assigned to the selected supplier factory.',
            ]);
        }

        return $companyLocation;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requestLines
     * @param  Collection<int, QuotationItem>  $items
     * @return array<int, int|null>
     */
    private function validateLineFactories(
        Supplier $supplier,
        Contact $contact,
        ?CompanyLocation $defaultFactory,
        array $requestLines,
        Collection $items,
        ?SupplierPo $existingSupplierPo = null,
    ): array {
        $factoryQuery = CompanyLocation::query()
            ->where('company_id', $supplier->company_id)
            ->where('location_type', 'factory');
        $activeFactoriesExist = (clone $factoryQuery)->where('status', 'active')->exists();
        $existingLineFactories = $existingSupplierPo
            ? $existingSupplierPo->lines()->pluck('company_location_id', 'quotation_item_id')
            : collect();
        $lineFactoryIds = [];

        foreach ($requestLines as $index => $requestLine) {
            $itemId = (int) $requestLine['quotation_item_id'];
            /** @var QuotationItem|null $item */
            $item = $items->get($itemId);
            $requestedFactoryId = filled($requestLine['company_location_id'] ?? null)
                ? (int) $requestLine['company_location_id']
                : $defaultFactory?->id;

            if (! $requestedFactoryId) {
                if ($activeFactoriesExist) {
                    throw ValidationException::withMessages([
                        "items.{$index}.company_location_id" => 'Select the supplier factory for this item.',
                    ]);
                }

                $lineFactoryIds[$itemId] = null;

                continue;
            }

            $factory = (clone $factoryQuery)->find($requestedFactoryId);
            $retainingHistoricalFactory = $existingSupplierPo
                && (int) $existingSupplierPo->supplier_id === (int) $supplier->id
                && (int) $existingLineFactories->get($itemId) === $requestedFactoryId;

            if (! $factory || ($factory->status !== 'active' && ! $retainingHistoricalFactory)) {
                throw ValidationException::withMessages([
                    "items.{$index}.company_location_id" => 'The selected factory must be an active factory of the supplier company.',
                ]);
            }

            if (
                ! $retainingHistoricalFactory
                && ($contact->status !== 'active' || ! $this->supplierContactIsEligible($contact))
            ) {
                throw ValidationException::withMessages([
                    'supplier_contact_id' => 'The selected contact is not assigned to the supplier role.',
                ]);
            }

            if (
                ! $retainingHistoricalFactory
                && ! $contact->all_locations
                && ! $contact->locations()->whereKey($factory->id)->exists()
            ) {
                throw ValidationException::withMessages([
                    'supplier_contact_id' => 'The selected contact is not assigned to the selected item factory.',
                ]);
            }

            if (
                $item
                && $factory->manufacturer_id
                && (int) $item->manufacturer_id !== (int) $factory->manufacturer_id
                && ! $retainingHistoricalFactory
            ) {
                throw ValidationException::withMessages([
                    "items.{$index}.company_location_id" => 'The selected factory manufacturer does not match this item.',
                ]);
            }

            $lineFactoryIds[$itemId] = $factory->id;
        }

        return $lineFactoryIds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $origins
     */
    private function syncLineOrigins(SupplierPoLine $line, array $origins): void
    {
        $line->origins()->delete();
        $countryNames = Country::query()
            ->whereIn('id', collect($origins)->pluck('country_id')->filter()->unique()->values())
            ->pluck('name', 'id');
        $lineNumber = 1;

        foreach ($origins as $origin) {
            $countryId = filled($origin['country_id'] ?? null) ? (int) $origin['country_id'] : null;
            $countryName = $countryId
                ? $countryNames->get($countryId)
                : trim((string) ($origin['country_name'] ?? ''));
            $amount = filled($origin['amount'] ?? null) ? $this->money($origin['amount']) : null;
            $location = trim((string) ($origin['location'] ?? ''));

            if (! $countryId && $countryName === '' && $amount === null && $location === '') {
                continue;
            }

            $line->origins()->create([
                'country_id' => $countryId,
                'country_name' => $countryName !== '' ? $countryName : null,
                'amount' => $amount,
                'location' => $location !== '' ? $location : null,
                'line_number' => $lineNumber++,
            ]);
        }
    }

    private function supplierContactIsEligible(Contact $contact): bool
    {
        if ($contact->serves_supplier) {
            return true;
        }

        return ! Contact::query()
            ->where('company_id', $contact->company_id)
            ->where('status', 'active')
            ->where('serves_supplier', true)
            ->exists();
    }

    /**
     * @return Collection<int, Manufacturer>
     */
    private function supplierManufacturers(Supplier $supplier): Collection
    {
        $manufacturers = $supplier->relationLoaded('manufacturers')
            ? $supplier->manufacturers
            : $supplier->manufacturers()->get();

        if ($manufacturers->isEmpty() && $supplier->manufacturer_id) {
            $supplier->loadMissing('manufacturer');

            if ($supplier->manufacturer) {
                $manufacturers = collect([$supplier->manufacturer]);
            }
        }

        return $manufacturers
            ->where('status', 'active')
            ->unique('id')
            ->values();
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
        $manufacturers = $this->supplierManufacturers($supplier);
        $primaryManufacturer = $supplier->manufacturer ?? $manufacturers->first();
        $locations = $supplier->company?->relationLoaded('locations')
            ? $supplier->company->locations
            : $supplier->company?->locations()
                ->where('status', 'active')
                ->where('location_type', 'factory')
                ->with(['country', 'manufacturer'])
                ->orderBy('name')
                ->get() ?? collect();

        return [
            'id' => $supplier->id,
            'company_id' => $supplier->company_id,
            'company_name' => $supplier->company?->name,
            'company_code' => $supplier->company?->company_code,
            'primary_contact_id' => $supplier->primary_contact_id,
            'primary_contact_name' => $supplier->primaryContact?->name,
            'manufacturer_id' => $primaryManufacturer?->id,
            'manufacturer_name' => $primaryManufacturer?->name,
            'manufacturer_ids' => $manufacturers->pluck('id')->values(),
            'manufacturers' => $manufacturers->map(fn ($manufacturer): array => [
                'id' => $manufacturer->id,
                'name' => $manufacturer->name,
            ])->values(),
            'requires_factory' => $locations->isNotEmpty(),
            'locations' => $locations
                ->map(fn (CompanyLocation $location): array => $this->transformCompanyLocation($location))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformSupplierContact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'company_id' => $contact->company_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'mobile' => $contact->mobile,
            'telephone' => $contact->telephone,
            'serves_supplier' => (bool) $contact->serves_supplier,
            'all_locations' => (bool) $contact->all_locations,
            'is_primary_supplier' => (bool) $contact->is_primary_supplier,
            'status' => $contact->status,
            'location_ids' => $contact->locations->pluck('id')->values(),
            'locations' => $contact->locations
                ->map(fn (CompanyLocation $location): array => $this->transformCompanyLocation($location))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformCompanyLocation(CompanyLocation $location): array
    {
        return [
            'id' => $location->id,
            'company_id' => $location->company_id,
            'name' => $location->name,
            'location' => $location->location,
            'address' => $location->address,
            'country_id' => $location->country_id,
            'country_name' => $location->country?->name,
            'manufacturer_id' => $location->manufacturer_id,
            'manufacturer_name' => $location->manufacturer?->name,
            'location_type' => $location->location_type,
            'status' => $location->status,
        ];
    }

    private function buyerPoItemFor(?BuyerPo $buyerPo, QuotationItem $item): ?BuyerPoItem
    {
        $existingForItem = BuyerPoItem::query()
            ->with('buyerPo')
            ->where('quotation_item_id', $item->id)
            ->orderBy('id')
            ->first();

        if ($existingForItem) {
            return $existingForItem;
        }

        if (! $buyerPo) {
            return null;
        }

        $existing = BuyerPoItem::query()
            ->with('buyerPo')
            ->where('buyer_po_id', $buyerPo->id)
            ->where('quotation_item_id', $item->id)
            ->orderBy('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $usedLineNumbers = BuyerPoItem::query()
            ->where('buyer_po_id', $buyerPo->id)
            ->pluck('line_number')
            ->map(fn ($lineNumber): int => (int) $lineNumber)
            ->all();
        $lineNumber = (int) $item->line_number;

        if (in_array($lineNumber, $usedLineNumbers, true)) {
            $lineNumber = empty($usedLineNumbers) ? 1 : max($usedLineNumbers) + 1;
        }

        return BuyerPoItem::query()->create([
            'buyer_po_id' => $buyerPo->id,
            'quotation_id' => $item->quotation_id,
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
        ])->load('buyerPo');
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPendingItem(QuotationItem $item): array
    {
        $buyerPoItem = $item->buyerPoItems->first();
        $buyerPo = $buyerPoItem?->buyerPo ?? $item->quotation->buyerPos->first();

        return [
            'quotation_item_id' => $item->id,
            'quotation_id' => $item->quotation_id,
            'quotation_reference' => $item->quotation?->quotation_reference,
            'quotation_status' => $item->quotation?->status,
            'quotation_closing_at' => $item->quotation?->closing_at?->toDateTimeString(),
            'buyer_company_name' => $item->quotation?->buyerCompany?->name,
            'buyer_po_id' => $buyerPo?->id,
            'buyer_po_item_id' => $buyerPoItem?->id,
            'buyer_po_number' => $buyerPo?->po_number,
            'buyer_po_date' => $buyerPo?->po_date?->toDateString(),
            'buyer_item_code' => $buyerPoItem?->buyer_item_code,
            'buyer_po_item_amount' => $buyerPoItem ? $this->money($buyerPoItem->total_amount) : null,
            'incoterm_id' => $item->incoterm_id,
            'incoterm_code' => $item->incoterm?->code,
            'manufacturer_id' => $item->manufacturer_id,
            'manufacturer_name' => $item->manufacturer?->name,
            'product_code' => $item->product_code,
            'product_name' => $item->product_name,
            'title' => $item->title,
            'description' => $item->manufacturer_description,
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
            'revision_number' => (int) $supplierPo->revision_number,
            'supplier_id' => $supplierPo->supplier_id,
            'supplier_company_id' => $supplierPo->supplier_company_id,
            'supplier_contact_id' => $supplierPo->supplier_contact_id,
            'company_location_id' => $supplierPo->company_location_id,
            'factory' => $supplierPo->companyLocation
                ? $this->transformCompanyLocation($supplierPo->companyLocation)
                : null,
            'incoterm_id' => $supplierPo->incoterm_id,
            'supplier_company_name' => $supplierPo->supplierCompany?->name,
            'supplier_contact_name' => $supplierPo->supplierContact?->name,
            'factory_name' => $supplierPo->companyLocation?->name,
            'factory_location' => $supplierPo->companyLocation?->location,
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
                'buyer_po_item_id' => $line->buyer_po_item_id,
                'buyer_item_code' => $line->buyerPoItem?->buyer_item_code,
                'quotation_item_id' => $line->quotation_item_id,
                'manufacturer_id' => $line->manufacturer_id,
                'manufacturer_name' => $line->manufacturer?->name,
                'company_location_id' => $line->company_location_id,
                'factory' => $line->companyLocation
                    ? $this->transformCompanyLocation($line->companyLocation)
                    : null,
                'factory_name' => $line->companyLocation?->name,
                'factory_location' => $line->companyLocation?->location,
                'factory_country_name' => $line->companyLocation?->country?->name,
                'factory_manufacturer_name' => $line->companyLocation?->manufacturer?->name,
                'product_code' => $line->product_code,
                'product_name' => $line->product_name,
                'title' => $line->title,
                'description' => $line->item_description,
                'quantity' => $this->money($line->quantity),
                'uom' => $line->uom,
                'delivery_date' => $line->delivery_date?->toDateString(),
                'incoterm_id' => $line->incoterm_id,
                'incoterm_code' => $line->incoterm?->code,
                'unit_cost' => $this->money($line->unit_cost),
                'total_cost' => $this->money($line->total_cost),
                'coo_entries' => $line->origins->map(fn ($origin): array => [
                    'id' => $origin->id,
                    'country_id' => $origin->country_id,
                    'country_name' => $origin->country?->name ?? $origin->country_name,
                    'country_code' => $origin->country?->country_code,
                    'amount' => $origin->amount !== null ? $this->money($origin->amount) : null,
                    'location' => $origin->location,
                    'line_number' => $origin->line_number,
                ])->values(),
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
            'revisions' => $supplierPo->relationLoaded('revisions')
                ? $supplierPo->revisions->map(fn (SupplierPoRevision $revision): array => [
                    'id' => $revision->id,
                    'revision_number' => $revision->revision_number,
                    'po_reference' => $revision->po_reference,
                    'finalized_at' => $revision->finalized_at?->toDateTimeString(),
                    'created_by_name' => $revision->creator?->name,
                    'downloads' => [
                        'docx' => "/api/supplier-pos/{$supplierPo->id}/revisions/{$revision->revision_number}/download/docx",
                        'pdf' => "/api/supplier-pos/{$supplierPo->id}/revisions/{$revision->revision_number}/download/pdf",
                    ],
                ])->values()
                : [],
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
            'revision_number' => (int) $supplierPo->revision_number,
            'supplier_company_name' => $supplierPo->supplierCompany?->name,
            'supplier_contact_name' => $supplierPo->supplierContact?->name,
            'company_location_id' => $supplierPo->company_location_id,
            'factory_name' => $supplierPo->companyLocation?->name,
            'factory_location' => $supplierPo->companyLocation?->location,
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
