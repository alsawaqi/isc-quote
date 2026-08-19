<?php

namespace App\Services;

final class QuotationSnapshotRichTextSanitizer
{
    public function __construct(private readonly RichTextSanitizer $richText) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function sanitize(array $snapshot): array
    {
        foreach (($snapshot['items'] ?? []) as $index => $item) {
            $source = $this->richSource($item);
            $clean = $this->richText->sanitize($source) ?? '';

            $snapshot['items'][$index]['description_html'] = $clean;
            $snapshot['items'][$index]['description'] = $this->toPlainText($clean);
        }

        foreach (($snapshot['terms'] ?? []) as $index => $term) {
            $source = $this->richSource($term);
            $clean = $this->richText->sanitize($source) ?? '';

            $snapshot['terms'][$index]['description_html'] = $clean;
            $snapshot['terms'][$index]['description'] = $clean;
            $snapshot['terms'][$index]['description_plain'] = $this->toPlainText($clean);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $entry */
    private function richSource(array $entry): string
    {
        $html = $entry['description_html'] ?? null;

        if (is_string($html) && trim($html) !== '') {
            return $html;
        }

        $fallback = $entry['description'] ?? '';

        return is_string($fallback) ? $fallback : '';
    }

    private function toPlainText(string $html): string
    {
        $text = preg_replace('/<li[^>]*>/i', "\n- ", $html) ?? $html;
        $text = preg_replace('/<\/p>|<br\s*\/?>|<\/div>|<\/li>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);
    }
}
