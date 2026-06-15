<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationTerm;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use ZipArchive;

class QuotationDocumentService
{
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
            'terms',
        ]);

        $subtotal = $quotation->items->sum(fn (QuotationItem $item): float => (float) $item->total_price);

        return [
            'quotation' => [
                'id' => $quotation->id,
                'reference' => $quotation->quotation_reference,
                'version_number' => $versionNumber,
                'rfq_number' => $quotation->rfq_number,
                'pr_number' => $quotation->pr_number,
                'dated' => $this->formatDate($quotation->created_at),
                'closing_at' => $this->formatClosingDate($quotation->closing_at),
                'validity' => "{$quotation->quotation_validity_value} ".ucfirst((string) $quotation->quotation_validity_unit).' from the date of quote.',
                'payment_terms' => "Within {$quotation->payment_term_days} days from the date of Invoice.",
                'delivery_period' => "Within {$quotation->delivery_period_min} to {$quotation->delivery_period_max} {$quotation->delivery_period_type} {$quotation->delivery_period_unit} from the date of PO",
                'currency' => $quotation->accepted_invoice_currency,
                'incoterm' => $quotation->incoterm?->code,
            ],
            'supplier' => $this->companySnapshot($quotation->supplierCompany),
            'buyer' => $this->companySnapshot($quotation->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($quotation->supplierContact),
            'buyer_contact' => $this->contactSnapshot($quotation->buyerContact),
            'items' => $quotation->items->map(fn (QuotationItem $item): array => [
                'line_number' => $item->line_number,
                'manufacturer' => $item->manufacturer?->name,
                'product_name' => $item->product_name,
                'title' => $item->title,
                'description' => $this->htmlToPlainText($item->buyer_description),
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'unit_price' => $this->money($item->unit_price),
                'total_price' => $this->money($item->total_price),
            ])->values()->all(),
            'terms' => $quotation->terms->map(fn (QuotationTerm $term): array => [
                'line_number' => $term->line_number,
                'key' => $term->key,
                'title' => $term->title,
                'description' => $term->description,
                'is_required_default' => $term->is_required_default,
            ])->values()->all(),
            'totals' => [
                'subtotal' => $this->money($subtotal),
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
        $section->addText('Commercial Offer', ['bold' => true, 'size' => 15, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
        $section->addText(' - RFQ '.$snapshot['quotation']['rfq_number'], ['bold' => true, 'size' => 10], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $refTable->addRow();
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['quotation']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['quotation']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'QUOTATION VALIDITY PERIOD', [$snapshot['quotation']['validity']], 'ACCEPTED TERMS OF PAYMENT', [$snapshot['quotation']['payment_terms']]);
        $this->addInfoRow($infoTable, 'DATE OF DELIVERY', [$snapshot['quotation']['delivery_period']], 'ACCEPTED INVOICE CURRENCY', [$snapshot['quotation']['currency']]);

        $section->addTextBreak(1);
        $section->addText(
            'RFQ '.$snapshot['quotation']['rfq_number'].'   PR '.$snapshot['quotation']['pr_number'].'   Closing Date: '.$snapshot['quotation']['closing_at'],
            ['bold' => true, 'size' => 9],
            ['alignment' => Jc::RIGHT]
        );

        $itemsTable = $section->addTable('ItemsTable');
        $itemsTable->addRow();
        foreach (['SL No', 'Description', 'QTY', 'Unit Price', 'Total excl. VAT'] as $heading) {
            $itemsTable->addCell($heading === 'Description' ? 5000 : 1100, ['bgColor' => '1F4E79', 'valign' => 'center'])
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
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['unit_price'], [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($item['total_price'], [], ['alignment' => Jc::RIGHT]);
        }

        $itemsTable->addRow();
        $itemsTable->addCell(800, ['gridSpan' => 4])->addText('Total Net Amount '.$snapshot['quotation']['currency'].' (Excluding VAT):', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['totals']['subtotal'], ['bold' => true], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);
        $section->addText('Terms & Conditions:', ['bold' => true, 'size' => 11, 'color' => '1F4E79']);

        foreach ($snapshot['terms'] as $term) {
            $section->addText($term['title'].':', ['bold' => true]);
            $section->addText($term['description'], [], ['spaceAfter' => 120]);
        }

        $this->addImageIfExists($section, 'quotation-assets/abb-value-provider.jpg', 180, null);
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
        $dompdf->loadHtml(view('quotations.document', [
            'snapshot' => $snapshot,
            'assets' => [
                'header' => $this->assetDataUri('quotation-assets/isc-header.jpeg'),
                'abb' => $this->assetDataUri('quotation-assets/abb-value-provider.jpg'),
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

    private function addImageIfExists($section, string $storagePath, ?int $width, ?int $height): void
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            return;
        }

        $style = ['alignment' => Jc::CENTER];
        if ($width !== null) {
            $style['width'] = $width;
        }
        if ($height !== null) {
            $style['height'] = $height;
        }

        $section->addImage(Storage::disk('local')->path($storagePath), $style);
    }

    private function assetDataUri(string $storagePath): ?string
    {
        if (! Storage::disk('local')->exists($storagePath)) {
            return null;
        }

        $extension = pathinfo($storagePath, PATHINFO_EXTENSION);
        $mime = $extension === 'jpg' ? 'image/jpeg' : "image/{$extension}";

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($storagePath));
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
