<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
            'followUpItem.buyerPo',
            'items.buyerPo',
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
                'payment_terms' => "Within {$invoice->payment_term_days} days from the date of invoice.",
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
            'items' => $invoice->items->map(fn (InvoiceItem $item): array => [
                'line_number' => $item->line_number,
                'description' => $this->htmlToPlainText($item->item_description),
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'unit_price' => $this->money($item->unit_price),
                'total_price' => $this->money($item->total_price),
                'buyer_po_number' => $item->buyerPo?->po_number,
            ])->values()->all(),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function writeDocx(array $snapshot, string $storagePath): void
    {
        Storage::disk('local')->makeDirectory(dirname($storagePath));
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(9);
        $phpWord->addTableStyle('InfoTable', [
            'borderColor' => 'BFBFBF',
            'borderSize' => 6,
            'cellMargin' => 120,
            'alignment' => JcTable::CENTER,
        ]);
        $phpWord->addTableStyle('ItemsTable', [
            'borderColor' => '8EA9DB',
            'borderSize' => 6,
            'cellMargin' => 100,
            'alignment' => JcTable::CENTER,
        ], [
            'bgColor' => '1F4E79',
        ]);

        $section = $phpWord->addSection([
            'marginTop' => 450,
            'marginBottom' => 450,
            'marginLeft' => 600,
            'marginRight' => 600,
        ]);

        $this->addImageIfExists($section, 'quotation-assets/isc-header.jpeg', 742, null);
        $section->addText('Tax Invoice', ['bold' => true, 'size' => 15, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $refTable->addRow();
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['invoice']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['invoice']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'PAYMENT TERMS', [$snapshot['invoice']['payment_terms']], 'DUE DATE', [$snapshot['invoice']['due_date']]);
        $this->addInfoRow($infoTable, 'SUPPLIER DO REF', [$snapshot['delivery_order']['reference'] ?: '-'], 'BUYER LPO NO.', [$snapshot['buyer_po']['number'] ?: '-']);

        $section->addTextBreak(1);
        $itemsTable = $section->addTable('ItemsTable');
        $itemsTable->addRow();
        foreach (['SL No', 'Item Description', 'Qty', 'Unit Price', 'Total excl. VAT'] as $heading) {
            $itemsTable->addCell($heading === 'Item Description' ? 5000 : 1100, ['bgColor' => '1F4E79', 'valign' => 'center'])
                ->addText($heading, ['bold' => true, 'color' => 'FFFFFF'], ['alignment' => Jc::CENTER]);
        }

        foreach ($snapshot['items'] as $item) {
            $itemsTable->addRow();
            $itemsTable->addCell(800)->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(5000);
            foreach (explode("\n", (string) $item['description']) as $line) {
                if (trim($line) !== '') {
                    $descriptionCell->addText(trim($line));
                }
            }
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['unit_price'], [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($item['total_price'], [], ['alignment' => Jc::RIGHT]);
        }

        $itemsTable->addRow();
        $itemsTable->addCell(800, ['gridSpan' => 4])->addText('Total Excluding VAT '.$snapshot['invoice']['currency'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['invoice']['subtotal'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addRow();
        $itemsTable->addCell(800, ['gridSpan' => 4])->addText('VAT '.$snapshot['invoice']['vat_rate'].'%:', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['invoice']['vat_amount'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addRow();
        $itemsTable->addCell(800, ['gridSpan' => 4])->addText('Total Including VAT '.$snapshot['invoice']['currency'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
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

        $this->addImageIfExists($section, 'quotation-assets/isc-footer.jpeg', 742, null);

        IOFactory::createWriter($phpWord, 'Word2007')->save(Storage::disk('local')->path($storagePath));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function writePdf(array $snapshot, string $storagePath): void
    {
        Storage::disk('local')->makeDirectory(dirname($storagePath));

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml(view('invoices.document', [
            'snapshot' => $snapshot,
            'assets' => [
                'header' => $this->assetDataUri('quotation-assets/isc-header.jpeg'),
                'footer' => $this->assetDataUri('quotation-assets/isc-footer.jpeg'),
            ],
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        Storage::disk('local')->put($storagePath, $dompdf->output());
    }

    private function addInfoRow(mixed $table, string $leftTitle, array $leftLines, string $rightTitle, array $rightLines): void
    {
        $table->addRow();
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
        if (! Storage::disk('local')->exists($storagePath)) {
            return;
        }

        $options = array_filter([
            'width' => $width,
            'height' => $height,
            'alignment' => Jc::CENTER,
        ]);

        $section->addImage(Storage::disk('local')->path($storagePath), $options);
    }

    private function assetDataUri(string $storagePath): ?string
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            return null;
        }

        $path = Storage::disk('local')->path($storagePath);
        $mime = mime_content_type($path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
