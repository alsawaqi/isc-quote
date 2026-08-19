<?php

namespace App\Services;

use App\Models\CompanyLocation;
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
    public function __construct(private readonly DocumentPageLayout $pageLayout) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(SupplierPo $supplierPo): array
    {
        $supplierPo->loadMissing([
            'supplierCompany.country',
            'supplierContact.designation',
            'companyLocation.country',
            'companyLocation.manufacturer',
            'buyerCompany.country',
            'buyerContact.designation',
            'incoterm',
            'lines.manufacturer',
            'lines.companyLocation.country',
            'lines.companyLocation.manufacturer',
            'lines.incoterm',
            'lines.origins.country',
            'lines.quotation.buyerCompany',
            'lines.buyerPo',
            'terms',
        ]);

        return [
            'po' => [
                'id' => $supplierPo->id,
                'reference' => $supplierPo->po_reference,
                'revision_number' => (int) $supplierPo->revision_number,
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
            'factory' => $this->factorySnapshot($supplierPo->companyLocation),
            'buyer' => $this->companySnapshot($supplierPo->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($supplierPo->supplierContact),
            'buyer_contact' => $this->contactSnapshot($supplierPo->buyerContact),
            'items' => $supplierPo->lines->map(fn (SupplierPoLine $line): array => [
                'line_number' => $line->line_number,
                'manufacturer' => $line->manufacturer?->name,
                'product_code' => $line->product_code,
                'product_name' => $line->product_name,
                'title' => $line->title,
                'description' => $this->htmlToPlainText($line->item_description),
                'quantity' => $this->money($line->quantity),
                'uom' => $line->uom,
                'delivery_date' => $line->delivery_date?->format('jS M Y'),
                'incoterm' => $line->incoterm?->code ?? $supplierPo->incoterm?->code,
                'factory' => $this->factorySnapshot($line->companyLocation),
                'factory_label' => $this->factoryLabel($line->companyLocation),
                'unit_cost' => $this->money($line->unit_cost),
                'total_cost' => $this->money($line->total_cost),
                'quotation_reference' => $line->quotation?->quotation_reference,
                'buyer_po_number' => $line->buyerPo?->po_number,
                'buyer_company' => $line->quotation?->buyerCompany?->name,
                'origins' => $line->origins->map(fn ($origin): array => [
                    'country' => $origin->country?->name ?? $origin->country_name,
                    'amount' => $origin->amount !== null ? $this->money($origin->amount) : null,
                    'location' => $origin->location,
                ])->values()->all(),
            ])->values()->all(),
            'terms' => $supplierPo->terms->sortBy('line_number')->values()->map(fn (SupplierPoTerm $term): array => [
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
        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(10);
        $phpWord->addTableStyle('InfoTable', $this->pageLayout->tableStyle('BFBFBF', 120));
        $phpWord->addTableStyle('ItemsTable', $this->pageLayout->tableStyle('8EA9DB', 100), [
            'bgColor' => '1F4E79',
        ]);

        $section = $this->pageLayout->addWordSection(
            $phpWord,
            (string) $snapshot['po']['reference'],
            $snapshot['buyer']['vat_tin'] ?? $snapshot['supplier']['vat_tin'] ?? null,
        );

        $section->addText('Purchase Order', ['bold' => true, 'size' => 16, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $this->pageLayout->addWordSummaryRow($refTable);
        $refTable->addCell(3200)->addText('Ref: '.$snapshot['po']['reference'], ['bold' => true]);
        $refTable->addCell(2400)->addText('Revision: '.$snapshot['po']['revision_number'], ['bold' => true]);
        $refTable->addCell(3800)->addText('Dated: '.$snapshot['po']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->supplierLines($snapshot['supplier'], $snapshot['factory']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'SUPPLIER QUOTATION Ref:', [$snapshot['po']['supplier_quote_reference'] ?: '-'], 'ACCEPTED TERMS OF PAYMENT', [$snapshot['po']['payment_terms']]);
        $this->addInfoRow($infoTable, 'DELIVERY', [$snapshot['po']['delivery_period']], 'ACCEPTED INVOICE CURRENCY', [$snapshot['po']['currency']]);

        $section->addTextBreak(1);

        $itemsTable = $section->addTable('ItemsTable');
        $this->pageLayout->addWordTableHeader($itemsTable);
        foreach ([
            'SL No' => 800,
            'Material / Item Code' => 1000,
            'Item Description' => 4300,
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
            $itemsTable->addCell(1000)->addText((string) ($item['product_code'] ?: '-'), [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(4300);
            $descriptionCell->addText(trim(($item['manufacturer'] ? $item['manufacturer'].' - ' : '').$item['title']), ['bold' => true]);
            foreach (explode("\n", (string) $item['description']) as $line) {
                if (trim($line) !== '') {
                    $descriptionCell->addText(trim($line));
                }
            }
            $itemMeta = array_filter([
                $item['factory_label'] ?? null,
                ! empty($item['delivery_date']) ? 'Delivery Date: '.$item['delivery_date'] : null,
                ! empty($item['incoterm']) ? 'Incoterm: '.$item['incoterm'] : null,
            ]);

            if ($itemMeta !== []) {
                $descriptionCell->addText(implode(' | ', $itemMeta), ['italic' => true, 'size' => 8, 'color' => '666666']);
            }

            foreach ($this->originLines($item['origins'] ?? []) as $originLine) {
                $descriptionCell->addText($originLine, ['size' => 8, 'color' => '666666']);
            }

            $descriptionCell->addText('Buyer PO: '.$item['buyer_po_number'].' | Quotation: '.$item['quotation_reference'], ['italic' => true, 'size' => 8, 'color' => '666666']);
            $itemsTable->addCell(1100)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1200)->addText($item['unit_cost'], [], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($item['total_cost'], [], ['alignment' => Jc::RIGHT]);
        }

        if ((float) $snapshot['po']['additional_charges'] > 0) {
            $this->pageLayout->addWordSummaryRow($itemsTable);
            $itemsTable->addCell(800, ['gridSpan' => 5])->addText(($snapshot['po']['additional_charges_label'] ?: 'Additional Charges').':', ['bold' => true], ['alignment' => Jc::RIGHT]);
            $itemsTable->addCell(1300)->addText($snapshot['po']['additional_charges'], ['bold' => true], ['alignment' => Jc::RIGHT]);
        }

        $this->pageLayout->addWordSummaryRow($itemsTable);
        $itemsTable->addCell(800, ['gridSpan' => 5])->addText('Total Net Amount (VAT Exclusive) '.$snapshot['po']['currency'].':', ['bold' => true], ['alignment' => Jc::RIGHT]);
        $itemsTable->addCell(1300)->addText($snapshot['totals']['total'], ['bold' => true], ['alignment' => Jc::RIGHT]);

        $section->addTextBreak(1);
        $section->addText('Terms and Conditions', ['bold' => true, 'size' => 11, 'color' => '1F4E79']);

        foreach ($snapshot['terms'] as $index => $term) {
            $lineNumber = $term['line_number'] ?? $index + 1;
            $section->addText($lineNumber.'. '.$term['title'].':', ['bold' => true]);
            $section->addText($term['description'], [], ['spaceAfter' => 120]);
        }

        $approval = $section->addTable('InfoTable');
        $this->pageLayout->addWordSummaryRow($approval);
        foreach (['Prepared By', 'Checked By', 'Accounts Dept.'] as $heading) {
            $approval->addCell(3100)->addText($heading, ['bold' => true], ['alignment' => Jc::CENTER]);
        }
        $approval->addRow(760, ['cantSplit' => true]);
        $approval->addCell(3100)->addText($snapshot['buyer_contact']['name'] ?? '-', [], ['alignment' => Jc::CENTER]);
        $approval->addCell(3100)->addText('', [], ['alignment' => Jc::CENTER]);
        $approval->addCell(3100)->addText('', [], ['alignment' => Jc::CENTER]);

        $section->addText('Industrial Supplies Center LLC.', ['bold' => true], ['alignment' => Jc::CENTER]);

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
        $dompdf->loadHtml(view('supplier-pos.document', [
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
            (string) $snapshot['po']['reference'],
            $snapshot['buyer']['vat_tin'] ?? $snapshot['supplier']['vat_tin'] ?? null,
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
    private function supplierLines(?array $company, ?array $factory): array
    {
        return array_values(array_filter([
            ...$this->companyLines($company),
            isset($factory['name']) && $factory['name'] ? 'Factory: '.$factory['name'] : null,
            $factory['address'] ?? null,
            $factory['location'] ?? null,
            isset($factory['manufacturer']) && $factory['manufacturer'] ? 'Manufacturer: '.$factory['manufacturer'] : null,
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

    private function addImageIfExists(mixed $section, string $storagePath, int $width, ?int $height): void
    {
        $assetPath = DocumentBrandingAssets::path($storagePath);

        if ($assetPath === null) {
            return;
        }

        $style = ['width' => $width, 'alignment' => Jc::CENTER];

        if ($height) {
            $style['height'] = $height;
        }

        $section->addImage($assetPath, $style);
    }

    private function assetDataUri(string $storagePath): ?string
    {
        return DocumentBrandingAssets::dataUri($storagePath);
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

    /**
     * @return array<string, mixed>|null
     */
    private function factorySnapshot(?CompanyLocation $factory): ?array
    {
        if (! $factory) {
            return null;
        }

        return [
            'id' => $factory->id,
            'name' => $factory->name,
            'address' => $factory->address,
            'location' => $factory->location,
            'country' => $factory->country?->name,
            'manufacturer' => $factory->manufacturer?->name,
        ];
    }

    private function factoryLabel(?CompanyLocation $factory): ?string
    {
        if (! $factory) {
            return null;
        }

        $parts = array_values(array_filter([
            $factory->name,
            $factory->location,
            $factory->country?->name,
        ]));

        $label = 'Factory: '.implode(' - ', $parts);

        if ($factory->manufacturer?->name) {
            $label .= ' | Manufacturer: '.$factory->manufacturer->name;
        }

        return $label;
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

    /**
     * @param  array<int, array<string, mixed>>  $origins
     * @return array<int, string>
     */
    private function originLines(array $origins): array
    {
        return collect($origins)
            ->map(function (array $origin): ?string {
                $parts = array_values(array_filter([
                    $origin['country'] ?? null,
                    ! empty($origin['amount']) ? 'Amount: '.$origin['amount'] : null,
                    ! empty($origin['location']) ? 'Location: '.$origin['location'] : null,
                ]));

                return $parts === [] ? null : 'Country of Origin: '.implode(' | ', $parts);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
