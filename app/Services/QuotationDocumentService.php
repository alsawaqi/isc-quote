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
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use Throwable;
use ZipArchive;

class QuotationDocumentService
{
    public function __construct(
        private readonly RichTextSanitizer $richTextSanitizer,
        private readonly QuotationSnapshotRichTextSanitizer $snapshotRichTextSanitizer,
        private readonly DocumentPageLayout $pageLayout,
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
            'discounts' => $quotation->discounts->map(fn (QuotationDiscount $discount): array => [
                'line_number' => $discount->line_number,
                'label' => $discount->label,
                'discount_type' => $discount->discount_type,
                'amount' => $this->money($discount->amount),
                'computed_amount' => $this->money($this->discountValue($discount, $totals['discount_base'])),
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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writeDocx(array $snapshot, string $storagePath): void
    {
        $snapshot = $this->snapshotRichTextSanitizer->sanitize($snapshot);
        Storage::disk('local')->makeDirectory(dirname($storagePath));
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);
        $phpWord->addTableStyle('InfoTable', $this->pageLayout->tableStyle('BFBFBF', 120));
        $phpWord->addTableStyle('ItemsTable', $this->pageLayout->tableStyle('8EA9DB', 100), [
            'bgColor' => '1F4E79',
        ]);

        $section = $this->pageLayout->addWordSection(
            $phpWord,
            (string) $snapshot['quotation']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
        );
        $rfqTitle = $snapshot['quotation']['rfq_title'] ?? trim(implode(' ', array_filter([
            ! empty($snapshot['quotation']['rfq_number']) ? 'RFQ '.$snapshot['quotation']['rfq_number'] : null,
            ! empty($snapshot['quotation']['pr_number']) ? 'PR '.$snapshot['quotation']['pr_number'] : null,
        ])));
        $currencyDisplay = $snapshot['quotation']['currency_display'] ?? $snapshot['quotation']['currency'];

        $section->addText('Commercial Offer', ['bold' => true, 'size' => 16, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
        if ($rfqTitle !== '') {
            $section->addText(' - '.$rfqTitle, ['bold' => true, 'size' => 10], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
        }

        $refTable = $section->addTable('InfoTable');
        $this->pageLayout->addWordSummaryRow($refTable);
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['quotation']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['quotation']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'QUOTATION VALIDITY PERIOD', [$snapshot['quotation']['validity']], 'ACCEPTED TERMS OF PAYMENT', [$snapshot['quotation']['payment_terms']]);
        $this->addInfoRow($infoTable, 'DATE OF DELIVERY', [$snapshot['quotation']['delivery_period']], 'ACCEPTED INVOICE CURRENCY', [$currencyDisplay]);

        if (! empty($snapshot['payment_schedule'])) {
            $section->addTextBreak(1);
            $section->addText('Payment Schedule', ['bold' => true, 'size' => 11, 'color' => '1F4E79']);

            foreach ($snapshot['payment_schedule'] as $schedule) {
                $line = "{$schedule['line_number']}. {$schedule['summary']}";
                if (! empty($schedule['notes'])) {
                    $line .= ' - '.$schedule['notes'];
                }
                $section->addText($line, [], ['spaceAfter' => 80]);
            }
        }

        if ($rfqTitle !== '' || ! empty($snapshot['quotation']['closing_at'])) {
            $section->addTextBreak(1);

            if ($rfqTitle !== '') {
                $section->addText($rfqTitle, ['bold' => true, 'size' => 9], ['alignment' => Jc::RIGHT, 'spaceAfter' => 20]);
            }

            if (! empty($snapshot['quotation']['closing_at'])) {
                $section->addText('Closing Date: '.$snapshot['quotation']['closing_at'], ['bold' => true, 'size' => 9], ['alignment' => Jc::RIGHT]);
            }
        }

        $itemsTable = $section->addTable('ItemsTable');
        $this->pageLayout->addWordTableHeader($itemsTable);
        foreach ([
            'SL No' => 800,
            'Material / Item Code' => 1000,
            'Description' => 3800,
            'QTY' => 1100,
            'Unit Price' => 1200,
            'VAT %' => 900,
            'Total excl. VAT' => 1300,
        ] as $heading => $width) {
            $itemsTable->addCell($width, ['bgColor' => '1F4E79', 'valign' => 'center'])
                ->addText($heading, ['bold' => true, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER]);
        }

        foreach ($snapshot['items'] as $item) {
            $itemsTable->addRow();
            $itemsTable->addCell(800)->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1000)->addText((string) ($item['product_code'] ?: '-'), [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(3800);
            $descriptionCell->addText(trim(($item['manufacturer'] ? $item['manufacturer'].' - ' : '').$item['title']), ['bold' => true]);
            $itemMeta = array_filter([
                ! empty($item['incoterm']) ? 'Incoterm: '.$item['incoterm'] : null,
            ]);

            if ($itemMeta !== []) {
                $descriptionCell->addText(implode(' | ', $itemMeta), ['italic' => true, 'size' => 8, 'color' => '666666']);
            }
            $this->addRichHtml($descriptionCell, $item['description_html'] ?? $item['description']);
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['unit_price'], [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(900)->addText($item['vat_rate'] ?? '0.000', [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($item['total_price'], [], ['alignment' => Jc::RIGHT]);
        }

        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 6])->addText('Total Net Amount '.$currencyDisplay.' (Excluding VAT):', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['totals']['subtotal'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        foreach (($snapshot['charges'] ?? []) as $charge) {
            $this->pageLayout->addWordSummaryRow($itemsTable);
            $itemsTable->addCell(800, ['gridSpan' => 6])->addText($charge['label'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($charge['amount'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        }
        foreach (($snapshot['discounts'] ?? []) as $discount) {
            $label = $discount['discount_type'] === 'percentage'
                ? "{$discount['label']} ({$discount['amount']}%)"
                : $discount['label'];
            $this->pageLayout->addWordSummaryRow($itemsTable);
            $itemsTable->addCell(800, ['gridSpan' => 6])->addText($label.':', ['bold' => true], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText('-'.$discount['computed_amount'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        }
        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 6])->addText('VAT Amount '.$currencyDisplay.' After Discount ('.ucfirst((string) ($snapshot['quotation']['vat_pricing'] ?? 'exclusive')).'):', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['totals']['vat'] ?? '0.000', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 6])->addText('Total Amount '.$currencyDisplay.' (Including VAT):', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['totals']['grand_total'] ?? $snapshot['totals']['subtotal'], ['bold' => true], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);
        $section->addText('Terms & Conditions:', ['bold' => true, 'size' => 11, 'color' => '1F4E79']);

        foreach ($snapshot['terms'] as $index => $term) {
            $lineNumber = $term['line_number'] ?? $index + 1;
            $section->addText($lineNumber.'. '.$term['title'].':', ['bold' => true]);
            $this->addRichHtml($section, $term['description_html'] ?? $term['description'], ['spaceAfter' => 120]);
        }

        $brandingRun = $section->addTextRun([
            'alignment' => Jc::CENTER,
            'spaceBefore' => 40,
            'spaceAfter' => 20,
        ]);
        $this->addImageIfExists($brandingRun, 'quotation-assets/isc-stamp.jpeg', 135, null, Jc::LEFT);
        $brandingRun->addText('      ', ['size' => 4]);
        $this->addImageIfExists($brandingRun, 'quotation-assets/abb-value-provider.jpg', 145, null);
        $section->addText('Industrial Supplies Center LLC.', ['bold' => true], ['alignment' => Jc::CENTER, 'spaceAfter' => 20]);

        IOFactory::createWriter($phpWord, 'Word2007')->save(Storage::disk('local')->path($storagePath));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writeTechnicalDocx(array $snapshot, string $storagePath): void
    {
        $snapshot = $this->snapshotRichTextSanitizer->sanitize($snapshot);
        Storage::disk('local')->makeDirectory(dirname($storagePath));
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);
        $phpWord->addTableStyle('TechnicalInfoTable', $this->pageLayout->tableStyle('111111', 90));
        $phpWord->addTableStyle('TechnicalItemsTable', $this->pageLayout->tableStyle('111111', 110), [
            'bgColor' => 'D9D9D9',
        ]);

        $section = $this->pageLayout->addWordSection(
            $phpWord,
            (string) $snapshot['quotation']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
        );
        $rfqTitle = $snapshot['quotation']['rfq_title'] ?? trim(implode(' ', array_filter([
            ! empty($snapshot['quotation']['rfq_number']) ? 'RFQ '.$snapshot['quotation']['rfq_number'] : null,
            ! empty($snapshot['quotation']['pr_number']) ? 'PR '.$snapshot['quotation']['pr_number'] : null,
        ])));

        $titleRun = $section->addTextRun(['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
        $titleRun->addText('Technical Offer', ['bold' => true, 'size' => 16]);
        if ($rfqTitle !== '') {
            $titleRun->addText(' - '.$rfqTitle, ['bold' => true, 'size' => 16, 'color' => 'FF0000']);
        }

        $refTable = $section->addTable('TechnicalInfoTable');
        $this->pageLayout->addWordSummaryRow($refTable);
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['quotation']['reference']);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['quotation']['dated'], [], ['alignment' => Jc::RIGHT]);

        $infoTable = $section->addTable('TechnicalInfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'QUOTATION VALIDITY PERIOD', [$this->technicalValidity($snapshot)], '', []);
        $this->addInfoRow($infoTable, 'DELIVERY PERIOD', [$snapshot['quotation']['delivery_period']], '', []);

        if ($rfqTitle !== '') {
            $section->addTextBreak(1);
            $section->addText($rfqTitle, ['bold' => true, 'color' => 'FF0000', 'underline' => 'single'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
        }

        $itemsTable = $section->addTable('TechnicalItemsTable');
        $this->pageLayout->addWordTableHeader($itemsTable);
        foreach ([
            ['SL No', 700],
            ['Material / Item Code', 1600],
            ['Description', 6000],
            ['QTY', 1100],
        ] as [$heading, $width]) {
            $itemsTable->addCell($width, ['bgColor' => 'D9D9D9', 'valign' => 'center'])
                ->addText($heading, ['bold' => true], ['alignment' => Jc::CENTER]);
        }

        foreach ($snapshot['items'] as $item) {
            $itemsTable->addRow();
            $itemsTable->addCell(700)->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1600)->addText((string) ($item['product_code'] ?: '-'), [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(6000);
            $descriptionCell->addText(trim(($item['manufacturer'] ? $item['manufacturer'].' - ' : '').$item['title']), ['bold' => true]);
            $itemMeta = array_filter([
                ! empty($item['incoterm']) ? 'Incoterm: '.$item['incoterm'] : null,
            ]);

            if ($itemMeta !== []) {
                $descriptionCell->addText(implode(' | ', $itemMeta), ['italic' => true, 'size' => 8, 'color' => '555555']);
            }
            $this->addRichHtml($descriptionCell, $item['description_html'] ?? $item['description']);
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
        }

        $section->addTextBreak(1);
        $section->addText('Terms & Conditions:', ['bold' => true, 'underline' => 'single'], ['alignment' => Jc::CENTER]);

        foreach ($snapshot['terms'] as $index => $term) {
            $run = $section->addTextRun(['spaceAfter' => 90]);
            $run->addText(($term['line_number'] ?? $index + 1).'. ', ['bold' => false]);
            $run->addText($term['title'].' : ', ['bold' => true]);
            $run->addText($term['description_plain'] ?? $this->htmlToPlainText($term['description'] ?? ''));
        }

        $section->addTextBreak(1);
        $this->addImageIfExists($section, 'quotation-assets/isc-stamp.jpeg', 120, null, Jc::LEFT);
        $section->addText('Industrial Supplies Center LLC', ['bold' => true], ['alignment' => Jc::LEFT]);

        IOFactory::createWriter($phpWord, 'Word2007')->save(Storage::disk('local')->path($storagePath));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writePdf(array $snapshot, string $storagePath): void
    {
        $snapshot = $this->snapshotRichTextSanitizer->sanitize($snapshot);
        Storage::disk('local')->makeDirectory(dirname($storagePath));
        $pdfSnapshot = $this->pageLayout->preparePdfSnapshot($snapshot, true);

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml(view('quotations.document', [
            'snapshot' => $pdfSnapshot,
            'assets' => [
                'header' => $this->assetDataUri('quotation-assets/isc-header.jpeg'),
                'abb' => $this->assetDataUri('quotation-assets/abb-value-provider.jpg'),
                'footer' => $this->assetDataUri('quotation-assets/isc-footer.jpeg'),
                'stamp' => $this->assetDataUri('quotation-assets/isc-stamp.jpeg'),
            ],
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();
        $this->pageLayout->addPdfPageChrome(
            $dompdf,
            (string) $snapshot['quotation']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
        );

        Storage::disk('local')->put($storagePath, $dompdf->output());
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writeTechnicalPdf(array $snapshot, string $storagePath): void
    {
        $snapshot = $this->snapshotRichTextSanitizer->sanitize($snapshot);
        Storage::disk('local')->makeDirectory(dirname($storagePath));
        $pdfSnapshot = $this->pageLayout->preparePdfSnapshot($snapshot, true);

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml(view('quotations.technical-document', [
            'snapshot' => $pdfSnapshot,
            'assets' => [
                'header' => $this->assetDataUri('quotation-assets/isc-header.jpeg'),
                'abb' => $this->assetDataUri('quotation-assets/abb-value-provider.jpg'),
                'footer' => $this->assetDataUri('quotation-assets/isc-footer.jpeg'),
                'stamp' => $this->assetDataUri('quotation-assets/isc-stamp.jpeg'),
            ],
            'technicalValidity' => $this->technicalValidity($snapshot),
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();
        $this->pageLayout->addPdfPageChrome(
            $dompdf,
            (string) $snapshot['quotation']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
            DocumentBrandingAssets::path('quotation-assets/abb-value-provider.jpg'),
        );

        Storage::disk('local')->put($storagePath, $dompdf->output());
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
     * @param  array<string, mixed>|null  $company
     * @return array<int, string>
     */
    private function companyLines(?array $company): array
    {
        return array_values(array_filter([
            $company['name'] ?? null,
            $company['address'] ?? null,
            $company['location'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $contact
     * @return array<int, string>
     */
    private function contactLines(?array $contact): array
    {
        return array_values(array_filter([
            trim(($contact['designation'] ? $contact['designation'].' ' : '').($contact['name'] ?? '')),
            $contact['job_title'] ?? null,
            $contact['mobile'] ? 'Mob: '.$contact['mobile'] : null,
            $contact['telephone'] ? 'Tel: '.$contact['telephone'].($contact['extension'] ? ', Ext:'.$contact['extension'] : '') : null,
            $contact['email'] ? 'E-mail: '.$contact['email'] : null,
        ]));
    }

    /**
     * @param  array<int, string>  $leftLines
     * @param  array<int, string>  $rightLines
     */
    private function addInfoRow($table, string $leftTitle, array $leftLines, string $rightTitle, array $rightLines): void
    {
        $table->addRow(null, ['cantSplit' => true]);
        $leftCell = $table->addCell(4700);
        $leftCell->addText($leftTitle, ['bold' => true, 'color' => '1F4E79']);
        foreach ($leftLines as $line) {
            $leftCell->addText($line);
        }

        $rightCell = $table->addCell(4700);
        $rightCell->addText($rightTitle, ['bold' => true, 'color' => '1F4E79']);
        foreach ($rightLines as $line) {
            $rightCell->addText($line);
        }
    }

    private function addImageIfExists($section, string $storagePath, ?int $width, ?int $height, string $alignment = Jc::CENTER): void
    {
        $assetPath = DocumentBrandingAssets::path($storagePath);

        if ($assetPath === null) {
            return;
        }

        $style = ['alignment' => $alignment];
        if ($width !== null) {
            $style['width'] = $width;
        }
        if ($height !== null) {
            $style['height'] = $height;
        }

        $section->addImage($assetPath, $style);
    }

    private function assetDataUri(string $storagePath): ?string
    {
        return DocumentBrandingAssets::dataUri($storagePath);
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

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function technicalValidity(array $snapshot): string
    {
        $validity = (string) ($snapshot['quotation']['validity'] ?? '');

        return trim((string) preg_replace('/\s+from the date of quote\.?$/i', '', $validity));
    }

    /**
     * @return array{items_subtotal: float, vat_total: float, charges_total: float, discounts_total: float, grand_total: float, discount_base: float}
     */
    private function quotationTotals(Quotation $quotation): array
    {
        $vatPricing = $quotation->vat_pricing ?? 'exclusive';
        $itemsSubtotal = $quotation->items->sum(fn (QuotationItem $item): float => (float) $item->total_price);
        $rawVatTotal = $quotation->items->sum(fn (QuotationItem $item): float => $this->lineVatAmount($item, $vatPricing));
        $chargesTotal = $quotation->charges->sum(fn (QuotationCharge $charge): float => (float) $charge->amount);
        $discountBase = $itemsSubtotal + $chargesTotal;
        $discountsTotal = $quotation->discounts->sum(fn (QuotationDiscount $discount): float => $this->discountValue($discount, $discountBase));
        $discountedBase = max(0, $discountBase - $discountsTotal);
        $vatTotal = $this->discountedVatTotal($rawVatTotal, $discountBase, $discountsTotal);

        return [
            'items_subtotal' => $itemsSubtotal,
            'vat_total' => $vatTotal,
            'charges_total' => $chargesTotal,
            'discounts_total' => $discountsTotal,
            'grand_total' => max(0, $discountedBase + $vatTotal),
            'discount_base' => $discountBase,
        ];
    }

    private function lineGrossTotal(QuotationItem $item, string $vatPricing): float
    {
        if ($vatPricing === 'inclusive') {
            return (float) $item->quantity * (float) $item->unit_price;
        }

        return (float) $item->total_price + $this->lineVatAmount($item, $vatPricing);
    }

    private function lineVatAmount(QuotationItem $item, string $vatPricing): float
    {
        if ($vatPricing === 'inclusive') {
            return max(0, ((float) $item->quantity * (float) $item->unit_price) - (float) $item->total_price);
        }

        return (float) $item->total_price * ((float) $item->vat_rate / 100);
    }

    private function discountValue(QuotationDiscount $discount, float $base): float
    {
        if ($discount->discount_type === 'percentage') {
            return $base * min(100, max(0, (float) $discount->amount)) / 100;
        }

        return min($base, max(0, (float) $discount->amount));
    }

    private function discountedVatTotal(float $rawVatTotal, float $discountBase, float $discountsTotal): float
    {
        if ($rawVatTotal <= 0 || $discountBase <= 0 || $discountsTotal <= 0) {
            return max(0, $rawVatTotal);
        }

        $discountedBase = max(0, $discountBase - $discountsTotal);

        return max(0, $rawVatTotal * ($discountedBase / $discountBase));
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

    private function addRichHtml($container, ?string $html, array $fallbackParagraphStyle = []): void
    {
        $html = $this->cleanRichHtml($html);
        $html = preg_replace('/<mark>/', '<span style="background-color: #fff59d;">', $html) ?? $html;
        $html = str_replace('</mark>', '</span>', $html);

        if ($html === '') {
            return;
        }

        try {
            Html::addHtml($container, $html, false, false);
        } catch (Throwable) {
            foreach (explode("\n", $this->htmlToPlainText($html)) as $line) {
                if (trim($line) !== '') {
                    $container->addText(trim($line), [], $fallbackParagraphStyle);
                }
            }
        }
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
        return $date?->format('jS F Y');
    }

    private function formatClosingDate(?CarbonInterface $date): ?string
    {
        return $date?->format('l, F j, Y \a\t g:i A');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', ',');
    }
}
