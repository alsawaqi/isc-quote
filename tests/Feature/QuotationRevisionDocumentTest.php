<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\QuotationItem;
use App\Models\QuotationVersion;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use ZipArchive;

class QuotationRevisionDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_can_finalize_a_quotation_into_version_one_with_downloadable_word_and_pdf(): void
    {
        Storage::disk('local')->deleteDirectory('generated/quotations');
        Storage::disk('local')->deleteDirectory('quotation-assets');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);
        // Simulate a legacy quotation. Exact item dates must not leak into a quotation revision.
        QuotationItem::query()
            ->where('quotation_id', $quotationId)
            ->update(['delivery_date' => '2026-09-30']);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize");

        $response->assertCreated()
            ->assertJsonPath('message', 'Quotation version 1 created.')
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.created_by_name', 'Ahmed Mansoor')
            ->assertJsonPath('data.downloads.docx', "/api/quotations/{$quotationId}/versions/1/download/docx")
            ->assertJsonPath('data.downloads.pdf', "/api/quotations/{$quotationId}/versions/1/download/pdf")
            ->assertJsonPath('data.downloads.commercial.docx', "/api/quotations/{$quotationId}/versions/1/download/commercial/docx")
            ->assertJsonPath('data.downloads.commercial.pdf', "/api/quotations/{$quotationId}/versions/1/download/commercial/pdf")
            ->assertJsonPath('data.downloads.technical.docx', "/api/quotations/{$quotationId}/versions/1/download/technical/docx")
            ->assertJsonPath('data.downloads.technical.pdf', "/api/quotations/{$quotationId}/versions/1/download/technical/pdf");

        $this->assertDatabaseHas('quotation_versions', [
            'quotation_id' => $quotationId,
            'version_number' => 1,
            'quotation_reference' => $response->json('data.quotation_reference'),
            'created_by' => $context['salesperson']->id,
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'quotation.version_created',
            'summary' => 'Ahmed Mansoor created quotation version 1.',
        ]);
        $version = QuotationVersion::query()
            ->where('quotation_id', $quotationId)
            ->where('version_number', 1)
            ->firstOrFail();
        $this->assertArrayNotHasKey('delivery_date', $version->snapshot['items'][0]);

        $docxPath = Storage::disk('local')->path($response->json('data.docx_path'));
        $pdfPath = Storage::disk('local')->path($response->json('data.pdf_path'));

        $this->assertFileExists($docxPath);
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));
        $documentXml = $zip->getFromName('word/document.xml');
        $commercialMediaFiles = $this->docxMediaFiles($zip);
        $this->assertDocxXmlPartsAreParseable($zip);
        $this->assertDocxUsesRepeatingPageChrome($zip, (string) $response->json('data.quotation_reference'));
        $zip->close();

        $this->assertIsString($documentXml);
        $this->assertStringContainsString('Commercial Offer', $documentXml);
        $this->assertStringContainsString('ISC-COR-QT-', $documentXml);
        $this->assertStringContainsString('RFQ 6000024422 PR 11729328', $documentXml);
        $this->assertStringContainsString('ABB-FM-001', $documentXml);
        $this->assertStringContainsString('Within 45 days from the date of Invoice.', $documentXml);
        $this->assertStringNotContainsString('30th Sep 2026', $documentXml);
        $this->assertGreaterThanOrEqual(4, count($commercialMediaFiles));
        $this->assertMatchesRegularExpression(
            '/<w:p(?: [^>]*)?>(?:(?!<\/w:p>).)*<v:shape(?:(?!<\/w:p>).)*<v:shape(?:(?!<\/w:p>).)*<\/w:p>/s',
            $documentXml,
            'The commercial stamp and ABB branding should share one compact paragraph.'
        );

        $technicalDocxPath = Storage::disk('local')->path(str_replace('.docx', '-technical.docx', $response->json('data.docx_path')));
        $technicalPdfPath = Storage::disk('local')->path(str_replace('.pdf', '-technical.pdf', $response->json('data.pdf_path')));

        $this->assertFileExists($technicalDocxPath);
        $this->assertFileExists($technicalPdfPath);

        $technicalZip = new ZipArchive;
        $this->assertTrue($technicalZip->open($technicalDocxPath));
        $technicalXml = (string) $technicalZip->getFromName('word/document.xml');
        $technicalMediaFiles = $this->docxMediaFiles($technicalZip);
        $this->assertDocxXmlPartsAreParseable($technicalZip);
        $this->assertDocxUsesRepeatingPageChrome($technicalZip, (string) $response->json('data.quotation_reference'));
        $technicalZip->close();

        $this->assertStringContainsString('Technical Offer', $technicalXml);
        $this->assertStringNotContainsString('30th Sep 2026', $technicalXml);
        $this->assertStringContainsString('ABB-FM-001', $technicalXml);
        $this->assertMatchesRegularExpression(
            '/SL No.*?Material \/ Item Code.*?Description.*?QTY/s',
            $technicalXml,
            'Technical item columns should place Material / Item Code directly after SL No.'
        );
        $this->assertStringNotContainsString('Material / Item Code: ABB-FM-001', $technicalXml);
        $this->assertStringNotContainsString('ACCEPTED TERMS OF PAYMENT', $technicalXml);
        $this->assertStringNotContainsString('ACCEPTED INVOICE CURRENCY', $technicalXml);
        $this->assertStringNotContainsString('Unit Price', $technicalXml);
        $this->assertStringNotContainsString('Total Net Amount', $technicalXml);
        $this->assertGreaterThanOrEqual(3, count($technicalMediaFiles));

        $downloadResponse = $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/docx")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('.DOCX', (string) $downloadResponse->headers->get('content-disposition'));

        $technicalDownloadResponse = $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/technical/docx")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('TECHNICAL', (string) $technicalDownloadResponse->headers->get('content-disposition'));

        $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/technical/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_finalizing_after_changes_creates_a_second_revision_and_detail_shows_timeline(): void
    {
        Storage::disk('local')->deleteDirectory('generated/quotations');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->assertJsonPath('data.version_number', 1);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [
                    [
                        'manufacturer_id' => $context['manufacturer']->id,
                        'product_code' => 'ABB-FM-002',
                        'product_name' => 'Flameproof Motor',
                        'title' => 'ABB Flameproof Motor Updated',
                        'buyer_description' => '<p>Updated buyer-visible description.</p>',
                        'manufacturer_description' => '<p>Updated manufacturer notes.</p>',
                        'quantity' => 2,
                        'uom' => 'EA',
                        'unit_price' => '3256.000',
                    ],
                ],
            ])->assertOk();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->assertJsonPath('data.version_number', 2);

        $detail = $this->withBearerToken($context['salesperson'])
            ->getJson("/api/quotations/{$quotationId}");

        $detail->assertOk()
            ->assertJsonPath('data.versions.0.version_number', 2)
            ->assertJsonPath('data.versions.1.version_number', 1)
            ->assertJsonPath('data.activity_logs.0.action', 'quotation.version_created')
            ->assertJsonPath('data.items.0.title', 'ABB Flameproof Motor Updated');

        $this->assertDatabaseHas('quotation_versions', [
            'quotation_id' => $quotationId,
            'version_number' => 2,
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'quotation.items_updated',
            'summary' => 'Ahmed Mansoor updated quotation products.',
        ]);
    }

    public function test_finalizing_after_non_product_edits_refreshes_latest_version_without_new_revision(): void
    {
        Storage::disk('local')->deleteDirectory('generated/quotations');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $firstVersion = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->assertJsonPath('data.version_number', 1)
            ->json('data');

        $this->withBearerToken($context['salesperson'])
            ->putJson("/api/quotations/{$quotationId}", [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'rfq_number' => '6000024422',
                'pr_number' => '11729328',
                'rfq_title' => 'RFQ 6000024422 PR 11729328',
                'closing_at' => null,
                'quotation_validity_value' => 45,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 45,
                'payment_terms_extra' => 'Revised bank charges note.',
                'payment_customer_type' => 'credit',
                'delivery_period_min' => 22,
                'delivery_period_max' => 24,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'OMR',
                'incoterm_id' => $context['incoterm']->id,
                'delivery_responsibility' => 'isc',
            ])
            ->assertOk();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.revision_action', 'refreshed');

        $this->assertDatabaseCount('quotation_versions', 1);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'quotation.version_refreshed',
        ]);

        $this->assertSame(
            $firstVersion['docx_path'],
            $this->withBearerToken($context['salesperson'])
                ->getJson("/api/quotations/{$quotationId}")
                ->assertOk()
                ->json('data.versions.0.docx_path')
        );
    }

    public function test_minor_description_spelling_correction_does_not_create_revision_but_meaningful_description_change_does(): void
    {
        Storage::disk('local')->deleteDirectory('generated/quotations');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->assertJsonPath('data.version_number', 1);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [
                    [
                        'manufacturer_id' => $context['manufacturer']->id,
                        'product_code' => 'ABB-FM-001',
                        'product_name' => 'Flameproof Motor',
                        'title' => 'ABB Flameproof Motor',
                        'buyer_description' => '<p>ABB Flameproof Motor, Ex db IIB T4 Gb, Zone 1.</p><ul><li>Terminal box location: RSH</li></ul>',
                        'manufacturer_description' => '<p>ABB Flameproof Motor, Ex db IIB T4 Gb, Zone 1.</p><ul><li>Terminal box location: RHS</li><li>Include ABB routine test report.</li></ul>',
                        'quantity' => 1,
                        'uom' => 'EA',
                        'unit_price' => '3256.000',
                    ],
                ],
            ])->assertOk();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.revision_action', 'refreshed');

        $this->assertDatabaseCount('quotation_versions', 1);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [
                    [
                        'manufacturer_id' => $context['manufacturer']->id,
                        'product_code' => 'ABB-FM-001',
                        'product_name' => 'Flameproof Motor',
                        'title' => 'ABB Flameproof Motor',
                        'buyer_description' => '<p>ABB Flameproof Motor with additional hazardous-area accessories and stainless steel terminal hardware.</p>',
                        'manufacturer_description' => '<p>ABB Flameproof Motor, Ex db IIB T4 Gb, Zone 1.</p><ul><li>Terminal box location: RHS</li><li>Include ABB routine test report.</li></ul>',
                        'quantity' => 1,
                        'uom' => 'EA',
                        'unit_price' => '3256.000',
                    ],
                ],
            ])->assertOk();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.revision_action', 'created');

        $this->assertDatabaseCount('quotation_versions', 2);
    }

    public function test_downloading_a_malformed_existing_word_revision_repairs_it_from_the_saved_snapshot(): void
    {
        Storage::disk('local')->deleteDirectory('generated/quotations');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $version = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->json('data');

        Storage::disk('local')->put($version['docx_path'], 'not a valid word package');

        $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/docx")
            ->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($version['docx_path'])));
        $this->assertDocxXmlPartsAreParseable($zip);
        $zip->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationContext(): array
    {
        $country = Country::create([
            'name' => 'Oman',
            'country_code' => 'OM',
            'phone_code' => '+968',
            'status' => 'active',
        ]);
        $designation = Designation::create([
            'name' => 'Mr.',
            'code' => 'MR',
            'status' => 'active',
        ]);
        $supplierCompany = Company::create([
            'country_id' => $country->id,
            'name' => 'Industrial Supplies Center LLC',
            'company_code' => 'ISC',
            'code_slug' => 'isc',
            'company_type' => 'internal',
            'address' => 'PO BOX 39, M.C.C., PC: 101',
            'location' => 'Muscat, Sultanate of Oman',
            'email' => 'sales@isc-depot.com',
            'phone' => '+968 24467233',
            'status' => 'active',
        ]);
        $salesContact = Contact::create([
            'company_id' => $supplierCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Ahmed Mansoor',
            'job_title' => 'Sales Engineer',
            'mobile' => '+968 93895693',
            'telephone' => '+968 24460320',
            'extension' => '106',
            'email' => 'ahmed@example.test',
            'status' => 'active',
        ]);
        Supplier::create([
            'company_id' => $supplierCompany->id,
            'primary_contact_id' => $salesContact->id,
            'status' => 'active',
        ]);

        $salesRole = Role::create([
            'name' => 'Salesperson',
            'slug' => 'salesperson',
            'is_system' => true,
            'status' => 'active',
        ]);
        $salesperson = User::create([
            'name' => 'Ahmed Mansoor',
            'email' => 'sales@example.test',
            'contact_id' => $salesContact->id,
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $salesperson->roles()->attach($salesRole);

        $buyerCompany = Company::create([
            'country_id' => $country->id,
            'name' => 'Occidental of Oman, Inc',
            'company_code' => 'OXY',
            'code_slug' => 'oxy',
            'company_type' => 'buyer',
            'address' => 'PO Box 717, Al Assalah Towers Block 2',
            'location' => 'Ghubrah South, Sultanate of Oman',
            'status' => 'active',
        ]);
        $buyerContact = Contact::create([
            'company_id' => $buyerCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Moosa Ambu Ali',
            'job_title' => 'Supply Chain Management',
            'email' => 'moosa@example.test',
            'status' => 'active',
        ]);
        $incoterm = Incoterm::create([
            'code' => 'DDP',
            'name' => 'Delivered Duty Paid',
            'reminder_days_before_delivery' => 40,
            'status' => 'active',
        ]);
        $manufacturer = Manufacturer::create([
            'country_id' => $country->id,
            'name' => 'ABB LLC',
            'status' => 'active',
        ]);

        return compact(
            'buyerCompany',
            'buyerContact',
            'country',
            'designation',
            'incoterm',
            'manufacturer',
            'salesContact',
            'salesperson',
            'supplierCompany',
        );
    }

    private function createCompleteQuotation(array $context): int
    {
        $quotationId = (int) $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'rfq_number' => '6000024422',
                'pr_number' => '11729328',
                'rfq_title' => 'RFQ 6000024422 PR 11729328',
                'closing_at' => '2026-06-02 14:30:00',
                'quotation_validity_value' => 30,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 45,
                'payment_terms_extra' => 'Any bank charges shall be borne by buyer.',
                'delivery_period_min' => 22,
                'delivery_period_max' => 24,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'OMR',
                'incoterm_id' => $context['incoterm']->id,
                'delivery_responsibility' => 'isc',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [
                    [
                        'manufacturer_id' => $context['manufacturer']->id,
                        'product_code' => 'ABB-FM-001',
                        'product_name' => 'Flameproof Motor',
                        'title' => 'ABB Flameproof Motor',
                        'buyer_description' => '<p>ABB Flameproof Motor, Ex db IIB T4 Gb, Zone 1.</p><ul><li>Terminal box location: RHS</li></ul>',
                        'manufacturer_description' => '<p>ABB Flameproof Motor, Ex db IIB T4 Gb, Zone 1.</p><ul><li>Terminal box location: RHS</li><li>Include ABB routine test report.</li></ul>',
                        'quantity' => 1,
                        'uom' => 'EA',
                        'unit_price' => '3256.000',
                    ],
                ],
            ])->assertOk();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    ['key' => 'cancellation', 'title' => 'Cancellation', 'description' => 'If buyer cancels after acceptance, buyer shall be liable for all costs.'],
                    ['key' => 'scope_of_work', 'title' => 'Scope of Work', 'description' => 'Supply only.'],
                    ['key' => 'delivery_term', 'title' => 'Delivery Terms', 'description' => 'DDP - OXY Yard, Muscat.'],
                    ['key' => 'warranty', 'title' => 'Warranty', 'description' => 'Standard manufacturer warranty applies.'],
                    ['key' => 'force_majeure', 'title' => 'Force Majeure', 'description' => 'Obligations are suspended for causes beyond reasonable control.'],
                ],
            ])->assertOk();

        return $quotationId;
    }

    private function withBearerToken(User $user): self
    {
        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function assertDocxXmlPartsAreParseable(ZipArchive $zip): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (! str_ends_with($name, '.xml')) {
                    continue;
                }

                libxml_clear_errors();
                $xml = $zip->getFromName($name);
                $parsed = simplexml_load_string((string) $xml);

                Assert::assertNotFalse($parsed, "DOCX XML part {$name} is not parseable: ".collect(libxml_get_errors())
                    ->map(fn ($error): string => trim($error->message))
                    ->implode('; '));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
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

    /**
     * @return array<int, string>
     */
    private function docxMediaFiles(ZipArchive $zip): array
    {
        $files = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'word/media/')) {
                $files[] = $name;
            }
        }

        return $files;
    }
}
