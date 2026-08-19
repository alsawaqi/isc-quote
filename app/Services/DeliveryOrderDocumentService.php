<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

class DeliveryOrderDocumentService
{
    public function __construct(private readonly DocumentPageLayout $pageLayout) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(DeliveryOrder $deliveryOrder): array
    {
        $deliveryOrder->loadMissing([
            'followUpItem.supplierPo.buyerCompany.country',
            'followUpItem.supplierPo.buyerContact.designation',
            'followUpItem.quotation.buyerCompany.country',
            'followUpItem.quotation.buyerContact.designation',
            'followUpItem.buyerPo',
            'items.buyerPo',
            'items.buyerPoItem',
        ]);

        $followUpItem = $deliveryOrder->followUpItem;
        $supplierPo = $followUpItem?->supplierPo;
        $quotation = $followUpItem?->quotation;
        $buyerPo = $followUpItem?->buyerPo;

        return [
            'delivery_order' => [
                'id' => $deliveryOrder->id,
                'reference' => $deliveryOrder->delivery_order_reference,
                'dated' => $this->formatDate($deliveryOrder->delivery_order_date),
                'delivery_place' => $deliveryOrder->delivery_place,
                'terms' => $deliveryOrder->terms,
                'status' => $deliveryOrder->status,
            ],
            'supplier' => $this->companySnapshot($supplierPo?->buyerCompany),
            'buyer' => $this->companySnapshot($quotation?->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($supplierPo?->buyerContact),
            'buyer_contact' => $this->contactSnapshot($quotation?->buyerContact),
            'buyer_po' => [
                'number' => $buyerPo?->po_number,
                'date' => $this->formatDate($buyerPo?->po_date),
            ],
            'items' => $deliveryOrder->items->map(fn (DeliveryOrderItem $item): array => [
                'line_number' => $item->line_number,
                'description' => $this->htmlToPlainText($item->item_description),
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
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
            'bgColor' => 'D9D9D9',
        ]);

        $section = $this->pageLayout->addWordSection(
            $phpWord,
            (string) $snapshot['delivery_order']['reference'],
            $snapshot['supplier']['vat_tin'] ?? $snapshot['buyer']['vat_tin'] ?? null,
        );

        $section->addText('Delivery Order', ['bold' => true, 'size' => 16, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $this->pageLayout->addWordSummaryRow($refTable);
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['delivery_order']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['delivery_order']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'LPO NO:', [$snapshot['buyer_po']['number'] ?: '-'], 'DATED:', [$snapshot['buyer_po']['date'] ?: '-']);
        $this->addInfoRow($infoTable, 'DELIVERY PLACE', [$snapshot['delivery_order']['delivery_place']], 'TERMS', [$snapshot['delivery_order']['terms'] ?: '-']);

        $section->addTextBreak(1);
        $itemsTable = $section->addTable('ItemsTable');
        $this->pageLayout->addWordTableHeader($itemsTable);
        foreach ([
            'SL No' => 1000,
            'Buyer Item Code' => 1500,
            'Item Description' => 5400,
            'Qty' => 1350,
        ] as $heading => $width) {
            $itemsTable->addCell($width, ['bgColor' => 'D9D9D9', 'valign' => 'center'])
                ->addText($heading, ['bold' => true], ['alignment' => Jc::CENTER]);
        }

        foreach ($snapshot['items'] as $item) {
            $itemsTable->addRow();
            $itemsTable->addCell(1000)->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1500)->addText($item['buyer_item_code'] ?: '-', [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(5400);
            foreach (explode("\n", (string) $item['description']) as $line) {
                if (trim($line) !== '') {
                    $descriptionCell->addText(trim($line));
                }
            }
            $itemsTable->addCell(1350)->addText($item['quantity'].' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
        }

        $section->addTextBreak(2);
        $signature = $section->addTable('InfoTable');
        $this->pageLayout->addWordSummaryRow($signature);
        $signature->addCell(4700)->addText('Delivered By', ['bold' => true], ['alignment' => Jc::CENTER]);
        $signature->addCell(4700)->addText('Received By / Customer Signature', ['bold' => true], ['alignment' => Jc::CENTER]);
        $signature->addRow(900, ['cantSplit' => true]);
        $signature->addCell(4700)->addText('');
        $signature->addCell(4700)->addText('');

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
        $dompdf->loadHtml(view('delivery-orders.document', [
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
            (string) $snapshot['delivery_order']['reference'],
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
            'postal_code' => $company?->postal_code,
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
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
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
