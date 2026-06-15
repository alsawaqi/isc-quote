<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
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

        $response = $this->withBearerToken($context['salesperson'])
            ->post("/api/quotations/{$quotationId}/buyer-po", [
                'po_number' => '4502757812',
                'po_date' => '2026-06-05',
                'po_value' => '3256.000',
                'po_file' => UploadedFile::fake()->create('buyer-po-4502757812.pdf', 64, 'application/pdf'),
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Buyer PO created and linked to quotation version 1.')
            ->assertJsonPath('data.po_number', '4502757812')
            ->assertJsonPath('data.quotation_version_number', 1)
            ->assertJsonPath('data.po_value', '3256.000')
            ->assertJsonPath('data.currency', 'OMR');

        $this->assertDatabaseHas('buyer_pos', [
            'quotation_id' => $quotationId,
            'quotation_version_id' => $version->id,
            'buyer_company_id' => $context['buyerCompany']->id,
            'po_number' => '4502757812',
            'po_value' => '3256.000',
            'currency' => 'OMR',
            'created_by' => $context['salesperson']->id,
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'status' => 'buyer_po_received',
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'buyer_po.created',
            'summary' => 'Ahmed Mansoor recorded buyer PO 4502757812 against quotation version 1.',
        ]);

        Storage::disk('local')->assertExists($response->json('data.po_file_path'));
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
                        'product_name' => 'Flameproof Motor',
                        'title' => 'ABB Flameproof Motor',
                        'buyer_description' => '<p>ABB Flameproof Motor.</p>',
                        'manufacturer_description' => '<p>ABB Flameproof Motor with internal notes.</p>',
                        'quantity' => 1,
                        'uom' => 'EA',
                        'unit_price' => '3256.000',
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
