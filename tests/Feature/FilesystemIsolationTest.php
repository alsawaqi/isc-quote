<?php

namespace Tests\Feature;

use App\Services\DocumentBrandingAssets;
use App\Services\PackingListDocumentService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FilesystemIsolationTest extends TestCase
{
    public function test_phpunit_local_disk_is_isolated_from_application_private_storage(): void
    {
        $configuredRoot = $this->normalizePath((string) config('filesystems.disks.local.root'));
        $testingRoot = $this->normalizePath(storage_path('framework/testing/disks'));
        $privateRoot = $this->normalizePath(storage_path('app/private'));

        $this->assertNotSame($privateRoot, $configuredRoot);
        $this->assertStringStartsWith($testingRoot.'/', $configuredRoot.'/');

        $relativePath = 'filesystem-isolation/'.bin2hex(random_bytes(12)).'.txt';
        $privatePath = storage_path('app/private/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

        try {
            Storage::disk('local')->put($relativePath, 'isolated PHPUnit write');

            Storage::disk('local')->assertExists($relativePath);
            $this->assertFileExists(Storage::disk('local')->path($relativePath));
            $this->assertFileDoesNotExist($privatePath);
        } finally {
            Storage::disk('local')->delete($relativePath);
        }
    }

    public function test_branding_assets_resolve_from_source_control_without_local_disk_copies(): void
    {
        Storage::disk('local')->deleteDirectory('quotation-assets');

        foreach (DocumentBrandingAssets::REQUIRED as $assetPath) {
            $bundledPath = DocumentBrandingAssets::bundledPath($assetPath);

            $this->assertFileExists($bundledPath);
            $this->assertFileIsReadable($bundledPath);
            Storage::disk('local')->assertMissing($assetPath);
            $this->assertSame($bundledPath, DocumentBrandingAssets::path($assetPath));
            $this->assertStringStartsWith('data:image/jpeg;base64,', (string) DocumentBrandingAssets::dataUri($assetPath));
        }
    }

    public function test_document_generation_embeds_bundled_assets_into_the_isolated_disk_output(): void
    {
        Storage::disk('local')->deleteDirectory('quotation-assets');
        $documentPath = 'branding-test/packing-list.docx';

        app(PackingListDocumentService::class)->writeDocx([
            'packing_list' => [
                'reference' => 'PL-TEST-001',
                'dated' => '08 Aug 2026',
                'remarks' => null,
            ],
            'supplier' => [
                'name' => 'Industrial Supplies Center LLC',
                'address' => 'Muscat',
                'location' => 'Oman',
                'country' => 'Oman',
            ],
            'buyer' => [
                'name' => 'QA Buyer LLC',
                'address' => 'Sohar',
                'location' => 'Oman',
                'country' => 'Oman',
            ],
            'supplier_contact' => ['name' => 'Sales Contact', 'job_title' => null, 'mobile' => null, 'email' => null],
            'buyer_contact' => ['name' => 'Buyer Contact', 'job_title' => null, 'mobile' => null, 'email' => null],
            'buyer_po' => ['number' => 'PO-001', 'date' => '08 Aug 2026'],
            'items' => [[
                'line_number' => 1,
                'description' => 'QA product',
                'quantity' => '1',
                'uom' => 'EA',
                'package_size' => '1 carton',
                'gross_weight' => '10 kg',
                'net_weight' => '9 kg',
            ]],
        ], $documentPath);

        Storage::disk('local')->assertExists($documentPath);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($documentPath)));
        $this->assertDocxUsesRepeatingPageChrome($zip, 'PL-TEST-001');
        $mediaFiles = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'word/media/')) {
                $mediaFiles[] = $name;
            }
        }

        $zip->close();

        $this->assertGreaterThanOrEqual(2, count($mediaFiles));
        Storage::disk('local')->deleteDirectory('branding-test');
    }

    private function assertDocxUsesRepeatingPageChrome(ZipArchive $zip, string $reference): void
    {
        $documentXml = $zip->getFromName('word/document.xml');
        $relationshipsXml = $zip->getFromName('word/_rels/document.xml.rels');

        $this->assertIsString($documentXml);
        $this->assertIsString($relationshipsXml);
        $this->assertStringContainsString('<w:headerReference', $documentXml);
        $this->assertStringContainsString('<w:footerReference', $documentXml);

        preg_match(
            '/<Relationship\b(?=[^>]*Type="[^"]*\/header")(?=[^>]*Target="([^"]+)")[^>]*\/>/',
            $relationshipsXml,
            $headerRelationship,
        );
        preg_match(
            '/<Relationship\b(?=[^>]*Type="[^"]*\/footer")(?=[^>]*Target="([^"]+)")[^>]*\/>/',
            $relationshipsXml,
            $footerRelationship,
        );

        $this->assertNotEmpty($headerRelationship[1] ?? null, 'DOCX must relate its document to a header XML part.');
        $this->assertNotEmpty($footerRelationship[1] ?? null, 'DOCX must relate its document to a footer XML part.');

        $headerXml = $zip->getFromName('word/'.$headerRelationship[1]);
        $footerXml = $zip->getFromName('word/'.$footerRelationship[1]);

        $this->assertIsString($headerXml);
        $this->assertIsString($footerXml);
        $this->assertStringContainsString('Ref: '.$reference, $headerXml);
        $this->assertStringContainsString('<v:imagedata', $headerXml);
        $this->assertStringContainsString('<v:imagedata', $footerXml);
        $this->assertStringContainsString('Page ', $footerXml);
        $this->assertMatchesRegularExpression('/<w:instrText\b[^>]*>PAGE<\/w:instrText>/', $footerXml);
        $this->assertMatchesRegularExpression('/<w:instrText\b[^>]*>NUMPAGES<\/w:instrText>/', $footerXml);
        $this->assertStringNotContainsString('riyada', strtolower($documentXml.$relationshipsXml.$headerXml.$footerXml));
    }

    private function normalizePath(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }
}
