<?php

namespace App\Http\Controllers;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationActivityLog;
use App\Models\QuotationItem;
use App\Models\QuotationTerm;
use App\Models\QuotationVersion;
use App\Models\Supplier;
use App\Models\User;
use App\Services\QuotationDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuotationController extends Controller
{
    private const PERIOD_UNITS = ['days', 'weeks', 'months'];

    private const DELIVERY_TYPES = ['working', 'calendar'];

    private const CURRENCIES = ['OMR', 'USD', 'EUR', 'GBP'];

    private const RESPONSIBILITIES = ['isc', 'buyer'];

    private const UOMS = ['EA', 'PCS', 'SET', 'LOT', 'MTR', 'KG'];

    private const DEFAULT_TERMS = [
        ['key' => 'cancellation', 'title' => 'Cancellation'],
        ['key' => 'scope_of_work', 'title' => 'Scope of Work'],
        ['key' => 'delivery_term', 'title' => 'Delivery Term'],
        ['key' => 'warranty', 'title' => 'Warranty'],
        ['key' => 'force_majeure', 'title' => 'Force Majeure'],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeQuotation($request);

        $query = Quotation::query()
            ->with(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson'])
            ->withCount('items')
            ->withSum('items', 'total_price')
            ->latest();

        if (! $request->user()?->hasRole('admin')) {
            $query->where('salesperson_id', $request->user()->id);
        }

        return response()->json([
            'data' => $query->limit(100)->get()->map(fn (Quotation $quotation) => $this->transform($quotation))->values(),
        ]);
    }

    public function show(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $quotation->load([
            'buyerPos.quotationVersion',
            'buyerPos.creator',
            'buyerCompany',
            'buyerContact',
            'supplierCompany',
            'supplierContact',
            'incoterm',
            'salesperson',
            'items.manufacturer',
            'terms',
            'versions.creator',
            'activityLogs.user',
        ]);

        return response()->json([
            'data' => [
                ...$this->transform($quotation),
                'items' => $quotation->items->map(fn (QuotationItem $item) => $this->transformItem($item))->values(),
                'terms' => $quotation->terms->map(fn (QuotationTerm $term) => $this->transformTerm($term))->values(),
                'versions' => $quotation->versions->map(fn (QuotationVersion $version) => $this->transformVersion($version))->values(),
                'buyer_po' => $quotation->buyerPos->first() ? $this->transformBuyerPo($quotation->buyerPos->first()) : null,
                'activity_logs' => $quotation->activityLogs->map(fn (QuotationActivityLog $log) => $this->transformActivityLog($log))->values(),
            ],
        ]);
    }

    public function createOptions(Request $request): JsonResponse
    {
        $this->authorizeQuotation($request);
        $supplier = $this->resolveSupplier($request->user());

        return response()->json([
            'supplier' => $supplier,
            'buyers' => Company::query()
                ->whereIn('company_type', ['buyer', 'mixed'])
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'company_code']),
            'buyer_contacts' => Contact::query()
                ->whereHas('company', fn (Builder $query) => $query->whereIn('company_type', ['buyer', 'mixed']))
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'company_id', 'name', 'email', 'mobile']),
            'incoterms' => Incoterm::query()
                ->where('status', 'active')
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'manufacturers' => Manufacturer::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'currencies' => collect(self::CURRENCIES)->map(fn (string $currency) => [
                'id' => $currency,
                'name' => $currency,
            ])->values(),
            'uoms' => collect(self::UOMS)->map(fn (string $uom) => [
                'id' => $uom,
                'name' => $uom,
            ])->values(),
            'period_units' => collect(self::PERIOD_UNITS)->map(fn (string $unit) => [
                'id' => $unit,
                'name' => Str::ucfirst($unit),
            ])->values(),
            'delivery_responsibilities' => [
                ['id' => 'isc', 'name' => 'ISC / supplier responsible'],
                ['id' => 'buyer', 'name' => 'Buyer responsible'],
            ],
            'term_defaults' => self::DEFAULT_TERMS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeQuotation($request);
        $supplier = $this->resolveSupplier($request->user());

        $validated = $request->validate([
            'buyer_company_id' => [
                'required',
                'integer',
                Rule::exists('companies', 'id')->where(fn ($query) => $query
                    ->whereIn('company_type', ['buyer', 'mixed'])
                    ->where('status', 'active')),
            ],
            'buyer_contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query
                    ->where('company_id', $request->input('buyer_company_id'))
                    ->where('status', 'active')),
            ],
            'rfq_number' => ['nullable', 'string', 'max:100'],
            'pr_number' => ['nullable', 'string', 'max:100'],
            'closing_at' => ['required', 'date'],
            'quotation_validity_value' => ['required', 'integer', 'min:1', 'max:3650'],
            'quotation_validity_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_min' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_max' => ['required', 'integer', 'gte:delivery_period_min', 'max:3650'],
            'delivery_period_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'delivery_period_type' => ['required', Rule::in(self::DELIVERY_TYPES)],
            'accepted_invoice_currency' => ['required', Rule::in(self::CURRENCIES)],
            'incoterm_id' => [
                'required',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'delivery_responsibility' => ['required', Rule::in(self::RESPONSIBILITIES)],
        ]);

        $quotation = DB::transaction(function () use ($request, $supplier, $validated): Quotation {
            $quotation = Quotation::query()->create([
                ...$validated,
                'quotation_reference' => 'PENDING',
                'salesperson_id' => $request->user()->id,
                'supplier_company_id' => $supplier['company_id'],
                'supplier_contact_id' => $supplier['contact_id'],
                'status' => 'draft',
            ]);

            $quotation->forceFill([
                'quotation_reference' => $this->referenceFor($quotation),
            ])->save();

            return $quotation->load(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson']);
        });

        $this->logActivity(
            $request,
            $quotation,
            'quotation.created',
            $request->user()->name.' created quotation draft.',
            ['quotation_reference' => $quotation->quotation_reference]
        );

        return response()->json([
            'message' => 'Quotation step one saved.',
            'data' => $this->transform($quotation),
        ], 201);
    }

    public function update(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $validated = $request->validate([
            'buyer_company_id' => [
                'required',
                'integer',
                Rule::exists('companies', 'id')->where(fn ($query) => $query
                    ->whereIn('company_type', ['buyer', 'mixed'])
                    ->where('status', 'active')),
            ],
            'buyer_contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query
                    ->where('company_id', $request->input('buyer_company_id'))
                    ->where('status', 'active')),
            ],
            'rfq_number' => ['nullable', 'string', 'max:100'],
            'pr_number' => ['nullable', 'string', 'max:100'],
            'closing_at' => ['required', 'date'],
            'quotation_validity_value' => ['required', 'integer', 'min:1', 'max:3650'],
            'quotation_validity_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_min' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_max' => ['required', 'integer', 'gte:delivery_period_min', 'max:3650'],
            'delivery_period_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'delivery_period_type' => ['required', Rule::in(self::DELIVERY_TYPES)],
            'accepted_invoice_currency' => ['required', Rule::in(self::CURRENCIES)],
            'incoterm_id' => [
                'required',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'delivery_responsibility' => ['required', Rule::in(self::RESPONSIBILITIES)],
        ]);

        $quotation = DB::transaction(function () use ($quotation, $validated): Quotation {
            $quotation->forceFill($validated)->save();

            return $quotation->load(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson']);
        });

        $this->logActivity(
            $request,
            $quotation,
            'quotation.commercial_updated',
            $request->user()->name.' updated quotation commercial details.',
            ['quotation_reference' => $quotation->quotation_reference]
        );

        return response()->json([
            'message' => 'Quotation commercial details updated.',
            'data' => $this->transform($quotation),
        ]);
    }

    public function storeItems(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.manufacturer_id' => [
                'required',
                'integer',
                Rule::exists('manufacturers', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.buyer_description' => ['nullable', 'string'],
            'items.*.manufacturer_description' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
            'items.*.uom' => ['required', 'string', 'max:24'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);

        $quotation = DB::transaction(function () use ($quotation, $validated): Quotation {
            $quotation->items()->delete();

            foreach ($validated['items'] as $index => $item) {
                $product = Product::query()->updateOrCreate(
                    [
                        'manufacturer_id' => $item['manufacturer_id'],
                        'name' => trim((string) $item['product_name']),
                        'title' => trim((string) $item['title']),
                    ],
                    [
                        'buyer_description' => $item['buyer_description'] ?? null,
                        'manufacturer_description' => $item['manufacturer_description'] ?? null,
                        'last_uom' => trim((string) $item['uom']),
                        'last_unit_price' => $this->money($item['unit_price']),
                        'status' => 'active',
                    ]
                );

                $quotation->items()->create([
                    'product_id' => $product->id,
                    'manufacturer_id' => $product->manufacturer_id,
                    'line_number' => $index + 1,
                    'product_name' => $product->name,
                    'title' => $product->title,
                    'buyer_description' => $item['buyer_description'] ?? null,
                    'manufacturer_description' => $item['manufacturer_description'] ?? null,
                    'quantity' => $this->money($item['quantity']),
                    'uom' => trim((string) $item['uom']),
                    'unit_price' => $this->money($item['unit_price']),
                    'total_price' => $this->money(((float) $item['quantity']) * ((float) $item['unit_price'])),
                ]);
            }

            return $quotation->load([
                'buyerCompany',
                'buyerContact',
                'supplierCompany',
                'supplierContact',
                'incoterm',
                'salesperson',
                'items.manufacturer',
            ]);
        });

        $this->logActivity(
            $request,
            $quotation,
            'quotation.items_updated',
            $request->user()->name.' updated quotation products.',
            ['items_count' => $quotation->items->count()]
        );

        return response()->json([
            'message' => 'Quotation items saved.',
            'data' => [
                ...$this->transform($quotation),
                'items' => $quotation->items->map(fn (QuotationItem $item) => $this->transformItem($item))->values(),
                'totals' => [
                    'subtotal' => $this->money($quotation->items->sum(fn (QuotationItem $item) => (float) $item->total_price)),
                ],
            ],
        ]);
    }

    public function storeTerms(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $validated = $request->validate([
            'terms' => ['required', 'array', 'min:5', 'max:50'],
            'terms.*.key' => ['nullable', 'string', 'max:100'],
            'terms.*.title' => ['required', 'string', 'max:255'],
            'terms.*.description' => ['required', 'string'],
        ]);

        $defaultKeys = collect(self::DEFAULT_TERMS)->pluck('key');
        $submittedDefaultKeys = collect($validated['terms'])
            ->pluck('key')
            ->filter()
            ->unique()
            ->values();

        if ($defaultKeys->diff($submittedDefaultKeys)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'terms' => 'Cancellation, scope of work, delivery term, warranty, and force majeure are required.',
            ]);
        }

        $quotation = DB::transaction(function () use ($quotation, $validated, $defaultKeys): Quotation {
            $quotation->terms()->delete();

            foreach ($validated['terms'] as $index => $term) {
                $key = filled($term['key'] ?? null) ? (string) $term['key'] : null;

                $quotation->terms()->create([
                    'line_number' => $index + 1,
                    'key' => $key,
                    'title' => trim((string) $term['title']),
                    'description' => trim((string) $term['description']),
                    'is_required_default' => $key !== null && $defaultKeys->contains($key),
                ]);
            }

            return $quotation->load(['terms']);
        });

        $this->logActivity(
            $request,
            $quotation,
            'quotation.terms_updated',
            $request->user()->name.' updated quotation terms and conditions.',
            ['terms_count' => $quotation->terms->count()]
        );

        return response()->json([
            'message' => 'Quotation terms saved.',
            'data' => [
                ...$this->transform($quotation),
                'terms' => $quotation->terms->map(fn (QuotationTerm $term) => $this->transformTerm($term))->values(),
            ],
        ]);
    }

    public function finalize(Request $request, Quotation $quotation, QuotationDocumentService $documents): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $quotation->load([
            'buyerCompany.country',
            'buyerContact.designation',
            'supplierCompany.country',
            'supplierContact.designation',
            'incoterm',
            'salesperson',
            'items.manufacturer',
            'terms',
        ]);

        if ($quotation->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'At least one product is required before creating a quotation version.',
            ]);
        }

        if ($quotation->terms->count() < count(self::DEFAULT_TERMS)) {
            throw ValidationException::withMessages([
                'terms' => 'Terms and conditions are required before creating a quotation version.',
            ]);
        }

        $versionNumber = ((int) $quotation->versions()->max('version_number')) + 1;
        $snapshot = $documents->snapshot($quotation, $versionNumber);
        $safeReference = Str::slug($quotation->quotation_reference, '-');
        $basePath = "generated/quotations/{$quotation->id}/revision-{$versionNumber}";
        $docxPath = "{$basePath}/{$safeReference}-rev-{$versionNumber}.docx";
        $pdfPath = "{$basePath}/{$safeReference}-rev-{$versionNumber}.pdf";

        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);

        $version = DB::transaction(function () use ($quotation, $request, $versionNumber, $snapshot, $docxPath, $pdfPath): QuotationVersion {
            $version = $quotation->versions()->create([
                'version_number' => $versionNumber,
                'quotation_reference' => $quotation->quotation_reference,
                'snapshot' => $snapshot,
                'docx_path' => $docxPath,
                'pdf_path' => $pdfPath,
                'created_by' => $request->user()->id,
                'finalized_at' => now(),
            ]);

            $quotation->forceFill(['status' => 'issued'])->save();

            $this->logActivity(
                $request,
                $quotation,
                'quotation.version_created',
                $request->user()->name." created quotation version {$versionNumber}.",
                ['version_number' => $versionNumber],
                $version
            );

            return $version->load('creator');
        });

        return response()->json([
            'message' => "Quotation version {$versionNumber} created.",
            'data' => $this->transformVersion($version),
        ], 201);
    }

    public function downloadVersion(Request $request, Quotation $quotation, int $versionNumber, string $format): BinaryFileResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        $version = $quotation->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $path = $format === 'docx' ? $version->docx_path : $version->pdf_path;
        $documents = app(QuotationDocumentService::class);

        if ($format === 'docx' && ! $documents->docxXmlPartsAreParseable($path)) {
            $documents->writeDocx($version->snapshot, $path);
        }

        if ($format === 'pdf' && ! Storage::disk('local')->exists($path)) {
            $documents->writePdf($version->snapshot, $path);
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';
        $filename = Str::slug($version->quotation_reference, '-')."-rev-{$version->version_number}.{$format}";

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    public function storeBuyerPo(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $version = $quotation->versions()
            ->orderByDesc('version_number')
            ->first();

        if (! $version) {
            throw ValidationException::withMessages([
                'quotation_version' => 'Create the final quotation version before recording the buyer PO.',
            ]);
        }

        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:100'],
            'po_date' => ['required', 'date'],
            'po_value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'po_file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ]);

        $buyerPo = DB::transaction(function () use ($request, $quotation, $validated, $version): BuyerPo {
            $file = $request->file('po_file');
            $safePoNumber = Str::slug((string) $validated['po_number']) ?: 'buyer-po';
            $storedPath = $file->storeAs(
                "buyer-pos/{$quotation->id}",
                $safePoNumber.'-'.now()->format('YmdHis').'.'.$file->getClientOriginalExtension(),
                'local'
            );

            $buyerPo = BuyerPo::query()->create([
                'quotation_id' => $quotation->id,
                'quotation_version_id' => $version->id,
                'buyer_company_id' => $quotation->buyer_company_id,
                'buyer_contact_id' => $quotation->buyer_contact_id,
                'po_number' => trim((string) $validated['po_number']),
                'po_date' => $validated['po_date'],
                'po_value' => $this->money($validated['po_value']),
                'currency' => $quotation->accepted_invoice_currency,
                'po_file_path' => $storedPath,
                'original_file_name' => $file->getClientOriginalName(),
                'created_by' => $request->user()->id,
                'status' => 'received',
            ]);

            $quotation->forceFill(['status' => 'buyer_po_received'])->save();

            $this->logActivity(
                $request,
                $quotation,
                'buyer_po.created',
                $request->user()->name." recorded buyer PO {$buyerPo->po_number} against quotation version {$version->version_number}.",
                [
                    'buyer_po_id' => $buyerPo->id,
                    'po_number' => $buyerPo->po_number,
                    'quotation_version_number' => $version->version_number,
                ],
                $version
            );

            return $buyerPo->load(['quotationVersion', 'creator']);
        });

        return response()->json([
            'message' => "Buyer PO created and linked to quotation version {$version->version_number}.",
            'data' => $this->transformBuyerPo($buyerPo),
        ], 201);
    }

    private function authorizeQuotation(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin') || $user->hasRole('salesperson') || $user->hasPermission('create-quotations')) {
            return;
        }

        abort(403);
    }

    private function authorizeQuotationRecord(Request $request, Quotation $quotation): void
    {
        $this->authorizeQuotation($request);

        $user = $request->user();

        if ($user?->hasRole('admin') || $quotation->salesperson_id === $user?->id) {
            return;
        }

        abort(403);
    }

    /**
     * @return array{company_id: int, company_name: string, company_code: string|null, contact_id: int, contact_name: string}
     */
    private function resolveSupplier(?User $user): array
    {
        if (! $user) {
            abort(401);
        }

        $user->loadMissing('contact.company');
        $supplier = null;

        if ($user->contact) {
            $supplier = Supplier::query()
                ->with(['company', 'primaryContact'])
                ->where('company_id', $user->contact->company_id)
                ->where('primary_contact_id', $user->contact->id)
                ->where('status', 'active')
                ->first();
        }

        if (! $supplier && $user->hasRole('admin')) {
            $supplier = Supplier::query()
                ->with(['company', 'primaryContact'])
                ->where('status', 'active')
                ->whereNotNull('primary_contact_id')
                ->orderBy('id')
                ->first();
        }

        if (! $supplier || ! $supplier->company || ! $supplier->primaryContact) {
            throw ValidationException::withMessages([
                'supplier_contact_id' => 'Your user is not linked to an active supplier contact.',
            ]);
        }

        return [
            'company_id' => $supplier->company->id,
            'company_name' => $supplier->company->name,
            'company_code' => $supplier->company->company_code,
            'contact_id' => $supplier->primaryContact->id,
            'contact_name' => $supplier->primaryContact->name,
        ];
    }

    private function referenceFor(Quotation $quotation): string
    {
        $quotation->loadMissing('buyerCompany');
        $buyerCode = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $quotation->buyerCompany?->company_code) ?: 'BUY');

        return sprintf('ISC-COR-QT-%d-%s-%s', $quotation->id, $buyerCode, now()->format('y'));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'quotation_reference' => $quotation->quotation_reference,
            'salesperson_id' => $quotation->salesperson_id,
            'salesperson_name' => $quotation->salesperson?->name,
            'supplier_company_id' => $quotation->supplier_company_id,
            'supplier_company_name' => $quotation->supplierCompany?->name,
            'supplier_contact_id' => $quotation->supplier_contact_id,
            'supplier_contact_name' => $quotation->supplierContact?->name,
            'buyer_company_id' => $quotation->buyer_company_id,
            'buyer_company_name' => $quotation->buyerCompany?->name,
            'buyer_contact_id' => $quotation->buyer_contact_id,
            'buyer_contact_name' => $quotation->buyerContact?->name,
            'rfq_number' => $quotation->rfq_number,
            'pr_number' => $quotation->pr_number,
            'closing_at' => $quotation->closing_at?->toDateTimeString(),
            'quotation_validity_value' => $quotation->quotation_validity_value,
            'quotation_validity_unit' => $quotation->quotation_validity_unit,
            'payment_term_days' => $quotation->payment_term_days,
            'delivery_period_min' => $quotation->delivery_period_min,
            'delivery_period_max' => $quotation->delivery_period_max,
            'delivery_period_unit' => $quotation->delivery_period_unit,
            'delivery_period_type' => $quotation->delivery_period_type,
            'accepted_invoice_currency' => $quotation->accepted_invoice_currency,
            'incoterm_id' => $quotation->incoterm_id,
            'incoterm_code' => $quotation->incoterm?->code,
            'delivery_responsibility' => $quotation->delivery_responsibility,
            'status' => $quotation->status,
            'items_count' => (int) ($quotation->items_count ?? $quotation->items?->count() ?? 0),
            'items_sum_total_price' => $quotation->items_sum_total_price !== null ? $this->money($quotation->items_sum_total_price) : null,
            'created_at' => $quotation->created_at?->toDateTimeString(),
            'updated_at' => $quotation->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformItem(QuotationItem $item): array
    {
        return [
            'id' => $item->id,
            'quotation_id' => $item->quotation_id,
            'product_id' => $item->product_id,
            'manufacturer_id' => $item->manufacturer_id,
            'manufacturer_name' => $item->manufacturer?->name,
            'line_number' => $item->line_number,
            'product_name' => $item->product_name,
            'title' => $item->title,
            'buyer_description' => $item->buyer_description,
            'manufacturer_description' => $item->manufacturer_description,
            'quantity' => $this->money($item->quantity),
            'uom' => $item->uom,
            'unit_price' => $this->money($item->unit_price),
            'total_price' => $this->money($item->total_price),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTerm(QuotationTerm $term): array
    {
        return [
            'id' => $term->id,
            'quotation_id' => $term->quotation_id,
            'line_number' => $term->line_number,
            'key' => $term->key,
            'title' => $term->title,
            'description' => $term->description,
            'is_required_default' => $term->is_required_default,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformVersion(QuotationVersion $version): array
    {
        return [
            'id' => $version->id,
            'quotation_id' => $version->quotation_id,
            'version_number' => $version->version_number,
            'quotation_reference' => $version->quotation_reference,
            'docx_path' => $version->docx_path,
            'pdf_path' => $version->pdf_path,
            'created_by' => $version->created_by,
            'created_by_name' => $version->creator?->name,
            'finalized_at' => $version->finalized_at?->toDateTimeString(),
            'downloads' => [
                'docx' => "/api/quotations/{$version->quotation_id}/versions/{$version->version_number}/download/docx",
                'pdf' => "/api/quotations/{$version->quotation_id}/versions/{$version->version_number}/download/pdf",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBuyerPo(BuyerPo $buyerPo): array
    {
        return [
            'id' => $buyerPo->id,
            'quotation_id' => $buyerPo->quotation_id,
            'quotation_version_id' => $buyerPo->quotation_version_id,
            'quotation_version_number' => $buyerPo->quotationVersion?->version_number,
            'buyer_company_id' => $buyerPo->buyer_company_id,
            'buyer_contact_id' => $buyerPo->buyer_contact_id,
            'po_number' => $buyerPo->po_number,
            'po_date' => $buyerPo->po_date?->toDateString(),
            'po_value' => $this->money($buyerPo->po_value),
            'currency' => $buyerPo->currency,
            'po_file_path' => $buyerPo->po_file_path,
            'original_file_name' => $buyerPo->original_file_name,
            'status' => $buyerPo->status,
            'created_by' => $buyerPo->created_by,
            'created_by_name' => $buyerPo->creator?->name,
            'created_at' => $buyerPo->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformActivityLog(QuotationActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'quotation_id' => $log->quotation_id,
            'quotation_version_id' => $log->quotation_version_id,
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name,
            'action' => $log->action,
            'summary' => $log->summary,
            'properties' => $log->properties,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function logActivity(
        Request $request,
        Quotation $quotation,
        string $action,
        string $summary,
        array $properties = [],
        ?QuotationVersion $version = null,
    ): void {
        $quotation->activityLogs()->create([
            'quotation_version_id' => $version?->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'summary' => $summary,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
