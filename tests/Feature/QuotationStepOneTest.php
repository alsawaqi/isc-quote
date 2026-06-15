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
use Tests\TestCase;

class QuotationStepOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesperson_can_load_step_one_options_with_their_default_supplier(): void
    {
        $context = $this->quotationContext();

        $response = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/quotations/create-options');

        $response->assertOk()
            ->assertJsonPath('supplier.company_id', $context['supplierCompany']->id)
            ->assertJsonPath('supplier.company_name', 'Industrial Supplies Center LLC')
            ->assertJsonPath('supplier.contact_id', $context['salesContact']->id)
            ->assertJsonPath('supplier.contact_name', 'Ahmed Mansoor')
            ->assertJsonPath('buyers.0.id', $context['buyerCompany']->id)
            ->assertJsonPath('buyer_contacts.0.company_id', $context['buyerCompany']->id)
            ->assertJsonPath('incoterms.0.code', 'DDP');
    }

    public function test_salesperson_can_create_quotation_step_one_with_their_default_supplier(): void
    {
        $context = $this->quotationContext();

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'rfq_number' => null,
                'pr_number' => 'PR-6000024422',
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
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Quotation step one saved.')
            ->assertJsonPath('data.supplier_company_id', $context['supplierCompany']->id)
            ->assertJsonPath('data.supplier_contact_id', $context['salesContact']->id)
            ->assertJsonPath('data.buyer_company_id', $context['buyerCompany']->id)
            ->assertJsonPath('data.buyer_contact_id', $context['buyerContact']->id)
            ->assertJsonPath('data.pr_number', 'PR-6000024422')
            ->assertJsonPath('data.closing_at', '2026-06-02 14:30:00')
            ->assertJsonPath('data.status', 'draft');

        $this->assertMatchesRegularExpression('/^ISC-COR-QT-\d+-OXY-\d{2}$/', (string) $response->json('data.quotation_reference'));

        $this->assertDatabaseHas('quotations', [
            'salesperson_id' => $context['salesperson']->id,
            'supplier_company_id' => $context['supplierCompany']->id,
            'supplier_contact_id' => $context['salesContact']->id,
            'buyer_company_id' => $context['buyerCompany']->id,
            'buyer_contact_id' => $context['buyerContact']->id,
            'rfq_number' => null,
            'pr_number' => 'PR-6000024422',
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
            'status' => 'draft',
        ]);
    }

    public function test_buyer_contact_must_belong_to_the_selected_buyer_company(): void
    {
        $context = $this->quotationContext();
        $otherCompany = Company::create([
            'country_id' => $context['country']->id,
            'name' => 'Other Buyer LLC',
            'company_code' => 'OTH',
            'code_slug' => 'oth',
            'company_type' => 'buyer',
            'status' => 'active',
        ]);
        $otherContact = Contact::create([
            'company_id' => $otherCompany->id,
            'designation_id' => $context['designation']->id,
            'name' => 'Wrong Contact',
            'email' => 'wrong@example.test',
            'status' => 'active',
        ]);

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $otherContact->id,
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
            ->assertStatus(422)
            ->assertJsonValidationErrors('buyer_contact_id');
    }

    public function test_salesperson_can_update_quotation_step_one_when_editing_a_revision(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);

        $response = $this->withBearerToken($context['salesperson'])
            ->putJson("/api/quotations/{$quotationId}", [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'rfq_number' => '6000024422-REV',
                'pr_number' => 'PR-UPDATED',
                'closing_at' => '2026-06-08 09:15:00',
                'quotation_validity_value' => 45,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 60,
                'delivery_period_min' => 24,
                'delivery_period_max' => 26,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'USD',
                'incoterm_id' => $context['incoterm']->id,
                'delivery_responsibility' => 'buyer',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Quotation commercial details updated.')
            ->assertJsonPath('data.rfq_number', '6000024422-REV')
            ->assertJsonPath('data.pr_number', 'PR-UPDATED')
            ->assertJsonPath('data.payment_term_days', 60)
            ->assertJsonPath('data.accepted_invoice_currency', 'USD')
            ->assertJsonPath('data.delivery_responsibility', 'buyer');

        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'quotation.commercial_updated',
            'summary' => 'Ahmed Mansoor updated quotation commercial details.',
        ]);
    }

    public function test_follow_up_user_cannot_create_quotation_step_one(): void
    {
        $context = $this->quotationContext();
        $followUpRole = Role::create([
            'name' => 'Follow-Up',
            'slug' => 'follow-up',
            'is_system' => true,
            'status' => 'active',
        ]);
        $followUp = User::create([
            'name' => 'Follow User',
            'email' => 'follow@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $followUp->roles()->attach($followUpRole);

        $this->withBearerToken($followUp)
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
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
            ->assertForbidden();
    }

    public function test_salesperson_can_save_step_two_items_and_products_are_created(): void
    {
        $context = $this->quotationContext();
        $manufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'ABB LLC',
            'status' => 'active',
        ]);
        $quotationId = $this->createQuotation($context);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [
                    [
                        'manufacturer_id' => $manufacturer->id,
                        'product_name' => 'Terminal Box',
                        'title' => 'Terminal Box Assembly',
                        'buyer_description' => '<p>Terminal box suitable for RHS mounting.</p><ul><li>Weatherproof enclosure</li></ul>',
                        'manufacturer_description' => '<p>Terminal box suitable for RHS mounting.</p><ul><li>Weatherproof enclosure</li><li>Include internal feature code TB-RHS-24.</li></ul>',
                        'quantity' => 2,
                        'uom' => 'EA',
                        'unit_price' => '150.250',
                    ],
                    [
                        'manufacturer_id' => $manufacturer->id,
                        'product_name' => 'Gland Kit',
                        'title' => 'Cable Gland Kit',
                        'buyer_description' => '<p>Cable gland kit.</p>',
                        'manufacturer_description' => '<p>Cable gland kit with internal packing note.</p>',
                        'quantity' => 3,
                        'uom' => 'PCS',
                        'unit_price' => '10.000',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Quotation items saved.')
            ->assertJsonPath('data.items.0.total_price', '300.500')
            ->assertJsonPath('data.items.1.total_price', '30.000')
            ->assertJsonPath('data.totals.subtotal', '330.500');

        $this->assertDatabaseHas('products', [
            'manufacturer_id' => $manufacturer->id,
            'name' => 'Terminal Box',
            'title' => 'Terminal Box Assembly',
            'buyer_description' => '<p>Terminal box suitable for RHS mounting.</p><ul><li>Weatherproof enclosure</li></ul>',
            'manufacturer_description' => '<p>Terminal box suitable for RHS mounting.</p><ul><li>Weatherproof enclosure</li><li>Include internal feature code TB-RHS-24.</li></ul>',
            'last_uom' => 'EA',
            'last_unit_price' => '150.250',
        ]);
        $this->assertDatabaseHas('quotation_items', [
            'quotation_id' => $quotationId,
            'line_number' => 1,
            'product_name' => 'Terminal Box',
            'title' => 'Terminal Box Assembly',
            'quantity' => '2.000',
            'uom' => 'EA',
            'unit_price' => '150.250',
            'total_price' => '300.500',
        ]);
    }

    public function test_salesperson_can_save_step_three_terms_with_required_clauses_and_custom_terms(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    [
                        'key' => 'cancellation',
                        'title' => 'Cancellation',
                        'description' => 'Cancellation after PO acceptance requires written approval.',
                    ],
                    [
                        'key' => 'scope_of_work',
                        'title' => 'Scope of Work',
                        'description' => 'Supply of quoted materials only.',
                    ],
                    [
                        'key' => 'delivery_term',
                        'title' => 'Delivery Term',
                        'description' => 'Delivery is subject to manufacturer confirmation.',
                    ],
                    [
                        'key' => 'warranty',
                        'title' => 'Warranty',
                        'description' => 'Standard manufacturer warranty applies.',
                    ],
                    [
                        'key' => 'force_majeure',
                        'title' => 'Force Majeure',
                        'description' => 'Neither party is liable for delays outside reasonable control.',
                    ],
                    [
                        'key' => null,
                        'title' => 'Document Requirement',
                        'description' => 'COO and packing list must be provided before dispatch.',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Quotation terms saved.')
            ->assertJsonPath('data.terms.0.key', 'cancellation')
            ->assertJsonPath('data.terms.0.is_required_default', true)
            ->assertJsonPath('data.terms.5.key', null)
            ->assertJsonPath('data.terms.5.is_required_default', false);

        $this->assertDatabaseHas('quotation_terms', [
            'quotation_id' => $quotationId,
            'line_number' => 1,
            'key' => 'cancellation',
            'title' => 'Cancellation',
            'description' => 'Cancellation after PO acceptance requires written approval.',
            'is_required_default' => true,
        ]);
        $this->assertDatabaseHas('quotation_terms', [
            'quotation_id' => $quotationId,
            'line_number' => 6,
            'key' => null,
            'title' => 'Document Requirement',
            'description' => 'COO and packing list must be provided before dispatch.',
            'is_required_default' => false,
        ]);
    }

    public function test_step_three_terms_require_all_standard_clauses(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    [
                        'key' => 'cancellation',
                        'title' => 'Cancellation',
                        'description' => 'Cancellation clause.',
                    ],
                    [
                        'key' => 'scope_of_work',
                        'title' => 'Scope of Work',
                        'description' => 'Scope clause.',
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms');
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
            'status' => 'active',
        ]);
        $salesContact = Contact::create([
            'company_id' => $supplierCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Ahmed Mansoor',
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
            'status' => 'active',
        ]);
        $buyerContact = Contact::create([
            'company_id' => $buyerCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Moosa Ambu Ali',
            'email' => 'moosa@example.test',
            'status' => 'active',
        ]);
        $incoterm = Incoterm::create([
            'code' => 'DDP',
            'name' => 'Delivered Duty Paid',
            'reminder_days_before_delivery' => 40,
            'status' => 'active',
        ]);

        return compact(
            'buyerCompany',
            'buyerContact',
            'country',
            'designation',
            'incoterm',
            'salesContact',
            'salesperson',
            'supplierCompany',
        );
    }

    private function withBearerToken(User $user): self
    {
        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createQuotation(array $context): int
    {
        return (int) $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'rfq_number' => '6000024422',
                'pr_number' => 'PR-6000024422',
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
            ->json('data.id');
    }
}
