<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Quotation;
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

class SupplierPoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_can_create_one_supplier_po_from_items_across_multiple_buyer_pos(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->supplierPoContext();

        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $second = $this->acceptedQuotationItem($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box');
        $draftOnly = $this->draftQuotationItem($context, 'Negotiation Buyer LLC', 'NEG', 'ABB Draft Item');

        $options = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options')
            ->assertOk();

        $options->assertJsonPath('suppliers.0.id', $context['supplier']->id)
            ->assertJsonPath('pending_items.0.quotation_item_id', $second['item']->id)
            ->assertJsonPath('pending_items.1.quotation_item_id', $first['item']->id);
        $this->assertNotContains($draftOnly->id, collect($options->json('pending_items'))->pluck('quotation_item_id')->all());

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', [
                'supplier_id' => $context['supplier']->id,
                'supplier_contact_id' => $context['supplierContact']->id,
                'supplier_quote_reference' => 'E-mail',
                'payment_term_days' => 30,
                'delivery_period_min' => 22,
                'delivery_period_max' => 24,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'USD',
                'incoterm_id' => $context['incoterm']->id,
                'additional_charges_label' => 'COO Charges USD',
                'additional_charges' => '120.000',
                'items' => [
                    ['quotation_item_id' => $first['item']->id, 'unit_cost' => '5041.350'],
                    ['quotation_item_id' => $second['item']->id, 'unit_cost' => '320.000'],
                ],
                'terms' => [
                    ['key' => 'acknowledgment', 'title' => 'Acknowledgment', 'description' => 'Suppliers shall acknowledge receipt of this PO by email within TWO days.'],
                    ['key' => 'delivery_terms', 'title' => 'Delivery Terms', 'description' => 'CPT - Sohar'],
                    ['key' => 'documents', 'title' => 'Documents', 'description' => 'Shipping Documents: Invoice, Packing list, COO, Bill of Lading'],
                    ['key' => 'warranty', 'title' => 'Warranty', 'description' => 'Warranty shall be 12 months from commissioning or 18 months from supply.'],
                    ['key' => 'bank_details', 'title' => 'Bank details', 'description' => 'Payment will be transferred to supplier bank details.'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Supplier PO created with 2 item(s).')
            ->assertJsonPath('data.supplier_company_name', 'ABB LLC')
            ->assertJsonPath('data.lines.0.quotation_id', $first['quotation']->id)
            ->assertJsonPath('data.lines.0.buyer_po_number', '4502757812')
            ->assertJsonPath('data.lines.1.quotation_id', $second['quotation']->id)
            ->assertJsonPath('data.lines.1.buyer_po_number', '4502759999')
            ->assertJsonPath('data.total_amount', '5481.350')
            ->assertJsonPath('data.downloads.docx', "/api/supplier-pos/{$response->json('data.id')}/download/docx")
            ->assertJsonPath('data.downloads.pdf', "/api/supplier-pos/{$response->json('data.id')}/download/pdf");

        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_id' => $first['quotation']->id,
            'buyer_po_id' => $first['buyerPo']->id,
            'quotation_item_id' => $first['item']->id,
        ]);
        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_id' => $second['quotation']->id,
            'buyer_po_id' => $second['buyerPo']->id,
            'quotation_item_id' => $second['item']->id,
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $first['quotation']->id,
            'action' => 'supplier_po.created',
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $second['quotation']->id,
            'action' => 'supplier_po.created',
        ]);

        $docxPath = Storage::disk('local')->path($response->json('data.docx_path'));
        $pdfPath = Storage::disk('local')->path($response->json('data.pdf_path'));

        $this->assertFileExists($docxPath);
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));
        $this->assertDocxXmlPartsAreParseable($zip);
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('Purchase Order', (string) $documentXml);
        $this->assertStringContainsString('ABB Flameproof Motor', (string) $documentXml);

        $listResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos')
            ->assertOk();

        $listResponse->assertJsonPath('data.0.id', $response->json('data.id'))
            ->assertJsonPath('data.0.po_reference', $response->json('data.po_reference'))
            ->assertJsonPath('data.0.supplier_company_name', 'ABB LLC')
            ->assertJsonPath('data.0.lines_count', 2)
            ->assertJsonPath('data.0.total_amount', '5481.350')
            ->assertJsonPath('data.0.downloads.docx', "/api/supplier-pos/{$response->json('data.id')}/download/docx");

        $detailResponse = $this->withBearerToken($context['salesperson'])
            ->getJson("/api/supplier-pos/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'))
            ->assertJsonPath('data.supplier_id', $context['supplier']->id)
            ->assertJsonPath('data.lines.0.quotation_item_id', $first['item']->id)
            ->assertJsonPath('data.lines.1.quotation_item_id', $second['item']->id)
            ->assertJsonPath('data.terms.0.title', 'Acknowledgment');

        $updateResponse = $this->withBearerToken($context['salesperson'])
            ->putJson("/api/supplier-pos/{$response->json('data.id')}", [
                'supplier_id' => $context['supplier']->id,
                'supplier_contact_id' => $context['supplierContact']->id,
                'supplier_quote_reference' => 'Updated supplier quote',
                'payment_term_days' => 45,
                'delivery_period_min' => 10,
                'delivery_period_max' => 12,
                'delivery_period_unit' => 'days',
                'delivery_period_type' => 'calendar',
                'accepted_invoice_currency' => 'USD',
                'incoterm_id' => $context['incoterm']->id,
                'additional_charges_label' => 'Updated Charges',
                'additional_charges' => '50.000',
                'items' => [
                    ['quotation_item_id' => $first['item']->id, 'unit_cost' => '5000.000'],
                    ['quotation_item_id' => $second['item']->id, 'unit_cost' => '400.000'],
                ],
                'terms' => [
                    ['key' => 'acknowledgment', 'title' => 'Updated Acknowledgment', 'description' => 'Supplier must acknowledge the revised PO.'],
                    ['key' => 'documents', 'title' => 'Updated Documents', 'description' => 'Updated shipping documents are required.'],
                ],
            ]);

        $updateResponse->assertOk()
            ->assertJsonPath('message', 'Supplier PO updated successfully.')
            ->assertJsonPath('data.id', $response->json('data.id'))
            ->assertJsonPath('data.po_reference', $response->json('data.po_reference'))
            ->assertJsonPath('data.supplier_quote_reference', 'Updated supplier quote')
            ->assertJsonPath('data.payment_term_days', 45)
            ->assertJsonPath('data.delivery_period_unit', 'days')
            ->assertJsonPath('data.delivery_period_type', 'calendar')
            ->assertJsonPath('data.additional_charges_label', 'Updated Charges')
            ->assertJsonPath('data.total_amount', '5450.000')
            ->assertJsonPath('data.lines.0.unit_cost', '5000.000')
            ->assertJsonPath('data.lines.1.unit_cost', '400.000');

        $this->assertDatabaseHas('supplier_pos', [
            'id' => $response->json('data.id'),
            'supplier_quote_reference' => 'Updated supplier quote',
            'payment_term_days' => 45,
            'subtotal' => '5400.000',
            'total_amount' => '5450.000',
        ]);
        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_item_id' => $first['item']->id,
            'unit_cost' => '5000.000',
            'total_cost' => '5000.000',
        ]);
        $this->assertDatabaseHas('supplier_po_terms', [
            'supplier_po_id' => $response->json('data.id'),
            'title' => 'Updated Documents',
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $first['quotation']->id,
            'action' => 'supplier_po.updated',
        ]);

        $updatedDocxPath = Storage::disk('local')->path($updateResponse->json('data.docx_path'));
        $updatedZip = new ZipArchive;
        $this->assertTrue($updatedZip->open($updatedDocxPath));
        $updatedDocumentXml = $updatedZip->getFromName('word/document.xml');
        $updatedZip->close();

        $this->assertStringContainsString('Updated Documents', (string) $updatedDocumentXml);

        $afterCreateOptions = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options')
            ->assertOk();

        $this->assertNotContains($first['item']->id, collect($afterCreateOptions->json('pending_items'))->pluck('quotation_item_id')->all());
        $this->assertNotContains($second['item']->id, collect($afterCreateOptions->json('pending_items'))->pluck('quotation_item_id')->all());
    }

    public function test_supplier_po_items_are_limited_to_the_supplier_linked_manufacturer(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->supplierPoContext();

        $abbItem = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $otherManufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'Siemens Manufacturing',
            'status' => 'active',
        ]);
        $siemensItem = $this->acceptedQuotationItem(
            $context,
            'Global Petrochem Ltd.',
            'GPL',
            '4502759999',
            'Siemens Control Relay',
            $otherManufacturer
        );

        $options = $this->withBearerToken($context['salesperson'])
            ->getJson("/api/supplier-pos/create-options?supplier_id={$context['supplier']->id}")
            ->assertOk();

        $pendingItemIds = collect($options->json('pending_items'))->pluck('quotation_item_id')->all();

        $this->assertContains($abbItem['item']->id, $pendingItemIds);
        $this->assertNotContains($siemensItem['item']->id, $pendingItemIds);

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', [
                'supplier_id' => $context['supplier']->id,
                'supplier_contact_id' => $context['supplierContact']->id,
                'supplier_quote_reference' => 'Wrong manufacturer attempt',
                'payment_term_days' => 30,
                'delivery_period_min' => 22,
                'delivery_period_max' => 24,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'USD',
                'incoterm_id' => $context['incoterm']->id,
                'additional_charges_label' => null,
                'additional_charges' => '0.000',
                'items' => [
                    ['quotation_item_id' => $siemensItem['item']->id, 'unit_cost' => '250.000'],
                ],
                'terms' => [
                    ['key' => 'acknowledgment', 'title' => 'Acknowledgment', 'description' => 'Suppliers shall acknowledge receipt of this PO by email within TWO days.'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseMissing('supplier_po_lines', [
            'quotation_item_id' => $siemensItem['item']->id,
        ]);
    }

    public function test_supplier_po_create_options_can_filter_pending_items_for_incremental_selection(): void
    {
        $context = $this->supplierPoContext();

        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $first['buyerPo']->forceFill(['po_date' => '2026-06-05'])->save();
        $second = $this->acceptedQuotationItem($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box');
        $second['buyerPo']->forceFill(['po_date' => '2026-07-12'])->save();
        $closed = $this->acceptedQuotationItem($context, 'Closed Buyer LLC', 'CBL', '4502761111', 'ABB Closed Order');
        $closed['quotation']->forceFill(['status' => 'closed'])->save();
        $otherManufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'Siemens Manufacturing',
            'status' => 'active',
        ]);
        $siemens = $this->acceptedQuotationItem($context, 'Desert Energy FZE', 'DEF', '4502762222', 'Siemens Control Relay', $otherManufacturer);

        $searchResponse = $this->withBearerToken($context['salesperson'])
            ->getJson("/api/supplier-pos/create-options?supplier_id={$context['supplier']->id}&search=OXY")
            ->assertOk();
        $searchIds = collect($searchResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($first['item']->id, $searchIds);
        $this->assertNotContains($second['item']->id, $searchIds);

        $quotationResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options?quotation_reference='.$second['quotation']->quotation_reference)
            ->assertOk();
        $quotationIds = collect($quotationResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($second['item']->id, $quotationIds);
        $this->assertNotContains($first['item']->id, $quotationIds);

        $buyerResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options?buyer_id='.$first['quotation']->buyer_company_id)
            ->assertOk();
        $buyerIds = collect($buyerResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($first['item']->id, $buyerIds);
        $this->assertNotContains($second['item']->id, $buyerIds);

        $dateResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options?buyer_po_date_from=2026-07-01&buyer_po_date_to=2026-07-31')
            ->assertOk();
        $dateIds = collect($dateResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($second['item']->id, $dateIds);
        $this->assertNotContains($first['item']->id, $dateIds);

        $manufacturerResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options?manufacturer_id='.$otherManufacturer->id)
            ->assertOk();
        $manufacturerIds = collect($manufacturerResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($siemens['item']->id, $manufacturerIds);
        $this->assertNotContains($first['item']->id, $manufacturerIds);

        $currentResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options?current_only=1')
            ->assertOk();
        $currentIds = collect($currentResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($first['item']->id, $currentIds);
        $this->assertNotContains($closed['item']->id, $currentIds);
        $this->assertContains(
            (string) $first['quotation']->buyer_company_id,
            collect($currentResponse->json('pending_item_filters.buyers'))->pluck('value')->all()
        );
        $this->assertContains(
            (string) $context['manufacturer']->id,
            collect($currentResponse->json('pending_item_filters.manufacturers'))->pluck('value')->all()
        );
    }

    public function test_admin_without_user_contact_can_load_supplier_po_create_options_from_internal_company_fallback(): void
    {
        $context = $this->supplierPoContext();
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'is_system' => true,
            'status' => 'active',
        ]);
        $admin = User::create([
            'name' => 'Ahmed Mansoor',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $admin->roles()->attach($adminRole);

        $this->withBearerToken($admin)
            ->getJson('/api/supplier-pos/create-options')
            ->assertOk()
            ->assertJsonPath('buyer.company_id', $context['buyerCompany']->id)
            ->assertJsonPath('buyer.company_name', 'Industrial Supplies Center LLC')
            ->assertJsonPath('buyer.contact_id', $context['buyerContact']->id)
            ->assertJsonPath('buyer.contact_name', 'Manu Thuruthel');
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPoContext(): array
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
        $buyerCompany = Company::create([
            'country_id' => $country->id,
            'name' => 'Industrial Supplies Center LLC',
            'company_code' => 'ISC',
            'code_slug' => 'isc',
            'company_type' => 'internal',
            'address' => 'PO BOX 39, POSTAL CODE: 101',
            'location' => 'MUSCAT - SULTANATE OF OMAN',
            'status' => 'active',
        ]);
        $buyerContact = Contact::create([
            'company_id' => $buyerCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Manu Thuruthel',
            'mobile' => '+96893895693',
            'telephone' => '+968 24460320',
            'email' => 'manu@isc-depot.com',
            'status' => 'active',
        ]);
        $supplierCompany = Company::create([
            'country_id' => $country->id,
            'name' => 'ABB LLC',
            'company_code' => 'ABB',
            'code_slug' => 'abb',
            'company_type' => 'supplier',
            'address' => '305 Hatat Complex B',
            'location' => 'Muscat, Oman',
            'status' => 'active',
        ]);
        $supplierContact = Contact::create([
            'company_id' => $supplierCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Omid',
            'job_title' => 'Sales Support',
            'email' => 'omid.nilchian@om.abb.com',
            'status' => 'active',
        ]);
        $manufacturer = Manufacturer::create([
            'country_id' => $country->id,
            'name' => 'ABB LLC',
            'status' => 'active',
        ]);
        $supplier = Supplier::create([
            'company_id' => $supplierCompany->id,
            'primary_contact_id' => $supplierContact->id,
            'manufacturer_id' => $manufacturer->id,
            'status' => 'active',
        ]);
        Supplier::create([
            'company_id' => $buyerCompany->id,
            'primary_contact_id' => $buyerContact->id,
            'status' => 'active',
        ]);

        $salesRole = Role::create([
            'name' => 'Salesperson',
            'slug' => 'salesperson',
            'is_system' => true,
            'status' => 'active',
        ]);
        $salesperson = User::create([
            'name' => 'Manu Thuruthel',
            'email' => 'sales@example.test',
            'contact_id' => $buyerContact->id,
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $salesperson->roles()->attach($salesRole);
        $incoterm = Incoterm::create([
            'code' => 'CPT',
            'name' => 'Carriage Paid To',
            'reminder_days_before_delivery' => 30,
            'status' => 'active',
        ]);

        return compact('buyerCompany', 'buyerContact', 'country', 'designation', 'incoterm', 'manufacturer', 'salesperson', 'supplier', 'supplierContact');
    }

    /**
     * @return array{quotation: Quotation, item: QuotationItem, buyerPo: BuyerPo}
     */
    private function acceptedQuotationItem(array $context, string $buyerName, string $buyerCode, string $buyerPoNumber, string $title, ?Manufacturer $manufacturer = null): array
    {
        $buyerCompany = Company::create([
            'country_id' => $context['country']->id,
            'name' => $buyerName,
            'company_code' => $buyerCode,
            'code_slug' => strtolower($buyerCode),
            'company_type' => 'buyer',
            'status' => 'active',
        ]);
        $buyerContact = Contact::create([
            'company_id' => $buyerCompany->id,
            'designation_id' => $context['designation']->id,
            'name' => "{$buyerCode} Contact",
            'email' => strtolower($buyerCode).'@example.test',
            'status' => 'active',
        ]);
        $quotation = $this->quotation($context, $buyerCompany, $buyerContact, 'buyer_po_received');
        $item = $this->quotationItem($context, $quotation, $title, $manufacturer);
        $version = QuotationVersion::create([
            'quotation_id' => $quotation->id,
            'version_number' => 1,
            'quotation_reference' => $quotation->quotation_reference,
            'snapshot' => ['quotation' => ['reference' => $quotation->quotation_reference]],
            'docx_path' => "generated/quotations/{$quotation->id}/revision-1/test.docx",
            'pdf_path' => "generated/quotations/{$quotation->id}/revision-1/test.pdf",
            'created_by' => $context['salesperson']->id,
            'finalized_at' => now(),
        ]);
        $buyerPo = BuyerPo::create([
            'quotation_id' => $quotation->id,
            'quotation_version_id' => $version->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'po_number' => $buyerPoNumber,
            'po_date' => now()->toDateString(),
            'po_value' => $item->total_price,
            'currency' => 'OMR',
            'po_file_path' => "buyer-pos/{$quotation->id}/{$buyerPoNumber}.pdf",
            'created_by' => $context['salesperson']->id,
            'status' => 'received',
        ]);

        return compact('buyerPo', 'item', 'quotation');
    }

    private function draftQuotationItem(array $context, string $buyerName, string $buyerCode, string $title): QuotationItem
    {
        $buyerCompany = Company::create([
            'country_id' => $context['country']->id,
            'name' => $buyerName,
            'company_code' => $buyerCode,
            'code_slug' => strtolower($buyerCode),
            'company_type' => 'buyer',
            'status' => 'active',
        ]);
        $buyerContact = Contact::create([
            'company_id' => $buyerCompany->id,
            'designation_id' => $context['designation']->id,
            'name' => "{$buyerCode} Contact",
            'email' => strtolower($buyerCode).'@example.test',
            'status' => 'active',
        ]);

        return $this->quotationItem($context, $this->quotation($context, $buyerCompany, $buyerContact, 'issued'), $title);
    }

    private function quotation(array $context, Company $buyerCompany, Contact $buyerContact, string $status): Quotation
    {
        $quotation = Quotation::create([
            'quotation_reference' => 'PENDING',
            'salesperson_id' => $context['salesperson']->id,
            'supplier_company_id' => $context['buyerCompany']->id,
            'supplier_contact_id' => $context['buyerContact']->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'rfq_number' => '6000024422',
            'pr_number' => '11729328',
            'closing_at' => now(),
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
            'status' => $status,
        ]);
        $quotation->forceFill(['quotation_reference' => "ISC-COR-QT-{$quotation->id}-{$buyerCompany->company_code}-26"])->save();

        return $quotation;
    }

    private function quotationItem(array $context, Quotation $quotation, string $title, ?Manufacturer $manufacturer = null): QuotationItem
    {
        $manufacturer ??= $context['manufacturer'];
        $product = Product::create([
            'manufacturer_id' => $manufacturer->id,
            'name' => $title,
            'title' => $title,
            'buyer_description' => '<p>Buyer visible description.</p>',
            'manufacturer_description' => '<p>Supplier-visible technical description with internal feature codes.</p>',
            'last_uom' => 'EA',
            'last_unit_price' => '3256.000',
            'status' => 'active',
        ]);

        return QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'manufacturer_id' => $manufacturer->id,
            'line_number' => 1,
            'product_name' => $product->name,
            'title' => $title,
            'buyer_description' => $product->buyer_description,
            'manufacturer_description' => $product->manufacturer_description,
            'quantity' => '1.000',
            'uom' => 'EA',
            'unit_price' => '3256.000',
            'total_price' => '3256.000',
        ]);
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
                $parsed = simplexml_load_string((string) $zip->getFromName($name));

                Assert::assertNotFalse($parsed, "DOCX XML part {$name} is not parseable.");
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
