<?php

namespace App\Services;

use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\SimpleType\Jc;

/** Quotation-only Letter layout, measured from the supplied QTN-COR-321 PDFs. */
final class QuotationOfferLayout
{
    public const VERSION = 'quotation-offer-letter-2026-09-v1';

    public function prepare(array $snapshot): array
    {
        foreach ($snapshot['items'] as &$item) {
            $plain = trim((string) ($item['description'] ?? strip_tags($item['description_html'] ?? '')));
            $item['offer_description'] = $item['description_html'] ?? nl2br(e($plain));
            $item['offer_code'] = ! empty($item['product_code']) && ! str_contains($plain, $item['product_code']) ? $item['product_code'] : null;
            $item['offer_manufacturer'] = ! preg_match('/\b(MFG|MANUFACTURER)\s*:/i', $plain) ? ($item['manufacturer'] ?? null) : null;
            // Dompdf cannot split oversized table cells. Preserve line breaks in bounded continuation rows.
            $lines = preg_split('/\R/u', preg_replace('/<br\s*\/?>|<\/(p|div|li)>/i', "\n", $item['offer_description']));
            $chunks = [];
            $chunk = '';
            $lineCount = 0;
            foreach ($lines as $line) {
                $line = trim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5));
                if ($line === '') {
                    continue;
                }
                foreach (explode("\n", wordwrap($line, 480, "\n", true)) as $part) {
                    if ($chunk !== '' && (mb_strlen($chunk.$part) > 650 || $lineCount >= 12)) {
                        $chunks[] = $chunk;
                        $chunk = '';
                        $lineCount = 0;
                    }
                    $chunk .= ($chunk === '' ? '' : "\n").$part;
                    $lineCount++;
                }
            }
            $chunks[] = $chunk;
            $item['offer_chunks'] = $chunks;
        }
        unset($item);

        $delivery = collect($snapshot['terms'] ?? [])->first(fn ($term) => ($term['key'] ?? '') === 'delivery_terms' || stripos($term['title'] ?? '', 'Delivery Terms') !== false);
        $snapshot['offer_incoterms'] = trim((string) ($delivery['description_plain'] ?? strip_tags($delivery['description_html'] ?? $delivery['description'] ?? $snapshot['quotation']['incoterm'] ?? '')));
        $snapshot['offer_rfq'] = ! empty($snapshot['quotation']['rfq_number']) ? 'RFQ '.$snapshot['quotation']['rfq_number'] : '';
        $snapshot['offer_payment_terms'] = [$snapshot['quotation']['payment_terms'] ?? ''];
        foreach ($snapshot['payment_schedule'] ?? [] as $schedule) {
            $snapshot['offer_payment_terms'][] = $schedule['summary'].(! empty($schedule['notes']) ? ' — '.$schedule['notes'] : '');
        }
        $snapshot['offer_totals'] = $this->totals($snapshot);

        return $snapshot;
    }

    private function totals(array $snapshot): array
    {
        $currency = $snapshot['quotation']['currency'] ?? 'OMR';
        $totals = [['Total Net Amount '.$currency.' (Excluding VAT):', $snapshot['totals']['subtotal']]];
        foreach ($snapshot['charges'] ?? [] as $charge) {
            $totals[] = [$charge['label'].':', $charge['amount']];
        }
        foreach ($snapshot['discounts'] ?? [] as $discount) {
            $totals[] = [$discount['label'].($discount['discount_type'] === 'percentage' ? ' ('.$this->quantity($discount['amount']).'%)' : '').':', '-'.$discount['computed_amount']];
        }
        if ((float) ($snapshot['totals']['vat'] ?? 0) !== 0.0) {
            $totals[] = ['VAT Amount '.$currency.' (After Discounts):', $snapshot['totals']['vat']];
        }
        if (count($totals) > 1) {
            $totals[] = ['Total Amount '.$currency.' (Including VAT):', $snapshot['totals']['grand_total']];
        }

        return $totals;
    }

    public function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) str_replace(',', '', (string) $value), 3, '.', ''), '0'), '.');
    }

    public function money(mixed $value): string
    {
        return number_format((float) str_replace(',', '', (string) $value), 3, '.', ',');
    }

    public function writePdf(array $snapshot, string $path, bool $technical): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(dirname($path));
        $fontDirectory = rtrim((string) config('quotation_documents.font_directory'), '/\\');
        $fontCache = storage_path('framework/cache/quotation-fonts');
        if (! is_dir($fontCache)) {
            mkdir($fontCache, 0755, true);
        }
        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'fontHeightRatio' => 0.8,
            'fontDir' => $fontCache,
            'fontCache' => $fontCache,
            'chroot' => [resource_path(), storage_path(), $fontDirectory],
        ]);
        $hasCalibri = is_file($fontDirectory.'/calibri.ttf') && is_file($fontDirectory.'/calibrib.ttf');
        $dompdf->loadHtml(view($technical ? 'quotations.technical-document' : 'quotations.document', [
            'snapshot' => $this->prepare($snapshot),
            'technical' => $technical,
            'layout' => $this,
            'fontDirectory' => str_replace('\\', '/', $fontDirectory),
            'hasCalibri' => $hasCalibri,
            'stamp' => DocumentBrandingAssets::dataUri('quotation-assets/offer-stamp.jpeg'),
        ])->render());
        $dompdf->setPaper('letter');
        $dompdf->render();
        $this->pdfChrome($dompdf, $snapshot, $hasCalibri ? 'Calibri' : 'DejaVu Sans');
        $disk->put($path, $dompdf->output());
        $disk->put($path.'.layout', self::VERSION);
    }

    private function pdfChrome(Dompdf $dompdf, array $snapshot, string $font): void
    {
        $header = DocumentBrandingAssets::path('quotation-assets/offer-header.jpeg');
        $footer = DocumentBrandingAssets::path('quotation-assets/offer-footer.jpeg');
        $abb = DocumentBrandingAssets::path('quotation-assets/offer-abb.jpg');
        $reference = 'Ref: '.$snapshot['quotation']['reference'];
        $vatin = 'VATIN '.(($snapshot['supplier']['vat_tin'] ?? null) ?: 'OM1100033153');
        $dompdf->getCanvas()->page_script(function ($page, $pages, $canvas, $metrics) use ($header, $footer, $abb, $reference, $vatin, $font): void {
            $regular = $metrics->getFont($font, 'normal');
            $canvas->image($header, 0, 12, 604, 80.4);
            $canvas->filled_rectangle(25, 71.5, 105, 17, [1, 1, 1]);
            $canvas->rectangle(25, 71.5, 105, 17, [0, 0, 0], 0.5);
            $canvas->text(33, 76, $vatin, $regular, 8);
            $width = max(136, $metrics->getTextWidth($reference, $regular, 7) + 16);
            $canvas->filled_rectangle(568 - $width, 68, $width, 17, [1, 1, 1]);
            $canvas->rectangle(568 - $width, 68, $width, 17, [0, 0, 0], 0.5);
            $canvas->text(576 - $width, 72.5, $reference, $regular, 7);
            $canvas->image($abb, 549, 688, 54, 55);
            $canvas->rectangle(10, 725, 64, 20, [0, 0, 0], 0.5);
            $canvas->text(30, 730.5, "Page {$page} of {$pages}", $regular, 8);
            $canvas->image($footer, 9, 746, 594, 41.4);
        });
    }

    public function writeWord(array $snapshot, string $path, bool $technical): void
    {
        $snapshot = $this->prepare($snapshot);
        Settings::setOutputEscapingEnabled(true);
        $word = new PhpWord;
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(11);
        $word->setDefaultParagraphStyle(['spaceAfter' => 0, 'lineHeight' => 1.0]);
        $section = $word->addSection([
            'paperSize' => 'Letter', 'marginTop' => 1960, 'marginBottom' => 2200,
            'marginLeft' => 640, 'marginRight' => 640, 'headerHeight' => 240, 'footerHeight' => 160,
        ]);
        $this->wordChrome($section, $snapshot);
        $run = $section->addTextRun(['alignment' => Jc::CENTER, 'spaceAfter' => 80]);
        $run->addText($technical ? 'Technical Offer' : 'Commercial Offer', ['size' => 16, 'bold' => true]);
        if ($snapshot['offer_rfq'] !== '') {
            $run->addText(' – '.$snapshot['offer_rfq'], ['size' => 16, 'bold' => true, 'color' => 'FF0000']);
        }
        $ref = $section->addTable($this->tableStyle(9400, 0));
        $ref->addRow(null, ['cantSplit' => true]);
        $ref->addCell(4700, ['borderTopSize' => 4])->addText('Ref: '.$snapshot['quotation']['reference'], ['size' => 10]);
        $ref->addCell(4700, ['borderTopSize' => 4])->addText('Dated: '.$snapshot['quotation']['dated'], ['size' => 10], ['alignment' => Jc::RIGHT]);
        $section->addTextBreak(1, ['size' => 6]);
        $info = $section->addTable($this->tableStyle(9040));
        $this->wordInfo($info, 'SUPPLIER', $this->companyLines($snapshot['supplier']), 'BUYER', $this->companyLines($snapshot['buyer']));
        $this->wordInfo($info, 'SUPPLIERS CONTACT', $this->contactLines($snapshot['supplier_contact']), 'BUYERS CONTACT', $this->contactLines($snapshot['buyer_contact']));
        $this->wordInfo($info, 'QUOTATION VALIDITY PERIOD', [$snapshot['quotation']['validity']], $technical ? '' : 'ACCEPTED TERMS OF PAYMENT', $technical ? [] : $snapshot['offer_payment_terms']);
        $info->addRow(null, ['cantSplit' => true]);
        foreach (['DATE OF DELIVERY', $technical ? '' : 'ACCEPTED INVOICE CURRENCY'] as $label) {
            $info->addCell(4520, ['bgColor' => 'D9D9D9'])->addText($label, ['bold' => true, 'size' => 10]);
        }
        $info->addRow(null, ['cantSplit' => true]);
        $cell = $info->addCell(4520);
        $cell->addText($snapshot['quotation']['delivery_period'], ['bold' => true, 'bgColor' => 'FFFF00', 'size' => 10]);
        if ($snapshot['offer_incoterms'] !== '') {
            $cell->addText('INCOTERMS: '.$snapshot['offer_incoterms'], ['bold' => true, 'color' => 'FF0000', 'bgColor' => 'FFFF00', 'size' => 10]);
        }
        $info->addCell(4520)->addText($technical ? '' : $snapshot['quotation']['currency'], ['bold' => true, 'color' => 'FF0000', 'size' => 10]);
        $section->addTextBreak(1, ['size' => 10]);
        $rfq = $snapshot['quotation']['rfq_title'] ?? $snapshot['offer_rfq'];
        if ($rfq !== '') {
            $section->addText($rfq, ['bold' => true, 'color' => 'FF0000', 'underline' => 'single', 'size' => 10], ['alignment' => Jc::CENTER]);
        }
        if (! empty($snapshot['quotation']['closing_at'])) {
            $section->addText('Closing Date & time: '.$snapshot['quotation']['closing_at'], ['bold' => true, 'color' => 'FF0000', 'underline' => 'single'], ['alignment' => Jc::CENTER]);
        }
        $widths = $technical ? [1080, 8020, 1020] : [720, 6600, 900, 1180, 1240];
        $headings = $technical ? ['SL No', 'Description', 'QTY'] : ['SL No', 'Description', 'QTY', 'Unit Price', 'Total excl. VAT'];
        $items = $section->addTable($this->tableStyle(array_sum($widths)));
        $items->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headings as $index => $heading) {
            $items->addCell($widths[$index], ['bgColor' => 'D9D9D9'])->addText($heading, ['bold' => true], ['alignment' => $index === 1 ? Jc::LEFT : Jc::CENTER]);
        }
        foreach ($snapshot['items'] as $item) {
            $items->addRow();
            $items->addCell($widths[0], ['valign' => 'center'])->addText((string) $item['line_number'], [], ['alignment' => Jc::CENTER]);
            $cell = $items->addCell($widths[1]);
            $cell->addText($item['title'], ['bold' => true]);
            if ($item['offer_code']) {
                $cell->addText('Material / Item Code: '.$item['offer_code']);
            }
            $html = $item['offer_description'];
            if (! preg_match('/<(p|div|ul|ol)\b/i', $html)) {
                $html = '<p>'.$html.'</p>';
            }
            $html = preg_replace('/<br\s*\/?\s*>/i', '</p><p>', $html);
            $html = str_replace(['<mark>', '</mark>'], ['<span style="background-color: #ffff00;">', '</span>'], $html);
            try {
                Html::addHtml($cell, $html, false, false);
            } catch (\Throwable) {
                foreach ($item['offer_chunks'] as $chunk) {
                    foreach (explode("\n", $chunk) as $line) {
                        $cell->addText($line);
                    }
                }
            }
            if ($item['offer_manufacturer']) {
                $cell->addText('MFG: '.$item['offer_manufacturer'], ['bold' => true]);
            }
            $items->addCell($widths[2], ['valign' => 'center'])->addText($this->quantity($item['quantity']).' '.$item['uom'], [], ['alignment' => Jc::CENTER]);
            if (! $technical) {
                $items->addCell($widths[3], ['valign' => 'center'])->addText($this->money($item['unit_price']), [], ['alignment' => Jc::CENTER]);
                $items->addCell($widths[4], ['valign' => 'center'])->addText($this->money($item['total_price']), [], ['alignment' => Jc::CENTER]);
            }
        }
        if (! $technical) {
            foreach ($snapshot['offer_totals'] as [$label, $value]) {
                $items->addRow(null, ['cantSplit' => true]);
                $items->addCell($widths[0], ['bgColor' => 'D9D9D9'])->addText('');
                $items->addCell(array_sum(array_slice($widths, 1, 3)), ['gridSpan' => 3, 'bgColor' => 'D9D9D9'])->addText($label, ['bold' => true, 'underline' => 'single', 'size' => 10], ['alignment' => Jc::RIGHT]);
                $items->addCell($widths[4], ['bgColor' => 'D9D9D9'])->addText($this->money($value), ['bold' => true, 'underline' => 'single'], ['alignment' => Jc::CENTER]);
            }
            if (($snapshot['quotation']['vat_pricing'] ?? 'exclusive') === 'inclusive') {
                $section->addText('Unit prices include VAT; line totals exclude VAT.', ['size' => 9]);
            }
        }
        $section->addTextBreak(1, ['size' => $technical ? 8 : 18]);
        $section->addText('Terms & Conditions:', ['bold' => true, 'underline' => 'single'], ['alignment' => Jc::CENTER, 'keepNext' => true]);
        foreach ($snapshot['terms'] as $index => $term) {
            $run = $section->addTextRun(['spaceAfter' => 0, 'alignment' => Jc::BOTH, 'indentation' => ['left' => 520, 'firstLine' => null, 'hanging' => 520], 'lineHeight' => 1.15]);
            $highlight = stripos($term['title'], 'Delivery Terms') !== false ? ['bgColor' => '00FFFF'] : [];
            $run->addText(($term['line_number'] ?? $index + 1).'.     ');
            $run->addText($term['title'].' : ', ['bold' => true, ...$highlight]);
            $run->addText($term['description_plain'] ?? strip_tags($term['description_html'] ?? $term['description']), $highlight);
        }
        $section->addTextBreak(1);
        $section->addImage(DocumentBrandingAssets::path('quotation-assets/offer-stamp.jpeg'), ['width' => 82, 'alignment' => Jc::LEFT, 'marginLeft' => 17]);
        $section->addText('Industrial Supplies Center LLC.', ['bold' => true], ['indentation' => ['left' => 340]]);
        Storage::disk('local')->makeDirectory(dirname($path));
        IOFactory::createWriter($word, 'Word2007')->save(Storage::disk('local')->path($path));
        Storage::disk('local')->put($path.'.layout', self::VERSION);
    }

    private function tableStyle(int $width, int $border = 6): array
    {
        return ['width' => $width, 'unit' => 'dxa', 'layout' => 'fixed', 'alignment' => 'center', 'borderSize' => $border, 'borderColor' => $border === 0 ? 'FFFFFF' : '000000', 'cellMarginTop' => 15, 'cellMarginBottom' => 15, 'cellMarginLeft' => 100, 'cellMarginRight' => 80];
    }

    private function wordInfo($table, string $leftTitle, array $left, string $rightTitle, array $right): void
    {
        $table->addRow(null, ['cantSplit' => true]);
        foreach ([$leftTitle, $rightTitle] as $title) {
            $table->addCell(4520, ['bgColor' => 'D9D9D9'])->addText($title, ['bold' => true, 'size' => 10]);
        }
        $table->addRow(null, ['cantSplit' => true]);
        foreach ([$left, $right] as $lines) {
            $cell = $table->addCell(4520);
            foreach ($lines as $index => $line) {
                $cell->addText($line, ['size' => 10, 'bold' => $index === 0 && count($lines) > 1]);
            }
        }
    }

    private function companyLines(?array $company): array
    {
        return array_values(array_filter([$company['name'] ?? '', $company['address'] ?? '', $company['location'] ?? '']));
    }

    private function contactLines(?array $contact): array
    {
        return array_values(array_filter([trim(($contact['designation'] ?? '').' '.($contact['name'] ?? '')), ! empty($contact['mobile']) ? 'Mob: '.$contact['mobile'] : '', ! empty($contact['email']) ? 'E-mail: '.$contact['email'] : '']));
    }

    private function wordChrome($section, array $snapshot): void
    {
        $header = $section->addHeader();
        $this->floatingImage($header, 'offer-header.jpeg', 0, 12, 604, 80.4);
        $this->textBox($header, 25, 71.5, 105, 17)->addText('VATIN '.(($snapshot['supplier']['vat_tin'] ?? null) ?: 'OM1100033153'), ['size' => 8]);
        $this->textBox($header, 432, 68, 136, 17)->addText('Ref: '.$snapshot['quotation']['reference'], ['size' => 7]);
        $footer = $section->addFooter();
        $this->floatingImage($footer, 'offer-abb.jpg', 549, 688, 54, 55);
        $this->floatingImage($footer, 'offer-footer.jpeg', 9, 746, 594, 41.4);
        $pageRun = $this->textBox($footer, 10, 725, 64, 20)->addTextRun(['alignment' => Jc::CENTER]);
        $pageRun->addText('Page ', ['size' => 8]);
        $pageRun->addField('PAGE', [], [], null, ['size' => 8]);
        $pageRun->addText(' of ', ['size' => 8]);
        $pageRun->addField('NUMPAGES', [], [], null, ['size' => 8]);
    }

    private function textBox($container, float $x, float $y, float $width, float $height)
    {
        return $container->addTextBox([
            'width' => $width, 'height' => $height, 'positioning' => 'absolute', 'posHorizontal' => 'absolute', 'posHorizontalRel' => 'page',
            'posVertical' => 'absolute', 'posVerticalRel' => 'page', 'marginLeft' => $x, 'marginTop' => $y, 'wrappingStyle' => 'infront',
            'borderSize' => 1, 'borderColor' => '000000', 'bgColor' => 'FFFFFF', 'innerMarginTop' => 3, 'innerMarginLeft' => 7, 'innerMarginRight' => 3, 'innerMarginBottom' => 0,
        ]);
    }

    private function floatingImage($container, string $asset, float $x, float $y, float $width, float $height): void
    {
        $container->addImage(DocumentBrandingAssets::path('quotation-assets/'.$asset), [
            'width' => $width, 'height' => $height, 'positioning' => 'absolute', 'posHorizontal' => 'absolute', 'posHorizontalRel' => 'page',
            'posVertical' => 'absolute', 'posVerticalRel' => 'page', 'marginLeft' => $x, 'marginTop' => $y, 'wrappingStyle' => 'behind',
        ]);
    }
}
