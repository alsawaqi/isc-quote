<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Designation;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Uom;
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

    public function test_salesperson_quotation_options_include_admin_managed_uoms_and_currencies(): void
    {
        $context = $this->quotationContext();
        $admin = $this->adminUser();

        $this->withBearerToken($admin)
            ->postJson('/api/admin/uoms', [
                'code' => 'PKT',
                'name' => 'Packet',
                'status' => 'active',
            ])
            ->assertCreated();

        $this->withBearerToken($admin)
            ->postJson('/api/admin/currencies', [
                'code' => 'AED',
                'name' => 'UAE Dirham',
                'symbol' => 'د.إ',
                'exchange_rate' => '9.550000',
                'status' => 'active',
            ])
            ->assertCreated();

        $response = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/quotations/create-options')
            ->assertOk();

        $this->assertContains('PKT', collect($response->json('uoms'))->pluck('id')->all());
        $this->assertContains('AED', collect($response->json('currencies'))->pluck('id')->all());
        $this->assertSame('د.إ', collect($response->json('currencies'))->firstWhere('id', 'AED')['symbol'] ?? null);
    }

    public function test_inactive_master_data_is_not_selectable_or_accepted(): void
    {
        $context = $this->quotationContext();

        Currency::query()->where('code', 'OMR')->update(['status' => 'inactive']);
        Uom::query()->where('code', 'PCS')->update(['status' => 'inactive']);

        $options = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/quotations/create-options')
            ->assertOk();

        $this->assertNotContains('OMR', collect($options->json('currencies'))->pluck('id')->all());
        $this->assertNotContains('PCS', collect($options->json('uoms'))->pluck('id')->all());

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'quotation_validity_value' => 30,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 30,
                'delivery_period_min' => 2,
                'delivery_period_max' => 3,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'OMR',
                'incoterm_id' => $context['incoterm']->id,
                'delivery_responsibility' => 'isc',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accepted_invoice_currency']);

        $quotationId = $this->createQuotation($context, ['accepted_invoice_currency' => 'USD']);
        $manufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'ABB LLC',
            'status' => 'active',
        ]);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [[
                    'manufacturer_id' => $manufacturer->id,
                    'product_code' => 'INACTIVE-UOM',
                    'product_name' => 'Inactive UOM Test',
                    'title' => 'Inactive UOM Test',
                    'quantity' => 1,
                    'uom' => 'PCS',
                    'unit_price' => 1,
                    'vat_rate' => 0,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.uom']);
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
                'rfq_title' => 'RFQ 6000024422 PR 11729328',
                'closing_at' => null,
                'quotation_validity_value' => 30,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 45,
                'payment_terms_extra' => 'Payment schedule subject to buyer finance approval.',
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
            ->assertJsonPath('data.rfq_title', 'RFQ 6000024422 PR 11729328')
            ->assertJsonPath('data.closing_at', null)
            ->assertJsonPath('data.payment_schedule_summary', 'Within 45 days from the date of Invoice. Payment schedule subject to buyer finance approval.')
            ->assertJsonPath('data.payment_customer_type', 'credit')
            ->assertJsonPath('data.payment_schedules.0.payment_percentage', '100.000')
            ->assertJsonPath('data.payment_schedules.0.due_event', 'after_invoice')
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
            'rfq_title' => 'RFQ 6000024422 PR 11729328',
            'closing_at' => null,
            'quotation_validity_value' => 30,
            'quotation_validity_unit' => 'days',
            'payment_term_days' => 45,
            'payment_terms_extra' => 'Payment schedule subject to buyer finance approval.',
            'payment_customer_type' => 'credit',
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

    public function test_salesperson_can_save_credit_payment_schedule_with_installments(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/payment-schedule", [
                'payment_schedules' => [
                    [
                        'label' => 'First Check',
                        'payment_method' => 'cheque',
                        'payment_percentage' => 40,
                        'due_timing' => 'relative',
                        'due_event' => 'on_arrival',
                        'due_offset_days' => 0,
                    ],
                    [
                        'label' => 'Second Check',
                        'payment_method' => 'cheque',
                        'payment_percentage' => 30,
                        'due_timing' => 'relative',
                        'due_event' => 'after_arrival',
                        'due_offset_days' => 30,
                    ],
                    [
                        'label' => 'Final Transfer',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 30,
                        'due_timing' => 'fixed_date',
                        'due_date' => '2026-09-15',
                        'notes' => 'Finance team confirmed date.',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Quotation payment schedule saved.')
            ->assertJsonPath('data.payment_schedules.0.label', 'First Check')
            ->assertJsonPath('data.payment_schedules.1.due_offset_days', 30)
            ->assertJsonPath('data.payment_schedules.2.due_date', '2026-09-15');

        $this->assertDatabaseHas('quotation_payment_schedules', [
            'quotation_id' => $quotationId,
            'line_number' => 2,
            'label' => 'Second Check',
            'payment_method' => 'cheque',
            'payment_percentage' => '30.000',
            'due_event' => 'after_arrival',
            'due_offset_days' => 30,
        ]);
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'payment_term_days' => 45,
        ]);
        $this->assertDatabaseHas('quotation_activity_logs', [
            'quotation_id' => $quotationId,
            'action' => 'quotation.payment_schedule_updated',
        ]);
    }

    public function test_paying_schedule_milestones_do_not_replace_invoice_payment_term_days(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context, [
            'payment_customer_type' => 'paying',
            'payment_term_days' => 7,
        ]);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/payment-schedule", [
                'payment_schedules' => [
                    [
                        'label' => 'Advance payment',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 50,
                        'due_timing' => 'relative',
                        'due_event' => 'before_delivery',
                        'due_offset_days' => 0,
                    ],
                    [
                        'label' => 'Balance payment',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 50,
                        'due_timing' => 'relative',
                        'due_event' => 'on_delivery',
                        'due_offset_days' => 0,
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.payment_term_days', 7);
        $this->assertStringContainsString(
            'Within 7 days from the date of Invoice.',
            (string) $response->json('data.payment_schedule_summary'),
        );
        $this->assertDatabaseHas('quotations', [
            'id' => $quotationId,
            'payment_customer_type' => 'paying',
            'payment_term_days' => 7,
        ]);
    }

    public function test_payment_schedule_percentages_must_total_one_hundred(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/payment-schedule", [
                'payment_schedules' => [
                    [
                        'label' => 'Advance',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 50,
                        'due_timing' => 'relative',
                        'due_event' => 'before_delivery',
                        'due_offset_days' => 0,
                    ],
                    [
                        'label' => 'Balance',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 40,
                        'due_timing' => 'relative',
                        'due_event' => 'on_delivery',
                        'due_offset_days' => 0,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_schedules');
    }

    public function test_salesperson_can_create_quotation_with_supplier_delivery_responsibility(): void
    {
        $context = $this->quotationContext();

        $options = $this->withBearerToken($context['salesperson'])
            ->getJson('/api/quotations/create-options')
            ->assertOk();

        $this->assertContains('supplier', collect($options->json('delivery_responsibilities'))->pluck('id')->all());

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'rfq_number' => 'RFQ-SUP-001',
                'pr_number' => 'PR-SUP-001',
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
                'delivery_responsibility' => 'supplier',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.delivery_responsibility', 'supplier');

        $this->assertDatabaseHas('quotations', [
            'id' => $response->json('data.id'),
            'delivery_responsibility' => 'supplier',
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
                'vat_pricing' => 'exclusive',
                'items' => [
                    [
                        'manufacturer_id' => $manufacturer->id,
                        'product_code' => 'TB-RHS-24',
                        'product_name' => 'Terminal Box',
                        'title' => 'Terminal Box Assembly',
                        'buyer_description' => '<p>Terminal box suitable for RHS mounting.</p><ul><li>Weatherproof enclosure</li></ul>',
                        'manufacturer_description' => '<p>Terminal box suitable for RHS mounting.</p><ul><li>Weatherproof enclosure</li><li>Include internal feature code TB-RHS-24.</li></ul>',
                        'quantity' => 2,
                        'uom' => 'EA',
                        'incoterm_id' => $context['incoterm']->id,
                        'unit_price' => '150.250',
                        'vat_rate' => '5',
                    ],
                    [
                        'manufacturer_id' => $manufacturer->id,
                        'product_code' => 'GK-001',
                        'product_name' => 'Gland Kit',
                        'title' => 'Cable Gland Kit',
                        'buyer_description' => '<p>Cable gland kit.</p>',
                        'manufacturer_description' => '<p>Cable gland kit with internal packing note.</p>',
                        'quantity' => 3,
                        'uom' => 'PCS',
                        'incoterm_id' => $context['incoterm']->id,
                        'unit_price' => '10.000',
                        'vat_rate' => '0',
                    ],
                ],
                'charges' => [
                    ['label' => 'Freight charges', 'amount' => '25.000'],
                ],
                'discounts' => [
                    ['label' => 'Project discount', 'discount_type' => 'fixed', 'amount' => '10.000'],
                    ['label' => 'Commercial discount', 'discount_type' => 'percentage', 'amount' => '5.000'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Quotation items saved.')
            ->assertJsonPath('data.items.0.vat_rate', '5.000')
            ->assertJsonPath('data.items.1.vat_rate', '0.000')
            ->assertJsonPath('data.items.0.product_code', 'TB-RHS-24')
            ->assertJsonPath('data.items.0.incoterm_id', $context['incoterm']->id)
            ->assertJsonPath('data.items.0.incoterm_code', 'DDP')
            ->assertJsonPath('data.items.0.total_price', '300.500')
            ->assertJsonPath('data.items.1.total_price', '30.000')
            ->assertJsonPath('data.charges.0.label', 'Freight charges')
            ->assertJsonPath('data.discounts.1.discount_type', 'percentage')
            ->assertJsonPath('data.totals.items_subtotal', '330.500')
            ->assertJsonPath('data.totals.vat_total', '13.851')
            ->assertJsonPath('data.totals.charges_total', '25.000')
            ->assertJsonPath('data.totals.discounts_total', '27.775')
            ->assertJsonPath('data.totals.grand_total', '341.576');
        $this->assertArrayNotHasKey('delivery_date', $response->json('data.items.0'));

        $this->assertDatabaseHas('products', [
            'manufacturer_id' => $manufacturer->id,
            'product_code' => 'TB-RHS-24',
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
            'product_code' => 'TB-RHS-24',
            'product_name' => 'Terminal Box',
            'title' => 'Terminal Box Assembly',
            'quantity' => '2.000',
            'uom' => 'EA',
            'delivery_date' => null,
            'incoterm_id' => $context['incoterm']->id,
            'unit_price' => '150.250',
            'total_price' => '300.500',
            'vat_rate' => '5.000',
        ]);
        $this->assertDatabaseHas('quotation_charges', [
            'quotation_id' => $quotationId,
            'label' => 'Freight charges',
            'amount' => '25.000',
        ]);
        $this->assertDatabaseHas('quotation_discounts', [
            'quotation_id' => $quotationId,
            'label' => 'Commercial discount',
            'discount_type' => 'percentage',
            'amount' => '5.000',
        ]);
    }

    public function test_quotation_items_reject_an_exact_delivery_date_before_a_buyer_po_exists(): void
    {
        $context = $this->quotationContext();
        $manufacturer = Manufacturer::create([
            'country_id' => $context['country']->id,
            'name' => 'ABB LLC',
            'status' => 'active',
        ]);
        $quotationId = $this->createQuotation($context);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", [
                'items' => [[
                    'manufacturer_id' => $manufacturer->id,
                    'product_code' => 'TB-RHS-24',
                    'product_name' => 'Terminal Box',
                    'title' => 'Terminal Box Assembly',
                    'buyer_description' => '<p>Terminal box suitable for RHS mounting.</p>',
                    'quantity' => 2,
                    'uom' => 'EA',
                    'delivery_date' => '2026-09-30',
                    'incoterm_id' => $context['incoterm']->id,
                    'unit_price' => '150.250',
                    'vat_rate' => '5',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.delivery_date']);

        $this->assertDatabaseCount('quotation_items', 0);
    }

    public function test_quotation_delivery_period_must_be_stated_in_weeks(): void
    {
        $context = $this->quotationContext();

        $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', [
                'buyer_company_id' => $context['buyerCompany']->id,
                'buyer_contact_id' => $context['buyerContact']->id,
                'quotation_validity_value' => 30,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 45,
                'delivery_period_min' => 22,
                'delivery_period_max' => 24,
                'delivery_period_unit' => 'days',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'OMR',
                'incoterm_id' => $context['incoterm']->id,
                'delivery_responsibility' => 'isc',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['delivery_period_unit']);
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

    public function test_quotation_terms_are_sanitized_before_required_content_validation_and_storage(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);
        $safeRequiredTerms = [
            ['key' => 'scope_of_work', 'title' => 'Scope of Work', 'description' => '<p>Supply only.</p>'],
            ['key' => 'delivery_term', 'title' => 'Delivery Term', 'description' => '<p>DDP Muscat.</p>'],
            ['key' => 'force_majeure', 'title' => 'Force Majeure', 'description' => '<p>Standard clause.</p>'],
        ];

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    ['key' => 'cancellation', 'title' => 'Cancellation', 'description' => '<script>alert("stored-xss")</script>'],
                    ...$safeRequiredTerms,
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terms');

        $maliciousDescription = '<p onclick="alert(1)">Cancellation <strong style="color:red">requires approval</strong><img src=x onerror="alert(2)"><script>alert(3)</script><mark style="color:red">in writing</mark></p>';
        $sanitizedDescription = '<p>Cancellation <strong>requires approval</strong><mark>in writing</mark></p>';

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    ['key' => 'cancellation', 'title' => 'Cancellation', 'description' => $maliciousDescription],
                    ...$safeRequiredTerms,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.terms.0.description', $sanitizedDescription);

        $this->assertDatabaseHas('quotation_terms', [
            'quotation_id' => $quotationId,
            'key' => 'cancellation',
            'description' => $sanitizedDescription,
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

    public function test_warranty_standard_clause_is_optional_for_quotation_terms(): void
    {
        $context = $this->quotationContext();
        $quotationId = $this->createQuotation($context);

        $response = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/terms", [
                'terms' => [
                    ['key' => 'cancellation', 'title' => 'Cancellation', 'description' => 'Cancellation clause.'],
                    ['key' => 'scope_of_work', 'title' => 'Scope of Work', 'description' => 'Scope clause.'],
                    ['key' => 'delivery_term', 'title' => 'Delivery Term', 'description' => 'Delivery clause.'],
                    ['key' => 'force_majeure', 'title' => 'Force Majeure', 'description' => 'Force majeure clause.'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Quotation terms saved.');

        $this->assertDatabaseMissing('quotation_terms', [
            'quotation_id' => $quotationId,
            'key' => 'warranty',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function test_fractional_pricing_matches_saved_items_and_document_snapshot(): void
    {
        $context = $this->quotationContext();
        $manufacturer = Manufacturer::create(['country_id' => $context['country']->id, 'name' => 'Pricing Test', 'status' => 'active']);
        $quotationId = $this->createQuotation($context);
        $items = array_map(fn ($index) => [
            'manufacturer_id' => $manufacturer->id,
            'product_code' => 'ROUND-'.$index,
            'product_name' => 'Fractional item',
            'title' => 'Fractional item',
            'quantity' => '0.500', 'uom' => 'EA', 'unit_price' => '0.333', 'vat_rate' => '5.000',
        ], range(1, 3));

        foreach (['exclusive' => ['0.501', '0.024', '0.525'], 'inclusive' => ['0.477', '0.024', '0.501']] as $mode => $expected) {
            $response = $this->withBearerToken($context['salesperson'])
                ->postJson("/api/quotations/{$quotationId}/items", ['vat_pricing' => $mode, 'items' => $items])
                ->assertOk()
                ->assertJsonPath('data.totals.items_subtotal', $expected[0])
                ->assertJsonPath('data.totals.vat_total', $expected[1])
                ->assertJsonPath('data.totals.grand_total', $expected[2]);
            $this->withBearerToken($context['salesperson'])->getJson("/api/quotations/{$quotationId}")
                ->assertOk()->assertJsonPath('data.totals', $response->json('data.totals'));

            $quotation = \App\Models\Quotation::findOrFail($quotationId);
            $snapshot = app(\App\Services\QuotationDocumentService::class)->snapshot($quotation, 1);
            $this->assertSame($expected[0], $snapshot['totals']['subtotal']);
            $this->assertSame($expected[1], $snapshot['totals']['vat']);
            $this->assertSame($expected[2], $snapshot['totals']['grand_total']);
        }
    }

    public function test_combined_excess_discounts_are_rejected_without_changing_saved_items(): void
    {
        $context = $this->quotationContext();
        $manufacturer = Manufacturer::create(['country_id' => $context['country']->id, 'name' => 'Discount Test', 'status' => 'active']);
        $quotationId = $this->createQuotation($context);
        $payload = ['vat_pricing' => 'exclusive', 'items' => [[
            'manufacturer_id' => $manufacturer->id, 'product_code' => 'DISCOUNT-1',
            'product_name' => 'Discount item', 'title' => 'Discount item',
            'quantity' => 1, 'uom' => 'EA', 'unit_price' => 100, 'vat_rate' => 5,
        ]], 'charges' => [['label' => 'Freight', 'amount' => 20]]];
        $saved = $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotationId}/items", $payload)->assertOk()->json('data');

        foreach ([
            [['label' => 'First', 'discount_type' => 'fixed', 'amount' => 80], ['label' => 'Second', 'discount_type' => 'fixed', 'amount' => 80]],
            [['label' => 'First', 'discount_type' => 'percentage', 'amount' => 60], ['label' => 'Second', 'discount_type' => 'percentage', 'amount' => 50]],
            [['label' => 'Too much', 'discount_type' => 'fixed', 'amount' => 121]],
        ] as $discounts) {
            $this->withBearerToken($context['salesperson'])
                ->postJson("/api/quotations/{$quotationId}/items", [...$payload, 'discounts' => $discounts])
                ->assertUnprocessable()->assertJsonValidationErrors('discounts');
        }
        $this->withBearerToken($context['salesperson'])->getJson("/api/quotations/{$quotationId}")
            ->assertOk()->assertJsonPath('data.items', $saved['items'])
            ->assertJsonPath('data.charges', $saved['charges'])->assertJsonPath('data.discounts', [])
            ->assertJsonPath('data.totals.grand_total', '125.000');
    }

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

    private function adminUser(): User
    {
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'is_system' => true,
            'status' => 'active',
        ]);
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $admin->roles()->attach($adminRole);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createQuotation(array $context, array $overrides = []): int
    {
        return (int) $this->withBearerToken($context['salesperson'])
            ->postJson('/api/quotations', array_merge([
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
            ], $overrides))
            ->json('data.id');
    }
}
