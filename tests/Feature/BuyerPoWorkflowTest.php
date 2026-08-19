<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerPoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_can_create_buyer_po_against_latest_final_quotation_version(): void
    {
        Storage::disk('local')->deleteDirectory('buyer-pos');
        Storage::disk('local')->deleteDirectory('generated/quotations');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated()
            ->assertJsonPath('data.version_number', 1);

        $version = QuotationVersion::query()
            ->where('quotation_id', $quotationId)
            ->where('version_number', 1)
            ->firstOrFail();

        $items = QuotationItem::query()
            ->where('quotation_id', $quotationId)
            ->orderBy('line_number')
            ->get();

        $response = $this->withBearerToken($context['salesperson'])
            ->post("/api/quotations/{$quotationId}/buyer-po", [
                'items' => [
                    [
                        'quotation_item_id' => $items[0]->id,
                        'buyer_item_code' => 'OXY-MOTOR-001',
                        'po_number' => '4502757812',
                        'po_date' => '2026-06-05',
                        'po_value' => '3256.000',
                        'po_file' => UploadedFile::fake()->create('buyer-po-4502757812.pdf', 64, 'application/pdf'),
                    ],
                    [
                        'quotation_item_id' => $items[1]->id,
                        'buyer_item_code' => 'OXY-GLAND-002',
                        'po_number' => '4502757813',
                        'po_date' => '2026-06-06',
                        'po_value' => '240.000',
                        'po_file' => UploadedFile::fake()->create('buyer-po-4502757813.pdf', 64, 'application/pdf'),
                    ],
                ],
            ]);
        $buyerPo = BuyerPo::query()->where('quotation_id', $quotationId)->where('po_number', '4502757812')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath('message', 'Buyer PO item details saved.')
            ->assertJsonPath('data.status', 'buyer_po_received')
            ->assertJsonPath('data.covered_items_count', 2)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.created_buyer_pos.0.items.0.buyer_item_code', 'OXY-MOTOR-001')
            ->assertJsonPath('data.created_buyer_pos.1.items.0.buyer_item_code', 'OXY-GLAND-002')
            ->assertJsonPath('data.created_buyer_pos.0.download_url', "/api/quotations/{$quotationId}/buyer-po/{$buyerPo->id}/download")
            ->assertJsonMissingPath('data.created_buyer_pos.0.po_file_path');

        $this->assertDatabaseHas('buyer_pos', [
            'quotation_id' => $quotationId,
            'quotation_version_id' => $version->id,
            'buyer_company_id' => $context['buyerCompany']->id,
            'po_number' => '4502757812',
            'po_value' => '3256.000',
            'currency' => 'OMR',
            'created_by' => $context['salesperson']->id,
        ]);
        $this->assertDatabaseHas('buyer_pos', [
            'quotation_id' => $quotationId,
            'quotation_version_id' => $version->id,
            'buyer_company_id' => $context['buyerCompany']->id,
            'po_number' => '4502757813',
            'po_value' => '240.000',
            'currency' => 'OMR',
            'created_by' => $context['salesperson']->id,
        ]);
        $this->assertDatabaseHas('buyer_po_items', [
            'buyer_po_id' => $buyerPo->id,
            'quotation_id' => $quotationId,
            'quotation_item_id' => $items[0]->id,
            'buyer_item_code' => 'OXY-MOTOR-001',
            'quantity' => '1.000',
            'total_amount' => '3256.000',
            'currency' => 'OMR',
        ]);
        $this->assertDatabaseHas('buyer_po_items', [
            'quotation_id' => $quotationId,
            'quotation_item_id' => $items[1]->id,
            'buyer_item_code' => 'OXY-GLAND-002',
            'quantity' => '2.000',
            'total_amount' => '240.000',
            'currency' => 'OMR',
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'status' => 'buyer_po_received',
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'buyer_po.items_created',
            'summary' => 'Ahmed Mansoor recorded buyer PO details for 2 item(s) across 2 PO(s).',
        ]);

        Storage::disk('local')->assertExists($buyerPo->po_file_path);

        $downloadUrl = (string) $response->json('data.created_buyer_pos.0.download_url');
        $this->get($downloadUrl)
            ->assertOk()
            ->assertDownload('BUYER-PO-4502757812.PDF')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $otherSalesperson = User::create([
            'name' => 'Other Salesperson',
            'email' => 'other-sales@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $otherSalesperson->roles()->attach(Role::query()->where('slug', 'salesperson')->firstOrFail());

        $this->withBearerToken($otherSalesperson)
            ->get($downloadUrl)
            ->assertForbidden();

        Storage::disk('local')->put('buyer-pos/outside-quotation/stolen.pdf', 'not this quotation');
        $buyerPo->forceFill(['po_file_path' => 'buyer-pos/outside-quotation/stolen.pdf'])->save();

        $this->withBearerToken($context['salesperson'])
            ->get($downloadUrl)
            ->assertNotFound();
    }

    public function test_buyer_po_can_be_recorded_for_only_some_items_first(): void
    {
        Storage::disk('local')->deleteDirectory('buyer-pos');
        Storage::disk('local')->deleteDirectory('generated/quotations');
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/finalize")
            ->assertCreated();

        $item = QuotationItem::query()
            ->where('quotation_id', $quotationId)
            ->orderBy('line_number')
            ->firstOrFail();

        $this->withBearerToken($context['salesperson'])
            ->post("/api/quotations/{$quotationId}/buyer-po", [
                'items' => [
                    [
                        'quotation_item_id' => $item->id,
                        'buyer_item_code' => 'OXY-PARTIAL-001',
                        'po_number' => '4502757900',
                        'po_date' => '2026-06-05',
                        'po_value' => '3256.000',
                        'po_file' => UploadedFile::fake()->create('buyer-po-partial.pdf', 64, 'application/pdf'),
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'buyer_po_partial')
            ->assertJsonPath('data.covered_items_count', 1)
            ->assertJsonPath('data.items_count', 2);

        $this->assertDatabaseCount('buyer_po_items', 1);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'status' => 'buyer_po_partial',
        ]);
    }

    public function test_buyer_po_requires_a_created_quotation_version_first(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createCompleteQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->post("/api/quotations/{$quotationId}/buyer-po", [
                'po_number' => '4502757812',
                'po_date' => '2026-06-05',
                'po_value' => '3256.000',
                'po_file' => UploadedFile::fake()->create('buyer-po.pdf', 32, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quotation_version']);
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
            'incoterm',
            'manufacturer',
            'salesperson',
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
                        'product_code' => 'ABB-FM-001',
                        'product_name' => 'Flameproof Motor',
                        'title' => 'ABB Flameproof Motor',
                        'buyer_description' => '<p>ABB Flameproof Motor.</p>',
                        'manufacturer_description' => '<p>ABB Flameproof Motor with internal notes.</p>',
                        'quantity' => 1,
                        'uom' => 'EA',
                        'unit_price' => '3256.000',
                    ],
                    [
                        'manufacturer_id' => $context['manufacturer']->id,
                        'product_code' => 'ABB-GLAND-001',
                        'product_name' => 'Cable Gland Kit',
                        'title' => 'ABB Cable Gland Kit',
                        'buyer_description' => '<p>ABB Cable Gland Kit.</p>',
                        'manufacturer_description' => '<p>ABB Cable Gland Kit with internal notes.</p>',
                        'quantity' => 2,
                        'uom' => 'EA',
                        'unit_price' => '120.000',
                    ],
                ],
            ])->assertOk();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    ['key' => 'cancellation', 'title' => 'Cancellation', 'description' => 'Cancellation terms.'],
                    ['key' => 'scope_of_work', 'title' => 'Scope of Work', 'description' => 'Supply only.'],
                    ['key' => 'delivery_term', 'title' => 'Delivery Term', 'description' => 'DDP - OXY Yard, Muscat.'],
                    ['key' => 'warranty', 'title' => 'Warranty', 'description' => 'Warranty applies.'],
                    ['key' => 'force_majeure', 'title' => 'Force Majeure', 'description' => 'Force majeure applies.'],
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

        return $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json');
    }
}
