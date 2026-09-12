<?php

namespace App\Http\Controllers;

use App\Models\BuyerPo;
use App\Models\BuyerPoItem;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\FollowUpItem;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationActivityLog;
use App\Models\QuotationCharge;
use App\Models\QuotationDiscount;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentSchedule;
use App\Models\QuotationTerm;
use App\Models\QuotationVersion;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Services\PrivateUploadDownloadService;
use App\Services\QuotationDescriptionChangeClassifier;
use App\Services\QuotationDocumentService;
use App\Services\QuotationPricing;
use App\Services\RichTextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class QuotationController extends Controller
{
    private const PERIOD_UNITS = ['days', 'weeks', 'months'];

    /**
     * Quotations communicate a delivery window rather than an exact delivery date.
     */
    private const QUOTATION_DELIVERY_UNIT = 'weeks';

    private const DELIVERY_TYPES = ['working', 'calendar'];

    private const PAYMENT_CUSTOMER_TYPES = ['credit', 'paying'];

    private const PAYMENT_METHODS = ['cheque', 'cash', 'bank_transfer', 'card', 'letter_of_credit', 'other'];

    private const PAYMENT_DUE_TIMINGS = ['relative', 'fixed_date'];

    private const PAYMENT_DUE_EVENTS = [
        'before_delivery',
        'on_delivery',
        'after_delivery',
        'before_arrival',
        'on_arrival',
        'after_arrival',
        'before_dispatch',
        'on_invoice',
        'after_invoice',
    ];

    private const RESPONSIBILITIES = ['isc', 'buyer', 'supplier'];

    private const VAT_PRICING_MODES = ['exclusive', 'inclusive'];

    private const DISCOUNT_TYPES = ['fixed', 'percentage'];

    private const DEFAULT_TERMS = [
        ['key' => 'cancellation', 'title' => 'Cancellation', 'required' => true],
        ['key' => 'scope_of_work', 'title' => 'Scope of Work', 'required' => true],
        ['key' => 'delivery_term', 'title' => 'Delivery Term', 'required' => true],
        ['key' => 'warranty', 'title' => 'Warranty', 'required' => false],
        ['key' => 'force_majeure', 'title' => 'Force Majeure', 'required' => true],
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
            'buyerPos.items.quotationItem.manufacturer',
            'buyerCompany',
            'buyerContact',
            'supplierCompany',
            'supplierContact',
            'incoterm',
            'salesperson',
            'items.manufacturer',
            'items.incoterm',
            'charges',
            'discounts',
            'paymentSchedules',
            'terms',
            'versions.creator',
            'activityLogs.user',
        ]);

        return response()->json([
            'data' => [
                ...$this->transform($quotation),
                'items' => $quotation->items->map(fn (QuotationItem $item) => $this->transformItem($item, $quotation->vat_pricing ?? 'exclusive'))->values(),
                'terms' => $quotation->terms->map(fn (QuotationTerm $term) => $this->transformTerm($term))->values(),
                'versions' => $quotation->versions->map(fn (QuotationVersion $version) => $this->transformVersion($version))->values(),
                'buyer_po' => $quotation->buyerPos->first() ? $this->transformBuyerPo($quotation->buyerPos->first()) : null,
                'buyer_pos' => $quotation->buyerPos->map(fn (BuyerPo $buyerPo) => $this->transformBuyerPo($buyerPo))->values(),
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
            'buyers' => $this->buyerCompanyQuery()
                ->orderBy('name')
                ->get(['id', 'name', 'company_code']),
            'buyer_contacts' => Contact::query()
                ->where(function (Builder $query): void {
                    $query->where('serves_buyer', true)
                        ->orWhereHas('company', fn (Builder $company) => $company->whereIn('company_type', ['buyer', 'mixed']));
                })
                ->whereHas('company', fn (Builder $query) => $this->applyBuyerCompanyScope($query))
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
            'currencies' => $this->currencyOptions(),
            'uoms' => $this->uomOptions(),
            'period_units' => collect(self::PERIOD_UNITS)->map(fn (string $unit) => [
                'id' => $unit,
                'name' => Str::ucfirst($unit),
            ])->values(),
            'delivery_responsibilities' => [
                ['id' => 'isc', 'name' => 'ISC / supplier responsible'],
                ['id' => 'buyer', 'name' => 'Buyer responsible'],
                ['id' => 'supplier', 'name' => 'Supplier / manufacturer responsible'],
            ],
            'payment_customer_types' => [
                ['id' => 'credit', 'name' => 'Credit Customer'],
                ['id' => 'paying', 'name' => 'Full Paying Customer'],
            ],
            'payment_methods' => [
                ['id' => 'cheque', 'name' => 'Check / Cheque'],
                ['id' => 'cash', 'name' => 'Cash'],
                ['id' => 'bank_transfer', 'name' => 'Bank Transfer'],
                ['id' => 'card', 'name' => 'Card'],
                ['id' => 'letter_of_credit', 'name' => 'Letter of Credit'],
                ['id' => 'other', 'name' => 'Other'],
            ],
            'payment_due_events' => [
                ['id' => 'before_delivery', 'name' => 'Before Delivery'],
                ['id' => 'on_delivery', 'name' => 'On Delivery'],
                ['id' => 'after_delivery', 'name' => 'After Delivery'],
                ['id' => 'before_arrival', 'name' => 'Before Arrival'],
                ['id' => 'on_arrival', 'name' => 'On Arrival'],
                ['id' => 'after_arrival', 'name' => 'After Arrival'],
                ['id' => 'before_dispatch', 'name' => 'Before Dispatch'],
                ['id' => 'on_invoice', 'name' => 'On Invoice'],
                ['id' => 'after_invoice', 'name' => 'After Invoice'],
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
                Rule::exists('companies', 'id')->where(fn ($query) => $query->where('status', 'active')),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->buyerCompanyQuery()->whereKey((int) $value)->exists()) {
                        $fail('The selected company is not an active buyer.');
                    }
                },
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
            'rfq_title' => ['nullable', 'string', 'max:255'],
            'closing_at' => ['nullable', 'date'],
            'quotation_validity_value' => ['required', 'integer', 'min:1', 'max:3650'],
            'quotation_validity_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'payment_terms_extra' => ['nullable', 'string', 'max:2000'],
            'payment_customer_type' => ['nullable', Rule::in(self::PAYMENT_CUSTOMER_TYPES)],
            'delivery_period_min' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_max' => ['required', 'integer', 'gte:delivery_period_min', 'max:3650'],
            'delivery_period_unit' => ['required', Rule::in([self::QUOTATION_DELIVERY_UNIT])],
            'delivery_period_type' => ['required', Rule::in(self::DELIVERY_TYPES)],
            'accepted_invoice_currency' => ['required', Rule::in($this->currencyCodes())],
            'incoterm_id' => [
                'required',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'delivery_responsibility' => ['required', Rule::in(self::RESPONSIBILITIES)],
        ]);
        $validated['payment_customer_type'] = $validated['payment_customer_type'] ?? 'credit';
        $validated['rfq_title'] = $this->nullableTrim($validated['rfq_title'] ?? null);
        $validated['payment_terms_extra'] = $this->nullableTrim($validated['payment_terms_extra'] ?? null);

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

            $this->seedDefaultPaymentSchedule($quotation);

            return $quotation->load(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson', 'paymentSchedules']);
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
                Rule::exists('companies', 'id')->where(fn ($query) => $query->where('status', 'active')),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $this->buyerCompanyQuery()->whereKey((int) $value)->exists()) {
                        $fail('The selected company is not an active buyer.');
                    }
                },
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
            'rfq_title' => ['nullable', 'string', 'max:255'],
            'closing_at' => ['nullable', 'date'],
            'quotation_validity_value' => ['required', 'integer', 'min:1', 'max:3650'],
            'quotation_validity_unit' => ['required', Rule::in(self::PERIOD_UNITS)],
            'payment_term_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'payment_terms_extra' => ['nullable', 'string', 'max:2000'],
            'payment_customer_type' => ['nullable', Rule::in(self::PAYMENT_CUSTOMER_TYPES)],
            'delivery_period_min' => ['required', 'integer', 'min:0', 'max:3650'],
            'delivery_period_max' => ['required', 'integer', 'gte:delivery_period_min', 'max:3650'],
            'delivery_period_unit' => ['required', Rule::in([self::QUOTATION_DELIVERY_UNIT])],
            'delivery_period_type' => ['required', Rule::in(self::DELIVERY_TYPES)],
            'accepted_invoice_currency' => ['required', Rule::in($this->currencyCodes())],
            'incoterm_id' => [
                'required',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'delivery_responsibility' => ['required', Rule::in(self::RESPONSIBILITIES)],
        ]);
        $validated['payment_customer_type'] = $validated['payment_customer_type'] ?? 'credit';
        $validated['rfq_title'] = $this->nullableTrim($validated['rfq_title'] ?? null);
        $validated['payment_terms_extra'] = $this->nullableTrim($validated['payment_terms_extra'] ?? null);

        if ($this->paymentScheduleIsLocked($quotation)
            && ((int) $quotation->payment_term_days !== (int) $validated['payment_term_days']
                || (string) ($quotation->payment_customer_type ?? 'credit') !== (string) $validated['payment_customer_type'])) {
            return $this->paymentScheduleConflictResponse();
        }

        $quotation = DB::transaction(function () use ($quotation, $validated): Quotation {
            $quotation->forceFill($validated)->save();

            if (! $quotation->paymentSchedules()->exists()) {
                $this->seedDefaultPaymentSchedule($quotation);
            } else {
                $this->syncDefaultPaymentScheduleDays($quotation);
            }

            return $quotation->load(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson', 'paymentSchedules']);
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

    public function storePaymentSchedule(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $validated = $request->validate([
            'payment_schedules' => ['required', 'array', 'min:1', 'max:24'],
            'payment_schedules.*.label' => ['nullable', 'string', 'max:120'],
            'payment_schedules.*.payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'payment_schedules.*.payment_percentage' => ['required', 'numeric', 'min:0.001', 'max:100'],
            'payment_schedules.*.due_timing' => ['required', Rule::in(self::PAYMENT_DUE_TIMINGS)],
            'payment_schedules.*.due_event' => ['nullable', Rule::in(self::PAYMENT_DUE_EVENTS)],
            'payment_schedules.*.due_offset_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payment_schedules.*.due_date' => ['nullable', 'date'],
            'payment_schedules.*.notes' => ['nullable', 'string'],
        ]);

        $normalized = collect($validated['payment_schedules'])
            ->map(function (array $schedule, int $index) use ($quotation): array {
                $dueTiming = (string) $schedule['due_timing'];

                if ($dueTiming === 'fixed_date') {
                    if (empty($schedule['due_date'])) {
                        throw ValidationException::withMessages([
                            "payment_schedules.{$index}.due_date" => 'A payment date is required when the schedule uses a fixed date.',
                        ]);
                    }

                    $schedule['due_event'] = null;
                    $schedule['due_offset_days'] = null;
                } else {
                    if (empty($schedule['due_event'])) {
                        throw ValidationException::withMessages([
                            "payment_schedules.{$index}.due_event" => 'A payment trigger is required when the schedule is relative.',
                        ]);
                    }

                    $schedule['due_date'] = null;
                    $schedule['due_offset_days'] = str_starts_with((string) $schedule['due_event'], 'on_')
                        ? 0
                        : (int) ($schedule['due_offset_days'] ?? 0);
                }

                return [
                    'line_number' => $index + 1,
                    'label' => trim((string) ($schedule['label'] ?? '')) ?: $this->defaultPaymentLineLabel($quotation, $schedule, $index),
                    'payment_method' => $schedule['payment_method'],
                    'payment_percentage' => $this->money($schedule['payment_percentage']),
                    'due_timing' => $dueTiming,
                    'due_event' => $schedule['due_event'] ?? null,
                    'due_offset_days' => $schedule['due_offset_days'] ?? null,
                    'due_date' => $schedule['due_date'] ?? null,
                    'notes' => isset($schedule['notes']) && trim((string) $schedule['notes']) !== '' ? trim((string) $schedule['notes']) : null,
                ];
            })
            ->values();

        $totalPercentage = round($normalized->sum(fn (array $schedule): float => (float) $schedule['payment_percentage']), 3);

        if (abs($totalPercentage - 100.0) > 0.005) {
            throw ValidationException::withMessages([
                'payment_schedules' => 'Payment schedule percentages must total 100%.',
            ]);
        }

        if ($this->paymentScheduleIsLocked($quotation)) {
            if (! $this->paymentScheduleMatches($quotation, $normalized)) {
                return $this->paymentScheduleConflictResponse();
            }

            $quotation->load(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson', 'paymentSchedules']);

            return response()->json([
                'message' => 'Quotation payment schedule is unchanged.',
                'data' => [
                    ...$this->transform($quotation),
                    'payment_schedules' => $quotation->paymentSchedules
                        ->map(fn (QuotationPaymentSchedule $schedule): array => $this->transformPaymentSchedule($schedule))
                        ->values(),
                ],
            ]);
        }

        $quotation = DB::transaction(function () use ($quotation, $normalized): Quotation {
            $quotation->paymentSchedules()->delete();

            foreach ($normalized as $schedule) {
                $quotation->paymentSchedules()->create($schedule);
            }

            return $quotation->load(['buyerCompany', 'buyerContact', 'supplierCompany', 'supplierContact', 'incoterm', 'salesperson', 'paymentSchedules']);
        });

        $this->logActivity(
            $request,
            $quotation,
            'quotation.payment_schedule_updated',
            $request->user()->name.' updated quotation payment schedule.',
            [
                'payment_customer_type' => $quotation->payment_customer_type,
                'schedule_count' => $quotation->paymentSchedules->count(),
                'payment_schedule_summary' => $this->paymentScheduleSummary($quotation),
            ]
        );

        return response()->json([
            'message' => 'Quotation payment schedule saved.',
            'data' => [
                ...$this->transform($quotation),
                'payment_schedules' => $quotation->paymentSchedules
                    ->map(fn (QuotationPaymentSchedule $schedule): array => $this->transformPaymentSchedule($schedule))
                    ->values(),
            ],
        ]);
    }

    public function storeItems(Request $request, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        if ($this->quotationHasDownstreamCommitments($quotation)) {
            return response()->json([
                'message' => 'Quotation products are locked because an accepted buyer PO or downstream supplier PO already relies on them.',
            ], 409);
        }

        $validated = $request->validate([
            'vat_pricing' => ['nullable', Rule::in(self::VAT_PRICING_MODES)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.manufacturer_id' => [
                'required',
                'integer',
                Rule::exists('manufacturers', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.product_code' => ['required', 'string', 'max:100'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.buyer_description' => ['nullable', 'string'],
            'items.*.manufacturer_description' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
            'items.*.uom' => ['required', 'string', 'max:24', Rule::in($this->uomCodes())],
            'items.*.delivery_date' => ['prohibited'],
            'items.*.incoterm_id' => [
                'nullable',
                'integer',
                Rule::exists('incoterms', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'charges' => ['nullable', 'array', 'max:20'],
            'charges.*.label' => ['required_with:charges', 'string', 'max:150'],
            'charges.*.amount' => ['required_with:charges', 'numeric', 'min:0', 'max:999999999'],
            'discounts' => ['nullable', 'array', 'max:20'],
            'discounts.*.label' => ['required_with:discounts', 'string', 'max:150'],
            'discounts.*.discount_type' => ['required_with:discounts', Rule::in(self::DISCOUNT_TYPES)],
            'discounts.*.amount' => ['required_with:discounts', 'numeric', 'min:0', 'max:999999999'],
        ]);

        foreach (($validated['discounts'] ?? []) as $index => $discount) {
            if (($discount['discount_type'] ?? null) === 'percentage' && (float) ($discount['amount'] ?? 0) > 100) {
                throw ValidationException::withMessages([
                    "discounts.{$index}.amount" => 'Percentage discounts cannot be greater than 100.',
                ]);
            }
        }

        $pricing = QuotationPricing::calculate($validated['items'], $validated['charges'] ?? [], $validated['discounts'] ?? [], $validated['vat_pricing'] ?? 'exclusive');
        if ($pricing['discounts_exceed_base']) {
            throw ValidationException::withMessages([
                'discounts' => 'Combined discounts cannot exceed the subtotal plus charges before VAT.',
            ]);
        }

        $quotation = DB::transaction(function () use ($quotation, $validated): Quotation {
            $vatPricing = $validated['vat_pricing'] ?? 'exclusive';
            $quotation->forceFill(['vat_pricing' => $vatPricing])->save();
            $quotation->items()->delete();
            $quotation->charges()->delete();
            $quotation->discounts()->delete();

            foreach ($validated['items'] as $index => $item) {
                $productCode = trim((string) $item['product_code']);
                $product = Product::query()->updateOrCreate(
                    [
                        'manufacturer_id' => $item['manufacturer_id'],
                        'product_code' => $productCode,
                    ],
                    [
                        'name' => trim((string) $item['product_name']),
                        'title' => trim((string) $item['title']),
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
                    'product_code' => $productCode,
                    'product_name' => $product->name,
                    'title' => $product->title,
                    'buyer_description' => $item['buyer_description'] ?? null,
                    'manufacturer_description' => $item['manufacturer_description'] ?? null,
                    'quantity' => $this->money($item['quantity']),
                    'uom' => trim((string) $item['uom']),
                    // Exact delivery dates are set on the supplier PO after the buyer PO is received.
                    'delivery_date' => null,
                    'incoterm_id' => $item['incoterm_id'] ?? $quotation->incoterm_id,
                    'unit_price' => $this->money($item['unit_price']),
                    'vat_rate' => $this->money($item['vat_rate'] ?? 0),
                    'total_price' => QuotationPricing::line($item, $vatPricing)['net'],
                ]);
            }

            foreach (($validated['charges'] ?? []) as $index => $charge) {
                $quotation->charges()->create([
                    'line_number' => $index + 1,
                    'label' => trim((string) $charge['label']),
                    'amount' => $this->money($charge['amount']),
                ]);
            }

            foreach (($validated['discounts'] ?? []) as $index => $discount) {
                $quotation->discounts()->create([
                    'line_number' => $index + 1,
                    'label' => trim((string) $discount['label']),
                    'discount_type' => $discount['discount_type'],
                    'amount' => $this->money($discount['amount']),
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
                'items.incoterm',
                'charges',
                'discounts',
            ]);
        });

        $latestVersion = $quotation->versions()
            ->orderByDesc('version_number')
            ->first();
        $itemRevisionComparison = null;

        if ($latestVersion) {
            $itemRevisionComparison = $this->materialRevisionComparison(
                app(QuotationDocumentService::class)->snapshot($quotation, $latestVersion->version_number),
                $latestVersion->snapshot ?? null
            );
        }

        $this->logActivity(
            $request,
            $quotation,
            'quotation.items_updated',
            $request->user()->name.' updated quotation products.',
            [
                'items_count' => $quotation->items->count(),
                'revision_material_change' => $itemRevisionComparison['changed'] ?? true,
                'change_reasons' => $itemRevisionComparison['reasons'] ?? ['pending_initial_version'],
                'minor_description_corrections' => $itemRevisionComparison['minor_description_corrections'] ?? [],
            ]
        );

        return response()->json([
            'message' => 'Quotation items saved.',
            'data' => [
                ...$this->transform($quotation),
                'items' => $quotation->items->map(fn (QuotationItem $item) => $this->transformItem($item, $quotation->vat_pricing ?? 'exclusive'))->values(),
                'charges' => $quotation->charges->map(fn (QuotationCharge $charge): array => $this->transformCharge($charge))->values(),
                'discounts' => $quotation->discounts->map(fn (QuotationDiscount $discount): array => $this->transformDiscount($discount))->values(),
                'totals' => $this->quotationTotals($quotation),
            ],
        ]);
    }

    public function storeTerms(Request $request, Quotation $quotation, RichTextSanitizer $richTextSanitizer): JsonResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        $validated = $request->validate([
            'terms' => ['required', 'array', 'min:1', 'max:50'],
            'terms.*.key' => ['nullable', 'string', 'max:100'],
            'terms.*.title' => ['required', 'string', 'max:255'],
            'terms.*.description' => ['nullable', 'string'],
        ]);

        $defaultKeys = collect(self::DEFAULT_TERMS)->pluck('key');
        $requiredDefaultKeys = $this->requiredDefaultTermKeys();
        $normalizedTerms = collect($validated['terms'])
            ->map(function (array $term) use ($richTextSanitizer): array {
                $description = $richTextSanitizer->sanitize(trim((string) ($term['description'] ?? ''))) ?? '';

                return [
                    'key' => filled($term['key'] ?? null) ? (string) $term['key'] : null,
                    'title' => trim((string) $term['title']),
                    'description' => $description,
                ];
            })
            ->filter(function (array $term) use ($defaultKeys, $requiredDefaultKeys): bool {
                if ($term['key'] !== null && $requiredDefaultKeys->contains($term['key'])) {
                    return true;
                }

                if ($term['key'] !== null && $defaultKeys->contains($term['key'])) {
                    return $this->hasRichTextContent($term['description']);
                }

                return $term['title'] !== '' || $this->hasRichTextContent($term['description']);
            })
            ->values();

        $submittedRequiredDefaultKeys = $normalizedTerms
            ->filter(fn (array $term): bool => $requiredDefaultKeys->contains($term['key']) && $this->hasRichTextContent($term['description']))
            ->pluck('key')
            ->filter()
            ->unique()
            ->values();

        if ($requiredDefaultKeys->diff($submittedRequiredDefaultKeys)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'terms' => 'Cancellation, scope of work, delivery term, and force majeure are required. Warranty is optional.',
            ]);
        }

        $incompleteCustomTerm = $normalizedTerms->first(fn (array $term): bool => $term['key'] === null && ($term['title'] === '' || ! $this->hasRichTextContent($term['description'])));

        if ($incompleteCustomTerm !== null) {
            throw ValidationException::withMessages([
                'terms' => 'Additional terms need both a title and a description.',
            ]);
        }

        $quotation = DB::transaction(function () use ($quotation, $normalizedTerms, $defaultKeys): Quotation {
            $quotation->terms()->delete();

            foreach ($normalizedTerms as $index => $term) {
                $quotation->terms()->create([
                    'line_number' => $index + 1,
                    'key' => $term['key'],
                    'title' => $term['title'],
                    'description' => $term['description'],
                    'is_required_default' => $term['key'] !== null && $defaultKeys->contains($term['key']),
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

        if ($this->quotationHasDownstreamCommitments($quotation)) {
            return response()->json([
                'message' => 'This quotation can no longer be finalized because a buyer PO, supplier PO, or terminal workflow state already relies on its accepted version.',
            ], 409);
        }

        $quotation->load([
            'buyerCompany.country',
            'buyerContact.designation',
            'supplierCompany.country',
            'supplierContact.designation',
            'incoterm',
            'salesperson',
            'items.manufacturer',
            'items.incoterm',
            'charges',
            'discounts',
            'paymentSchedules',
            'terms',
        ]);

        if ($quotation->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'At least one product is required before creating a quotation version.',
            ]);
        }

        $missingRequiredTerms = $this->requiredDefaultTermKeys()->diff(
            $quotation->terms
                ->filter(fn (QuotationTerm $term): bool => $term->key !== null && $this->hasRichTextContent((string) $term->description))
                ->pluck('key')
                ->unique()
                ->values()
        );

        if ($missingRequiredTerms->isNotEmpty()) {
            throw ValidationException::withMessages([
                'terms' => 'Terms and conditions are required before creating a quotation version.',
            ]);
        }

        if ($quotation->paymentSchedules->isEmpty()) {
            throw ValidationException::withMessages([
                'payment_schedules' => 'Payment schedule is required before creating a quotation version.',
            ]);
        }

        $latestVersion = $quotation->versions()
            ->orderByDesc('version_number')
            ->first();
        $nextVersionNumber = ((int) ($latestVersion?->version_number ?? 0)) + 1;

        if ($latestVersion) {
            $refreshSnapshot = $documents->snapshot($quotation, $latestVersion->version_number);
            $comparison = $this->materialRevisionComparison($refreshSnapshot, $latestVersion->snapshot ?? null);

            if (! $comparison['changed']) {
                $documents->writeDocx($refreshSnapshot, $latestVersion->docx_path);
                $documents->writePdf($refreshSnapshot, $latestVersion->pdf_path);
                $documents->writeTechnicalDocx($refreshSnapshot, $this->typedVersionPath($latestVersion, 'technical', 'docx'));
                $documents->writeTechnicalPdf($refreshSnapshot, $this->typedVersionPath($latestVersion, 'technical', 'pdf'));

                $version = DB::transaction(function () use ($quotation, $request, $latestVersion, $refreshSnapshot, $comparison): QuotationVersion {
                    $latestVersion->forceFill([
                        'quotation_reference' => $quotation->quotation_reference,
                        'snapshot' => $refreshSnapshot,
                    ])->save();

                    $quotation->forceFill(['status' => 'issued'])->save();

                    $this->logActivity(
                        $request,
                        $quotation,
                        'quotation.version_refreshed',
                        $request->user()->name." updated quotation version {$latestVersion->version_number} without creating a new revision.",
                        [
                            'version_number' => $latestVersion->version_number,
                            'revision_material_change' => false,
                            'change_reasons' => $comparison['reasons'],
                            'minor_description_corrections' => $comparison['minor_description_corrections'],
                        ],
                        $latestVersion
                    );

                    return $latestVersion->load('creator');
                });

                return response()->json([
                    'message' => "Quotation version {$version->version_number} updated without creating a new revision because no material product or price change was detected.",
                    'data' => [
                        ...$this->transformVersion($version),
                        'revision_action' => 'refreshed',
                    ],
                ]);
            }
        }

        $versionNumber = $nextVersionNumber;
        $snapshot = $documents->snapshot($quotation, $versionNumber);
        $comparison = $this->materialRevisionComparison($snapshot, $latestVersion?->snapshot ?? null);
        $safeReference = Str::slug($quotation->quotation_reference, '-');
        $basePath = "generated/quotations/{$quotation->id}/revision-{$versionNumber}";
        $docxPath = "{$basePath}/{$safeReference}-rev-{$versionNumber}.docx";
        $pdfPath = "{$basePath}/{$safeReference}-rev-{$versionNumber}.pdf";
        $technicalDocxPath = "{$basePath}/{$safeReference}-rev-{$versionNumber}-technical.docx";
        $technicalPdfPath = "{$basePath}/{$safeReference}-rev-{$versionNumber}-technical.pdf";

        $documents->writeDocx($snapshot, $docxPath);
        $documents->writePdf($snapshot, $pdfPath);
        $documents->writeTechnicalDocx($snapshot, $technicalDocxPath);
        $documents->writeTechnicalPdf($snapshot, $technicalPdfPath);

        $version = DB::transaction(function () use ($quotation, $request, $versionNumber, $snapshot, $docxPath, $pdfPath, $comparison): QuotationVersion {
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
                [
                    'version_number' => $versionNumber,
                    'revision_material_change' => true,
                    'change_reasons' => $comparison['reasons'],
                    'minor_description_corrections' => $comparison['minor_description_corrections'],
                ],
                $version
            );

            return $version->load('creator');
        });

        return response()->json([
            'message' => "Quotation version {$versionNumber} created.",
            'data' => [
                ...$this->transformVersion($version),
                'revision_action' => 'created',
            ],
        ], 201);
    }

    public function downloadVersion(Request $request, Quotation $quotation, int $versionNumber, string $format): BinaryFileResponse
    {
        return $this->downloadVersionDocument($request, $quotation, $versionNumber, 'commercial', $format);
    }

    public function downloadVersionType(Request $request, Quotation $quotation, int $versionNumber, string $documentType, string $format): BinaryFileResponse
    {
        return $this->downloadVersionDocument($request, $quotation, $versionNumber, $documentType, $format);
    }

    private function downloadVersionDocument(Request $request, Quotation $quotation, int $versionNumber, string $documentType, string $format): BinaryFileResponse
    {
        $this->authorizeQuotationRecord($request, $quotation);

        if (! in_array($format, ['docx', 'pdf'], true)) {
            abort(404);
        }

        if (! in_array($documentType, ['commercial', 'technical'], true)) {
            abort(404);
        }

        $version = $quotation->versions()
            ->where('version_number', $versionNumber)
            ->firstOrFail();
        $path = $this->typedVersionPath($version, $documentType, $format);
        $documents = app(QuotationDocumentService::class);

        if ($documentType === 'technical') {
            if ($format === 'docx' && ($documents->needsLayoutRefresh($path) || ! $documents->docxXmlPartsAreParseable($path))) {
                $documents->writeTechnicalDocx($version->snapshot, $path);
            }

            if ($format === 'pdf' && $documents->needsLayoutRefresh($path)) {
                $documents->writeTechnicalPdf($version->snapshot, $path);
            }
        } else {
            if ($format === 'docx' && ($documents->needsLayoutRefresh($path) || ! $documents->docxXmlPartsAreParseable($path))) {
                $documents->writeDocx($version->snapshot, $path);
            }

            if ($format === 'pdf' && $documents->needsLayoutRefresh($path)) {
                $documents->writePdf($version->snapshot, $path);
            }
        }

        $contentType = $format === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';
        $filename = Str::slug($version->quotation_reference, '-')."-{$documentType}-rev-{$version->version_number}.{$format}";
        $filename = Str::upper($filename);

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

        if ($request->has('items')) {
            return $this->storeItemWiseBuyerPo($request, $quotation, $version);
        }

        if ($quotation->buyerPos()->exists()) {
            return response()->json([
                'message' => 'A buyer PO has already been recorded for this quotation.',
            ], 409);
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

            $this->syncBuyerPoItems($buyerPo, $quotation);

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

            return $buyerPo->load(['quotationVersion', 'creator', 'items.quotationItem.manufacturer']);
        });

        return response()->json([
            'message' => "Buyer PO created and linked to quotation version {$version->version_number}.",
            'data' => $this->transformBuyerPo($buyerPo),
        ], 201);
    }

    private function storeItemWiseBuyerPo(Request $request, Quotation $quotation, QuotationVersion $version): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.quotation_item_id' => ['required', 'integer', 'distinct'],
            'items.*.buyer_item_code' => ['nullable', 'string', 'max:100'],
            'items.*.po_number' => ['required', 'string', 'max:100'],
            'items.*.po_date' => ['required', 'date'],
            'items.*.po_value' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.po_file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'],
        ]);

        $rows = collect($validated['items'])
            ->map(function (array $row, int $index): array {
                return [
                    ...$row,
                    '_index' => $index,
                    'quotation_item_id' => (int) $row['quotation_item_id'],
                    'buyer_item_code' => isset($row['buyer_item_code']) && trim((string) $row['buyer_item_code']) !== ''
                        ? trim((string) $row['buyer_item_code'])
                        : null,
                    'po_number' => trim((string) $row['po_number']),
                    'po_value' => $this->money($row['po_value']),
                ];
            })
            ->values();

        $quotationItemIds = $rows->pluck('quotation_item_id')->all();
        $quotationItems = $quotation->items()
            ->whereIn('id', $quotationItemIds)
            ->get()
            ->keyBy('id');

        if ($quotationItems->count() !== count($quotationItemIds)) {
            throw ValidationException::withMessages([
                'items' => 'Every buyer PO item must belong to this quotation.',
            ]);
        }

        if ($this->selectedItemsAlreadyHaveBuyerPo($quotation->id, $quotationItemIds)) {
            throw ValidationException::withMessages([
                'items' => 'One or more selected quotation items already have a buyer PO recorded.',
            ]);
        }

        $this->validateConsistentBuyerPoDates($rows);
        $this->validateBuyerPoNumbersAvailable($quotation, $rows);

        $buyerPos = DB::transaction(function () use ($request, $quotation, $version, $rows, $quotationItems): Collection {
            $createdOrUpdated = collect();

            foreach ($rows->groupBy(fn (array $row): string => Str::lower($row['po_number'])) as $poRows) {
                /** @var Collection<int, array<string, mixed>> $poRows */
                $firstRow = $poRows->first();
                $buyerPo = BuyerPo::query()
                    ->where('buyer_company_id', $quotation->buyer_company_id)
                    ->where('po_number', $firstRow['po_number'])
                    ->first();

                if (! $buyerPo) {
                    $file = $request->file("items.{$firstRow['_index']}.po_file");
                    $safePoNumber = Str::slug((string) $firstRow['po_number']) ?: 'buyer-po';
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
                        'po_number' => $firstRow['po_number'],
                        'po_date' => $firstRow['po_date'],
                        'po_value' => '0.000',
                        'currency' => $quotation->accepted_invoice_currency,
                        'po_file_path' => $storedPath,
                        'original_file_name' => $file->getClientOriginalName(),
                        'created_by' => $request->user()->id,
                        'status' => 'received',
                    ]);
                }

                $usedLineNumbers = $buyerPo->items()
                    ->pluck('line_number')
                    ->map(fn ($lineNumber): int => (int) $lineNumber)
                    ->all();
                $nextLineNumber = empty($usedLineNumbers) ? 1 : max($usedLineNumbers) + 1;

                foreach ($poRows as $row) {
                    /** @var QuotationItem $item */
                    $item = $quotationItems[(int) $row['quotation_item_id']];
                    $lineNumber = (int) $item->line_number;

                    if (in_array($lineNumber, $usedLineNumbers, true)) {
                        $lineNumber = $nextLineNumber++;
                    }

                    $usedLineNumbers[] = $lineNumber;
                    $quantity = (float) $item->quantity;
                    $lineAmount = (float) $row['po_value'];

                    $buyerPo->items()->create([
                        'quotation_id' => $quotation->id,
                        'quotation_item_id' => $item->id,
                        'line_number' => $lineNumber,
                        'buyer_item_code' => $row['buyer_item_code'],
                        'item_description' => $item->buyer_description ?: $item->title,
                        'quantity' => $item->quantity,
                        'uom' => $item->uom,
                        'unit_price' => $quantity > 0 ? $this->money($lineAmount / $quantity) : '0.000',
                        'total_amount' => $row['po_value'],
                        'currency' => $buyerPo->currency,
                        'status' => 'open',
                    ]);
                }

                $buyerPo->forceFill([
                    'po_value' => $this->money($buyerPo->items()->sum('total_amount')),
                ])->save();

                $createdOrUpdated->push($buyerPo->load(['quotationVersion', 'creator', 'items.quotationItem.manufacturer']));
            }

            $this->refreshBuyerPoStatus($quotation);

            $itemCount = $rows->count();
            $poCount = $createdOrUpdated->count();
            $this->logActivity(
                $request,
                $quotation,
                'buyer_po.items_created',
                $request->user()->name." recorded buyer PO details for {$itemCount} item(s) across {$poCount} PO(s).",
                [
                    'quotation_version_number' => $version->version_number,
                    'buyer_po_ids' => $createdOrUpdated->pluck('id')->values(),
                    'quotation_item_ids' => $rows->pluck('quotation_item_id')->values(),
                ],
                $version
            );

            return $createdOrUpdated;
        });

        $quotation->refresh()->load([
            'buyerPos.quotationVersion',
            'buyerPos.creator',
            'buyerPos.items.quotationItem.manufacturer',
        ]);

        return response()->json([
            'message' => 'Buyer PO item details saved.',
            'data' => [
                'buyer_po' => $quotation->buyerPos->first() ? $this->transformBuyerPo($quotation->buyerPos->first()) : null,
                'buyer_pos' => $quotation->buyerPos->map(fn (BuyerPo $buyerPo): array => $this->transformBuyerPo($buyerPo))->values(),
                'created_buyer_pos' => $buyerPos->map(fn (BuyerPo $buyerPo): array => $this->transformBuyerPo($buyerPo))->values(),
                'status' => $quotation->status,
                'covered_items_count' => $this->coveredBuyerPoItemCount($quotation),
                'items_count' => $quotation->items()->count(),
            ],
        ], 201);
    }

    public function downloadBuyerPoUpload(
        Request $request,
        Quotation $quotation,
        BuyerPo $buyerPo,
        PrivateUploadDownloadService $downloads,
    ): BinaryFileResponse
    {
        if ((int) $buyerPo->quotation_id !== (int) $quotation->id) {
            abort(404);
        }

        $this->authorizeBuyerPoUpload($request, $quotation, $buyerPo);

        return $downloads->download(
            $buyerPo->po_file_path,
            $buyerPo->original_file_name,
            "buyer-pos/{$quotation->id}",
        );
    }

    private function authorizeBuyerPoUpload(Request $request, Quotation $quotation, BuyerPo $buyerPo): void
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->hasRole('admin')) {
            return;
        }

        if (
            (int) $quotation->salesperson_id === (int) $user->id
            && ($user->hasRole('salesperson') || $user->hasPermission('create-quotations'))
        ) {
            return;
        }

        if (
            $user->hasRole('follow-up')
            && FollowUpItem::query()
                ->where('quotation_id', $quotation->id)
                ->where('buyer_po_id', $buyerPo->id)
                ->where('assigned_to', $user->id)
                ->exists()
        ) {
            return;
        }

        abort(403);
    }

    private function syncBuyerPoItems(BuyerPo $buyerPo, Quotation $quotation): void
    {
        $usedLineNumbers = $buyerPo->items()
            ->pluck('line_number')
            ->map(fn ($lineNumber): int => (int) $lineNumber)
            ->all();
        $nextLineNumber = empty($usedLineNumbers) ? 1 : max($usedLineNumbers) + 1;

        $quotation->items()
            ->orderBy('line_number')
            ->orderBy('id')
            ->get()
            ->each(function (QuotationItem $item) use ($buyerPo, &$usedLineNumbers, &$nextLineNumber): void {
                $exists = $buyerPo->items()
                    ->where('quotation_item_id', $item->id)
                    ->exists();

                if ($exists) {
                    return;
                }

                $lineNumber = (int) $item->line_number;
                if (in_array($lineNumber, $usedLineNumbers, true)) {
                    $lineNumber = $nextLineNumber++;
                }
                $usedLineNumbers[] = $lineNumber;

                $buyerPo->items()->create([
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
                ]);
            });
    }

    /**
     * @param  array<int, int>  $quotationItemIds
     */
    private function selectedItemsAlreadyHaveBuyerPo(int $quotationId, array $quotationItemIds): bool
    {
        return BuyerPoItem::query()
            ->where('quotation_id', $quotationId)
            ->whereIn('quotation_item_id', $quotationItemIds)
            ->exists();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function validateConsistentBuyerPoDates(Collection $rows): void
    {
        $rows
            ->groupBy(fn (array $row): string => Str::lower($row['po_number']))
            ->each(function (Collection $poRows): void {
                $dates = $poRows
                    ->pluck('po_date')
                    ->map(fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
                    ->unique()
                    ->values();

                if ($dates->count() > 1) {
                    throw ValidationException::withMessages([
                        'items' => 'Rows using the same buyer PO number must use the same PO date.',
                    ]);
                }
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function validateBuyerPoNumbersAvailable(Quotation $quotation, Collection $rows): void
    {
        $poNumbers = $rows->pluck('po_number')->unique()->values();
        $externalConflict = BuyerPo::query()
            ->where('buyer_company_id', $quotation->buyer_company_id)
            ->whereIn('po_number', $poNumbers)
            ->where('quotation_id', '!=', $quotation->id)
            ->first();

        if ($externalConflict) {
            throw ValidationException::withMessages([
                'items' => "Buyer PO {$externalConflict->po_number} is already linked to another quotation.",
            ]);
        }

        $existingByNumber = BuyerPo::query()
            ->where('quotation_id', $quotation->id)
            ->whereIn('po_number', $poNumbers)
            ->get()
            ->keyBy('po_number');

        foreach ($rows as $row) {
            $existing = $existingByNumber->get($row['po_number']);

            if (! $existing) {
                continue;
            }

            $existingDate = $existing->po_date?->toDateString();
            $incomingDate = Carbon::parse((string) $row['po_date'])->toDateString();

            if ($existingDate !== $incomingDate) {
                throw ValidationException::withMessages([
                    'items' => "Buyer PO {$existing->po_number} already exists with a different PO date.",
                ]);
            }
        }
    }

    private function refreshBuyerPoStatus(Quotation $quotation): void
    {
        if (in_array($quotation->status, ['supplier_po_created', 'closed', 'cancelled', 'rejected', 'lost'], true)) {
            return;
        }

        $itemsCount = $quotation->items()->count();
        $coveredItemsCount = $this->coveredBuyerPoItemCount($quotation);
        $quotation->forceFill([
            'status' => $itemsCount > 0 && $coveredItemsCount >= $itemsCount
                ? 'buyer_po_received'
                : 'buyer_po_partial',
        ])->save();
    }

    private function coveredBuyerPoItemCount(Quotation $quotation): int
    {
        return BuyerPoItem::query()
            ->where('quotation_id', $quotation->id)
            ->distinct('quotation_item_id')
            ->count('quotation_item_id');
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
                ->where('status', 'active')
                ->orderBy('id')
                ->first();

            if ($supplier?->company) {
                return [
                    'company_id' => $supplier->company->id,
                    'company_name' => $supplier->company->name,
                    'company_code' => $supplier->company->company_code,
                    'contact_id' => $user->contact->id,
                    'contact_name' => $user->contact->name,
                ];
            }
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

    private function buyerCompanyQuery(): Builder
    {
        return $this->applyBuyerCompanyScope(Company::query());
    }

    private function applyBuyerCompanyScope(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $builder): void {
                $builder->whereHas('buyerProfile', fn (Builder $profile) => $profile->where('status', 'active'))
                    ->orWhereIn('company_type', ['buyer', 'mixed']);
            });
    }

    private function referenceFor(Quotation $quotation): string
    {
        $quotation->loadMissing('buyerCompany');
        $buyerCode = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $quotation->buyerCompany?->company_code) ?: 'BUY');

        return sprintf('ISC-COR-QT-%d-%s-%s', $quotation->id, $buyerCode, now()->format('y'));
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function rfqDisplayTitle(Quotation $quotation): ?string
    {
        if (filled($quotation->rfq_title)) {
            return trim((string) $quotation->rfq_title);
        }

        $parts = [];

        if (filled($quotation->rfq_number)) {
            $parts[] = 'RFQ '.trim((string) $quotation->rfq_number);
        }

        if (filled($quotation->pr_number)) {
            $parts[] = 'PR '.trim((string) $quotation->pr_number);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @return Collection<int, string>
     */
    private function requiredDefaultTermKeys(): Collection
    {
        return collect(self::DEFAULT_TERMS)
            ->filter(fn (array $term): bool => ($term['required'] ?? true) === true)
            ->pluck('key')
            ->values();
    }

    private function hasRichTextContent(?string $value): bool
    {
        $text = preg_replace('/<[^>]*>/', ' ', (string) $value) ?? (string) $value;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);

        return trim($text) !== '';
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
            'rfq_title' => $quotation->rfq_title,
            'rfq_display_title' => $this->rfqDisplayTitle($quotation),
            'closing_at' => $quotation->closing_at?->toDateTimeString(),
            'quotation_validity_value' => $quotation->quotation_validity_value,
            'quotation_validity_unit' => $quotation->quotation_validity_unit,
            'payment_term_days' => $quotation->payment_term_days,
            'payment_terms_extra' => $quotation->payment_terms_extra,
            'payment_customer_type' => $quotation->payment_customer_type ?? 'credit',
            'payment_customer_type_label' => $this->paymentCustomerTypeLabel($quotation->payment_customer_type ?? 'credit'),
            'payment_schedule_summary' => $this->paymentScheduleSummary($quotation),
            'payment_schedules' => $quotation->relationLoaded('paymentSchedules')
                ? $quotation->paymentSchedules->map(fn (QuotationPaymentSchedule $schedule): array => $this->transformPaymentSchedule($schedule))->values()
                : [],
            'delivery_period_min' => $quotation->delivery_period_min,
            'delivery_period_max' => $quotation->delivery_period_max,
            'delivery_period_unit' => $quotation->delivery_period_unit,
            'delivery_period_type' => $quotation->delivery_period_type,
            'accepted_invoice_currency' => $quotation->accepted_invoice_currency,
            'accepted_invoice_currency_symbol' => $this->currencySymbolFor($quotation->accepted_invoice_currency),
            'accepted_invoice_currency_display' => $this->currencyDisplayFor($quotation->accepted_invoice_currency),
            'vat_pricing' => $quotation->vat_pricing ?? 'exclusive',
            'incoterm_id' => $quotation->incoterm_id,
            'incoterm_code' => $quotation->incoterm?->code,
            'delivery_responsibility' => $quotation->delivery_responsibility,
            'charges' => $quotation->relationLoaded('charges')
                ? $quotation->charges->map(fn (QuotationCharge $charge): array => $this->transformCharge($charge))->values()
                : [],
            'discounts' => $quotation->relationLoaded('discounts')
                ? $quotation->discounts->map(fn (QuotationDiscount $discount): array => $this->transformDiscount($discount))->values()
                : [],
            'totals' => $this->quotationTotals($quotation),
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
    private function transformItem(QuotationItem $item, ?string $vatPricing = null): array
    {
        $vatPricing ??= 'exclusive';

        return [
            'id' => $item->id,
            'quotation_id' => $item->quotation_id,
            'product_id' => $item->product_id,
            'manufacturer_id' => $item->manufacturer_id,
            'manufacturer_name' => $item->manufacturer?->name,
            'line_number' => $item->line_number,
            'product_code' => $item->product_code,
            'product_name' => $item->product_name,
            'title' => $item->title,
            'buyer_description' => $item->buyer_description,
            'manufacturer_description' => $item->manufacturer_description,
            'quantity' => $this->money($item->quantity),
            'uom' => $item->uom,
            'incoterm_id' => $item->incoterm_id,
            'incoterm_code' => $item->incoterm?->code,
            'unit_price' => $this->money($item->unit_price),
            'vat_rate' => $this->money($item->vat_rate),
            'total_price' => $this->money($item->total_price),
            'vat_amount' => $this->money($this->lineVatAmount($item, $vatPricing)),
            'total_with_vat' => $this->money($this->lineGrossTotal($item, $vatPricing)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformCharge(QuotationCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'quotation_id' => $charge->quotation_id,
            'line_number' => $charge->line_number,
            'label' => $charge->label,
            'amount' => $this->money($charge->amount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformDiscount(QuotationDiscount $discount): array
    {
        return [
            'id' => $discount->id,
            'quotation_id' => $discount->quotation_id,
            'line_number' => $discount->line_number,
            'label' => $discount->label,
            'discount_type' => $discount->discount_type,
            'amount' => $this->money($discount->amount),
        ];
    }

    /**
     * @return Collection<int, array{id: string, code: string, name: string, symbol?: string|null, exchange_rate?: string|null}>
     */
    private function currencyOptions(): Collection
    {
        return Currency::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['code', 'name', 'symbol', 'exchange_rate'])
            ->map(fn (Currency $currency): array => [
                'id' => $currency->code,
                'code' => $currency->code,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
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

    private function currencySymbolFor(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        $symbol = Currency::query()
            ->where('code', $code)
            ->value('symbol');

        return $symbol ?: $this->defaultCurrencySymbol($code);
    }

    private function currencyDisplayFor(?string $code): ?string
    {
        if (! $code) {
            return null;
        }

        $symbol = $this->currencySymbolFor($code);

        return $symbol ? "{$code} ({$symbol})" : $code;
    }

    private function defaultCurrencySymbol(string $code): ?string
    {
        return match (Str::upper($code)) {
            'AED' => 'د.إ',
            'EUR' => '€',
            'GBP' => '£',
            'OMR' => 'ر.ع.',
            'SAR' => '﷼',
            'USD' => '$',
            default => null,
        };
    }

    /**
     * @return Collection<int, array{id: string, code: string, name: string}>
     */
    private function uomOptions(): Collection
    {
        return Uom::query()
            ->where('status', 'active')
            ->orderBy('code')
            ->get(['code', 'name'])
            ->map(fn (Uom $uom): array => [
                'id' => $uom->code,
                'code' => $uom->code,
                'name' => $uom->name,
            ])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function uomCodes(): array
    {
        return $this->uomOptions()
            ->pluck('id')
            ->map(fn (mixed $uom): string => (string) $uom)
            ->all();
    }

    private function seedDefaultPaymentSchedule(Quotation $quotation): void
    {
        $quotation->paymentSchedules()->create([
            'line_number' => 1,
            'label' => $quotation->payment_customer_type === 'paying' ? 'Full payment' : 'Credit payment',
            'payment_method' => 'bank_transfer',
            'payment_percentage' => '100.000',
            'due_timing' => 'relative',
            'due_event' => 'after_invoice',
            'due_offset_days' => $quotation->payment_term_days,
            'due_date' => null,
            'notes' => null,
        ]);
    }

    private function syncDefaultPaymentScheduleDays(Quotation $quotation): void
    {
        $schedules = $quotation->paymentSchedules()->get();

        if ($schedules->count() !== 1) {
            return;
        }

        /** @var QuotationPaymentSchedule $schedule */
        $schedule = $schedules->first();

        if ($schedule->due_timing !== 'relative' || $schedule->due_event !== 'after_invoice') {
            return;
        }

        $schedule->forceFill([
            'due_offset_days' => $quotation->payment_term_days,
        ])->save();
    }

    private function paymentScheduleIsLocked(Quotation $quotation): bool
    {
        return FollowUpItem::query()->where('quotation_id', $quotation->id)->exists()
            || $quotation->paymentSchedules()
                ->whereHas('paymentPlanFollowUps')
                ->exists();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $normalized
     */
    private function paymentScheduleMatches(Quotation $quotation, Collection $normalized): bool
    {
        $current = $quotation->paymentSchedules()
            ->orderBy('line_number')
            ->get()
            ->map(fn (QuotationPaymentSchedule $schedule): array => [
                'line_number' => (int) $schedule->line_number,
                'label' => (string) $schedule->label,
                'payment_method' => (string) $schedule->payment_method,
                'payment_percentage' => $this->money($schedule->payment_percentage),
                'due_timing' => (string) $schedule->due_timing,
                'due_event' => $schedule->due_event,
                'due_offset_days' => $schedule->due_offset_days === null ? null : (int) $schedule->due_offset_days,
                'due_date' => $schedule->due_date?->toDateString(),
                'notes' => $schedule->notes,
            ])
            ->values()
            ->all();

        $incoming = $normalized
            ->map(fn (array $schedule): array => [
                ...$schedule,
                'line_number' => (int) $schedule['line_number'],
                'payment_percentage' => $this->money($schedule['payment_percentage']),
                'due_offset_days' => $schedule['due_offset_days'] === null ? null : (int) $schedule['due_offset_days'],
                'due_date' => empty($schedule['due_date']) ? null : Carbon::parse($schedule['due_date'])->toDateString(),
            ])
            ->values()
            ->all();

        return $current === $incoming;
    }

    private function paymentScheduleConflictResponse(): JsonResponse
    {
        $message = 'Payment terms are locked because follow-up tracking has already started. Existing instalments, payments, and evidence were not changed.';

        return response()->json([
            'message' => $message,
            'errors' => [
                'payment_schedules' => [$message],
            ],
        ], 409);
    }

    private function quotationHasDownstreamCommitments(Quotation $quotation): bool
    {
        return in_array($quotation->status, ['buyer_po_received', 'supplier_po_created', 'closed', 'cancelled', 'rejected', 'lost'], true)
            || $quotation->buyerPos()->exists()
            || $quotation->items()->whereHas('supplierPoLines')->exists();
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function defaultPaymentLineLabel(Quotation $quotation, array $schedule, int $index): string
    {
        if (($schedule['payment_method'] ?? null) === 'cheque') {
            return 'Check '.($index + 1);
        }

        if ($quotation->payment_customer_type === 'paying') {
            return $index === 0 ? 'Advance payment' : 'Balance payment';
        }

        return 'Installment '.($index + 1);
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

    private function paymentScheduleLineSummary(QuotationPaymentSchedule $schedule): string
    {
        $percentage = rtrim(rtrim($this->money($schedule->payment_percentage), '0'), '.');
        $method = $this->paymentMethodLabel($schedule->payment_method);

        if ($schedule->due_timing === 'fixed_date') {
            $due = 'on '.$schedule->due_date?->toDateString();
        } else {
            $days = (int) ($schedule->due_offset_days ?? 0);
            $event = $this->dueEventLabel($schedule->due_event);
            $due = str_starts_with((string) $schedule->due_event, 'on_') || $days === 0
                ? $event
                : "{$days} days {$event}";
        }

        return "{$schedule->label}: {$percentage}% by {$method}, {$due}";
    }

    private function paymentScheduleSummary(Quotation $quotation): string
    {
        return $this->acceptedPaymentTerms($quotation);
    }

    private function acceptedPaymentTerms(Quotation $quotation): string
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
            'summary' => $this->paymentScheduleLineSummary($schedule),
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
                'commercial' => [
                    'docx' => "/api/quotations/{$version->quotation_id}/versions/{$version->version_number}/download/commercial/docx",
                    'pdf' => "/api/quotations/{$version->quotation_id}/versions/{$version->version_number}/download/commercial/pdf",
                ],
                'technical' => [
                    'docx' => "/api/quotations/{$version->quotation_id}/versions/{$version->version_number}/download/technical/docx",
                    'pdf' => "/api/quotations/{$version->quotation_id}/versions/{$version->version_number}/download/technical/pdf",
                ],
            ],
        ];
    }

    private function typedVersionPath(QuotationVersion $version, string $documentType, string $format): string
    {
        if ($documentType === 'commercial') {
            return $format === 'docx' ? $version->docx_path : $version->pdf_path;
        }

        $commercialPath = $format === 'docx' ? $version->docx_path : $version->pdf_path;
        $extension = '.'.$format;

        if (str_ends_with($commercialPath, $extension)) {
            return substr($commercialPath, 0, -strlen($extension)).'-technical'.$extension;
        }

        return dirname($commercialPath).'/'.Str::slug($version->quotation_reference, '-')."-technical-rev-{$version->version_number}.{$format}";
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
            'original_file_name' => $buyerPo->original_file_name,
            'download_url' => $buyerPo->po_file_path
                ? "/api/quotations/{$buyerPo->quotation_id}/buyer-po/{$buyerPo->id}/download"
                : null,
            'items' => $buyerPo->relationLoaded('items')
                ? $buyerPo->items->map(fn (BuyerPoItem $item): array => $this->transformBuyerPoItem($item))->values()
                : [],
            'status' => $buyerPo->status,
            'created_by' => $buyerPo->created_by,
            'created_by_name' => $buyerPo->creator?->name,
            'created_at' => $buyerPo->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBuyerPoItem(BuyerPoItem $item): array
    {
        return [
            'id' => $item->id,
            'buyer_po_id' => $item->buyer_po_id,
            'quotation_id' => $item->quotation_id,
            'quotation_item_id' => $item->quotation_item_id,
            'line_number' => $item->line_number,
            'buyer_item_code' => $item->buyer_item_code,
            'product_code' => $item->quotationItem?->product_code,
            'product_name' => $item->quotationItem?->product_name,
            'title' => $item->quotationItem?->title ?? $item->item_description,
            'manufacturer_name' => $item->quotationItem?->manufacturer?->name,
            'item_description' => $item->item_description,
            'quantity' => $this->money($item->quantity),
            'uom' => $item->uom,
            'unit_price' => $this->money($item->unit_price),
            'total_amount' => $this->money($item->total_amount),
            'currency' => $item->currency,
            'status' => $item->status,
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
     * @param  array<string, mixed>  $currentSnapshot
     * @param  array<string, mixed>|null  $previousSnapshot
     * @return array{changed: bool, reasons: array<int, string>, minor_description_corrections: array<int, string>}
     */
    private function materialRevisionComparison(array $currentSnapshot, ?array $previousSnapshot): array
    {
        if (! $previousSnapshot || ! isset($previousSnapshot['items']) || ! is_array($previousSnapshot['items'])) {
            return [
                'changed' => true,
                'reasons' => ['initial_version'],
                'minor_description_corrections' => [],
            ];
        }

        $reasons = [];

        if ($this->materialRevisionCore($currentSnapshot) !== $this->materialRevisionCore($previousSnapshot)) {
            $reasons[] = 'product_or_price_changed';
        }

        $descriptionComparison = $this->compareMaterialDescriptions($currentSnapshot, $previousSnapshot);

        if ($descriptionComparison['material_changes'] !== []) {
            $reasons[] = 'product_description_changed';
        }

        return [
            'changed' => $reasons !== [],
            'reasons' => $reasons === [] ? ['no_material_product_or_price_change'] : $reasons,
            'minor_description_corrections' => $descriptionComparison['minor_corrections'],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function materialRevisionCore(array $snapshot): array
    {
        $quotation = is_array($snapshot['quotation'] ?? null) ? $snapshot['quotation'] : [];

        return [
            'currency' => $this->canonicalRevisionText($quotation['currency'] ?? null),
            'vat_pricing' => $this->canonicalRevisionText($quotation['vat_pricing'] ?? 'exclusive'),
            'items' => collect($snapshot['items'] ?? [])
                ->map(fn (mixed $item): array => $this->materialItemCore(is_array($item) ? $item : []))
                ->sortBy([['line_number', 'asc'], ['product_code', 'asc'], ['title', 'asc']])
                ->values()
                ->all(),
            'charges' => collect($snapshot['charges'] ?? [])
                ->map(fn (mixed $charge): array => [
                    'line_number' => (int) ($charge['line_number'] ?? 0),
                    'label' => $this->canonicalRevisionText($charge['label'] ?? null),
                    'amount' => $this->canonicalRevisionMoney($charge['amount'] ?? 0),
                ])
                ->sortBy([['line_number', 'asc'], ['label', 'asc']])
                ->values()
                ->all(),
            'discounts' => collect($snapshot['discounts'] ?? [])
                ->map(fn (mixed $discount): array => [
                    'line_number' => (int) ($discount['line_number'] ?? 0),
                    'label' => $this->canonicalRevisionText($discount['label'] ?? null),
                    'discount_type' => $this->canonicalRevisionText($discount['discount_type'] ?? 'fixed'),
                    'amount' => $this->canonicalRevisionMoney($discount['amount'] ?? 0),
                ])
                ->sortBy([['line_number', 'asc'], ['label', 'asc']])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function materialItemCore(array $item): array
    {
        return [
            'line_number' => (int) ($item['line_number'] ?? 0),
            'manufacturer' => $this->canonicalRevisionText($item['manufacturer'] ?? null),
            'product_code' => $this->canonicalRevisionText($item['product_code'] ?? null),
            'product_name' => $this->canonicalRevisionText($item['product_name'] ?? null),
            'title' => $this->canonicalRevisionText($item['title'] ?? null),
            'quantity' => $this->canonicalRevisionMoney($item['quantity'] ?? 0),
            'uom' => $this->canonicalRevisionText($item['uom'] ?? null),
            'incoterm' => $this->canonicalRevisionText($item['incoterm'] ?? $item['incoterm_code'] ?? null),
            'unit_price' => $this->canonicalRevisionMoney($item['unit_price'] ?? 0),
            'vat_rate' => $this->canonicalRevisionMoney($item['vat_rate'] ?? 0),
            'total_price' => $this->canonicalRevisionMoney($item['total_price'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $currentSnapshot
     * @param  array<string, mixed>  $previousSnapshot
     * @return array{material_changes: array<int, string>, minor_corrections: array<int, string>}
     */
    private function compareMaterialDescriptions(array $currentSnapshot, array $previousSnapshot): array
    {
        $current = $this->materialDescriptionMap($currentSnapshot);
        $previous = $this->materialDescriptionMap($previousSnapshot);
        $materialChanges = [];
        $minorCorrections = [];

        if (array_keys($current) !== array_keys($previous)) {
            return [
                'material_changes' => array_values(array_unique(array_merge(array_keys($current), array_keys($previous)))),
                'minor_corrections' => [],
            ];
        }

        foreach ($current as $key => $currentDescription) {
            $previousDescription = $previous[$key] ?? '';

            if ($currentDescription === $previousDescription) {
                continue;
            }

            if ($this->isMinorDescriptionCorrection($previousDescription, $currentDescription)) {
                $minorCorrections[] = $key;

                continue;
            }

            $materialChanges[] = $key;
        }

        return [
            'material_changes' => $materialChanges,
            'minor_corrections' => $minorCorrections,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, string>
     */
    private function materialDescriptionMap(array $snapshot): array
    {
        return collect($snapshot['items'] ?? [])
            ->mapWithKeys(function (mixed $item): array {
                $item = is_array($item) ? $item : [];
                $key = implode('|', [
                    (int) ($item['line_number'] ?? 0),
                    $this->canonicalRevisionText($item['product_code'] ?? null),
                    $this->canonicalRevisionText($item['title'] ?? null),
                ]);

                return [$key => $this->canonicalRevisionDescription($item['description'] ?? $item['description_html'] ?? '')];
            })
            ->sortKeys()
            ->all();
    }

    private function isMinorDescriptionCorrection(string $previous, string $current): bool
    {
        return app(QuotationDescriptionChangeClassifier::class)->isMinorCorrection($previous, $current);
    }

    private function canonicalRevisionText(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::lower(trim($text));
    }

    private function canonicalRevisionDescription(mixed $value): string
    {
        return app(QuotationDescriptionChangeClassifier::class)->canonicalize($value);
    }

    private function canonicalRevisionMoney(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
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

    /**
     * @return array<string, string>
     */
    private function quotationTotals(Quotation $quotation): array
    {
        $totals = QuotationPricing::forQuotation($quotation);
        unset($totals['discount_values'], $totals['discounts_exceed_base']);

        return ['subtotal' => $totals['items_subtotal'], ...$totals];
    }

    private function lineGrossTotal(QuotationItem $item, string $vatPricing): float
    {
        return (float) QuotationPricing::line($item->getAttributes(), $vatPricing)['gross'];
    }

    private function lineVatAmount(QuotationItem $item, string $vatPricing): float
    {
        return (float) QuotationPricing::line($item->getAttributes(), $vatPricing)['vat'];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
