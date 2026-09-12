<?php

namespace App\Services;

use App\Models\Quotation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Three-decimal, half-up pricing shared by saved quotations and exports. */
class QuotationPricing
{
    private static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(3, RoundingMode::HALF_UP);
    }

    public static function line(array $item, string $mode): array
    {
        $quantity = self::decimal($item['quantity']);
        $price = self::decimal($item['unit_price']);
        $rate = self::decimal($item['vat_rate'] ?? 0);
        $amount = $quantity->multipliedBy($price)->toScale(3, RoundingMode::HALF_UP);
        $net = isset($item['total_price']) ? self::decimal($item['total_price']) : ($mode === 'inclusive'
            ? $amount->multipliedBy(100)->dividedBy($rate->plus(100), 3, RoundingMode::HALF_UP)
            : $amount);
        $vat = $mode === 'inclusive'
            ? BigDecimal::max(0, $amount->minus($net))->toScale(3)
            : $net->multipliedBy($rate)->dividedBy(100, 3, RoundingMode::HALF_UP);

        return ['net' => (string) $net, 'vat' => (string) $vat, 'gross' => (string) $net->plus($vat)];
    }

    public static function forQuotation(Quotation $quotation): array
    {
        $quotation->loadMissing(['items', 'charges', 'discounts']);

        return self::calculate(
            $quotation->items->map(fn ($item) => $item->getAttributes())->all(),
            $quotation->charges->map(fn ($charge) => $charge->getAttributes())->all(),
            $quotation->discounts->map(fn ($discount) => $discount->getAttributes())->all(),
            $quotation->vat_pricing ?? 'exclusive',
        );
    }

    public static function calculate(array $items, array $charges, array $discounts, string $mode): array
    {
        $subtotal = self::decimal(0);
        $rawVat = self::decimal(0);
        foreach ($items as $item) {
            $line = self::line($item, $mode);
            $subtotal = $subtotal->plus($line['net']);
            $rawVat = $rawVat->plus($line['vat']);
        }
        $chargeTotal = self::decimal(0);
        foreach ($charges as $charge) {
            $chargeTotal = $chargeTotal->plus(self::decimal($charge['amount']));
        }
        $base = $subtotal->plus($chargeTotal);
        $requested = self::decimal(0);
        $applied = self::decimal(0);
        $discountValues = [];
        foreach ($discounts as $discount) {
            $amount = self::decimal($discount['amount']);
            // Each percentage uses the original net subtotal plus charges, not a running balance.
            $value = $discount['discount_type'] === 'percentage'
                ? $base->multipliedBy($amount)->dividedBy(100, 3, RoundingMode::HALF_UP)
                : $amount;
            $requested = $requested->plus($value);
            // Keep legacy over-discounted records reconcilable; new submissions are rejected.
            $value = BigDecimal::min($value, $base->minus($applied));
            $applied = $applied->plus($value);
            $discountValues[] = (string) $value;
        }
        $vat = $base->isZero() ? $rawVat : $rawVat->multipliedBy($base->minus($applied))
            ->dividedBy($base, 3, RoundingMode::HALF_UP);

        return [
            'items_subtotal' => (string) $subtotal,
            'vat_total' => (string) $vat,
            'charges_total' => (string) $chargeTotal,
            'discounts_total' => (string) $applied,
            'grand_total' => (string) $base->minus($applied)->plus($vat),
            'discount_values' => $discountValues,
            'discounts_exceed_base' => $requested->isGreaterThan($base),
        ];
    }
}
