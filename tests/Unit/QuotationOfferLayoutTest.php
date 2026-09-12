<?php

namespace Tests\Unit;

use App\Services\QuotationOfferLayout;
use PHPUnit\Framework\TestCase;

class QuotationOfferLayoutTest extends TestCase
{
    public function test_snapshot_thousands_separators_do_not_change_prices_or_quantities(): void
    {
        $layout = new QuotationOfferLayout;
        $this->assertSame('4,299.000', $layout->money('4,299.000'));
        $this->assertSame('4,299.000', $layout->money('4299.000'));
        $this->assertSame('-1,250.125', $layout->money('-1,250.125'));
        $this->assertSame('1000', $layout->quantity('1,000.000'));
        $this->assertSame('0.125', $layout->quantity('0.125'));
    }

    public function test_long_description_continuations_preserve_every_entry(): void
    {
        $lines = array_map(fn ($index) => 'DETAIL-'.$index.' '.str_repeat('motor specification ', 10), range(1, 150));
        $snapshot = (new QuotationOfferLayout)->prepare([
            'items' => [['description' => implode("\n", $lines), 'description_html' => implode('<br />', $lines)]],
            'terms' => [], 'quotation' => ['currency' => 'OMR'], 'totals' => ['subtotal' => '4,299.000'],
        ]);
        $chunks = $snapshot['items'][0]['offer_chunks'];
        $this->assertGreaterThan(1, count($chunks));
        foreach ($lines as $line) {
            $this->assertStringContainsString(trim($line), implode("\n", $chunks));
        }
        $this->assertSame([['Total Net Amount OMR (Excluding VAT):', '4,299.000']], $snapshot['offer_totals']);
    }
}
