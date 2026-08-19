<?php

namespace Tests\Feature;

use App\Models\BuyerPo;
use App\Models\BuyerPoItem;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Country;
use App\Models\DeliveryOrder;
use App\Models\DeliveryOrderItem;
use App\Models\Designation;
use App\Models\FollowUpItem;
use App\Models\Incoterm;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ItemFulfilment;
use App\Models\LogisticsCase;
use App\Models\Manufacturer;
use App\Models\PackingList;
use App\Models\PackingListItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationVersion;
use App\Models\Supplier;
use App\Models\SupplierPo;
use App\Models\SupplierPoLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItemWiseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_migration_backfills_legacy_item_links(): void
    {
        $chain = $this->createLegacyItemChain();

        $this->assertDatabaseCount('buyer_po_items', 0);
        $this->assertDatabaseCount('item_fulfilments', 0);

        $migration = require database_path('migrations/2026_08_15_000000_create_item_wise_foundation.php');
        $migration->up();

        $buyerPoItem = BuyerPoItem::query()->firstOrFail();
        $this->assertSame($chain['buyerPo']->id, $buyerPoItem->buyer_po_id);
        $this->assertSame($chain['quotationItem']->id, $buyerPoItem->quotation_item_id);
        $this->assertSame('10.000', (string) $buyerPoItem->quantity);

        $chain['supplierPoLine']->refresh();
        $this->assertSame($buyerPoItem->id, $chain['supplierPoLine']->buyer_po_item_id);

        $chain['followUpItem']->refresh();
        $this->assertSame($buyerPoItem->id, $chain['followUpItem']->buyer_po_item_id);

        $fulfilment = ItemFulfilment::query()->firstOrFail();
        $this->assertSame($chain['quotation']->id, $fulfilment->quotation_id);
        $this->assertSame($chain['supplierPoLine']->id, $fulfilment->supplier_po_line_id);
        $this->assertSame($chain['followUpItem']->id, $fulfilment->follow_up_item_id);
        $this->assertSame('awaiting_acknowledgement', $fulfilment->status);

        $chain['logisticsCase']->refresh();
        $this->assertSame($fulfilment->id, $chain['logisticsCase']->item_fulfilment_id);

        $chain['packingList']->refresh();
        $this->assertSame($chain['quotation']->id, $chain['packingList']->quotation_id);
        $this->assertSame($chain['buyerPo']->id, $chain['packingList']->buyer_po_id);
        $chain['packingListItem']->refresh();
        $this->assertSame($buyerPoItem->id, $chain['packingListItem']->buyer_po_item_id);
        $this->assertSame($fulfilment->id, $chain['packingListItem']->item_fulfilment_id);

        $chain['deliveryOrder']->refresh();
        $this->assertSame($chain['quotation']->id, $chain['deliveryOrder']->quotation_id);
        $this->assertSame($chain['buyerPo']->id, $chain['deliveryOrder']->buyer_po_id);
        $chain['deliveryOrderItem']->refresh();
        $this->assertSame($buyerPoItem->id, $chain['deliveryOrderItem']->buyer_po_item_id);
        $this->assertSame($fulfilment->id, $chain['deliveryOrderItem']->item_fulfilment_id);

        $chain['invoice']->refresh();
        $this->assertSame($chain['quotation']->id, $chain['invoice']->quotation_id);
        $this->assertSame($chain['buyerPo']->id, $chain['invoice']->buyer_po_id);
        $chain['invoiceItem']->refresh();
        $this->assertSame($buyerPoItem->id, $chain['invoiceItem']->buyer_po_item_id);
        $this->assertSame($fulfilment->id, $chain['invoiceItem']->item_fulfilment_id);

        $chain['payment']->refresh();
        $this->assertSame($fulfilment->id, $chain['payment']->item_fulfilment_id);
    }

    public function test_item_wise_foundation_removes_legacy_single_record_database_limits(): void
    {
        $chain = $this->createLegacyItemChain();

        BuyerPo::query()->create([
            'quotation_id' => $chain['quotation']->id,
            'quotation_version_id' => $chain['quotationVersion']->id,
            'buyer_company_id' => $chain['buyerCompany']->id,
            'buyer_contact_id' => $chain['buyerContact']->id,
            'po_number' => '45000002',
            'po_date' => '2026-08-16',
            'po_value' => '250.000',
            'currency' => 'OMR',
            'po_file_path' => 'buyer-pos/legacy-2.pdf',
            'created_by' => $chain['user']->id,
            'status' => 'received',
        ]);

        $secondLine = SupplierPoLine::query()->create([
            'supplier_po_id' => $chain['supplierPo']->id,
            'quotation_id' => $chain['quotation']->id,
            'buyer_po_id' => $chain['buyerPo']->id,
            'quotation_item_id' => $chain['quotationItem']->id,
            'product_id' => $chain['product']->id,
            'manufacturer_id' => $chain['manufacturer']->id,
            'line_number' => 2,
            'product_code' => 'ABB-MOTOR',
            'product_name' => 'Motor',
            'title' => 'Motor',
            'item_description' => '<p>Second split.</p>',
            'quantity' => '2.000',
            'uom' => 'EA',
            'unit_cost' => '20.000',
            'total_cost' => '40.000',
        ]);

        FollowUpItem::query()->create([
            'supplier_po_line_id' => $secondLine->id,
            'supplier_po_id' => $chain['supplierPo']->id,
            'quotation_id' => $chain['quotation']->id,
            'buyer_po_id' => $chain['buyerPo']->id,
            'quotation_item_id' => $chain['quotationItem']->id,
            'status' => 'awaiting_acknowledgement',
        ]);

        PackingList::query()->create([
            'follow_up_item_id' => $chain['followUpItem']->id,
            'packing_list_reference' => 'PL-002',
            'packing_list_date' => '2026-08-18',
            'package_size' => 'Box',
            'gross_weight' => '12 KG',
            'net_weight' => '10 KG',
            'created_by' => $chain['user']->id,
        ]);

        DeliveryOrder::query()->create([
            'follow_up_item_id' => $chain['followUpItem']->id,
            'delivery_order_reference' => 'DO-002',
            'delivery_order_date' => '2026-08-19',
            'delivery_place' => 'Muscat',
            'created_by' => $chain['user']->id,
        ]);

        Invoice::query()->create([
            'follow_up_item_id' => $chain['followUpItem']->id,
            'invoice_reference' => 'INV-002',
            'invoice_date' => '2026-08-20',
            'payment_term_days' => 30,
            'due_date' => '2026-09-19',
            'currency' => 'OMR',
            'subtotal' => '25.000',
            'vat_rate' => '0.000',
            'vat_amount' => '0.000',
            'total_amount' => '25.000',
            'created_by' => $chain['user']->id,
        ]);

        $this->assertSame(2, BuyerPo::query()->where('quotation_id', $chain['quotation']->id)->count());
        $this->assertSame(2, SupplierPoLine::query()->where('quotation_item_id', $chain['quotationItem']->id)->count());
        $this->assertSame(2, FollowUpItem::query()->where('supplier_po_id', $chain['supplierPo']->id)->where('quotation_item_id', $chain['quotationItem']->id)->count());
        $this->assertSame(2, PackingList::query()->where('follow_up_item_id', $chain['followUpItem']->id)->count());
        $this->assertSame(2, DeliveryOrder::query()->where('follow_up_item_id', $chain['followUpItem']->id)->count());
        $this->assertSame(2, Invoice::query()->where('follow_up_item_id', $chain['followUpItem']->id)->count());

        $this->assertTrue(Schema::hasTable('buyer_po_items'));
        $this->assertTrue(Schema::hasTable('item_fulfilments'));
    }

    /**
     * @return array<string, mixed>
     */
    private function createLegacyItemChain(): array
    {
        $country = Country::query()->create([
            'name' => 'Oman',
            'country_code' => 'OM',
            'phone_code' => '+968',
            'status' => 'active',
        ]);
        $designation = Designation::query()->create([
            'name' => 'Mr.',
            'code' => 'MR',
            'status' => 'active',
        ]);
        $buyerCompany = Company::query()->create([
            'country_id' => $country->id,
            'name' => 'Buyer LLC',
            'company_code' => 'BYR',
            'code_slug' => 'byr',
            'company_type' => 'buyer',
            'status' => 'active',
        ]);
        $buyerContact = Contact::query()->create([
            'company_id' => $buyerCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Buyer Contact',
            'email' => 'buyer@example.test',
            'status' => 'active',
        ]);
        $internalCompany = Company::query()->create([
            'country_id' => $country->id,
            'name' => 'Industrial Supplies Center LLC',
            'company_code' => 'ISC',
            'code_slug' => 'isc',
            'company_type' => 'internal',
            'status' => 'active',
        ]);
        $internalContact = Contact::query()->create([
            'company_id' => $internalCompany->id,
            'designation_id' => $designation->id,
            'name' => 'ISC Contact',
            'email' => 'isc@example.test',
            'status' => 'active',
        ]);
        $supplierCompany = Company::query()->create([
            'country_id' => $country->id,
            'name' => 'ABB LLC',
            'company_code' => 'ABB',
            'code_slug' => 'abb',
            'company_type' => 'supplier',
            'status' => 'active',
        ]);
        $supplierContact = Contact::query()->create([
            'company_id' => $supplierCompany->id,
            'designation_id' => $designation->id,
            'name' => 'Supplier Contact',
            'email' => 'supplier@example.test',
            'status' => 'active',
        ]);
        $supplier = Supplier::query()->create([
            'company_id' => $supplierCompany->id,
            'primary_contact_id' => $supplierContact->id,
            'status' => 'active',
        ]);
        $user = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $incoterm = Incoterm::query()->create([
            'code' => 'DDP',
            'name' => 'Delivered Duty Paid',
            'reminder_days_before_delivery' => 30,
            'status' => 'active',
        ]);
        $manufacturer = Manufacturer::query()->create([
            'country_id' => $country->id,
            'name' => 'ABB',
            'status' => 'active',
        ]);
        $product = Product::query()->create([
            'manufacturer_id' => $manufacturer->id,
            'product_code' => 'ABB-MOTOR',
            'name' => 'Motor',
            'title' => 'Motor',
            'buyer_description' => '<p>Buyer motor.</p>',
            'manufacturer_description' => '<p>Supplier motor.</p>',
            'last_uom' => 'EA',
            'last_unit_price' => '25.000',
            'status' => 'active',
        ]);
        $quotation = Quotation::query()->create([
            'quotation_reference' => 'QT-001',
            'salesperson_id' => $user->id,
            'supplier_company_id' => $internalCompany->id,
            'supplier_contact_id' => $internalContact->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'quotation_validity_value' => 30,
            'quotation_validity_unit' => 'days',
            'payment_term_days' => 30,
            'delivery_period_min' => 1,
            'delivery_period_max' => 2,
            'delivery_period_unit' => 'weeks',
            'delivery_period_type' => 'working',
            'accepted_invoice_currency' => 'OMR',
            'incoterm_id' => $incoterm->id,
            'delivery_responsibility' => 'isc',
            'status' => 'buyer_po_received',
        ]);
        $quotationItem = QuotationItem::query()->create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'manufacturer_id' => $manufacturer->id,
            'line_number' => 1,
            'product_code' => 'ABB-MOTOR',
            'product_name' => 'Motor',
            'title' => 'Motor',
            'buyer_description' => '<p>Buyer motor.</p>',
            'manufacturer_description' => '<p>Supplier motor.</p>',
            'quantity' => '10.000',
            'uom' => 'EA',
            'unit_price' => '25.000',
            'total_price' => '250.000',
        ]);
        $quotationVersion = QuotationVersion::query()->create([
            'quotation_id' => $quotation->id,
            'version_number' => 1,
            'quotation_reference' => $quotation->quotation_reference,
            'snapshot' => ['quotation' => ['reference' => $quotation->quotation_reference]],
            'docx_path' => 'generated/quotations/1/revision-1/test.docx',
            'pdf_path' => 'generated/quotations/1/revision-1/test.pdf',
            'created_by' => $user->id,
            'finalized_at' => now(),
        ]);
        $buyerPo = BuyerPo::query()->create([
            'quotation_id' => $quotation->id,
            'quotation_version_id' => $quotationVersion->id,
            'buyer_company_id' => $buyerCompany->id,
            'buyer_contact_id' => $buyerContact->id,
            'po_number' => '45000001',
            'po_date' => '2026-08-15',
            'po_value' => '250.000',
            'currency' => 'OMR',
            'po_file_path' => 'buyer-pos/legacy.pdf',
            'created_by' => $user->id,
            'status' => 'received',
        ]);
        $supplierPo = SupplierPo::query()->create([
            'po_reference' => 'SPO-001',
            'supplier_id' => $supplier->id,
            'supplier_company_id' => $supplierCompany->id,
            'supplier_contact_id' => $supplierContact->id,
            'buyer_company_id' => $internalCompany->id,
            'buyer_contact_id' => $internalContact->id,
            'incoterm_id' => $incoterm->id,
            'payment_term_days' => 30,
            'delivery_period_min' => 1,
            'delivery_period_max' => 2,
            'delivery_period_unit' => 'weeks',
            'delivery_period_type' => 'working',
            'accepted_invoice_currency' => 'OMR',
            'subtotal' => '200.000',
            'total_amount' => '200.000',
            'created_by' => $user->id,
            'status' => 'issued',
        ]);
        $supplierPoLine = SupplierPoLine::query()->create([
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $quotationItem->id,
            'product_id' => $product->id,
            'manufacturer_id' => $manufacturer->id,
            'line_number' => 1,
            'product_code' => 'ABB-MOTOR',
            'product_name' => 'Motor',
            'title' => 'Motor',
            'item_description' => '<p>Supplier motor.</p>',
            'quantity' => '10.000',
            'uom' => 'EA',
            'unit_cost' => '20.000',
            'total_cost' => '200.000',
        ]);
        $followUpItem = FollowUpItem::query()->create([
            'supplier_po_line_id' => $supplierPoLine->id,
            'supplier_po_id' => $supplierPo->id,
            'quotation_id' => $quotation->id,
            'buyer_po_id' => $buyerPo->id,
            'quotation_item_id' => $quotationItem->id,
            'status' => 'awaiting_acknowledgement',
        ]);
        $logisticsCase = LogisticsCase::query()->create([
            'follow_up_item_id' => $followUpItem->id,
            'delivery_responsibility' => 'isc',
            'status' => 'warehouse_received',
            'eta_at' => now(),
            'received_quantity' => '10.000',
            'created_by' => $user->id,
        ]);
        $packingList = PackingList::query()->create([
            'follow_up_item_id' => $followUpItem->id,
            'packing_list_reference' => 'PL-001',
            'packing_list_date' => '2026-08-18',
            'package_size' => 'Box',
            'gross_weight' => '12 KG',
            'net_weight' => '10 KG',
            'created_by' => $user->id,
        ]);
        $packingListItem = PackingListItem::query()->create([
            'packing_list_id' => $packingList->id,
            'quotation_item_id' => $quotationItem->id,
            'buyer_po_id' => $buyerPo->id,
            'line_number' => 1,
            'item_description' => '<p>Buyer motor.</p>',
            'quantity' => '10.000',
            'uom' => 'EA',
            'package_size' => 'Box',
            'gross_weight' => '12 KG',
            'net_weight' => '10 KG',
        ]);
        $deliveryOrder = DeliveryOrder::query()->create([
            'follow_up_item_id' => $followUpItem->id,
            'delivery_order_reference' => 'DO-001',
            'delivery_order_date' => '2026-08-19',
            'delivery_place' => 'Muscat',
            'created_by' => $user->id,
        ]);
        $deliveryOrderItem = DeliveryOrderItem::query()->create([
            'delivery_order_id' => $deliveryOrder->id,
            'quotation_item_id' => $quotationItem->id,
            'buyer_po_id' => $buyerPo->id,
            'line_number' => 1,
            'item_description' => '<p>Buyer motor.</p>',
            'quantity' => '10.000',
            'uom' => 'EA',
        ]);
        $invoice = Invoice::query()->create([
            'follow_up_item_id' => $followUpItem->id,
            'delivery_order_id' => $deliveryOrder->id,
            'invoice_reference' => 'INV-001',
            'invoice_date' => '2026-08-20',
            'payment_term_days' => 30,
            'due_date' => '2026-09-19',
            'currency' => 'OMR',
            'subtotal' => '250.000',
            'vat_rate' => '0.000',
            'vat_amount' => '0.000',
            'total_amount' => '250.000',
            'created_by' => $user->id,
        ]);
        $invoiceItem = InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'quotation_item_id' => $quotationItem->id,
            'buyer_po_id' => $buyerPo->id,
            'delivery_order_id' => $deliveryOrder->id,
            'line_number' => 1,
            'item_description' => '<p>Buyer motor.</p>',
            'quantity' => '10.000',
            'uom' => 'EA',
            'unit_price' => '25.000',
            'total_price' => '250.000',
        ]);
        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'follow_up_item_id' => $followUpItem->id,
            'amount' => '100.000',
            'currency' => 'OMR',
            'payment_date' => '2026-08-21',
            'recorded_by' => $user->id,
        ]);

        return compact(
            'buyerCompany',
            'buyerContact',
            'buyerPo',
            'deliveryOrder',
            'deliveryOrderItem',
            'followUpItem',
            'invoice',
            'invoiceItem',
            'logisticsCase',
            'manufacturer',
            'packingList',
            'packingListItem',
            'payment',
            'product',
            'quotation',
            'quotationItem',
            'quotationVersion',
            'supplierPo',
            'supplierPoLine',
            'user',
        );
    }
}
