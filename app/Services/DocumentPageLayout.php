<?php

namespace App\Services;

use Dompdf\Dompdf;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\JcTable;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Keeps the page chrome used by every generated commercial document consistent.
 *
 * Branding deliberately contains only ISC assets. In particular, the legacy
 * Riyada footer graphic is not part of this layout.
 */
final class DocumentPageLayout
{
    private const DEFAULT_VATIN = 'OM1100033153';

    private const CONTENT_WIDTH = 10000;

    public function addWordSection(PhpWord $phpWord, string $reference, ?string $vatTin = null): Section
    {
        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'marginTop' => 2150,
            'marginBottom' => 1450,
            'marginLeft' => 600,
            'marginRight' => 600,
            'headerHeight' => 260,
            'footerHeight' => 230,
        ]);

        $this->addWordHeader($section, $reference, $vatTin);
        $this->addWordFooter($section);

        return $section;
    }

    /**
     * Adds a real repeating table-heading row. This is intentionally separate
     * from item rows: an exceptionally long item description may still flow to
     * the next page instead of being clipped.
     */
    public function addWordTableHeader(Table $table): void
    {
        $table->addRow(null, [
            'tblHeader' => true,
            'cantSplit' => true,
        ]);
    }

    /**
     * Keeps compact summary rows intact without preventing long item cells from
     * wrapping across a page.
     */
    public function addWordSummaryRow(Table $table): void
    {
        $table->addRow(null, ['cantSplit' => true]);
    }

    /**
     * Draws the PDF chrome through Dompdf's canvas after content has flowed.
     *
     * CSS fixed elements can reset Dompdf's page margins, which puts long
     * tables beneath the header and makes the footer disappear. Canvas chrome
     * is repeated by Dompdf on every page without affecting document flow.
     */
    public function addPdfPageChrome(
        Dompdf $dompdf,
        string $reference,
        ?string $vatTin = null,
        ?string $footerPartnerPath = null,
    ): void {
        $headerPath = DocumentBrandingAssets::path('quotation-assets/isc-header.jpeg');
        $footerPath = DocumentBrandingAssets::path('quotation-assets/isc-footer.jpeg');
        $vatTin = $this->normalizedVatTin($vatTin);

        $dompdf->getCanvas()->page_script(function (
            int $pageNumber,
            int $pageCount,
            mixed $canvas,
            mixed $fontMetrics,
        ) use ($headerPath, $footerPath, $footerPartnerPath, $reference, $vatTin): void {
            $pageWidth = $canvas->get_width();
            $pageHeight = $canvas->get_height();
            $margin = 34.0;
            $contentWidth = $pageWidth - ($margin * 2);
            $regular = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $bold = $fontMetrics->getFont('DejaVu Sans', 'bold');
            $ink = [0.07, 0.11, 0.15];
            $lime = [0.61, 0.68, 0.16];

            if ($headerPath !== null) {
                $canvas->image($headerPath, $margin, 12, $contentWidth, $contentWidth * (145 / 1089));
            }

            $metaY = 86.0;
            $metaHeight = 17.0;
            $leftBoxWidth = 205.0;
            $referenceText = 'Ref: '.$reference;
            $referenceWidth = max(185.0, $fontMetrics->getTextWidth($referenceText, $bold, 7.5) + 16);
            $referenceX = $pageWidth - $margin - $referenceWidth;

            $canvas->filled_rectangle($margin, $metaY, $leftBoxWidth, $metaHeight, [1, 1, 1]);
            $canvas->rectangle($margin, $metaY, $leftBoxWidth, $metaHeight, $ink, 0.5);
            $canvas->text($margin + 7, $metaY + 5, 'VATIN '.$vatTin, $regular, 7.5, $ink);
            $canvas->filled_rectangle($referenceX, $metaY, $referenceWidth, $metaHeight, [1, 1, 1]);
            $canvas->rectangle($referenceX, $metaY, $referenceWidth, $metaHeight, $ink, 0.5);
            $canvas->text(
                $referenceX + $referenceWidth - 7 - $fontMetrics->getTextWidth($referenceText, $bold, 7.5),
                $metaY + 5,
                $referenceText,
                $bold,
                7.5,
                $ink,
            );
            $canvas->line($margin, $metaY + $metaHeight + 4, $pageWidth - $margin, $metaY + $metaHeight + 4, $lime, 1.4);

            $pageText = "Page {$pageNumber} of {$pageCount}";
            $pageTextWidth = $fontMetrics->getTextWidth($pageText, $regular, 8);
            $canvas->text($pageWidth - $margin - $pageTextWidth, $pageHeight - 77, $pageText, $regular, 8, $ink);

            if ($footerPartnerPath !== null && is_file($footerPartnerPath)) {
                $partnerWidth = 54.0;
                $canvas->image($footerPartnerPath, $margin, $pageHeight - 82, $partnerWidth, $partnerWidth * (147 / 328));
            }

            $footerRuleY = $pageHeight - 59;
            $canvas->line($margin, $footerRuleY, $pageWidth - $margin, $footerRuleY, $lime, 1.4);

            if ($footerPath !== null) {
                $canvas->image($footerPath, $margin, $pageHeight - 55, $contentWidth, $contentWidth * (85 / 1093));
            }
        });
    }

    /**
     * Dompdf cannot reliably split a single table row that is taller than a
     * page. Add safe description chunks to PDF snapshots so a long item can
     * render as continuation rows instead of disappearing onto blank pages.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function preparePdfSnapshot(array $snapshot, bool $preferHtmlDescription = false): array
    {
        if (! isset($snapshot['items']) || ! is_array($snapshot['items'])) {
            return $snapshot;
        }

        foreach ($snapshot['items'] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $description = $preferHtmlDescription && ! empty($item['description_html'])
                ? (string) $item['description_html']
                : (string) ($item['description'] ?? '');

            $item['pdf_description_chunks'] = $this->pdfDescriptionChunks($description);
            $snapshot['items'][$index] = $item;
        }

        return $snapshot;
    }

    /**
     * @return array{borderColor: string, borderSize: int, cellMargin: int, alignment: string, layout: string, width: int, unit: string}
     */
    public function tableStyle(string $borderColor = 'BFBFBF', int $cellMargin = 100): array
    {
        return [
            'borderColor' => $borderColor,
            'borderSize' => 6,
            'cellMargin' => $cellMargin,
            'alignment' => JcTable::CENTER,
            'layout' => TableStyle::LAYOUT_FIXED,
            'width' => self::CONTENT_WIDTH,
            'unit' => TblWidth::TWIP,
        ];
    }

    private function addWordHeader(Section $section, string $reference, ?string $vatTin): void
    {
        $header = $section->addHeader();
        $this->addImage($header, 'quotation-assets/isc-header.jpeg', 520);

        $meta = $header->addTable([
            'alignment' => JcTable::CENTER,
            'cellMargin' => 0,
            'layout' => TableStyle::LAYOUT_FIXED,
            'width' => self::CONTENT_WIDTH,
            'unit' => TblWidth::TWIP,
        ]);
        $meta->addRow(null, ['cantSplit' => true]);
        $meta->addCell(4300, ['borderSize' => 6, 'borderColor' => '111111', 'valign' => 'center'])->addText(
            'VATIN '.($this->normalizedVatTin($vatTin)),
            ['size' => 8],
            ['spaceAfter' => 0],
        );
        $meta->addCell(5700, ['borderSize' => 6, 'borderColor' => '111111', 'valign' => 'center'])->addText(
            'Ref: '.$reference,
            ['size' => 8],
            ['alignment' => Jc::RIGHT, 'spaceAfter' => 0],
        );
    }

    private function addWordFooter(Section $section): void
    {
        $footer = $section->addFooter();
        $footer->addPreserveText(
            'Page {PAGE} of {NUMPAGES}',
            ['size' => 8],
            ['alignment' => Jc::RIGHT, 'spaceAfter' => 0],
        );

        $this->addImage($footer, 'quotation-assets/isc-footer.jpeg', 520);
    }

    private function normalizedVatTin(?string $vatTin): string
    {
        $vatTin = trim((string) $vatTin);

        return $vatTin !== '' ? $vatTin : self::DEFAULT_VATIN;
    }

    /**
     * @return array<int, string>
     */
    private function pdfDescriptionChunks(string $description): array
    {
        $description = preg_replace('/<br\s*\/?>|<\/(p|div|li)>/i', "\n", $description) ?? $description;
        $description = trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5));

        if ($description === '') {
            return [''];
        }

        $chunks = [];
        $current = '';
        $maximumLength = 900;

        foreach (preg_split('/\s+/u', $description, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            while ($this->stringLength($word) > $maximumLength) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                $chunks[] = $this->stringSlice($word, 0, $maximumLength);
                $word = $this->stringSlice($word, $maximumLength);
            }

            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $this->stringLength($candidate) > $maximumLength) {
                $chunks[] = $current;
                $current = $word;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [''] : $chunks;
    }

    private function stringLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function stringSlice(string $value, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length);
        }

        return substr($value, $start, $length);
    }

    private function addImage(mixed $container, string $assetPath, int $width): void
    {
        $path = DocumentBrandingAssets::path($assetPath);

        if ($path === null) {
            return;
        }

        $container->addImage($path, [
            'width' => $width,
            'alignment' => Jc::CENTER,
        ]);
    }
}
