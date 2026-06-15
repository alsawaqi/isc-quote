<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Designation;
use App\Models\FollowUpAuditLog;
use App\Models\FollowUpComment;
use App\Models\FollowUpItem;
use App\Models\Incoterm;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationActivityLog;
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

class AdminTraceabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_quotation_trace_and_open_full_quotation_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00'));

        try {
            $context = $this->traceContext();
            $oxy = $this->createTraceJob($context, 'Occidental Of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor', 'payment_pending');
            $gpl = $this->createTraceJob($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box', 'awaiting_acknowledgement');

            $response = $this->withBearerToken($context['admin'])
                ->getJson('/api/admin/trace/quotations?search=Occidental&status=buyer_po_received')
                ->assertOk()
                ->assertJsonPath('summary.quotations', 1)
                ->assertJsonPath('summary.items', 1)
                ->assertJsonPath('data.0.id', $oxy['quotation']->id)
                ->assertJsonPath('data.0.quotation_reference', $oxy['quotation']->quotation_reference)
                ->assertJsonPath('data.0.buyer_company_name', 'Occidental Of Oman, Inc')
                ->assertJsonPath('data.0.buyer_po_numbers.0', '4502757812')
                ->assertJsonPath('data.0.supplier_po_references.0', $oxy['supplierPo']->po_reference)
                ->assertJsonPath('data.0.current_stage_label', 'Payment / Close')
                ->assertJsonPath('data.0.latest_comment.comment', 'Accounts called buyer for payment status.')
                ->assertJsonPath('data.0.detail_url', "/admin/trace/quotations/{$oxy['quotation']->id}");

            $this->assertNotContains($gpl['quotation']->id, collect($response->json('data'))->pluck('id')->all());
            $this->assertStringNotContainsString('_', $response->json('data.0.status_label'));
            $this->assertStringNotContainsString('_', $response->json('data.0.current_stage_label'));

            $detail = $this->withBearerToken($context['admin'])
                ->getJson("/api/admin/trace/quotations/{$oxy['quotation']->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $oxy['quotation']->id)
                ->assertJsonPath('data.items.0.quotation_item_id', $oxy['item']->id)
                ->assertJsonPath('data.items.0.buyer_po_number', '4502757812')
                ->assertJsonPath('data.items.0.supplier_po_reference', $oxy['supplierPo']->po_reference)
                ->assertJsonPath('data.items.0.status_label', 'Payment Pending')
                ->assertJsonPath('data.items.0.comments.0.comment', 'Accounts called buyer for payment status.')
                ->assertJsonPath('data.items.0.timeline_events.0.source', 'quotation')
                ->assertJsonPath('data.items.0.timeline_events.1.source', 'follow_up');

            $this->assertNotNull($detail->json('data.items.0.timeline_events.1.elapsed_from_previous_label'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_admin_can_filter_item_trace_by_product_and_supplier_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00'));

        try {
            $context = $this->traceContext();
            $first = $this->createTraceJob($context, 'Occidental Of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor', 'payment_pending');
            $second = $this->createTraceJob($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box', 'awaiting_acknowledgement');

            $response = $this->withBearerToken($context['admin'])
                ->getJson('/api/admin/trace/items?search=Flameproof&status=payment_pending')
                ->assertOk()
                ->assertJsonPath('summary.items', 1)
                ->assertJsonPath('data.0.quotation_item_id', $first['item']->id)
                ->assertJsonPath('data.0.product_name', 'ABB Flameproof Motor')
                ->assertJsonPath('data.0.quotation_reference', $first['quotation']->quotation_reference)
                ->assertJsonPath('data.0.buyer_po_number', '4502757812')
                ->assertJsonPath('data.0.supplier_po_reference', $first['supplierPo']->po_reference)
                ->assertJsonPath('data.0.supplier_company_name', 'ABB LLC')
                ->assertJsonPath('data.0.current_stage_label', 'Payment / Close')
                ->assertJsonPath('data.0.comments_count', 1)
                ->assertJsonPath('data.0.latest_comment.comment', 'Accounts called buyer for payment status.')
                ->assertJsonPath('data.0.timeline_events.1.action', 'invoice.sent');

            $this->assertNotContains($second['item']->id, collect($response->json('data'))->pluck('quotation_item_id')->all());
            $this->assertContains('manufacturer_id', collect($response->json('filter_options'))->keys()->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_salesperson_cannot_access_admin_traceability_api(): void
    {
        $context = $this->traceContext();

        $this->withBearerToken($context['salesperson'])
            ->getJson('/api/admin/trace/items')
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function traceContext(): array
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
     * @return array{quotation: Quotation, item: QuotationItem, buyerPo: BuyerPo, supplierPo: SupplierPo, followUpItem: FollowUpItem}
     */
    private function createTraceJob(array $context, string $buyerName, string $buyerCode, string $buyerPoNumber, string $productName, string $status): array
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
            'quotation_reference' => "ISC-COR-QT-{$buyerCode}-26",
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
            'status' => 'buyer_po_received',
        ]);
        $product = Product::create([
            'manufacturer_id' => $context['manufacturer']->id,
            'name' => $productName,
            'title' => $productName,
            'buyer_description' => '<p>Buyer visible description.</p>',
            'manufacturer_description' => '<p>Supplier technical description.</p>',
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
        $version = QuotationVersion::create([
            'quotation_id' => $quotation->id,
            'version_number' => 1,
            'quotation_reference' => $quotation->quotation_reference,
            'snapshot' => ['quotation' => ['reference' => $quotation->quotation_reference]],
            'docx_path' => "generated/quotations/{$quotation->id}/revision-1/test.docx",
            'pdf_path' => "generated/quotations/{$quotation->id}/revision-1/test.pdf",
            'created_by' => $context['salesperson']->id,
            'finalized_at' => now()->subDays(12),
        ]);
        $buyerPo = BuyerPo::create([
            'quotation_id' => $quotation->id,
            'quotation_version_id' => $version->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'po_number' => $buyerPoNumber,
            'po_date' => now()->subDays(10)->toDateString(),
            'po_value' => $item->total_price,
            'currency' => 'OMR',
            'po_file_path' => "buyer-pos/{$quotation->id}/{$buyerPoNumber}.pdf",
            'created_by' => $context['salesperson']->id,
            'status' => 'received',
        ]);
        $supplierPo = SupplierPo::create([
            'po_reference' => "ISC-COR-PO-{$buyerCode}-ABB-26",
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
            'finalized_at' => now()->subDays(8),
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
            'title' => $item->title,
            'item_description' => $item->manufacturer_description,
            'quantity' => '1.000',
            'uom' => 'EA',
            'unit_cost' => '5041.350',
            'total_cost' => '5041.350',
        ]);
        $followUpItem = FollowUpItem::create([
            'supplier_po_line_id' => $line->id,
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $item->id,
            'assigned_to' => $context['followUp']->id,
            'status' => $status,
            'reminder_interval_value' => 2,
            'reminder_interval_unit' => 'weeks',
            'next_follow_up_at' => now()->addWeek(),
            'last_comment_at' => now()->subDays(1),
        ]);

        $quotationLog = QuotationActivityLog::create([
            'quotation_id' => $quotation->id,
            'quotation_version_id' => $version->id,
            'user_id' => $context['salesperson']->id,
            'action' => 'quotation.version_created',
            'summary' => 'Quotation version 1 created.',
            'properties' => ['version_number' => 1],
        ]);
        $quotationLog->forceFill([
            'created_at' => now()->subDays(12),
            'updated_at' => now()->subDays(12),
        ])->save();
        FollowUpAuditLog::create([
            'follow_up_item_id' => $followUpItem->id,
            'user_id' => $context['followUp']->id,
            'stage' => $status === 'awaiting_acknowledgement' ? 'acknowledgement' : 'payment',
            'action' => $status === 'awaiting_acknowledgement' ? 'supplier_po.sent' : 'invoice.sent',
            'summary' => $status === 'awaiting_acknowledgement' ? 'Supplier PO sent for acknowledgement.' : 'Invoice marked as sent.',
            'properties' => ['status' => $status],
            'occurred_at' => now()->subDays(2),
        ]);
        FollowUpComment::create([
            'follow_up_item_id' => $followUpItem->id,
            'user_id' => $context['followUp']->id,
            'stage' => $status === 'awaiting_acknowledgement' ? 'acknowledgement' : 'payment',
            'comment' => $status === 'awaiting_acknowledgement'
                ? 'Sent supplier acknowledgement reminder.'
                : 'Accounts called buyer for payment status.',
            'communication_type' => 'call',
            'contacted_person' => 'Buyer account team',
            'next_action' => 'Follow up again next week.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return compact('buyerPo', 'followUpItem', 'item', 'quotation', 'supplierPo');
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
