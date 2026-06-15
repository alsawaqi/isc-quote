<?php

namespace App\Services;

use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\SupplierPoTerm;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use ZipArchive;

class SupplierPoDocumentService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(SupplierPo $supplierPo): array
    {
        $supplierPo->loadMissing([
            'supplierCompany.country',
            'supplierContact.designation',
            'buyerCompany.country',
            'buyerContact.designation',
            'incoterm',
            'lines.manufacturer',
            'lines.quotation.buyerCompany',
            'lines.buyerPo',
            'terms',
        ]);

        return [
            'po' => [
                'id' => $supplierPo->id,
                'reference' => $supplierPo->po_reference,
                'dated' => $this->formatDate($supplierPo->created_at),
                'supplier_quote_reference' => $supplierPo->supplier_quote_reference,
                'payment_terms' => "{$supplierPo->payment_term_days} Days from {$supplierPo->supplierCompany?->name} Invoice date",
                'delivery_period' => "Within {$supplierPo->delivery_period_min} to {$supplierPo->delivery_period_max} {$supplierPo->delivery_period_type} {$supplierPo->delivery_period_unit} from the date of PO",
                'currency' => $supplierPo->accepted_invoice_currency,
                'incoterm' => $supplierPo->incoterm?->code,
                'additional_charges_label' => $supplierPo->additional_charges_label,
                'additional_charges' => $this->money($supplierPo->additional_charges),
            ],
            'supplier' => $this->companySnapshot($supplierPo->supplierCompany),
            'buyer' => $this->companySnapshot($supplierPo->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($supplierPo->supplierContact),
            'buyer_contact' => $this->contactSnapshot($supplierPo->buyerContact),
            'items' => $supplierPo->lines->map(fn (SupplierPoLine $line): array => [
                'line_number' => $line->line_number,
                'manufacturer' => $line->manufacturer?->name,
                'product_name' => $line->product_name,
                'title' => $line->title,
                'description' => $this->htmlToPlainText($line->item_description),
                'quantity' => $this->money($line->quantity),
                'uom' => $line->uom,
                'unit_cost' => $this->money($line->unit_cost),
                'total_cost' => $this->money($line->total_cost),
                'quotation_reference' => $line->quotation?->quotation_reference,
                'buyer_po_number' => $line->buyerPo?->po_number,
                'buyer_company' => $line->quotation?->buyerCompany?->name,
            ])->values()->all(),
            'terms' => $supplierPo->terms->map(fn (SupplierPoTerm $term): array => [
                'line_number' => $term->line_number,
                'key' => $term->key,
                'title' => $term->title,
                'description' => $term->description,
                'is_required_default' => $term->is_required_default,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->money($supplierPo->subtotal),
                'total' => $this->money($supplierPo->total_amount),
            ],
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
        $section->addText('Purchase Order', ['bold' => true, 'size' => 15, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $refTable->addRow();
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['po']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['po']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'SUPPLIER QUOTATION Ref:', [$snapshot['po']['supplier_quote_reference'] ?: '-'], 'ACCEPTED TERMS OF PAYMENT', [$snapshot['po']['payment_terms']]);
        $this->addInfoRow($infoTable, 'DELIVERY', [$snapshot['po']['delivery_period']], 'ACCEPTED INVOICE CURRENCY', [$snapshot['po']['currency']]);

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
            $descriptionCell->addText(trim(($item['manufacturer'] ? $item['manufacturer'].' - ' : '').$item['title']), ['bold' => true]);
            foreach (explode("\n", (string) $item['description']) as $line) {
                if (trim($line) !== '') {
                    $descriptionCell->addText(trim($line));
                }
            }
            $descriptionCell->addText('Buyer PO: '.$item['buyer_po_number'].' | Quotation: '.$item['quotation_reference'], ['italic' => true, 'size' => 8, 'color' => '666666']);
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['unit_cost'], [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($item['total_cost'], [], ['alignment' => Jc::RIGHT]);
        }

        if ((float) $snapshot['po']['additional_charges'] > 0) {
            $itemsTable->addRow();
            $itemsTable->addCell(800, ['gridSpan' => 4])->addText(($snapshot['po']['additional_charges_label'] ?: 'Additional Charges').':', ['bold' => true], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($snapshot['po']['additional_charges'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        }

        $itemsTable->addRow();
        $itemsTable->addCell(800, ['gridSpan' => 4])->addText('Total Net Amount (VAT Exclusive) '.$snapshot['po']['currency'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['totals']['total'], ['bold' => true], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);
        $section->addText('Terms and Conditions', ['bold' => true, 'size' => 11, 'color' => '1F4E79']);

        foreach ($snapshot['terms'] as $term) {
            $section->addText($term['title'].':', ['bold' => true]);
            $section->addText($term['description'], [], ['spaceAfter' => 120]);
        }

        $approval = $section->addTable('InfoTable');
        $approval->addRow();
        foreach (['Prepared By', 'Checked By', 'Accounts Dept.'] as $heading) {
            $approval->addCell(3100)->addText($heading, ['bold' => true], ['alignment' => Jc::CENTER]);
        }
        $approval->addRow(760);
        $approval->addCell(3100)->addText($snapshot['buyer_contact']['name'] ?? '-', [], ['alignment' => Jc::CENTER]);
        $approval->addCell(3100)->addText('', [], ['alignment' => Jc::CENTER]);
        $approval->addCell(3100)->addText('', [], ['alignment' => Jc::CENTER]);

        $section->addText('Industrial Supplies Center LLC.', ['bold' => true], ['alignment' => Jc::CENTER]);
        $this->addImageIfExists($section, 'quotation-assets/isc-footer.jpeg', 742, null);

        IOFactory::createWriter($phpWord, 'Word2007')->save(Storage::disk('local')->path($storagePath));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writePdf(array $snapshot, string $storagePath): void
    {
        Storage::disk('local')->makeDirectory(dirname($storagePath));

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);
        $dompdf->loadHtml(view('supplier-pos.document', [
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

                if (simplexml_load_string((string) $zip->getFromName($name)) === false) {
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
     * @return array<int, string>
     */
    private function companyLines(?array $company): array
    {
        return array_values(array_filter([
            $company['name'] ?? null,
            $company['address'] ?? null,
            $company['location'] ?? null,
            isset($company['vat_tin']) && $company['vat_tin'] ? 'VATIN '.$company['vat_tin'] : null,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function contactLines(?array $contact): array
    {
        return array_values(array_filter([
            trim(($contact['designation'] ? $contact['designation'].' ' : '').($contact['name'] ?? '')),
            $contact['job_title'] ?? null,
            isset($contact['mobile']) && $contact['mobile'] ? 'Mob: '.$contact['mobile'] : null,
            isset($contact['telephone']) && $contact['telephone'] ? 'Tel: '.$contact['telephone'] : null,
            isset($contact['email']) && $contact['email'] ? 'E-mail: '.$contact['email'] : null,
        ]));
    }

    /**
     * @param  array<int, string>  $leftLines
     * @param  array<int, string>  $rightLines
     */
    private function addInfoRow(mixed $table, string $leftTitle, array $leftLines, string $rightTitle, array $rightLines): void
    {
        $table->addRow();
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

    private function addImageIfExists(mixed $section, string $storagePath, int $width, ?int $height): void
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            return;
        }

        $style = ['width' => $width, 'alignment' => Jc::CENTER];

        if ($height) {
            $style['height'] = $height;
        }

        $section->addImage(Storage::disk('local')->path($storagePath), $style);
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

    /**
     * @return array<string, string|null>
     */
    private function companySnapshot(?Model $company): array
    {
        return [
            'name' => $company?->getAttribute('name'),
            'address' => $company?->getAttribute('address'),
            'location' => $company?->getAttribute('location'),
            'vat_tin' => $company?->getAttribute('vat_tin'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function contactSnapshot(?Model $contact): array
    {
        return [
            'designation' => $contact?->getAttribute('designation')?->name,
            'name' => $contact?->getAttribute('name'),
            'job_title' => $contact?->getAttribute('job_title'),
            'mobile' => $contact?->getAttribute('mobile'),
            'telephone' => $contact?->getAttribute('telephone'),
            'email' => $contact?->getAttribute('email'),
        ];
    }

    private function htmlToPlainText(?string $value): string
    {
        if (! $value) {
            return '';
        }

        $withBreaks = preg_replace('/<\/(p|div|li|br|ul|ol)>/i', "\n", $value) ?? $value;

        return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5));
    }

    private function formatDate(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->format('jS M Y');
        }

        return now()->format('jS M Y');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
