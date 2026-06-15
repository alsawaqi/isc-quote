<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\PackingList;
use App\Models\PackingListItem;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;

class PackingListDocumentService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(PackingList $packingList): array
    {
        $packingList->loadMissing([
            'followUpItem.supplierPo.supplierCompany.country',
            'followUpItem.supplierPo.supplierContact.designation',
            'followUpItem.quotation.buyerCompany.country',
            'followUpItem.quotation.buyerContact.designation',
            'followUpItem.buyerPo',
            'items.buyerPo',
        ]);

        $followUpItem = $packingList->followUpItem;
        $supplierPo = $followUpItem?->supplierPo;
        $quotation = $followUpItem?->quotation;
        $buyerPo = $followUpItem?->buyerPo;

        return [
            'packing_list' => [
                'id' => $packingList->id,
                'reference' => $packingList->packing_list_reference,
                'dated' => $this->formatDate($packingList->packing_list_date),
                'package_size' => $packingList->package_size,
                'gross_weight' => $packingList->gross_weight,
                'net_weight' => $packingList->net_weight,
                'remarks' => $packingList->remarks,
            ],
            'supplier' => $this->companySnapshot($supplierPo?->buyerCompany),
            'buyer' => $this->companySnapshot($quotation?->buyerCompany),
            'supplier_contact' => $this->contactSnapshot($supplierPo?->buyerContact),
            'buyer_contact' => $this->contactSnapshot($quotation?->buyerContact),
            'buyer_po' => [
                'number' => $buyerPo?->po_number,
                'date' => $this->formatDate($buyerPo?->po_date),
            ],
            'items' => $packingList->items->map(fn (PackingListItem $item): array => [
                'line_number' => $item->line_number,
                'description' => $this->htmlToPlainText($item->item_description),
                'quantity' => $this->money($item->quantity),
                'uom' => $item->uom,
                'package_size' => $item->package_size,
                'gross_weight' => $item->gross_weight,
                'net_weight' => $item->net_weight,
                'buyer_po_number' => $item->buyerPo?->po_number,
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
            'bgColor' => 'D9D9D9',
        ]);

        $section = $phpWord->addSection([
            'marginTop' => 450,
            'marginBottom' => 450,
            'marginLeft' => 600,
            'marginRight' => 600,
        ]);

        $this->addImageIfExists($section, 'quotation-assets/isc-header.jpeg', 742, null);
        $section->addText('Packing List', ['bold' => true, 'size' => 15, 'color' => '1F4E79'], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);

        $refTable = $section->addTable('InfoTable');
        $refTable->addRow();
        $refTable->addCell(4700)->addText('Ref: '.$snapshot['packing_list']['reference'], ['bold' => true]);
        $refTable->addCell(4700)->addText('Dated: '.$snapshot['packing_list']['dated'], ['bold' => true]);

        $infoTable = $section->addTable('InfoTable');
        $this->addInfoRow($infoTable, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->addInfoRow($infoTable, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->addInfoRow($infoTable, 'LPO NO:', [$snapshot['buyer_po']['number'] ?: '-'], 'DATED:', [$snapshot['buyer_po']['date'] ?: '-']);

        $section->addTextBreak(1);
        $itemsTable = $section->addTable('ItemsTable');
        $itemsTable->addRow();
        foreach (['SL No', 'Item Description', 'Qty', 'Size', 'Gross / Net KG'] as $heading) {
            $itemsTable->addCell($heading === 'Item Description' ? 5100 : 1250, ['bgColor' => 'D9D9D9', 'valign' => 'center'])
                ->addText($heading, ['bold' => true], ['alignment' => Jc::CENTER]);
        }

        foreach ($snapshot['items'] as $item) {
            $itemsTable->addRow();
            $itemsTable->addCell(850)->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $descriptionCell = $itemsTable->addCell(5100);
            foreach (explode("\n", (string) $item['description']) as $line) {
                if (trim($line) !== '') {
                    $descriptionCell->addText(trim($line));
                }
            }
            $itemsTable->addCell(950)->addText($item['quantity'].$item['uom'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1550)->addText($item['package_size'], [], ['alignment' => Jc::CENTER]);
            $itemsTable->addCell(1450)->addText($item['gross_weight'].' / '.$item['net_weight'], [], ['alignment' => Jc::CENTER]);
        }

        if ($snapshot['packing_list']['remarks']) {
            $section->addTextBreak(1);
            $section->addText('Remarks: '.$snapshot['packing_list']['remarks'], ['italic' => true]);
        }

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
        $dompdf->loadHtml(view('packing-lists.document', [
            'snapshot' => $snapshot,
        ])->render());
        $dompdf->setPaper('A4');
        $dompdf->render();

        Storage::disk('local')->put($storagePath, $dompdf->output());
    }

    private function addInfoRow($table, string $leftTitle, array $leftLines, string $rightTitle, array $rightLines): void
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
            'postal_code' => $company?->postal_code,
            'country' => $company?->country?->name,
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

    private function addImageIfExists($section, string $storagePath, ?int $width = null, ?int $height = null): void
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
}
