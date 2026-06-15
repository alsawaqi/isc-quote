<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
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
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize");

        $response->assertCreated()
            ->assertJsonPath('message', 'Quotation version 1 created.')
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.created_by_name', 'Ahmed Mansoor')
            ->assertJsonPath('data.downloads.docx', "/api/quotations/{$quotationId}/versions/1/download/docx")
            ->assertJsonPath('data.downloads.pdf', "/api/quotations/{$quotationId}/versions/1/download/pdf");

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

        $docxPath = Storage::disk('local')->path($response->json('data.docx_path'));
        $pdfPath = Storage::disk('local')->path($response->json('data.pdf_path'));

        $this->assertFileExists($docxPath);
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));
        $documentXml = $zip->getFromName('word/document.xml');
        $this->assertDocxXmlPartsAreParseable($zip);
        $zip->close();

        $this->assertIsString($documentXml);
        $this->assertStringContainsString('Commercial Offer', $documentXml);
        $this->assertStringContainsString('ISC-COR-QT-', $documentXml);

        $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/docx")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->withBearerToken($context['salesperson'])
            ->get("/api/quotations/{$quotationId}/versions/1/download/pdf")
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
                'closing_at' => '2026-06-02 14:30:00',
                'quotation_validity_value' => 30,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 45,
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
}
