<?php

namespace Tests\Unit;

use App\Services\QuotationPricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotationPricingTest extends TestCase
{
    public static function pricingCases(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/quotation-pricing.json'), true, flags: JSON_THROW_ON_ERROR) as $case) {
            yield $case['name'] => [$case];
        }
    }

    #[DataProvider('pricingCases')]
    public function test_pricing_matches_the_shared_preview_contract(array $case): void
    {
        $result = QuotationPricing::calculate($case['items'], $case['charges'], $case['discounts'], $case['mode']);
        $this->assertSame($case['expected'], array_values(array_intersect_key($result, array_flip([
            'items_subtotal', 'vat_total', 'charges_total', 'discounts_total', 'grand_total',
        ]))));
        $this->assertSame($case['values'], $result['discount_values']);
        $this->assertSame($case['exceeds'], $result['discounts_exceed_base']);

        // Saving the calculated net must not change a later read or document calculation.
        $savedItems = array_map(fn ($item) => [...$item, 'total_price' => QuotationPricing::line($item, $case['mode'])['net']], $case['items']);
        $this->assertSame($result, QuotationPricing::calculate($savedItems, $case['charges'], $case['discounts'], $case['mode']));
    }
}
