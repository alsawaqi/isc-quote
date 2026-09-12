<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Quotation;
use App\Models\QuotationCharge;
use App\Models\QuotationDiscount;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentSchedule;
use App\Models\QuotationTerm;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class QuotationDocumentService
{
    public function __construct(
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly QuotationSnapshotRichTextSanitizer $snapshotRichTextSanitizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Quotation $quotation, int $versionNumber): array
    {
        $quotation->loadMissing([
            'salesperson',
            'supplierCompany.country',
            'supplierContact.designation',
            'buyerCompany.country',
            'buyerContact.designation',
            'incoterm',
            'items.manufacturer',
            'items.incoterm',
            'charges',
            'discounts',
            'paymentSchedules',
            'terms',
        ]);

        $totals = $this->quotationTotals($quotation);
        $currency = $this->currencyInfo($quotation->accepted_invoice_currency);

        return [
            'quotation' => [
                'id' => $quotation->id,
                'reference' => $quotation->quotation_reference,
                'version_number' => $versionNumber,
                'rfq_number' => $quotation->rfq_number,
                'pr_number' => $quotation->pr_number,
                'rfq_title' => $this->rfqTitle($quotation),
                'dated' => $this->formatDate($quotation->created_at),
                'closing_at' => $quotation->closing_at ? $this->formatClosingDate($quotation->closing_at) : null,
                'validity' => "{$quotation->quotation_validity_value} ".ucfirst((string) $quotation->quotation_validity_unit).' from the date of quote.',
                'payment_customer_type' => $quotation->payment_customer_type ?? 'credit',
                'payment_customer_type_label' => $this->paymentCustomerTypeLabel($quotation->payment_customer_type ?? 'credit'),
                'payment_terms' => $this->paymentScheduleSummary($quotation),
                'delivery_period' => "Within {$quotation->delivery_period_min} to {$quotation->delivery_period_max} {$quotation->delivery_period_type} {$quotation->delivery_period_unit} from the date of PO",
                'currency' => $currency['code'],
                'currency_symbol' => $currency['symbol'],
                'currency_display' => $currency['display'],
                'vat_pricing' => $quotation->vat_pricing ?? 'exclusive',
                'incoterm' => $quotation->incoterm?->code,
            ],
            'supplier' => $this->companySnapshot($quotation->supplierCompany),
            'buyer' => $this->companySnapshot($quotation->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($quotation->supplierContact),
            'buyer_contact' => $this->contactSnapshot($quotation->buyerContact),
            'items' => $quotation->items->map(fn (QuotationItem $item): array => [
                'line_number' => $item->line_number,
                'manufacturer' => $item->manufacturer?->name,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'title' => $item->title,
                'description' => $this->htmlToPlainText($item->buyer_description),
                'description_html' => $this->cleanRichHtml($item->buyer_description),
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'incoterm' => $item->incoterm?->code ?? $quotation->incoterm?->code,
                'incoterm_name' => $item->incoterm?->name,
                'unit_price' => $this->money($item->unit_price),
                'vat_rate' => $this->money($item->vat_rate),
                'vat_amount' => $this->money($this->lineVatAmount($item, $quotation->vat_pricing ?? 'exclusive')),
                'total_price' => $this->money($item->total_price),
                'total_with_vat' => $this->money($this->lineGrossTotal($item, $quotation->vat_pricing ?? 'exclusive')),
            ])->values()->all(),
            'charges' => $quotation->charges->map(fn (QuotationCharge $charge): array => [
                'line_number' => $charge->line_number,
                'label' => $charge->label,
                'amount' => $this->money($charge->amount),
            ])->values()->all(),
            'discounts' => $quotation->discounts->values()->map(fn (QuotationDiscount $discount, int $index): array => [
                'line_number' => $discount->line_number,
                'label' => $discount->label,
                'discount_type' => $discount->discount_type,
                'amount' => $this->money($discount->amount),
                'computed_amount' => $totals['discount_values'][$index],
            ])->values()->all(),
            'payment_schedule' => $quotation->paymentSchedules->map(fn (QuotationPaymentSchedule $schedule): array => [
                'line_number' => $schedule->line_number,
                'label' => $schedule->label,
                'payment_method' => $this->paymentMethodLabel($schedule->payment_method),
                'payment_percentage' => $this->money($schedule->payment_percentage),
                'due' => $this->scheduleDueText($schedule),
                'notes' => $schedule->notes,
                'summary' => $this->paymentScheduleLineSummary($schedule),
            ])->values()->all(),
            'terms' => $quotation->terms->sortBy('line_number')->values()->map(fn (QuotationTerm $term): array => [
                'line_number' => $term->line_number,
                'key' => $term->key,
                'title' => $term->title,
                'description' => $term->description,
                'description_plain' => $this->htmlToPlainText($term->description),
                'description_html' => $this->cleanRichHtml($term->description),
                'is_required_default' => $term->is_required_default,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->money($totals['items_subtotal']),
                'vat' => $this->money($totals['vat_total']),
                'charges' => $this->money($totals['charges_total']),
                'discounts' => $this->money($totals['discounts_total']),
                'grand_total' => $this->money($totals['grand_total']),
            ],
        ];
    }

    public function writeDocx(array $snapshot, string $storagePath): void
    {
        app(QuotationOfferLayout::class)->writeWord($this->snapshotRichTextSanitizer->sanitize($snapshot), $storagePath, false);
    }

    public function writeTechnicalDocx(array $snapshot, string $storagePath): void
    {
        app(QuotationOfferLayout::class)->writeWord($this->snapshotRichTextSanitizer->sanitize($snapshot), $storagePath, true);
    }

    public function writePdf(array $snapshot, string $storagePath): void
    {
        app(QuotationOfferLayout::class)->writePdf($this->snapshotRichTextSanitizer->sanitize($snapshot), $storagePath, false);
    }

    public function writeTechnicalPdf(array $snapshot, string $storagePath): void
    {
        app(QuotationOfferLayout::class)->writePdf($this->snapshotRichTextSanitizer->sanitize($snapshot), $storagePath, true);
    }

    public function needsLayoutRefresh(string $storagePath): bool
    {
        return ! Storage::disk('local')->exists($storagePath)
            || ! Storage::disk('local')->exists($storagePath.'.layout')
            || Storage::disk('local')->get($storagePath.'.layout') !== QuotationOfferLayout::VERSION;
    }

    public function docxXmlPartsAreParseable(string $storagePath): bool
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open(Storage::disk('local')->path($storagePath)) !== true) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (! str_ends_with($name, '.xml')) {
                    continue;
                }

                libxml_clear_errors();
                $xml = $zip->getFromName($name);

                if (simplexml_load_string((string) $xml) === false) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function companySnapshot($company): ?array
    {
        if (! $company) {
            return null;
        }

        return [
            'name' => $company->name,
            'address' => $company->address,
            'location' => $company->location,
            'postal_code' => $company->postal_code,
            'email' => $company->email,
            'phone' => $company->phone,
            'vat_tin' => $company->vat_tin,
            'country' => $company->country?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contactSnapshot($contact): ?array
    {
        if (! $contact) {
            return null;
        }

        return [
            'designation' => $contact->designation?->name,
            'name' => $contact->name,
            'job_title' => $contact->job_title,
            'mobile' => $contact->mobile,
            'telephone' => $contact->telephone,
            'extension' => $contact->extension,
            'email' => $contact->email,
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

    private function quotationTotals(Quotation $quotation): array
    {
        return QuotationPricing::forQuotation($quotation);
    }

    private function lineGrossTotal(QuotationItem $item, string $vatPricing): float
    {
        return (float) QuotationPricing::line($item->getAttributes(), $vatPricing)['gross'];
    }

    private function lineVatAmount(QuotationItem $item, string $vatPricing): float
    {
        return (float) QuotationPricing::line($item->getAttributes(), $vatPricing)['vat'];
    }

    private function rfqTitle(Quotation $quotation): ?string
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
     * @return array{code: string, symbol: string|null, display: string}
     */
    private function currencyInfo(string $code): array
    {
        $currency = Currency::query()
            ->where('code', $code)
            ->first(['code', 'symbol']);

        $symbol = $currency?->symbol ?: $this->defaultCurrencySymbol($code);

        return [
            'code' => $code,
            'symbol' => $symbol,
            'display' => $symbol ? "{$code} ({$symbol})" : $code,
        ];
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

    private function cleanRichHtml(?string $html): string
    {
        return $this->richTextSanitizer->sanitize($html) ?? '';
    }

    private function htmlToPlainText(?string $html): string
    {
        $text = (string) $html;
        $text = preg_replace('/<li[^>]*>/i', "\n- ", $text) ?? $text;
        $text = preg_replace('/<\/p>|<br\s*\/?>|<\/div>|<\/li>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);
    }

    private function formatDate(?CarbonInterface $date): ?string
    {
        return $date?->format('jS M Y');
    }

    private function formatClosingDate(?CarbonInterface $date): ?string
    {
        return $date?->format($date->minute === 0 ? 'd.m.Y, ga' : 'd.m.Y, g:ia');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', ',');
    }
}
