import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import ts from 'typescript';

const source = readFileSync(new URL('../resources/js/quotationPricing.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } });
const { quotationPricing, quotationLine } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);
const cases = JSON.parse(readFileSync(new URL('./Fixtures/quotation-pricing.json', import.meta.url), 'utf8'));

for (const fixture of cases) {
    test(fixture.name, () => {
        const result = quotationPricing(fixture.items, fixture.charges, fixture.discounts, fixture.mode);
        assert.deepEqual([result.subtotal, result.vat, result.charges, result.discounts, result.total].map(value => value.toFixed(3)), fixture.expected);
        assert.deepEqual(result.discountValues.map(value => value.toFixed(3)), fixture.values);
        assert.equal(result.discountsExceedBase, fixture.exceeds);
        const savedItems = fixture.items.map(item => ({ ...item, total_price: quotationLine(item, fixture.mode).net }));
        assert.deepEqual(quotationPricing(savedItems, fixture.charges, fixture.discounts, fixture.mode), result);
    });
}
