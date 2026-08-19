<?php

namespace Tests\Unit;

use App\Services\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

class RichTextSanitizerTest extends TestCase
{
    private RichTextSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new RichTextSanitizer;
    }

    public function test_executable_markup_urls_attributes_and_styles_are_removed(): void
    {
        $html = <<<'HTML'
            <h2 onclick="alert(1)">Technical offer</h2>
            <p style="position:fixed;color:red">Safe <strong data-secret="x">bold</strong>
            <script>alert('stored-xss')</script>
            <style>body { display: none }</style>
            <img src="x" onerror="alert(2)">
            <a href="javascript:alert(3)" onmouseover="alert(4)">link text</a>
            <svg onload="alert(5)"><circle></circle></svg>
            <mark style="background-image:url(javascript:alert(6))">highlight</mark></p>
            HTML;

        $clean = $this->sanitizer->sanitize($html);

        $this->assertSame(
            '<h2>Technical offer</h2> <p>Safe <strong>bold</strong> link text <mark>highlight</mark></p>',
            preg_replace('/\s+/', ' ', (string) $clean),
        );
        $this->assertStringNotContainsString('script', strtolower((string) $clean));
        $this->assertStringNotContainsString('javascript:', strtolower((string) $clean));
        $this->assertStringNotContainsString('onerror', strtolower((string) $clean));
        $this->assertStringNotContainsString('onclick', strtolower((string) $clean));
        $this->assertStringNotContainsString('style=', strtolower((string) $clean));
        $this->assertStringNotContainsString('<img', strtolower((string) $clean));
    }

    public function test_supported_editor_formatting_is_retained_without_attributes(): void
    {
        $html = '<h2 class="title">Heading</h2><p>Plain <strong>bold</strong> <em>italic</em> <u>underlined</u> <s>struck</s> <mark class="highlight" style="background-color: #fff59d;">marked</mark><br>next</p><blockquote cite="https://example.test"><p>Quote</p></blockquote><ul data-list="bullet"><li>One</li></ul><ol start="8"><li>Two</li></ol>';

        $this->assertSame(
            '<h2>Heading</h2><p>Plain <strong>bold</strong> <em>italic</em> <u>underlined</u> <s>struck</s> <mark>marked</mark><br>next</p><blockquote><p>Quote</p></blockquote><ul><li>One</li></ul><ol><li>Two</li></ol>',
            $this->sanitizer->sanitize($html),
        );
    }

    public function test_only_the_historical_fixed_highlight_span_is_normalized_to_mark(): void
    {
        $this->assertSame(
            '<p><mark>approved highlight</mark> unsafe style</p>',
            $this->sanitizer->sanitize(
                '<p><span style="background-color: #fff59d;">approved highlight</span> <span style="color:red">unsafe style</span></p>',
            ),
        );
    }

    public function test_unicode_and_encoded_business_text_are_preserved(): void
    {
        $this->assertSame(
            '<p>شروط الدفع &amp; التسليم — ٣٠ يوماً</p>',
            $this->sanitizer->sanitize('<p lang="ar">شروط الدفع &amp; التسليم — ٣٠ يوماً</p>'),
        );
    }
}
