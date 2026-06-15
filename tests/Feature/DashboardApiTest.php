<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\FollowUpItem;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationVersion;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_returns_live_database_counts_jobs_and_alerts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 10:00:00'));

        try {
            $context = $this->dashboardContext();

            $this->createQuotation($context, 'ISC-COR-QT-001-PEN-26', 'Pending Energy LLC', 'PEN', 'draft', null);
            $this->createQuotation($context, 'ISC-COR-QT-002-WPO-26', 'Waiting PO LLC', 'WPO', 'buyer_po_received', '45000001');
            $supplierPoQuotation = $this->createQuotation($context, 'ISC-COR-QT-003-OXY-26', 'Occidental Of Oman, Inc', 'OXY', 'buyer_po_received', '45000002');
            $supplierPo = $this->createSupplierPoWithFollowUp($context, $supplierPoQuotation['quotation'], $supplierPoQuotation['item'], $supplierPoQuotation['buyerPo']);

            $response = $this->withBearerToken($context['admin'])
                ->getJson('/api/dashboard')
                ->assertOk()
                ->assertJsonPath('metrics.0.label', 'Pending Quotations')
                ->assertJsonPath('metrics.0.value', '1')
                ->assertJsonPath('metrics.1.label', 'Buyer POs Pending')
                ->assertJsonPath('metrics.1.value', '1')
                ->assertJsonPath('metrics.2.label', 'Supplier POs Awaiting Ack')
                ->assertJsonPath('metrics.2.value', '1')
                ->assertJsonPath('metrics.3.label', 'Follow-Ups Due')
                ->assertJsonPath('metrics.3.value', '1')
                ->assertJsonPath('workflowStages.0.label', 'RFQ')
                ->assertJsonPath('workflowStages.0.value', '3')
                ->assertJsonPath('workflowStages.2.label', 'Buyer PO')
                ->assertJsonPath('workflowStages.2.value', '2')
                ->assertJsonPath('workflowStages.3.label', 'Supplier PO')
                ->assertJsonPath('workflowStages.3.value', '1')
                ->assertJsonPath('recentJobs.0.jobRef', $supplierPo->po_reference)
                ->assertJsonPath('recentJobs.0.buyer', 'Occidental Of Oman, Inc')
                ->assertJsonPath('recentJobs.0.stage', 'Order Acknowledgement')
                ->assertJsonPath('recentJobs.0.dueTone', 'danger')
                ->assertJsonPath('alerts.0.title', 'Overdue')
                ->assertJsonPath('alerts.0.jobRef', $supplierPo->po_reference)
                ->assertJsonPath('alerts.0.dueValue', 'overdue');

            $this->assertStringNotContainsString('_', $response->json('recentJobs.0.stage'));
            $this->assertStringNotContainsString('_', $response->json('alerts.0.detail'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_api_is_admin_only(): void
    {
        $context = $this->dashboardContext();

        $this->withBearerToken($context['salesperson'])
            ->getJson('/api/dashboard')
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardContext(): array
    {
        $adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'is_system' => true, 'status' => 'active']);
        $salesRole = Role::create(['name' => 'Salesperson', 'slug' => 'salesperson', 'is_system' => true, 'status' => 'active']);
        $followRole = Role::create(['name' => 'Follow-Up', 'slug' => 'follow-up', 'is_system' => true, 'status' => 'active']);

        $admin = User::create(['name' => 'Ahmed Mansoor', 'email' => 'admin@example.test', 'password' => Hash::make('password'), 'status' => 'active']);
        $admin->roles()->attach($adminRole);
        $salesperson = User::create(['name' => 'Manu Thuruthel', 'email' => 'sales@example.test', 'password' => Hash::make('password'), 'status' => 'active']);
        $salesperson->roles()->attach($salesRole);
        $followUp = User::create(['name' => 'Sara Follow', 'email' => 'follow@example.test', 'password' => Hash::make('password'), 'status' => 'active']);
        $followUp->roles()->attach($followRole);

        $country = Country::create(['name' => 'Oman', 'country_code' => 'OM', 'phone_code' => '+968', 'status' => 'active']);
        $designation = Designation::create(['name' => 'Mr.', 'code' => 'MR', 'status' => 'active']);
        $incoterm = Incoterm::create(['code' => 'DDP', 'name' => 'Delivered Duty Paid', 'reminder_days_before_delivery' => 40, 'status' => 'active']);
        $internalCompany = Company::create(['country_id' => $country->id, 'name' => 'Industrial Supplies Center LLC', 'company_code' => 'ISC', 'code_slug' => 'isc', 'company_type' => 'internal', 'status' => 'active']);
        $internalContact = Contact::create(['company_id' => $internalCompany->id, 'designation_id' => $designation->id, 'name' => 'Manu Thuruthel', 'email' => 'manu@example.test', 'status' => 'active']);
        $manufacturer = Manufacturer::create(['country_id' => $country->id, 'name' => 'ABB', 'status' => 'active']);
        $supplierCompany = Company::create(['country_id' => $country->id, 'name' => 'ABB LLC', 'company_code' => 'ABB', 'code_slug' => 'abb', 'company_type' => 'supplier', 'status' => 'active']);
        $supplierContact = Contact::create(['company_id' => $supplierCompany->id, 'designation_id' => $designation->id, 'name' => 'ABB Contact', 'email' => 'abb@example.test', 'status' => 'active']);
        $supplier = Supplier::create(['company_id' => $supplierCompany->id, 'primary_contact_id' => $supplierContact->id, 'manufacturer_id' => $manufacturer->id, 'status' => 'active']);

        return compact('admin', 'country', 'designation', 'followUp', 'incoterm', 'internalCompany', 'internalContact', 'manufacturer', 'salesperson', 'supplier', 'supplierCompany', 'supplierContact');
    }

    /**
     * @return array{quotation: Quotation, item: QuotationItem, buyerPo: BuyerPo|null}
     */
    private function createQuotation(array $context, string $reference, string $buyerName, string $buyerCode, string $status, ?string $buyerPoNumber): array
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
        $quotation = Quotation::create([
            'quotation_reference' => $reference,
            'salesperson_id' => $context['salesperson']->id,
            'supplier_company_id' => $context['internalCompany']->id,
            'supplier_contact_id' => $context['internalContact']->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'rfq_number' => '6000024422',
            'pr_number' => '11729328',
            'closing_at' => now()->addDays(5),
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
        $product = Product::create([
            'manufacturer_id' => $context['manufacturer']->id,
            'name' => "{$buyerCode} Motor",
            'title' => "{$buyerCode} Motor",
            'buyer_description' => '<p>Buyer visible description.</p>',
            'manufacturer_description' => '<p>Supplier visible description.</p>',
            'last_uom' => 'EA',
            'last_unit_price' => '3256.000',
            'status' => 'active',
        ]);
        $item = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'manufacturer_id' => $context['manufacturer']->id,
            'line_number' => 1,
            'product_name' => $product->name,
            'title' => $product->title,
            'buyer_description' => $product->buyer_description,
            'manufacturer_description' => $product->manufacturer_description,
            'quantity' => '1.000',
            'uom' => 'EA',
            'unit_price' => '3256.000',
            'total_price' => '3256.000',
        ]);

        $buyerPo = null;

        if ($buyerPoNumber !== null) {
            $version = QuotationVersion::create([
                'quotation_id' => $quotation->id,
                'version_number' => 1,
                'quotation_reference' => $reference,
                'snapshot' => ['quotation' => ['reference' => $reference]],
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
        }

        return compact('buyerPo', 'item', 'quotation');
    }

    private function createSupplierPoWithFollowUp(array $context, Quotation $quotation, QuotationItem $item, BuyerPo $buyerPo): SupplierPo
    {
        $supplierPo = SupplierPo::create([
            'po_reference' => 'ISC-COR-PO-001-ABB-26',
            'supplier_id' => $context['supplier']->id,
            'supplier_company_id' => $context['supplierCompany']->id,
            'supplier_contact_id' => $context['supplierContact']->id,
            'buyer_company_id' => $context['internalCompany']->id,
            'buyer_contact_id' => $context['internalContact']->id,
            'incoterm_id' => $context['incoterm']->id,
            'supplier_quote_reference' => 'E-mail',
            'payment_term_days' => 30,
            'delivery_period_min' => 22,
            'delivery_period_max' => 24,
            'delivery_period_unit' => 'weeks',
            'delivery_period_type' => 'working',
            'accepted_invoice_currency' => 'USD',
            'subtotal' => '5041.350',
            'total_amount' => '5041.350',
            'created_by' => $context['salesperson']->id,
            'finalized_at' => now(),
            'status' => 'issued',
        ]);
        $line = SupplierPoLine::create([
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $item->id,
            'product_id' => $item->product_id,
            'manufacturer_id' => $item->manufacturer_id,
            'line_number' => 1,
            'product_name' => $item->product_name,
            'title' => 'ABB Flameproof Motor',
            'item_description' => $item->manufacturer_description,
            'quantity' => '1.000',
            'uom' => 'EA',
            'unit_cost' => '5041.350',
            'total_cost' => '5041.350',
        ]);
        FollowUpItem::create([
            'supplier_po_line_id' => $line->id,
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $item->id,
            'assigned_to' => $context['followUp']->id,
            'status' => 'awaiting_acknowledgement',
            'reminder_interval_value' => 2,
            'reminder_interval_unit' => 'weeks',
            'next_follow_up_at' => now()->subDay(),
        ]);

        return $supplierPo;
    }

    private function withBearerToken(User $user): self
    {
        $testNow = Carbon::getTestNow();
        Carbon::setTestNow();

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        Carbon::setTestNow($testNow);

        return $this
            ->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json');
    }
}
