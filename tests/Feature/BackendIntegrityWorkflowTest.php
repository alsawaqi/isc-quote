<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\FollowUpItem;
use App\Models\Incoterm;
use App\Models\Invoice;
use App\Models\Manufacturer;
use App\Models\Payment;
use App\Models\PaymentPlanFollowUp;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentSchedule;
use App\Models\QuotationVersion;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BackendIntegrityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_payment_schedule_rejects_destructive_changes_but_allows_an_unchanged_save(): void
    {
        $context = $this->workflowContext();
        $quotation = $context['quotation'];
        $schedules = $context['schedules'];
        $scheduleIds = $schedules->pluck('id')->all();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotation->id}/payment-schedule", [
                'payment_schedules' => [
                    [
                        'label' => 'Changed advance',
                        'payment_method' => 'cheque',
                        'payment_percentage' => 60,
                        'due_timing' => 'relative',
                        'due_event' => 'on_invoice',
                        'due_offset_days' => 0,
                    ],
                    [
                        'label' => 'Changed balance',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 40,
                        'due_timing' => 'relative',
                        'due_event' => 'after_invoice',
                        'due_offset_days' => 45,
                    ],
                ],
            ])
            ->assertStatus(409)
            ->assertJsonPath('errors.payment_schedules.0', 'Payment terms are locked because follow-up tracking has already started. Existing instalments, payments, and evidence were not changed.');

        $this->assertSame($scheduleIds, $quotation->paymentSchedules()->orderBy('line_number')->pluck('id')->all());
        $this->assertDatabaseCount('payment_plan_follow_ups', 2);

        $this->withBearerToken($context['salesperson'])
            ->putJson("/api/quotations/{$quotation->id}", [
                'buyer_company_id' => $quotation->buyer_company_id,
                'buyer_contact_id' => $quotation->buyer_contact_id,
                'quotation_validity_value' => 30,
                'quotation_validity_unit' => 'days',
                'payment_term_days' => 60,
                'payment_customer_type' => 'credit',
                'delivery_period_min' => 2,
                'delivery_period_max' => 4,
                'delivery_period_unit' => 'weeks',
                'delivery_period_type' => 'working',
                'accepted_invoice_currency' => 'OMR',
                'incoterm_id' => $quotation->incoterm_id,
                'delivery_responsibility' => 'isc',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('quotations', ['id' => $quotation->id, 'payment_term_days' => 30]);

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotation->id}/payment-schedule", [
                'payment_schedules' => [
                    [
                        'label' => 'Advance',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 50,
                        'due_timing' => 'relative',
                        'due_event' => 'on_invoice',
                        'due_offset_days' => 0,
                    ],
                    [
                        'label' => 'Balance',
                        'payment_method' => 'bank_transfer',
                        'payment_percentage' => 50,
                        'due_timing' => 'relative',
                        'due_event' => 'after_invoice',
                        'due_offset_days' => 30,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Quotation payment schedule is unchanged.');

        $this->assertSame($scheduleIds, $quotation->paymentSchedules()->orderBy('line_number')->pluck('id')->all());
    }

    public function test_full_generic_invoice_payment_closes_all_payment_plan_reminders_without_duplicate_payments(): void
    {
        $context = $this->workflowContext();
        $followUpItem = $context['followUpItem'];

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItem->id}/payments", [
                'amount' => '1050.000',
                'payment_date' => '2026-08-08',
                'payment_reference' => 'FULL-TRANSFER-001',
                'remarks' => 'Customer settled the invoice in one transfer.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.invoice.status', 'paid');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $context['invoice']->id,
            'payment_plan_follow_up_id' => null,
            'amount' => '1050.000',
        ]);
        $this->assertSame(2, PaymentPlanFollowUp::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->where('status', 'paid')
            ->where('payment_reference', 'FULL-TRANSFER-001')
            ->count());
        $this->assertSame(
            ['525.000', '525.000'],
            PaymentPlanFollowUp::query()
                ->where('follow_up_item_id', $followUpItem->id)
                ->orderBy('id')
                ->pluck('paid_amount')
                ->all(),
        );
    }

    public function test_cumulative_generic_payments_allocate_the_full_amount_across_plan_lines(): void
    {
        $context = $this->workflowContext();
        $followUpItem = $context['followUpItem'];
        $this->withBearerToken($context['followUp']);

        $this->postJson("/api/follow-up/{$followUpItem->id}/payments", [
            'amount' => '400.000',
            'payment_date' => '2026-08-02',
            'payment_reference' => 'PARTIAL-001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'partially_paid');

        $this->postJson("/api/follow-up/{$followUpItem->id}/payments", [
            'amount' => '650.000',
            'payment_date' => '2026-08-08',
            'payment_reference' => 'FINAL-001',
        ])
            ->assertCreated()
            ->assertJsonPath('data.invoice.status', 'paid');

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(
            ['525.000', '525.000'],
            PaymentPlanFollowUp::query()
                ->where('follow_up_item_id', $followUpItem->id)
                ->orderBy('id')
                ->pluck('paid_amount')
                ->all(),
        );
    }

    public function test_paid_invoice_cannot_be_regenerated_and_closed_follow_up_cannot_regress(): void
    {
        $context = $this->workflowContext();
        $followUpItem = $context['followUpItem'];
        $invoice = $context['invoice'];

        $invoice->forceFill(['status' => 'paid'])->save();
        $followUpItem->forceFill(['status' => 'paid'])->save();

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItem->id}/invoice", [
                'payment_term_days' => 90,
                'vat_amount' => '99.000',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
            'payment_term_days' => 30,
            'vat_amount' => '50.000',
        ]);

        $invoice->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $followUpItem->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItem->id}/invoice/sent", [
                'sent_at' => '2026-08-08 14:00:00',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'closed']);
        $this->assertDatabaseHas('follow_up_items', ['id' => $followUpItem->id, 'status' => 'closed']);
    }

    public function test_partially_prepaid_invoice_can_be_sent_once_without_losing_partial_payment_status(): void
    {
        $context = $this->workflowContext();
        $followUpItem = $context['followUpItem'];
        $invoice = $context['invoice'];
        $tracker = PaymentPlanFollowUp::query()
            ->where('follow_up_item_id', $followUpItem->id)
            ->orderBy('id')
            ->firstOrFail();

        $tracker->forceFill([
            'status' => 'paid',
            'paid_at' => '2026-08-02 10:00:00',
            'paid_amount' => '525.000',
            'payment_reference' => 'PREPAY-001',
            'recorded_by' => $context['followUp']->id,
        ])->save();
        Payment::create([
            'invoice_id' => $invoice->id,
            'follow_up_item_id' => $followUpItem->id,
            'payment_plan_follow_up_id' => $tracker->id,
            'amount' => '525.000',
            'currency' => 'OMR',
            'payment_date' => '2026-08-02',
            'payment_reference' => 'PREPAY-001',
            'recorded_by' => $context['followUp']->id,
        ]);
        $invoice->forceFill(['status' => 'partially_paid'])->save();
        $followUpItem->forceFill(['status' => 'partially_paid'])->save();

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItem->id}/invoice/sent", [
                'sent_at' => '2026-08-08 14:00:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.invoice.status', 'partially_paid')
            ->assertJsonPath('data.invoice.paid_amount', '525.000')
            ->assertJsonPath('data.invoice.balance_amount', '525.000');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'partially_paid',
            'sent_at' => '2026-08-08 14:00:00',
        ]);

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItem->id}/invoice/sent", [
                'sent_at' => '2026-08-09 09:00:00',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'partially_paid',
            'sent_at' => '2026-08-08 14:00:00',
        ]);
    }

    public function test_quotation_with_buyer_and_supplier_po_cannot_be_finalized_back_to_issued(): void
    {
        $context = $this->workflowContext();
        $quotation = $context['quotation'];
        $quotation->forceFill(['status' => 'supplier_po_created'])->save();

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotation->id}/finalize")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This quotation can no longer be finalized because a buyer PO, supplier PO, or terminal workflow state already relies on its accepted version.');

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotation->id}/items", ['items' => []])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Quotation products are locked because an accepted buyer PO or downstream supplier PO already relies on them.');

        $this->withBearerToken($context['salesperson'])
            ->postJson("/api/quotations/{$quotation->id}/buyer-po")
            ->assertStatus(409)
            ->assertJsonPath('message', 'A buyer PO has already been recorded for this quotation.');

        $this->assertDatabaseHas('quotations', [
            'id' => $quotation->id,
            'status' => 'supplier_po_created',
        ]);
        $this->assertDatabaseCount('quotation_versions', 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowContext(): array
    {
        $country = Country::create(['name' => 'Oman', 'country_code' => 'OM', 'phone_code' => '+968', 'status' => 'active']);
        $designation = Designation::create(['name' => 'Engineer', 'code' => 'ENG', 'status' => 'active']);
        $incoterm = Incoterm::create(['code' => 'DDP', 'name' => 'Delivered Duty Paid', 'status' => 'active']);
        $manufacturer = Manufacturer::create(['country_id' => $country->id, 'name' => 'ABB QA', 'status' => 'active']);

        $internalCompany = $this->company($country, 'Industrial Supplies Center LLC', 'ISC', 'internal');
        $internalContact = $this->contact($internalCompany, $designation, 'Sales Contact', 'sales-contact@example.test');
        Supplier::create(['company_id' => $internalCompany->id, 'primary_contact_id' => $internalContact->id, 'status' => 'active']);

        $vendorCompany = $this->company($country, 'ABB Vendor LLC', 'ABBV', 'supplier');
        $vendorContact = $this->contact($vendorCompany, $designation, 'Vendor Contact', 'vendor@example.test');
        $vendorSupplier = Supplier::create(['company_id' => $vendorCompany->id, 'primary_contact_id' => $vendorContact->id, 'status' => 'active']);

        $buyerCompany = $this->company($country, 'Oman Energy Buyer SAOC', 'OEB', 'buyer');
        $buyerContact = $this->contact($buyerCompany, $designation, 'Buyer Contact', 'buyer@example.test');

        $salesRole = Role::create(['name' => 'Salesperson', 'slug' => 'salesperson', 'is_system' => true, 'status' => 'active']);
        $followUpRole = Role::create(['name' => 'Follow-Up', 'slug' => 'follow-up', 'is_system' => true, 'status' => 'active']);
        $salesperson = User::create([
            'name' => 'Sales User',
            'email' => 'sales-integrity@example.test',
            'contact_id' => $internalContact->id,
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $followUp = User::create([
            'name' => 'Follow-Up User',
            'email' => 'followup-integrity@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $salesperson->roles()->attach($salesRole);
        $followUp->roles()->attach($followUpRole);

        $quotation = Quotation::create([
            'quotation_reference' => 'ISC-COR-QT-INTEGRITY-26',
            'salesperson_id' => $salesperson->id,
            'supplier_company_id' => $internalCompany->id,
            'supplier_contact_id' => $internalContact->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'quotation_validity_value' => 30,
            'quotation_validity_unit' => 'days',
            'payment_term_days' => 30,
            'payment_customer_type' => 'credit',
            'delivery_period_min' => 2,
            'delivery_period_max' => 4,
            'delivery_period_unit' => 'weeks',
            'delivery_period_type' => 'working',
            'accepted_invoice_currency' => 'OMR',
            'vat_pricing' => 'exclusive',
            'incoterm_id' => $incoterm->id,
            'delivery_responsibility' => 'isc',
            'status' => 'buyer_po_received',
        ]);

        $schedules = collect([
            QuotationPaymentSchedule::create([
                'quotation_id' => $quotation->id,
                'line_number' => 1,
                'label' => 'Advance',
                'payment_method' => 'bank_transfer',
                'payment_percentage' => 50,
                'due_timing' => 'relative',
                'due_event' => 'on_invoice',
                'due_offset_days' => 0,
            ]),
            QuotationPaymentSchedule::create([
                'quotation_id' => $quotation->id,
                'line_number' => 2,
                'label' => 'Balance',
                'payment_method' => 'bank_transfer',
                'payment_percentage' => 50,
                'due_timing' => 'relative',
                'due_event' => 'after_invoice',
                'due_offset_days' => 30,
            ]),
        ]);

        $product = Product::create([
            'manufacturer_id' => $manufacturer->id,
            'product_code' => 'ABB-MTR-24V',
            'name' => 'ABB Motor',
            'title' => 'ABB Motor 24V',
            'buyer_description' => 'ABB motor for customer.',
            'manufacturer_description' => 'ABB factory motor specification.',
            'last_uom' => 'EA',
            'last_unit_price' => 1000,
            'status' => 'active',
        ]);
        $quotationItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'manufacturer_id' => $manufacturer->id,
            'line_number' => 1,
            'product_code' => $product->product_code,
            'product_name' => $product->name,
            'title' => $product->title,
            'buyer_description' => $product->buyer_description,
            'manufacturer_description' => $product->manufacturer_description,
            'quantity' => 1,
            'uom' => 'EA',
            'unit_price' => 1000,
            'vat_rate' => 5,
            'total_price' => 1000,
        ]);
        $version = QuotationVersion::create([
            'quotation_id' => $quotation->id,
            'version_number' => 1,
            'quotation_reference' => $quotation->quotation_reference,
            'snapshot' => ['quotation' => ['reference' => $quotation->quotation_reference], 'items' => []],
            'docx_path' => 'generated/quotations/integrity/revision-1.docx',
            'pdf_path' => 'generated/quotations/integrity/revision-1.pdf',
            'created_by' => $salesperson->id,
            'finalized_at' => now(),
        ]);
        $buyerPo = BuyerPo::create([
            'quotation_id' => $quotation->id,
            'quotation_version_id' => $version->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'po_number' => 'BUYER-PO-INTEGRITY',
            'po_date' => '2026-08-01',
            'po_value' => 1050,
            'currency' => 'OMR',
            'po_file_path' => 'buyer-pos/integrity.pdf',
            'created_by' => $salesperson->id,
            'status' => 'received',
        ]);
        $supplierPo = SupplierPo::create([
            'po_reference' => 'ISC-SPO-INTEGRITY-26',
            'supplier_id' => $vendorSupplier->id,
            'supplier_company_id' => $vendorCompany->id,
            'supplier_contact_id' => $vendorContact->id,
            'buyer_company_id' => $internalCompany->id,
            'buyer_contact_id' => $internalContact->id,
            'incoterm_id' => $incoterm->id,
            'payment_term_days' => 30,
            'delivery_period_min' => 2,
            'delivery_period_max' => 4,
            'delivery_period_unit' => 'weeks',
            'delivery_period_type' => 'working',
            'accepted_invoice_currency' => 'OMR',
            'additional_charges' => 0,
            'subtotal' => 900,
            'total_amount' => 900,
            'created_by' => $salesperson->id,
            'status' => 'issued',
        ]);
        $supplierPoLine = SupplierPoLine::create([
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $quotationItem->id,
            'product_id' => $product->id,
            'manufacturer_id' => $manufacturer->id,
            'line_number' => 1,
            'product_code' => $product->product_code,
            'product_name' => $product->name,
            'title' => $product->title,
            'item_description' => $product->manufacturer_description,
            'quantity' => 1,
            'uom' => 'EA',
            'unit_cost' => 900,
            'total_cost' => 900,
        ]);
        $followUpItem = FollowUpItem::create([
            'supplier_po_line_id' => $supplierPoLine->id,
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $quotationItem->id,
            'assigned_to' => $followUp->id,
            'status' => 'invoice_created',
        ]);
        $invoice = Invoice::create([
            'follow_up_item_id' => $followUpItem->id,
            'invoice_reference' => 'ISC-INV-INTEGRITY-26',
            'invoice_date' => '2026-08-01',
            'payment_term_days' => 30,
            'due_date' => '2026-08-31',
            'currency' => 'OMR',
            'subtotal' => 1000,
            'vat_rate' => 5,
            'vat_amount' => 50,
            'total_amount' => 1050,
            'status' => 'issued',
            'created_by' => $followUp->id,
        ]);

        foreach ($schedules as $schedule) {
            PaymentPlanFollowUp::create([
                'follow_up_item_id' => $followUpItem->id,
                'quotation_payment_schedule_id' => $schedule->id,
                'invoice_id' => $invoice->id,
                'status' => 'pending',
                'due_date' => '2026-08-31',
                'expected_percentage' => 50,
                'expected_amount' => 525,
                'currency' => 'OMR',
            ]);
        }

        return compact(
            'followUp',
            'followUpItem',
            'invoice',
            'quotation',
            'salesperson',
            'schedules',
        );
    }

    private function company(Country $country, string $name, string $code, string $type): Company
    {
        return Company::create([
            'country_id' => $country->id,
            'name' => $name,
            'company_code' => $code,
            'code_slug' => strtolower($code),
            'company_type' => $type,
            'status' => 'active',
        ]);
    }

    private function contact(Company $company, Designation $designation, string $name, string $email): Contact
    {
        return Contact::create([
            'company_id' => $company->id,
            'designation_id' => $designation->id,
            'name' => $name,
            'email' => $email,
            'serves_buyer' => true,
            'serves_supplier' => true,
            'status' => 'active',
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
}
