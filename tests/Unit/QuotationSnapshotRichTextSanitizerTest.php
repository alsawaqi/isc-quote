<?php

namespace Tests\Unit;

use App\Services\QuotationSnapshotRichTextSanitizer;
use App\Services\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

class QuotationSnapshotRichTextSanitizerTest extends TestCase
{
    public function test_historical_snapshot_html_is_sanitized_before_document_rendering(): void
    {
        $sanitizer = new QuotationSnapshotRichTextSanitizer(new RichTextSanitizer);
        $snapshot = $sanitizer->sanitize([
            'items' => [
                [
                    'description' => 'stale plain text',
                    'description_html' => '<p onclick="alert(1)">Motor <strong style="color:red">description</strong><img src=x onerror="alert(2)"><script>alert(3)</script><mark style="color:red">IP66</mark></p>',
                ],
                [
                    'description' => '<p>Legacy fallback <em>description</em></p>',
                    'description_html' => '',
                ],
            ],
            'terms' => [[
                'description' => '<p>Unsafe fallback</p>',
                'description_html' => '<blockquote cite="javascript:alert(4)"><p>Formatted <u>term</u></p></blockquote><style>body{display:none}</style>',
                'description_plain' => 'stale unsafe plain text',
            ]],
        ]);

        $this->assertSame(
            '<p>Motor <strong>description</strong><mark>IP66</mark></p>',
            $snapshot['items'][0]['description_html'],
        );
        $this->assertSame('Motor descriptionIP66', $snapshot['items'][0]['description']);
        $this->assertSame(
            '<p>Legacy fallback <em>description</em></p>',
            $snapshot['items'][1]['description_html'],
        );
        $this->assertSame(
            '<blockquote><p>Formatted <u>term</u></p></blockquote>',
            $snapshot['terms'][0]['description_html'],
        );
        $this->assertSame('Formatted term', $snapshot['terms'][0]['description_plain']);
        $this->assertStringNotContainsString('javascript:', serialize($snapshot));
        $this->assertStringNotContainsString('onerror', serialize($snapshot));
        $this->assertStringNotContainsString('<script', serialize($snapshot));
        $this->assertStringNotContainsString('<style', serialize($snapshot));
    }
}
