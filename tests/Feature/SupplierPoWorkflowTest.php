<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\CompanyLocation;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationVersion;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\User;
use App\Services\SupplierPoDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use ZipArchive;

class SupplierPoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_currency_is_not_available_for_supplier_purchase_orders(): void
    {
        $context = $this->supplierPoContext();

        Currency::query()->where('code', 'USD')->update(['status' => 'inactive']);

        $response = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options')
            ->assertOk();

        $this->assertNotContains('USD', collect($response->json('currencies'))->pluck('id')->all());
    }

    public function test_supplier_po_does_not_prefill_a_legacy_quotation_delivery_date(): void
    {
        $context = $this->supplierPoContext();
        $accepted = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $accepted['item']->forceFill(['delivery_date' => '2026-09-30'])->save();

        $options = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options')
            ->assertOk();
        $pendingItem = collect($options->json('pending_items'))->firstWhere('quotation_item_id', $accepted['item']->id);

        $this->assertIsArray($pendingItem);
        $this->assertArrayNotHasKey('delivery_date', $pendingItem);

        $payload = $this->supplierPoRequestPayload($context, $accepted['item']);
        unset($payload['items'][0]['delivery_date']);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.delivery_date', null);

        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_item_id' => $accepted['item']->id,
            'delivery_date' => null,
        ]);
    }

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
                    [
                        'quotation_item_id' => $first['item']->id,
                        'unit_cost' => '5041.350',
                        'item_description' => '<p>Supplier motor description.</p>',
                        'delivery_date' => '2026-10-15',
                        'incoterm_id' => $context['incoterm']->id,
                        'coo_entries' => [
                            [
                                'country_id' => $context['country']->id,
                                'amount' => '1.000',
                                'location' => 'Sohar Factory',
                            ],
                            [
                                'country_name' => 'United Arab Emirates',
                                'amount' => '2.000',
                                'location' => 'Jebel Ali',
                            ],
                        ],
                    ],
                    [
                        'quotation_item_id' => $second['item']->id,
                        'unit_cost' => '320.000',
                        'item_description' => '<p>Supplier terminal box description.</p>',
                        'delivery_date' => '2026-10-20',
                        'incoterm_id' => $context['incoterm']->id,
                        'coo_entries' => [
                            [
                                'country_id' => $context['country']->id,
                                'amount' => '1.000',
                                'location' => 'Muscat Warehouse',
                            ],
                        ],
                    ],
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
            ->assertJsonPath('data.revision_number', 1)
            ->assertJsonPath('data.lines.0.quotation_id', $first['quotation']->id)
            ->assertJsonPath('data.lines.0.buyer_po_number', '4502757812')
            ->assertJsonPath('data.lines.0.delivery_date', '2026-10-15')
            ->assertJsonPath('data.lines.0.incoterm_id', $context['incoterm']->id)
            ->assertJsonPath('data.lines.0.incoterm_code', 'CPT')
            ->assertJsonPath('data.lines.0.coo_entries.0.country_name', 'Oman')
            ->assertJsonPath('data.lines.0.coo_entries.0.amount', '1.000')
            ->assertJsonPath('data.lines.0.coo_entries.0.location', 'Sohar Factory')
            ->assertJsonPath('data.lines.0.coo_entries.1.country_name', 'United Arab Emirates')
            ->assertJsonPath('data.lines.1.quotation_id', $second['quotation']->id)
            ->assertJsonPath('data.lines.1.buyer_po_number', '4502759999')
            ->assertJsonPath('data.lines.1.delivery_date', '2026-10-20')
            ->assertJsonPath('data.total_amount', '5481.350')
            ->assertJsonPath('data.revisions.0.revision_number', 1)
            ->assertJsonPath('data.downloads.docx', "/api/supplier-pos/{$response->json('data.id')}/download/docx")
            ->assertJsonPath('data.downloads.pdf', "/api/supplier-pos/{$response->json('data.id')}/download/pdf");

        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_id' => $first['quotation']->id,
            'buyer_po_id' => $first['buyerPo']->id,
            'quotation_item_id' => $first['item']->id,
            'product_code' => $first['item']->product_code,
            'item_description' => '<p>Supplier motor description.</p>',
            'delivery_date' => '2026-10-15 00:00:00',
            'incoterm_id' => $context['incoterm']->id,
        ]);
        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_id' => $second['quotation']->id,
            'buyer_po_id' => $second['buyerPo']->id,
            'quotation_item_id' => $second['item']->id,
            'delivery_date' => '2026-10-20 00:00:00',
            'incoterm_id' => $context['incoterm']->id,
        ]);
        $this->assertDatabaseHas('supplier_po_line_origins', [
            'country_id' => $context['country']->id,
            'country_name' => 'Oman',
            'amount' => '1.000',
            'location' => 'Sohar Factory',
            'line_number' => 1,
        ]);
        $this->assertDatabaseHas('supplier_po_line_origins', [
            'country_id' => null,
            'country_name' => 'United Arab Emirates',
            'amount' => '2.000',
            'location' => 'Jebel Ali',
            'line_number' => 2,
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $first['quotation']->id,
            'action' => 'supplier_po.created',
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $second['quotation']->id,
            'action' => 'supplier_po.created',
        ]);
        $this->assertDatabaseHas('supplier_pos', [
            'id' => $response->json('data.id'),
            'revision_number' => 1,
        ]);
        $this->assertDatabaseHas('supplier_po_revisions', [
            'supplier_po_id' => $response->json('data.id'),
            'revision_number' => 1,
            'po_reference' => $response->json('data.po_reference'),
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
        $this->assertDocxUsesRepeatingPageChrome($zip, (string) $response->json('data.po_reference'));
        $zip->close();

        $this->assertStringContainsString('Purchase Order', (string) $documentXml);
        $this->assertStringContainsString('Revision:', (string) $documentXml);
        $this->assertStringContainsString('Material / Item Code', (string) $documentXml);
        $this->assertStringContainsString('ABB Flameproof Motor', (string) $documentXml);
        $this->assertStringContainsString('Delivery Date: 15th Oct 2026', (string) $documentXml);
        $this->assertStringContainsString('Incoterm: CPT', (string) $documentXml);
        $this->assertStringContainsString('Country of Origin: Oman', (string) $documentXml);
        $this->assertStringContainsString('Location: Sohar Factory', (string) $documentXml);

        $listResponse = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos')
            ->assertOk();

        $listResponse->assertJsonPath('data.0.id', $response->json('data.id'))
            ->assertJsonPath('data.0.po_reference', $response->json('data.po_reference'))
            ->assertJsonPath('data.0.revision_number', 1)
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
            ->assertJsonPath('data.terms.0.title', 'Acknowledgment')
            ->assertJsonPath('data.revisions.0.revision_number', 1);

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
                    [
                        'quotation_item_id' => $first['item']->id,
                        'unit_cost' => '5000.000',
                        'item_description' => '<p>Updated motor supplier description.</p>',
                        'delivery_date' => '2026-11-01',
                        'incoterm_id' => $context['incoterm']->id,
                        'coo_entries' => [
                            [
                                'country_id' => $context['country']->id,
                                'amount' => '1.000',
                                'location' => 'Updated Sohar Factory',
                            ],
                        ],
                    ],
                    [
                        'quotation_item_id' => $second['item']->id,
                        'unit_cost' => '400.000',
                        'item_description' => '<p>Updated terminal box description.</p>',
                        'delivery_date' => '2026-11-05',
                        'incoterm_id' => $context['incoterm']->id,
                    ],
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
            ->assertJsonPath('data.revision_number', 2)
            ->assertJsonPath('data.supplier_quote_reference', 'Updated supplier quote')
            ->assertJsonPath('data.payment_term_days', 45)
            ->assertJsonPath('data.delivery_period_unit', 'days')
            ->assertJsonPath('data.delivery_period_type', 'calendar')
            ->assertJsonPath('data.additional_charges_label', 'Updated Charges')
            ->assertJsonPath('data.total_amount', '5450.000')
            ->assertJsonPath('data.lines.0.unit_cost', '5000.000')
            ->assertJsonPath('data.lines.0.delivery_date', '2026-11-01')
            ->assertJsonPath('data.lines.0.coo_entries.0.location', 'Updated Sohar Factory')
            ->assertJsonPath('data.lines.1.unit_cost', '400.000')
            ->assertJsonPath('data.lines.1.delivery_date', '2026-11-05');

        $this->assertDatabaseHas('supplier_pos', [
            'id' => $response->json('data.id'),
            'supplier_quote_reference' => 'Updated supplier quote',
            'revision_number' => 2,
            'payment_term_days' => 45,
            'subtotal' => '5400.000',
            'total_amount' => '5450.000',
        ]);
        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $response->json('data.id'),
            'quotation_item_id' => $first['item']->id,
            'unit_cost' => '5000.000',
            'total_cost' => '5000.000',
            'delivery_date' => '2026-11-01 00:00:00',
            'incoterm_id' => $context['incoterm']->id,
        ]);
        $this->assertDatabaseHas('supplier_po_line_origins', [
            'country_id' => $context['country']->id,
            'country_name' => 'Oman',
            'amount' => '1.000',
            'location' => 'Updated Sohar Factory',
            'line_number' => 1,
        ]);
        $this->assertDatabaseHas('supplier_po_terms', [
            'supplier_po_id' => $response->json('data.id'),
            'title' => 'Updated Documents',
            'line_number' => 2,
        ]);
        $this->assertDatabaseHas('supplier_po_revisions', [
            'supplier_po_id' => $response->json('data.id'),
            'revision_number' => 2,
            'po_reference' => $response->json('data.po_reference'),
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
        $this->assertStringContainsString('2. Updated Documents:', (string) $updatedDocumentXml);
        $this->assertStringContainsString('Updated Sohar Factory', (string) $updatedDocumentXml);

        $revisionDownloadName = Str::upper(Str::slug($response->json('data.po_reference'), '-').'-rev-1.pdf');
        $this->withBearerToken($context['salesperson'])
            ->get("/api/supplier-pos/{$response->json('data.id')}/revisions/1/download/pdf")
            ->assertOk()
            ->assertDownload($revisionDownloadName);

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

    public function test_create_options_expose_multi_manufacturer_factories_and_supplier_contact_scope(): void
    {
        $context = $this->supplierPoContext();
        $abbItem = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $secondManufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'Baldor Manufacturing',
            'status' => 'active',
        ]);
        $baldorItem = $this->acceptedQuotationItem(
            $context,
            'Global Petrochem Ltd.',
            'GPL',
            '4502759999',
            'Baldor Motor',
            $secondManufacturer,
        );
        $unlinkedManufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'Unlinked Manufacturing',
            'status' => 'active',
        ]);
        $unlinkedItem = $this->acceptedQuotationItem(
            $context,
            'Desert Energy FZE',
            'DEF',
            '4502760000',
            'Unlinked Relay',
            $unlinkedManufacturer,
        );

        $context['supplier']->manufacturers()->sync([
            $context['manufacturer']->id,
            $secondManufacturer->id,
        ]);

        $factory = CompanyLocation::create([
            'company_id' => $context['supplier']->company_id,
            'manufacturer_id' => $context['manufacturer']->id,
            'country_id' => $context['country']->id,
            'location_type' => 'factory',
            'name' => 'Muscat Assembly Plant',
            'location' => 'Muscat, Oman',
            'address' => 'Industrial Estate, Muscat',
            'status' => 'active',
        ]);
        CompanyLocation::create([
            'company_id' => $context['supplier']->company_id,
            'manufacturer_id' => $secondManufacturer->id,
            'country_id' => $context['country']->id,
            'location_type' => 'factory',
            'name' => 'Closed Plant',
            'location' => 'Sohar, Oman',
            'status' => 'inactive',
        ]);

        $supplierRoleContact = Contact::create([
            'company_id' => $context['supplier']->company_id,
            'designation_id' => $context['designation']->id,
            'name' => 'Factory Sales Contact',
            'email' => 'factory@example.test',
            'serves_supplier' => true,
            'all_locations' => false,
            'is_primary_supplier' => true,
            'status' => 'active',
        ]);
        $supplierRoleContact->locations()->attach($factory->id);
        $buyerOnlyContact = Contact::create([
            'company_id' => $context['supplier']->company_id,
            'designation_id' => $context['designation']->id,
            'name' => 'Buyer Only Contact',
            'email' => 'buyer-only@example.test',
            'serves_buyer' => true,
            'serves_supplier' => false,
            'status' => 'active',
        ]);

        $response = $this->withBearerToken($context['salesperson'])
            ->getJson("/api/supplier-pos/create-options?supplier_id={$context['supplier']->id}")
            ->assertOk();

        $supplier = collect($response->json('suppliers'))->firstWhere('id', $context['supplier']->id);
        $this->assertNotNull($supplier);
        $this->assertEqualsCanonicalizing(
            [$context['manufacturer']->id, $secondManufacturer->id],
            collect($supplier['manufacturers'])->pluck('id')->all(),
        );
        $this->assertTrue($supplier['requires_factory']);
        $this->assertSame([$factory->id], collect($supplier['locations'])->pluck('id')->all());

        $contacts = collect($response->json('supplier_contacts'));
        $this->assertContains($supplierRoleContact->id, $contacts->pluck('id')->all());
        $this->assertNotContains($buyerOnlyContact->id, $contacts->pluck('id')->all());
        $this->assertNotContains($context['supplierContact']->id, $contacts->pluck('id')->all());
        $this->assertFalse($contacts->firstWhere('id', $supplierRoleContact->id)['all_locations']);
        $this->assertSame([$factory->id], $contacts->firstWhere('id', $supplierRoleContact->id)['location_ids']);

        $pendingItemIds = collect($response->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($abbItem['item']->id, $pendingItemIds);
        $this->assertContains($baldorItem['item']->id, $pendingItemIds);
        $this->assertNotContains($unlinkedItem['item']->id, $pendingItemIds);

        $factoryResponse = $this->withBearerToken($context['salesperson'])
            ->getJson("/api/supplier-pos/create-options?supplier_id={$context['supplier']->id}&company_location_id={$factory->id}")
            ->assertOk();
        $factoryPendingItemIds = collect($factoryResponse->json('pending_items'))->pluck('quotation_item_id')->all();
        $this->assertContains($abbItem['item']->id, $factoryPendingItemIds);
        $this->assertNotContains($baldorItem['item']->id, $factoryPendingItemIds);
    }

    public function test_supplier_po_requires_a_valid_factory_and_contact_scope_when_factories_exist(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->supplierPoContext();
        $accepted = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $assignedFactory = CompanyLocation::create([
            'company_id' => $context['supplier']->company_id,
            'manufacturer_id' => $context['manufacturer']->id,
            'country_id' => $context['country']->id,
            'location_type' => 'factory',
            'name' => 'Assigned Factory',
            'location' => 'Muscat, Oman',
            'status' => 'active',
        ]);
        $unassignedFactory = CompanyLocation::create([
            'company_id' => $context['supplier']->company_id,
            'manufacturer_id' => $context['manufacturer']->id,
            'country_id' => $context['country']->id,
            'location_type' => 'factory',
            'name' => 'Unassigned Factory',
            'location' => 'Sohar, Oman',
            'status' => 'active',
        ]);
        $otherCompanyFactory = CompanyLocation::create([
            'company_id' => $context['buyerCompany']->id,
            'country_id' => $context['country']->id,
            'location_type' => 'factory',
            'name' => 'Other Company Factory',
            'location' => 'Nizwa, Oman',
            'status' => 'active',
        ]);
        $context['supplierContact']->forceFill([
            'serves_supplier' => true,
            'all_locations' => false,
        ])->save();
        $context['supplierContact']->locations()->attach($assignedFactory->id);

        $secondManufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'Second Factory Manufacturer',
            'status' => 'active',
        ]);
        $context['supplier']->manufacturers()->sync([
            $context['manufacturer']->id,
            $secondManufacturer->id,
        ]);
        $mismatched = $this->acceptedQuotationItem(
            $context,
            'Factory Mismatch Buyer LLC',
            'FMB',
            '4502761111',
            'Second Manufacturer Motor',
            $secondManufacturer,
        );

        $payload = $this->supplierPoRequestPayload($context, $accepted['item']);

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_location_id']);

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', [
                ...$payload,
                'company_location_id' => $otherCompanyFactory->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_location_id']);

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', [
                ...$payload,
                'company_location_id' => $unassignedFactory->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_contact_id']);

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', [
                ...$this->supplierPoRequestPayload($context, $mismatched['item']),
                'company_location_id' => $assignedFactory->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.company_location_id']);

        $created = $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', [
                ...$payload,
                'company_location_id' => $assignedFactory->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_location_id', $assignedFactory->id)
            ->assertJsonPath('data.factory.id', $assignedFactory->id)
            ->assertJsonPath('data.factory.name', 'Assigned Factory')
            ->assertJsonPath('data.lines.0.company_location_id', $assignedFactory->id)
            ->assertJsonPath('data.lines.0.factory_name', 'Assigned Factory');

        $this->assertDatabaseHas('supplier_pos', [
            'id' => $created->json('data.id'),
            'company_location_id' => $assignedFactory->id,
        ]);
        $this->assertDatabaseHas('supplier_po_lines', [
            'supplier_po_id' => $created->json('data.id'),
            'quotation_item_id' => $accepted['item']->id,
            'company_location_id' => $assignedFactory->id,
        ]);
        $snapshot = app(SupplierPoDocumentService::class)
            ->snapshot(SupplierPo::query()->findOrFail($created->json('data.id')));
        $this->assertSame($assignedFactory->id, $snapshot['factory']['id']);
        $this->assertSame('Assigned Factory', $snapshot['factory']['name']);
        $this->assertSame($assignedFactory->id, $snapshot['items'][0]['factory']['id']);
        $this->assertStringContainsString('Assigned Factory', $snapshot['items'][0]['factory_label']);

        $this->withBearerToken($context['salesperson'])
            ->putJson("/api/supplier-pos/{$created->json('data.id')}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['company_location_id']);

        $context['supplier']->update(['status' => 'inactive']);
        $context['supplier']->company()->update(['status' => 'inactive']);
        $context['supplierContact']->update(['status' => 'inactive']);
        $assignedFactory->update(['status' => 'inactive']);

        $this->withBearerToken($context['salesperson'])
            ->getJson("/api/supplier-pos/create-options?supplier_po_id={$created->json('data.id')}")
            ->assertOk()
            ->assertJsonFragment(['id' => $context['supplier']->id, 'company_name' => $context['supplier']->company->name])
            ->assertJsonFragment(['id' => $assignedFactory->id, 'name' => 'Assigned Factory', 'status' => 'inactive']);

        $this->withBearerToken($context['salesperson'])
            ->putJson("/api/supplier-pos/{$created->json('data.id')}", [
                ...$payload,
                'company_location_id' => $assignedFactory->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.company_location_id', $assignedFactory->id);
    }

    public function test_unconfigured_supplier_without_an_active_manufacturer_cannot_receive_items(): void
    {
        $context = $this->supplierPoContext();
        $accepted = $this->acceptedQuotationItem($context, 'Configured Buyer LLC', 'CBL', '4502762222', 'Configured Motor');
        $company = Company::create([
            'country_id' => $context['country']->id,
            'name' => 'Unconfigured Supplier LLC',
            'company_code' => 'UNS',
            'code_slug' => 'unconfigured-supplier',
            'company_type' => 'supplier',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'designation_id' => $context['designation']->id,
            'name' => 'Unconfigured Contact',
            'email' => 'unconfigured@example.test',
            'serves_supplier' => true,
            'all_locations' => true,
            'status' => 'active',
        ]);
        $supplier = Supplier::create([
            'company_id' => $company->id,
            'primary_contact_id' => $contact->id,
            'status' => 'active',
        ]);

        $options = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/supplier-pos/create-options')
            ->assertOk();
        $this->assertNotContains($supplier->id, collect($options->json('suppliers'))->pluck('id')->all());

        $payload = $this->supplierPoRequestPayload($context, $accepted['item']);
        $payload['supplier_id'] = $supplier->id;
        $payload['supplier_contact_id'] = $contact->id;

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);
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
        $productCode = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', strtoupper($title)), '-');
        $product = Product::create([
            'manufacturer_id' => $manufacturer->id,
            'product_code' => $productCode,
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
            'product_code' => $productCode,
            'product_name' => $product->name,
            'title' => $title,
            'buyer_description' => $product->buyer_description,
            'manufacturer_description' => $product->manufacturer_description,
            'quantity' => '1.000',
            'uom' => 'EA',
            'delivery_date' => '2026-09-30',
            'incoterm_id' => $context['incoterm']->id,
            'unit_price' => '3256.000',
            'total_price' => '3256.000',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPoRequestPayload(array $context, QuotationItem $item): array
    {
        return [
            'supplier_id' => $context['supplier']->id,
            'supplier_contact_id' => $context['supplierContact']->id,
            'supplier_quote_reference' => 'Factory scoped quote',
            'payment_term_days' => 30,
            'delivery_period_min' => 10,
            'delivery_period_max' => 12,
            'delivery_period_unit' => 'weeks',
            'delivery_period_type' => 'working',
            'accepted_invoice_currency' => 'USD',
            'incoterm_id' => $context['incoterm']->id,
            'additional_charges' => '0.000',
            'items' => [
                [
                    'quotation_item_id' => $item->id,
                    'unit_cost' => '500.000',
                    'item_description' => '<p>Supplier-facing manufacturer description.</p>',
                    'delivery_date' => '2026-10-10',
                    'incoterm_id' => $context['incoterm']->id,
                    'coo_entries' => [
                        [
                            'country_id' => $context['country']->id,
                            'amount' => '1.000',
                            'location' => 'Factory Dispatch',
                        ],
                    ],
                ],
            ],
            'terms' => [
                [
                    'key' => 'acknowledgment',
                    'title' => 'Acknowledgment',
                    'description' => 'Supplier shall acknowledge receipt of this PO.',
                ],
            ],
        ];
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
}
