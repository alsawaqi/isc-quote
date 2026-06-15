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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FollowUpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_po_creation_creates_assigned_follow_up_items_for_each_supplier_po_line(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $second = $this->acceptedQuotationItem($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box');

        $supplierPo = $this->createSupplierPo($context, [$first['item'], $second['item']]);

        $this->assertDatabaseHas('follow_up_items', [
            'supplier_po_id' => $supplierPo['id'],
            'quotation_id' => $first['quotation']->id,
            'buyer_po_id' => $first['buyerPo']->id,
            'quotation_item_id' => $first['item']->id,
            'assigned_to' => $context['followUp']->id,
            'status' => 'awaiting_acknowledgement',
        ]);
        $this->assertDatabaseHas('follow_up_items', [
            'supplier_po_id' => $supplierPo['id'],
            'quotation_id' => $second['quotation']->id,
            'buyer_po_id' => $second['buyerPo']->id,
            'quotation_item_id' => $second['item']->id,
            'assigned_to' => $context['followUp']->id,
            'status' => 'awaiting_acknowledgement',
        ]);

        $response = $this->withBearerToken($context['followUp'])
            ->getJson('/api/follow-up')
            ->assertOk();

        $response->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.awaiting_acknowledgement', 2)
            ->assertJsonPath('data.0.supplier_po_reference', $supplierPo['po_reference'])
            ->assertJsonPath('data.0.assigned_to_name', $context['followUp']->name);

        $this->withBearerToken($context['salesperson'])
            ->getJson('/api/follow-up')
            ->assertForbidden();
    }

    public function test_follow_up_dashboard_returns_due_reminder_queue_for_open_items(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');

        try {
            $context = $this->followUpContext();
            $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
            $second = $this->acceptedQuotationItem($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box');
            $closed = $this->acceptedQuotationItem($context, 'Desert Energy FZE', 'DEF', '4502760001', 'ABB Panel');
            $supplierPo = $this->createSupplierPo($context, [$first['item'], $second['item'], $closed['item']]);
            $this->withBearerToken($context['followUp']);
            Carbon::setTestNow(Carbon::parse('2026-06-05 09:00:00'));
            $ids = DB::table('follow_up_items')
                ->where('supplier_po_id', $supplierPo['id'])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            DB::table('follow_up_items')->where('id', $ids[0])->update([
                'status' => 'shipping_documents_complete',
                'next_follow_up_at' => '2026-06-04 08:00:00',
            ]);
            DB::table('follow_up_items')->where('id', $ids[1])->update([
                'status' => 'logistics_eta_recorded',
                'next_follow_up_at' => '2026-06-05 12:00:00',
            ]);
            DB::table('follow_up_items')->where('id', $ids[2])->update([
                'status' => 'closed',
                'next_follow_up_at' => '2026-06-03 12:00:00',
            ]);

            $response = $this->getJson('/api/follow-up')
                ->assertOk();

            $response->assertJsonPath('summary.overdue', 1)
                ->assertJsonPath('summary.due_today', 1)
                ->assertJsonPath('due_reminders.0.id', $ids[0])
                ->assertJsonPath('due_reminders.0.due_state', 'overdue')
                ->assertJsonPath('due_reminders.1.id', $ids[1])
                ->assertJsonPath('due_reminders.1.due_state', 'due_today');

            $this->assertCount(2, $response->json('due_reminders'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_follow_up_dashboard_returns_grouped_action_and_filtered_buyer_views(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');

        try {
            $context = $this->followUpContext();
            $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
            $second = $this->acceptedQuotationItem($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box');
            $third = $this->acceptedQuotationItem($context, 'Desert Energy FZE', 'DEF', '4502760001', 'ABB Panel');
            $supplierPo = $this->createSupplierPo($context, [$first['item'], $second['item'], $third['item']]);
            $this->withBearerToken($context['followUp']);
            Carbon::setTestNow(Carbon::parse('2026-06-05 09:00:00'));
            $ids = DB::table('follow_up_items')
                ->where('supplier_po_id', $supplierPo['id'])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            DB::table('follow_up_items')->where('id', $ids[0])->update([
                'status' => 'awaiting_acknowledgement',
                'next_follow_up_at' => '2026-06-04 08:00:00',
            ]);
            DB::table('follow_up_items')->where('id', $ids[1])->update([
                'status' => 'shipping_documents_complete',
                'next_follow_up_at' => '2026-06-05 12:00:00',
            ]);
            DB::table('follow_up_items')->where('id', $ids[2])->update([
                'status' => 'ready_for_invoice',
                'next_follow_up_at' => '2026-06-20 12:00:00',
            ]);

            $actionResponse = $this->getJson('/api/follow-up?group_by=action')
                ->assertOk();
            $actionGroups = collect($actionResponse->json('groups'));

            $this->assertSame('action', $actionResponse->json('group_by'));
            $this->assertSame(3, $actionResponse->json('summary.total'));
            $this->assertSame(1, $actionGroups->firstWhere('key', 'overdue')['count']);
            $this->assertSame($ids[0], $actionGroups->firstWhere('key', 'overdue')['items'][0]['id']);
            $this->assertSame(1, $actionGroups->firstWhere('key', 'due_today')['count']);
            $this->assertSame(1, $actionGroups->firstWhere('key', 'ready_for_invoice')['count']);
            $this->assertContains('buyer', collect($actionResponse->json('filter_options.group_by'))->pluck('value')->all());

            $overdueResponse = $this->getJson('/api/follow-up?group_by=action&action=overdue')
                ->assertOk();

            $this->assertSame(1, $overdueResponse->json('summary.total'));
            $this->assertSame([$ids[0]], collect($overdueResponse->json('data'))->pluck('id')->all());

            $buyerResponse = $this->getJson('/api/follow-up?group_by=buyer&search=Global')
                ->assertOk();
            $buyerGroups = collect($buyerResponse->json('groups'));

            $this->assertSame('buyer', $buyerResponse->json('group_by'));
            $this->assertSame(1, $buyerResponse->json('summary.total'));
            $this->assertSame('Global Petrochem Ltd.', $buyerGroups->first()['label']);
            $this->assertSame([$ids[1]], collect($buyerResponse->json('data'))->pluck('id')->all());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_notifications_return_actionable_follow_up_alerts_with_clean_labels(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');

        try {
            $context = $this->followUpContext();
            $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
            $second = $this->acceptedQuotationItem($context, 'Global Petrochem Ltd.', 'GPL', '4502759999', 'ABB Terminal Box');
            $supplierPo = $this->createSupplierPo($context, [$first['item'], $second['item']]);
            $this->withBearerToken($context['followUp']);
            Carbon::setTestNow(Carbon::parse('2026-06-05 09:00:00'));
            $ids = DB::table('follow_up_items')
                ->where('supplier_po_id', $supplierPo['id'])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            DB::table('follow_up_items')->where('id', $ids[0])->update([
                'status' => 'awaiting_acknowledgement',
                'next_follow_up_at' => '2026-06-04 08:00:00',
            ]);
            DB::table('follow_up_items')->where('id', $ids[1])->update([
                'status' => 'shipping_documents_complete',
                'next_follow_up_at' => '2026-06-05 12:00:00',
            ]);

            $response = $this->getJson('/api/notifications')
                ->assertOk()
                ->assertJsonPath('unread_count', 2)
                ->assertJsonPath('data.0.type', 'overdue')
                ->assertJsonPath('data.0.title', 'Overdue Follow-Up')
                ->assertJsonPath('data.0.action_url', "/follow-up/{$ids[0]}")
                ->assertJsonPath('data.1.type', 'due_today')
                ->assertJsonPath('data.1.title', 'Follow-Up Due Today');

            $this->assertStringNotContainsString('_', $response->json('data.0.stage_label'));
            $this->assertStringNotContainsString('_', $response->json('data.1.stage_label'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_follow_up_user_can_set_reminder_add_comment_and_record_acknowledgement(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        try {
            $context = $this->followUpContext();
            $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
            $supplierPo = $this->createSupplierPo($context, [$first['item']]);
            $followUpItemId = (int) DB::table('follow_up_items')
                ->where('supplier_po_id', $supplierPo['id'])
                ->value('id');

            $this->withBearerToken($context['followUp']);
            Carbon::setTestNow(Carbon::parse('2026-06-05 09:00:00'));

            $this->putJson("/api/follow-up/{$followUpItemId}/reminder", [
                    'reminder_interval_value' => 2,
                    'reminder_interval_unit' => 'weeks',
                ])
                ->assertOk()
                ->assertJsonPath('data.next_follow_up_at', '2026-06-19 09:00:00');

            Carbon::setTestNow(Carbon::parse('2026-06-07 11:30:00'));

            $this->postJson("/api/follow-up/{$followUpItemId}/comments", [
                    'comment' => 'Sent email to ABB requesting order acknowledgement.',
                    'stage' => 'acknowledgement',
                    'communication_type' => 'email',
                    'contacted_person' => 'Omid',
                    'next_action' => 'Wait for acknowledgement copy.',
                ])
                ->assertCreated()
                ->assertJsonPath('data.latest_comment.comment', 'Sent email to ABB requesting order acknowledgement.')
                ->assertJsonPath('data.latest_comment.stage', 'acknowledgement')
                ->assertJsonPath('data.next_follow_up_at', '2026-06-21 11:30:00');

            $file = UploadedFile::fake()->create('abb-acknowledgement.pdf', 24, 'application/pdf');

            $this->post("/api/follow-up/{$followUpItemId}/acknowledgement", [
                    'acknowledgement_received_at' => '2026-06-08 10:15:00',
                    'acknowledgement_notes' => 'ABB acknowledged the PO by email.',
                    'acknowledgement_file' => $file,
                ])
                ->assertOk()
                ->assertJsonPath('data.status', 'acknowledged')
                ->assertJsonPath('data.acknowledged_by_name', $context['followUp']->name)
                ->assertJsonPath('data.acknowledgement_notes', 'ABB acknowledged the PO by email.');

            $acknowledgementPath = DB::table('follow_up_items')
                ->where('id', $followUpItemId)
                ->value('acknowledgement_file_path');

            $this->assertNotNull($acknowledgementPath);
            Storage::disk('local')->assertExists($acknowledgementPath);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_follow_up_comments_are_required_to_belong_to_a_workflow_stage(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');

        try {
            $context = $this->followUpContext();
            $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
            $supplierPo = $this->createSupplierPo($context, [$first['item']]);
            $this->withBearerToken($context['followUp']);
            Carbon::setTestNow(Carbon::parse('2026-06-07 11:30:00'));
            $followUpItemId = (int) DB::table('follow_up_items')
                ->where('supplier_po_id', $supplierPo['id'])
                ->value('id');

            $this->putJson("/api/follow-up/{$followUpItemId}/reminder", [
                'reminder_interval_value' => 2,
                'reminder_interval_unit' => 'days',
            ])->assertOk();

            $this->postJson("/api/follow-up/{$followUpItemId}/comments", [
                'comment' => 'Called shipping agent but did not record the stage.',
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['stage']);

            $this->postJson("/api/follow-up/{$followUpItemId}/comments", [
                'comment' => 'Supplier invoice received, COO still pending.',
                'stage' => 'shipping',
                'communication_type' => 'email',
                'contacted_person' => 'ABB logistics desk',
                'next_action' => 'Request COO copy.',
            ])
                ->assertCreated()
                ->assertJsonPath('data.latest_comment.stage', 'shipping')
                ->assertJsonPath('data.latest_comment.stage_label', 'Shipping Details')
                ->assertJsonPath('data.comments_by_stage.shipping.0.comment', 'Supplier invoice received, COO still pending.')
                ->assertJsonPath('data.next_follow_up_at', '2026-06-09 11:30:00');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_shipping_documents_are_required_before_follow_up_can_move_to_logistics(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp']);

        $documents = $this->getJson("/api/follow-up/{$followUpItemId}/shipping-documents")
            ->assertOk()
            ->assertJsonPath('complete', false)
            ->json('data');

        $this->assertSame([
            'supplier_invoice',
            'bill_of_lading',
            'airway_bill',
            'certificate_of_origin',
            'packing_list',
        ], collect($documents)->pluck('document_type')->all());

        $this->postJson("/api/follow-up/{$followUpItemId}/shipping-documents/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_documents']);

        foreach (['supplier_invoice', 'bill_of_lading', 'airway_bill', 'certificate_of_origin'] as $documentType) {
            $file = UploadedFile::fake()->create("{$documentType}.pdf", 18, 'application/pdf');

            $this->post("/api/follow-up/{$followUpItemId}/shipping-documents/{$documentType}", [
                'document_file' => $file,
                'document_number' => strtoupper($documentType).'-001',
                'document_date' => '2026-06-10',
            ])
                ->assertOk()
                ->assertJsonPath('data.document_type', $documentType)
                ->assertJsonPath('data.status', 'uploaded');
        }

        $this->postJson("/api/follow-up/{$followUpItemId}/shipping-documents/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_documents']);
    }

    public function test_shipping_document_uploads_are_audited_with_elapsed_time_between_progress_events(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');

        try {
            $context = $this->followUpContext();
            $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
            $supplierPo = $this->createSupplierPo($context, [$first['item']]);
            $this->withBearerToken($context['followUp']);
            $followUpItemId = (int) DB::table('follow_up_items')
                ->where('supplier_po_id', $supplierPo['id'])
                ->value('id');

            Carbon::setTestNow(Carbon::parse('2026-06-10 09:00:00'));

            $this->post("/api/follow-up/{$followUpItemId}/shipping-documents/supplier_invoice", [
                'document_file' => UploadedFile::fake()->create('supplier-invoice.pdf', 18, 'application/pdf'),
                'document_number' => 'INV-ABB-001',
                'document_date' => '2026-06-10',
                'remarks' => 'Supplier invoice received first.',
            ])->assertOk();

            Carbon::setTestNow(Carbon::parse('2026-06-13 15:30:00'));

            $this->post("/api/follow-up/{$followUpItemId}/shipping-documents/bill_of_lading", [
                'document_file' => UploadedFile::fake()->create('bill-of-lading.pdf', 18, 'application/pdf'),
                'document_number' => 'BL-ABB-009',
                'document_date' => '2026-06-13',
                'remarks' => 'Bill of lading arrived after shipper follow-up.',
            ])->assertOk();

            $this->assertDatabaseHas('follow_up_audit_logs', [
                'follow_up_item_id' => $followUpItemId,
                'stage' => 'shipping',
                'action' => 'shipping_document.uploaded',
                'summary' => 'Supplier Invoice uploaded.',
            ]);
            $this->assertDatabaseHas('follow_up_audit_logs', [
                'follow_up_item_id' => $followUpItemId,
                'stage' => 'shipping',
                'action' => 'shipping_document.uploaded',
                'summary' => 'Bill of Lading uploaded.',
            ]);

            $timeline = $this->withBearerToken($this->adminUser())
                ->getJson("/api/follow-up/{$followUpItemId}")
                ->assertOk()
                ->json('data.timeline_events');
            $shippingEvents = collect($timeline)
                ->where('action', 'shipping_document.uploaded')
                ->values();

            $this->assertSame('Supplier Invoice uploaded.', $shippingEvents[0]['summary']);
            $this->assertSame('Bill of Lading uploaded.', $shippingEvents[1]['summary']);
            $this->assertSame(282600, $shippingEvents[1]['elapsed_from_previous_seconds']);
            $this->assertSame('3 days 6 hours 30 minutes after previous event', $shippingEvents[1]['elapsed_from_previous_label']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_full_audit_timeline_is_only_returned_to_admin_users(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');

        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp'])
            ->post("/api/follow-up/{$followUpItemId}/shipping-documents/supplier_invoice", [
                'document_file' => UploadedFile::fake()->create('supplier-invoice.pdf', 18, 'application/pdf'),
                'document_number' => 'INV-ABB-001',
                'document_date' => '2026-06-10',
                'remarks' => 'Supplier invoice received first.',
            ])->assertOk();

        $this->withBearerToken($context['followUp'])
            ->getJson("/api/follow-up/{$followUpItemId}")
            ->assertOk()
            ->assertJsonMissingPath('data.timeline_events');

        $response = $this->withBearerToken($this->adminUser())
            ->getJson("/api/follow-up/{$followUpItemId}")
            ->assertOk();

        $this->assertNotEmpty($response->json('data.timeline_events'));
        $this->assertContains('shipping_document.uploaded', collect($response->json('data.timeline_events'))->pluck('action')->all());
    }

    public function test_follow_up_operational_milestones_are_written_to_the_audit_timeline(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');

        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->completeShippingDocumentsFor($context, $followUpItemId);

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/logistics/eta", [
                'delivery_responsibility' => 'isc',
                'eta_at' => '2026-06-20 15:00:00',
                'agent_name' => 'Muscat Freight Services',
                'remarks' => 'Shipment booked with ISC-appointed agent.',
            ])->assertOk();

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/arrived", [
            'arrived_at' => '2026-06-20 14:30:00',
            'remarks' => 'Shipment reached Muscat airport.',
        ])->assertOk();

        foreach ([
            'packing_list.generated',
            'shipping_documents.completed',
            'logistics.eta_recorded',
            'logistics.arrived',
        ] as $action) {
            $this->assertDatabaseHas('follow_up_audit_logs', [
                'follow_up_item_id' => $followUpItemId,
                'action' => $action,
            ]);
        }
    }

    public function test_follow_up_user_can_generate_packing_list_and_complete_shipping_documents(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp']);

        foreach (['supplier_invoice', 'bill_of_lading', 'airway_bill', 'certificate_of_origin'] as $documentType) {
            $this->post("/api/follow-up/{$followUpItemId}/shipping-documents/{$documentType}", [
                'document_file' => UploadedFile::fake()->create("{$documentType}.pdf", 18, 'application/pdf'),
                'document_number' => strtoupper($documentType).'-001',
                'document_date' => '2026-06-10',
            ])->assertOk();
        }

        $response = $this->postJson("/api/follow-up/{$followUpItemId}/packing-list", [
            'package_size' => '120*80*956 CM',
            'gross_weight' => '330 Kg',
            'net_weight' => '300 Kg',
            'remarks' => 'Single packed motor.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.package_size', '120*80*956 CM')
            ->assertJsonPath('data.gross_weight', '330 Kg')
            ->assertJsonPath('data.net_weight', '300 Kg')
            ->assertJsonPath('data.items.0.buyer_po_number', '4502757812')
            ->assertJsonPath('data.downloads.docx', "/api/packing-lists/{$response->json('data.id')}/download/docx")
            ->assertJsonPath('data.downloads.pdf', "/api/packing-lists/{$response->json('data.id')}/download/pdf");

        $this->assertDatabaseHas('shipping_documents', [
            'follow_up_item_id' => $followUpItemId,
            'document_type' => 'packing_list',
            'status' => 'generated',
        ]);

        $docxPath = Storage::disk('local')->path($response->json('data.docx_path'));
        $pdfPath = Storage::disk('local')->path($response->json('data.pdf_path'));

        $this->assertFileExists($docxPath);
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('Packing List', (string) $documentXml);
        $this->assertStringContainsString('4502757812', (string) $documentXml);
        $this->assertStringContainsString('120*80*956 CM', (string) $documentXml);
        $this->assertStringContainsString('330 Kg', (string) $documentXml);

        $this->postJson("/api/follow-up/{$followUpItemId}/shipping-documents/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipping_documents_complete');
    }

    public function test_eta_cannot_start_until_shipping_documents_are_complete(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/logistics/eta", [
                'delivery_responsibility' => 'isc',
                'eta_at' => '2026-06-20 15:00:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_documents']);
    }

    public function test_follow_up_user_can_record_isc_eta_arrival_and_warehouse_receipt(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->completeShippingDocumentsFor($context, $followUpItemId);

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/logistics/eta", [
                'delivery_responsibility' => 'isc',
                'eta_at' => '2026-06-20 15:00:00',
                'agent_name' => 'Muscat Freight Services',
                'agent_contact' => '+968 2450 0000',
                'remarks' => 'Shipment booked with ISC-appointed agent.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'logistics_eta_recorded')
            ->assertJsonPath('data.logistics_case.delivery_responsibility', 'isc')
            ->assertJsonPath('data.logistics_case.status', 'eta_recorded')
            ->assertJsonPath('data.logistics_case.eta_at', '2026-06-20 15:00:00')
            ->assertJsonPath('data.logistics_case.events.0.event_type', 'eta_recorded');

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/arrived", [
                'arrived_at' => '2026-06-20 14:30:00',
                'remarks' => 'Shipment reached Muscat airport.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'arrived')
            ->assertJsonPath('data.logistics_case.status', 'arrived');

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/warehouse-received", [
                'warehouse_received_at' => '2026-06-21 09:15:00',
                'received_location' => 'ISC Warehouse - Muscat',
                'received_quantity' => '1',
                'goods_condition' => 'Good condition',
                'remarks' => 'Ready for delivery order preparation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_delivery_order')
            ->assertJsonPath('data.logistics_case.status', 'warehouse_received')
            ->assertJsonPath('data.logistics_case.received_quantity', '1.000');

        $this->assertDatabaseHas('logistics_events', [
            'event_type' => 'warehouse_received',
            'title' => 'Goods received at ISC warehouse',
        ]);
    }

    public function test_follow_up_user_can_send_documents_to_buyer_agent_and_record_buyer_receipt(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->completeShippingDocumentsFor($context, $followUpItemId);

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/logistics/eta", [
                'delivery_responsibility' => 'buyer_agent',
                'eta_at' => '2026-06-25 12:00:00',
                'agent_name' => 'OXY Clearing Agent',
                'agent_contact' => 'Moosa Ambu Ali',
            ])
            ->assertOk()
            ->assertJsonPath('data.logistics_case.delivery_responsibility', 'buyer_agent');

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/documents-sent", [
                'documents_sent_at' => '2026-06-15 10:00:00',
                'remarks' => 'Full shipping document set sent to buyer agent.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'documents_sent_to_buyer_agent')
            ->assertJsonPath('data.logistics_case.status', 'documents_sent_to_buyer_agent');

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/buyer-received", [
                'buyer_received_at' => '2026-06-28 16:45:00',
                'received_quantity' => '1',
                'goods_condition' => 'Received without shortage or damage',
                'remarks' => 'Buyer confirmed receipt by email.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_invoice')
            ->assertJsonPath('data.logistics_case.status', 'buyer_received');

        $this->assertDatabaseHas('logistics_events', [
            'event_type' => 'buyer_received',
            'title' => 'Buyer confirmed goods received',
        ]);
    }

    public function test_delivery_order_can_only_be_created_after_isc_warehouse_receipt(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/delivery-order", [
                'delivery_place' => 'OXY Yard, Muscat',
                'terms' => 'Delivered in good condition.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_order']);
    }

    public function test_follow_up_user_can_generate_delivery_order_and_upload_signed_copy(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        Storage::disk('local')->deleteDirectory('generated/delivery-orders');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->moveIscItemToWarehouseReceipt($context, $followUpItemId);

        $response = $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/delivery-order", [
                'delivery_place' => 'OXY Yard, Muscat',
                'terms' => 'Delivery against buyer LPO 4502757812.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'delivery_order_created')
            ->assertJsonPath('data.delivery_order.delivery_place', 'OXY Yard, Muscat')
            ->assertJsonPath('data.delivery_order.items.0.buyer_po_number', '4502757812');

        $docxPath = Storage::disk('local')->path($response->json('data.delivery_order.docx_path'));
        $pdfPath = Storage::disk('local')->path($response->json('data.delivery_order.pdf_path'));

        $this->assertFileExists($docxPath);
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('Delivery Order', (string) $documentXml);
        $this->assertStringContainsString('4502757812', (string) $documentXml);
        $this->assertStringContainsString('OXY Yard, Muscat', (string) $documentXml);

        $deliveryOrderId = (int) $response->json('data.delivery_order.id');

        $this->post("/api/follow-up/{$followUpItemId}/delivery-order/signed", [
                'signed_at' => '2026-06-22 13:30:00',
                'signed_file' => UploadedFile::fake()->create('signed-delivery-order.pdf', 20, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_invoice')
            ->assertJsonPath('data.delivery_order.status', 'signed');

        $signedPath = DB::table('delivery_orders')
            ->where('id', $deliveryOrderId)
            ->value('signed_file_path');

        $this->assertNotNull($signedPath);
        Storage::disk('local')->assertExists($signedPath);
    }

    public function test_invoice_requires_billing_ready_status_and_flags_zero_vat_amount_with_positive_rate(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        Storage::disk('local')->deleteDirectory('generated/delivery-orders');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/invoice", [
                'payment_term_days' => 45,
                'vat_rate' => '5',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $this->moveIscItemToReadyForInvoice($context, $followUpItemId);

        $this->postJson("/api/follow-up/{$followUpItemId}/invoice", [
                'payment_term_days' => 45,
                'vat_rate' => '5',
                'vat_amount' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vat_amount']);
    }

    public function test_follow_up_user_can_create_invoice_after_delivery_or_buyer_receipt(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        Storage::disk('local')->deleteDirectory('generated/delivery-orders');
        Storage::disk('local')->deleteDirectory('generated/invoices');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->moveIscItemToReadyForInvoice($context, $followUpItemId);
        $this->withBearerToken($context['followUp']);

        try {
            Carbon::setTestNow(Carbon::parse('2026-06-30 10:00:00'));

            $response = $this->postJson("/api/follow-up/{$followUpItemId}/invoice", [
                    'payment_term_days' => 45,
                    'vat_rate' => '5',
                    'bank_details' => "Bank Muscat\nAccount: 0123456789",
                    'remarks' => 'Invoice issued after signed delivery order.',
                ]);
        } finally {
            Carbon::setTestNow();
        }

        $response->assertCreated()
            ->assertJsonPath('data.status', 'invoice_created')
            ->assertJsonPath('data.invoice.buyer_po_number', '4502757812')
            ->assertJsonPath('data.invoice.currency', 'OMR')
            ->assertJsonPath('data.invoice.subtotal', '3256.000')
            ->assertJsonPath('data.invoice.vat_rate', '5.000')
            ->assertJsonPath('data.invoice.vat_amount', '162.800')
            ->assertJsonPath('data.invoice.total_amount', '3418.800')
            ->assertJsonPath('data.invoice.due_date', '2026-08-14');

        $docxPath = Storage::disk('local')->path($response->json('data.invoice.docx_path'));
        $pdfPath = Storage::disk('local')->path($response->json('data.invoice.pdf_path'));

        $this->assertFileExists($docxPath);
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($docxPath));
        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertStringContainsString('Tax Invoice', (string) $documentXml);
        $this->assertStringContainsString('4502757812', (string) $documentXml);
        $this->assertStringContainsString('3418.800', (string) $documentXml);
    }

    public function test_payment_cannot_be_recorded_before_invoice_exists(): void
    {
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/payments", [
                'amount' => '100.000',
                'payment_date' => '2026-07-10',
                'payment_reference' => 'TRN-001',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);
    }

    public function test_invoice_can_be_marked_sent_and_payment_tracking_handles_partial_and_full_payment(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        Storage::disk('local')->deleteDirectory('generated/delivery-orders');
        Storage::disk('local')->deleteDirectory('generated/invoices');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->createInvoiceFor($context, $followUpItemId);

        $this->withBearerToken($context['followUp'])
            ->postJson("/api/follow-up/{$followUpItemId}/invoice/sent", [
                'sent_at' => '2026-07-01 09:30:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'payment_pending')
            ->assertJsonPath('data.invoice.status', 'sent')
            ->assertJsonPath('data.invoice.payment_status', 'pending')
            ->assertJsonPath('data.invoice.balance_amount', '3418.800');

        $this->postJson("/api/follow-up/{$followUpItemId}/payments", [
                'amount' => '1000.000',
                'payment_date' => '2026-07-15',
                'payment_reference' => 'TRN-PART-001',
                'remarks' => 'First partial payment.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.invoice.status', 'partially_paid')
            ->assertJsonPath('data.invoice.payment_status', 'partially_paid')
            ->assertJsonPath('data.invoice.paid_amount', '1000.000')
            ->assertJsonPath('data.invoice.balance_amount', '2418.800')
            ->assertJsonPath('data.invoice.payments.0.payment_reference', 'TRN-PART-001');

        $this->postJson("/api/follow-up/{$followUpItemId}/payments", [
                'amount' => '3000.000',
                'payment_date' => '2026-07-20',
                'payment_reference' => 'TRN-OVERPAY',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->postJson("/api/follow-up/{$followUpItemId}/payments", [
                'amount' => '2418.800',
                'payment_date' => '2026-07-25',
                'payment_reference' => 'TRN-FINAL-001',
                'remarks' => 'Final settlement.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.invoice.payment_status', 'paid')
            ->assertJsonPath('data.invoice.paid_amount', '3418.800')
            ->assertJsonPath('data.invoice.balance_amount', '0.000');

        $this->assertDatabaseHas('payments', [
            'payment_reference' => 'TRN-FINAL-001',
            'amount' => '2418.800',
        ]);
    }

    public function test_job_can_only_be_closed_after_invoice_is_paid(): void
    {
        Storage::disk('local')->deleteDirectory('follow-up');
        Storage::disk('local')->deleteDirectory('generated/supplier-pos');
        Storage::disk('local')->deleteDirectory('generated/packing-lists');
        Storage::disk('local')->deleteDirectory('generated/delivery-orders');
        Storage::disk('local')->deleteDirectory('generated/invoices');
        $context = $this->followUpContext();
        $first = $this->acceptedQuotationItem($context, 'Occidental of Oman, Inc', 'OXY', '4502757812', 'ABB Flameproof Motor');
        $supplierPo = $this->createSupplierPo($context, [$first['item']]);
        $followUpItemId = (int) DB::table('follow_up_items')
            ->where('supplier_po_id', $supplierPo['id'])
            ->value('id');

        $this->createInvoiceFor($context, $followUpItemId);
        $this->withBearerToken($context['followUp']);

        $this->postJson("/api/follow-up/{$followUpItemId}/close", [
                'closed_notes' => 'Trying to close before payment.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment']);

        $this->postJson("/api/follow-up/{$followUpItemId}/payments", [
            'amount' => '3418.800',
            'payment_date' => '2026-07-25',
            'payment_reference' => 'TRN-FULL-001',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'paid');

        try {
            Carbon::setTestNow(Carbon::parse('2026-07-26 11:00:00'));

            $response = $this->postJson("/api/follow-up/{$followUpItemId}/close", [
                'closed_notes' => 'Customer payment received and job closed.',
            ]);
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_at', '2026-07-26 11:00:00')
            ->assertJsonPath('data.invoice.status', 'closed')
            ->assertJsonPath('data.invoice.payment_status', 'closed');

        $this->assertDatabaseHas('follow_up_items', [
            'id' => $followUpItemId,
            'status' => 'closed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function followUpContext(): array
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
        $internalCompany = Company::create([
            'country_id' => $country->id,
            'name' => 'Industrial Supplies Center LLC',
            'company_code' => 'ISC',
            'code_slug' => 'isc',
            'company_type' => 'internal',
            'address' => 'PO BOX 39, POSTAL CODE: 101',
            'location' => 'MUSCAT - SULTANATE OF OMAN',
            'status' => 'active',
        ]);
        $internalContact = Contact::create([
            'company_id' => $internalCompany->id,
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
            'company_id' => $internalCompany->id,
            'primary_contact_id' => $internalContact->id,
            'status' => 'active',
        ]);

        $salesRole = Role::create([
            'name' => 'Salesperson',
            'slug' => 'salesperson',
            'is_system' => true,
            'status' => 'active',
        ]);
        $followUpRole = Role::create([
            'name' => 'Follow-Up',
            'slug' => 'follow-up',
            'is_system' => true,
            'status' => 'active',
        ]);
        $salesperson = User::create([
            'name' => 'Manu Thuruthel',
            'email' => 'sales@example.test',
            'contact_id' => $internalContact->id,
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $salesperson->roles()->attach($salesRole);
        $followUp = User::create([
            'name' => 'Sara Follow',
            'email' => 'follow@example.test',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);
        $followUp->roles()->attach($followUpRole);
        $incoterm = Incoterm::create([
            'code' => 'CPT',
            'name' => 'Carriage Paid To',
            'reminder_days_before_delivery' => 30,
            'status' => 'active',
        ]);

        return compact('country', 'designation', 'followUp', 'incoterm', 'internalCompany', 'internalContact', 'manufacturer', 'salesperson', 'supplier', 'supplierContact');
    }

    /**
     * @return array{quotation: Quotation, item: QuotationItem, buyerPo: BuyerPo}
     */
    private function acceptedQuotationItem(array $context, string $buyerName, string $buyerCode, string $buyerPoNumber, string $title): array
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
            'quotation_reference' => 'PENDING',
            'salesperson_id' => $context['salesperson']->id,
            'supplier_company_id' => $context['internalCompany']->id,
            'supplier_contact_id' => $context['internalContact']->id,
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
            'status' => 'buyer_po_received',
        ]);
        $quotation->forceFill(['quotation_reference' => "ISC-COR-QT-{$quotation->id}-{$buyerCompany->company_code}-26"])->save();
        $product = Product::create([
            'manufacturer_id' => $context['manufacturer']->id,
            'name' => $title,
            'title' => $title,
            'buyer_description' => '<p>Buyer visible description.</p>',
            'manufacturer_description' => '<p>Supplier-visible technical description with internal feature codes.</p>',
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
            'title' => $title,
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

    /**
     * @param array<int, QuotationItem> $items
     *
     * @return array<string, mixed>
     */
    private function createSupplierPo(array $context, array $items): array
    {
        $payload = [
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
            'items' => collect($items)->map(fn (QuotationItem $item): array => [
                'quotation_item_id' => $item->id,
                'unit_cost' => '5041.350',
            ])->values()->all(),
            'terms' => [
                ['key' => 'acknowledgment', 'title' => 'Acknowledgment', 'description' => 'Suppliers shall acknowledge receipt of this PO by email within TWO days.'],
                ['key' => 'documents', 'title' => 'Documents', 'description' => 'Shipping Documents: Invoice, Packing list, COO, Bill of Lading'],
            ],
        ];

        return $this->withBearerToken($context['salesperson'])
            ->postJson('/api/supplier-pos', $payload)
            ->assertCreated()
            ->json('data');
    }

    private function completeShippingDocumentsFor(array $context, int $followUpItemId): void
    {
        $this->withBearerToken($context['followUp']);

        foreach (['supplier_invoice', 'bill_of_lading', 'airway_bill', 'certificate_of_origin'] as $documentType) {
            $this->post("/api/follow-up/{$followUpItemId}/shipping-documents/{$documentType}", [
                'document_file' => UploadedFile::fake()->create("{$documentType}.pdf", 18, 'application/pdf'),
                'document_number' => strtoupper($documentType).'-001',
                'document_date' => '2026-06-10',
            ])->assertOk();
        }

        $this->postJson("/api/follow-up/{$followUpItemId}/packing-list", [
            'package_size' => '120*80*956 CM',
            'gross_weight' => '330 Kg',
            'net_weight' => '300 Kg',
            'remarks' => 'Single packed motor.',
        ])->assertCreated();

        $this->postJson("/api/follow-up/{$followUpItemId}/shipping-documents/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'shipping_documents_complete');
    }

    private function moveIscItemToWarehouseReceipt(array $context, int $followUpItemId): void
    {
        $this->completeShippingDocumentsFor($context, $followUpItemId);
        $this->withBearerToken($context['followUp']);

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/eta", [
            'delivery_responsibility' => 'isc',
            'eta_at' => '2026-06-20 15:00:00',
            'agent_name' => 'Muscat Freight Services',
        ])->assertOk();

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/arrived", [
            'arrived_at' => '2026-06-20 14:30:00',
        ])->assertOk();

        $this->postJson("/api/follow-up/{$followUpItemId}/logistics/warehouse-received", [
            'warehouse_received_at' => '2026-06-21 09:15:00',
            'received_location' => 'ISC Warehouse - Muscat',
            'received_quantity' => '1',
            'goods_condition' => 'Good condition',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_delivery_order');
    }

    private function moveIscItemToReadyForInvoice(array $context, int $followUpItemId): void
    {
        $this->moveIscItemToWarehouseReceipt($context, $followUpItemId);
        $this->withBearerToken($context['followUp']);

        $this->postJson("/api/follow-up/{$followUpItemId}/delivery-order", [
            'delivery_place' => 'OXY Yard, Muscat',
            'terms' => 'Delivery against buyer LPO 4502757812.',
        ])->assertCreated();

        $this->post("/api/follow-up/{$followUpItemId}/delivery-order/signed", [
            'signed_at' => '2026-06-22 13:30:00',
            'signed_file' => UploadedFile::fake()->create('signed-delivery-order.pdf', 20, 'application/pdf'),
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_invoice');
    }

    private function createInvoiceFor(array $context, int $followUpItemId): void
    {
        $this->moveIscItemToReadyForInvoice($context, $followUpItemId);
        $this->withBearerToken($context['followUp']);

        $this->postJson("/api/follow-up/{$followUpItemId}/invoice", [
            'payment_term_days' => 45,
            'vat_rate' => '5',
            'bank_details' => "Bank Muscat\nAccount: 0123456789",
        ])->assertCreated()
            ->assertJsonPath('data.status', 'invoice_created')
            ->assertJsonPath('data.invoice.total_amount', '3418.800');
    }

    private function adminUser(): User
    {
        $adminRole = Role::query()->firstOrCreate([
            'slug' => 'admin',
        ], [
            'name' => 'Admin',
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

        return $admin->refresh();
    }

    private function withBearerToken(User $user): self
    {
        $testNow = Carbon::getTestNow();

        if ($testNow !== null) {
            Carbon::setTestNow();
        }

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        if ($testNow !== null) {
            Carbon::setTestNow($testNow);
        }

        return $this->withHeader('Authorization', "Bearer {$token}");
    }
}
