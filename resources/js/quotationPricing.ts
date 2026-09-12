type Amount = number | string | null | undefined;
type Item = { quantity: Amount; unit_price: Amount; vat_rate?: Amount; total_price?: Amount };
type Charge = { amount: Amount };
type Discount = Charge & { discount_type: 'fixed' | 'percentage' };
type Mode = 'inclusive' | 'exclusive';

// Integer thousandths keep half-up rounding identical to the PHP decimal calculator.
function divide(value: bigint, divisor: bigint): bigint {
    return (value + divisor / 2n) / divisor;
}

function mills(value: Amount): bigint {
    if (value == null || value === '' || !Number.isFinite(Number(value)) || Number(value) < 0) return 0n;
    const [mantissa, exponent = '0'] = String(value).toLowerCase().split('e');
    const [whole, fraction = ''] = mantissa.replace(/^\+/, '').split('.');
    const digits = BigInt((whole || '0') + fraction);
    const shift = 3 + Number(exponent) - fraction.length;
    return shift >= 0 ? digits * 10n ** BigInt(shift) : divide(digits, 10n ** BigInt(-shift));
}

const money = (value: bigint): number => Number(value) / 1000;

function lineMills(item: Item, mode: Mode) {
    const amount = divide(mills(item.quantity) * mills(item.unit_price), 1000n);
    const rate = mills(item.vat_rate);
    const net = item.total_price != null ? mills(item.total_price)
        : mode === 'inclusive' ? divide(amount * 100000n, 100000n + rate) : amount;
    const vat = mode === 'inclusive' ? (amount > net ? amount - net : 0n) : divide(net * rate, 100000n);
    return { net, vat, gross: net + vat };
}

export function quotationLine(item: Item, mode: Mode) {
    const line = lineMills(item, mode);
    return { net: money(line.net), vat: money(line.vat), gross: money(line.gross) };
}

export function quotationPricing(items: Item[], charges: Charge[], discounts: Discount[], mode: Mode) {
    const lines = items.map((item) => lineMills(item, mode));
    const subtotal = lines.reduce((sum, line) => sum + line.net, 0n);
    const rawVat = lines.reduce((sum, line) => sum + line.vat, 0n);
    const chargeTotal = charges.reduce((sum, charge) => sum + mills(charge.amount), 0n);
    const base = subtotal + chargeTotal;
    let requested = 0n;
    let applied = 0n;
    const discountValues = discounts.map((discount) => {
        const amount = mills(discount.amount);
        const requestedValue = discount.discount_type === 'percentage' ? divide(base * amount, 100000n) : amount;
        requested += requestedValue;
        const value = requestedValue > base - applied ? base - applied : requestedValue;
        applied += value;
        return money(value);
    });
    const vat = base === 0n ? rawVat : divide(rawVat * (base - applied), base);
    return {
        subtotal: money(subtotal), vat: money(vat), charges: money(chargeTotal),
        discounts: money(applied), total: money(base - applied + vat),
        discountValues, discountsExceedBase: requested > base,
    };
}
