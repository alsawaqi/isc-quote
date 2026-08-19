<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quotation;
use App\Models\QuotationPaymentSchedule;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

class InvoiceDocumentService
{
    public function __construct(private readonly DocumentPageLayout $pageLayout) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Invoice $invoice): array
    {
        $invoice->loadMissing([
            'deliveryOrder',
            'followUpItem.supplierPo.buyerCompany.country',
            'followUpItem.supplierPo.buyerContact.designation',
            'followUpItem.quotation.buyerCompany.country',
            'followUpItem.quotation.buyerContact.designation',
            'followUpItem.quotation.paymentSchedules',
            'followUpItem.buyerPo',
            'items.buyerPo',
            'items.buyerPoItem',
        ]);

        $followUpItem = $invoice->followUpItem;
        $supplierPo = $followUpItem?->supplierPo;
        $quotation = $followUpItem?->quotation;
        $buyerPo = $followUpItem?->buyerPo;

        return [
            'invoice' => [
                'id' => $invoice->id,
                'reference' => $invoice->invoice_reference,
                'dated' => $this->formatDate($invoice->invoice_date),
                'payment_terms' => $quotation ? $this->paymentScheduleSummary($quotation) : "Within {$invoice->payment_term_days} days from the date of Invoice.",
                'due_date' => $this->formatDate($invoice->due_date),
                'currency' => $invoice->currency,
                'subtotal' => $this->money($invoice->subtotal),
                'vat_rate' => $this->money($invoice->vat_rate),
                'vat_amount' => $this->money($invoice->vat_amount),
                'total_amount' => $this->money($invoice->total_amount),
                'bank_details' => $invoice->bank_details,
                'remarks' => $invoice->remarks,
            ],
            'supplier' => $this->companySnapshot($supplierPo?->buyerCompany),
            'buyer' => $this->companySnapshot($quotation?->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($supplierPo?->buyerContact),
            'buyer_contact' => $this->contactSnapshot($quotation?->buyerContact),
            'buyer_po' => [
                'number' => $buyerPo?->po_number,
                'date' => $this->formatDate($buyerPo?->po_date),
            ],
            'delivery_order' => [
                'reference' => $invoice->deliveryOrder?->delivery_order_reference,
                'date' => $this->formatDate($invoice->deliveryOrder?->delivery_order_date),
            ],
            'payment_schedule' => $quotation?->paymentSchedules
                ->map(fn (QuotationPaymentSchedule $schedule): array => [
                    'line_number' => $schedule->line_number,
                    'label' => $schedule->label,
                    'payment_method' => $this->paymentMethodLabel($schedule->payment_method),
                    'payment_percentage' => $this->money($schedule->payment_percentage),
                    'due' => $this->scheduleDueText($schedule),
                    'notes' => $schedule->notes,
                ])
                ->values()
                ->all() ?? [],
            'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                'line_number' => $item->line_number,
                'description' => $this->htmlToPlainText($item->item_description),
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'unit_price' => $this->money($item->unit_price),
                'total_price' => $this->money($item->total_price),
                'buyer_po_number' => $item->buyerPo?->po_number,
                'buyer_item_code' => $item->buyerPoItem?->buyer_item_code,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writeDocx(array $snapshot, string $storagePath): void
    {
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
            (string) $snapshot['invoice']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
        );

        $section->addText('Tax Invoice', ['bold' => true, 'size' => 16, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $this->pageLayout->addWordSummaryRow($refTable);
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['invoice']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['invoice']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'PAYMENT TERMS', [$snapshot['invoice']['payment_terms']], 'DUE DATE', [$snapshot['invoice']['due_date']]);
        $this->addInfoRow($infoTable, 'SUPPLIER DO REF', [$snapshot['delivery_order']['reference'] ?: '-'], 'BUYER LPO NO.', [$snapshot['buyer_po']['number'] ?: '-']);

        if (! empty($snapshot['payment_schedule'])) {
            $section->addTextBreak(1);
            $section->addText('Agreed Payment Schedule', ['bold' => true, 'color' => '1F4E79']);

            foreach ($snapshot['payment_schedule'] as $schedule) {
                $line = "{$schedule['line_number']}. {$schedule['label']}: {$schedule['payment_percentage']}% by {$schedule['payment_method']}, {$schedule['due']}";
                if (! empty($schedule['notes'])) {
                    $line .= ' - '.$schedule['notes'];
                }
                $section->addText($line);
            }
        }

        $section->addTextBreak(1);
        $itemsTable = $section->addTable('ItemsTable');
        $this->pageLayout->addWordTableHeader($itemsTable);
        foreach ([
            'SL No' => 800,
            'Buyer Item Code' => 1200,
            'Item Description' => 3900,
            'Qty' => 1100,
            'Unit Price' => 1200,
            'Total excl. VAT' => 1300,
        ] as $heading => $width) {
            $itemsTable->addCell($width, ['bgColor' => '1F4E79', 'valign' => 'center'])
                ->addText($heading, ['bold' => true, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER]);
        }

        foreach ($snapshot['items'] as $item) {
            $itemsTable->addRow();
            $itemsTable->addCell(800)->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['buyer_item_code'] ?: '-', [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(3900);
            foreach (explode("\n", (string) $item['description']) as $line) {
                if (trim($line) !== '') {
                    $descriptionCell->addText(trim($line));
                }
            }
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['unit_price'], [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($item['total_price'], [], ['alignment' => Jc::RIGHT]);
        }

        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 5])->addText('Total Excluding VAT '.$snapshot['invoice']['currency'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['invoice']['subtotal'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 5])->addText('VAT '.$snapshot['invoice']['vat_rate'].'%:', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['invoice']['vat_amount'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 5])->addText('Total Including VAT '.$snapshot['invoice']['currency'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['invoice']['total_amount'], ['bold' => true], ['alignment' => Jc::RIGHT]);

        if ($snapshot['invoice']['bank_details']) {
            $section->addTextBreak(1);
            $section->addText('Bank Details', ['bold' => true, 'color' => '1F4E79']);
            foreach (explode("\n", (string) $snapshot['invoice']['bank_details']) as $line) {
                if (trim($line) !== '') {
                    $section->addText(trim($line));
                }
            }
        }

        if ($snapshot['invoice']['remarks']) {
            $section->addTextBreak(1);
            $section->addText('Remarks: '.$snapshot['invoice']['remarks'], ['italic' => true]);
        }

        IOFactory::createWriter($phpWord, 'Word2007')->save(Storage::disk('local')->path($storagePath));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writePdf(array $snapshot, string $storagePath): void
    {
        Storage::disk('local')->makeDirectory(dirname($storagePath));
        $pdfSnapshot = $this->pageLayout->preparePdfSnapshot($snapshot);

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml(view('invoices.document', [
            'snapshot' => $pdfSnapshot,
            'assets' => [
                'header' => $this->assetDataUri('quotation-assets/isc-header.jpeg'),
                'footer' => $this->assetDataUri('quotation-assets/isc-footer.jpeg'),
            ],
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();
        $this->pageLayout->addPdfPageChrome(
            $dompdf,
            (string) $snapshot['invoice']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
        );

        Storage::disk('local')->put($storagePath, $dompdf->output());
    }

    private function addInfoRow(mixed $table, string $leftTitle, array $leftLines, string $rightTitle, array $rightLines): void
    {
        $table->addRow(null, ['cantSplit' => true]);
        $left = $table->addCell(4700);
        $right = $table->addCell(4700);
        $left->addText($leftTitle, ['bold' => true, 'color' => '1F4E79']);
        $right->addText($rightTitle, ['bold' => true, 'color' => '1F4E79']);

        foreach ($leftLines as $line) {
            $left->addText((string) $line);
        }

        foreach ($rightLines as $line) {
            $right->addText((string) $line);
        }
    }

    private function companySnapshot(?Company $company): array
    {
        return [
            'name' => $company?->name,
            'address' => $company?->address,
            'location' => $company?->location,
            'country' => $company?->country?->name,
            'vat_tin' => $company?->vat_tin,
        ];
    }

    private function contactSnapshot(?Contact $contact): array
    {
        return [
            'name' => trim(($contact?->designation?->name ? $contact->designation->name.' ' : '').(string) $contact?->name),
            'job_title' => $contact?->job_title,
            'mobile' => $contact?->mobile,
            'email' => $contact?->email,
        ];
    }

    private function companyLines(?array $company): array
    {
        return array_values(array_filter([
            $company['name'] ?? null,
            $company['address'] ?? null,
            $company['location'] ?? null,
            $company['country'] ?? null,
            isset($company['vat_tin']) && $company['vat_tin'] ? 'VATIN '.$company['vat_tin'] : null,
        ]));
    }

    private function contactLines(?array $contact): array
    {
        return array_values(array_filter([
            $contact['name'] ?? null,
            $contact['job_title'] ?? null,
            isset($contact['mobile']) && $contact['mobile'] ? 'Mob: '.$contact['mobile'] : null,
            isset($contact['email']) && $contact['email'] ? 'E-mail: '.$contact['email'] : null,
        ]));
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

    private function formatDate(null|CarbonInterface|string $date): ?string
    {
        if (! $date) {
            return null;
        }

        return $date instanceof CarbonInterface
            ? $date->format('d M Y')
            : (string) $date;
    }

    private function htmlToPlainText(?string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", (string) $html);
        $text = preg_replace('/<\/(p|div|li)>/i', "\n", (string) $text);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function addImageIfExists(mixed $section, string $storagePath, ?int $width = null, ?int $height = null): void
    {
        $assetPath = DocumentBrandingAssets::path($storagePath);

        if ($assetPath === null) {
            return;
        }

        $options = array_filter([
            'width' => $width,
            'height' => $height,
            'alignment' => Jc::CENTER,
        ]);

        $section->addImage($assetPath, $options);
    }

    private function assetDataUri(string $storagePath): ?string
    {
        return DocumentBrandingAssets::dataUri($storagePath);
    }
}
