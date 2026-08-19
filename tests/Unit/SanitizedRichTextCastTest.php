<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\QuotationItem;
use App\Models\QuotationTerm;
use App\Models\SupplierPoLine;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SanitizedRichTextCastTest extends TestCase
{
    /**
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function richTextAttributes(): array
    {
        return [
            'product buyer description' => [Product::class, 'buyer_description'],
            'product manufacturer description' => [Product::class, 'manufacturer_description'],
            'quotation buyer description' => [QuotationItem::class, 'buyer_description'],
            'quotation manufacturer description' => [QuotationItem::class, 'manufacturer_description'],
            'quotation term description' => [QuotationTerm::class, 'description'],
            'supplier PO line description' => [SupplierPoLine::class, 'item_description'],
        ];
    }

    /** @param class-string<Model> $modelClass */
    #[DataProvider('richTextAttributes')]
    public function test_rich_text_fields_are_sanitized_on_assignment_and_when_reading_legacy_rows(string $modelClass, string $attribute): void
    {
        $payload = '<p onclick="alert(1)">Formatted <strong style="color:red">text</strong><img src=x onerror="alert(2)"><script>alert(3)</script><mark style="color:red">highlight</mark></p>';
        $expected = '<p>Formatted <strong>text</strong><mark>highlight</mark></p>';

        $model = new $modelClass;
        $model->setAttribute($attribute, $payload);

        $this->assertSame($expected, $model->getAttributes()[$attribute]);
        $this->assertSame($expected, $model->getAttribute($attribute));

        $legacyModel = new $modelClass;
        $legacyModel->setRawAttributes([$attribute => $payload]);

        $this->assertSame($payload, $legacyModel->getAttributes()[$attribute]);
        $this->assertSame($expected, $legacyModel->getAttribute($attribute));
    }
}
