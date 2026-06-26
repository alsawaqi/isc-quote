<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_routes_serve_the_vue_single_page_app(): void
    {
        foreach (['/', '/login', '/dashboard', '/countries', '/quotations', '/supplier-po', '/supplier-pos', '/supplier-pos/create', '/supplier-pos/1/edit', '/follow-up', '/follow-up/groups', '/follow-up/visualization', '/follow-up/1'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertViewIs('app');
        }
    }

    public function test_quotation_workspace_uses_tabs_for_buyer_po_instead_of_a_separate_route(): void
    {
        $router = file_get_contents(resource_path('js/router.ts'));
        $quotationDetail = file_get_contents(resource_path('js/components/QuotationDetailView.vue'));

        $this->assertStringNotContainsString("path: '/quotations/:id/buyer-po/create'", $router);
        $this->assertStringContainsString("const activeWorkspaceTab = ref<'quotation' | 'buyer-po'>('quotation');", $quotationDetail);
        $this->assertStringContainsString("activeWorkspaceTab = 'buyer-po'", $quotationDetail);
        $this->assertStringContainsString('Create Buyer PO', $quotationDetail);
        $this->assertStringContainsString('Edit Quotation', $quotationDetail);
    }

    public function test_supplier_po_workspace_lists_existing_pos_before_creation(): void
    {
        $router = file_get_contents(resource_path('js/router.ts'));
        $navigation = file_get_contents(resource_path('js/data/dashboard.ts'));

        $this->assertStringContainsString("path: '/supplier-po'", $router);
        $this->assertStringContainsString("redirect: '/supplier-pos'", $router);
        $this->assertStringContainsString("path: '/supplier-pos'", $router);
        $this->assertStringContainsString("path: '/supplier-pos/create'", $router);
        $this->assertStringContainsString("path: '/supplier-pos/:id/edit'", $router);
        $this->assertStringContainsString('Supplier POs', $navigation);
        $this->assertStringNotContainsString("label: 'Create Supplier PO'", $navigation);
        $this->assertStringContainsString('create-supplier-pos', $navigation);

        $supplierPoList = file_get_contents(resource_path('js/components/SupplierPoListView.vue'));
        $this->assertStringContainsString('Edit', $supplierPoList);
        $this->assertStringContainsString('router.push(`/supplier-pos/${supplierPo.id}/edit`)', $supplierPoList);
    }

    public function test_follow_up_workspace_has_dashboard_and_item_detail_routes(): void
    {
        $router = file_get_contents(resource_path('js/router.ts'));
        $navigation = file_get_contents(resource_path('js/data/dashboard.ts'));

        $this->assertStringContainsString("const FollowUpDashboardView = () => import('./components/FollowUpDashboardView.vue');", $router);
        $this->assertStringContainsString("const FollowUpGroupView = () => import('./components/FollowUpGroupView.vue');", $router);
        $this->assertStringContainsString("const FollowUpQuotationView = () => import('./components/FollowUpQuotationView.vue');", $router);
        $this->assertStringContainsString("const FollowUpVisualizationView = () => import('./components/FollowUpVisualizationView.vue');", $router);
        $this->assertStringContainsString("const FollowUpDetailView = () => import('./components/FollowUpDetailView.vue');", $router);
        $this->assertStringContainsString("path: '/follow-up'", $router);
        $this->assertStringContainsString("path: '/follow-up/groups'", $router);
        $this->assertStringContainsString("path: '/follow-up/quotations'", $router);
        $this->assertStringContainsString("path: '/follow-up/quotations/:id'", $router);
        $this->assertStringContainsString("path: '/follow-up/visualization'", $router);
        $this->assertStringContainsString("path: '/follow-up/:id'", $router);
        $this->assertStringContainsString("roles: ['admin', 'follow-up']", $router);
        $this->assertStringContainsString("path: '/follow-up'", $navigation);
        $this->assertStringContainsString("path: '/follow-up/groups'", $navigation);
        $this->assertStringContainsString("path: '/follow-up/quotations'", $navigation);
        $this->assertStringContainsString("path: '/follow-up/visualization'", $navigation);
    }

    public function test_follow_up_quotation_workspace_supports_item_grouping(): void
    {
        $quotationWorkspace = file_get_contents(resource_path('js/components/FollowUpQuotationView.vue'));

        $this->assertStringContainsString('/api/follow-up/quotations', $quotationWorkspace);
        $this->assertStringContainsString('selectedFollowUpItemIds', $quotationWorkspace);
        $this->assertStringContainsString('createFollowUpGroup', $quotationWorkspace);
        $this->assertStringContainsString('splitFollowUpGroup', $quotationWorkspace);
        $this->assertStringContainsString('Quotation Details', $quotationWorkspace);
        $this->assertStringContainsString('Workflow Groups', $quotationWorkspace);
        $this->assertStringContainsString('Invoice Scope', $quotationWorkspace);
    }

    public function test_follow_up_detail_uses_a_step_by_step_workflow_instead_of_showing_all_panels(): void
    {
        $detail = file_get_contents(resource_path('js/components/FollowUpDetailView.vue'));

        $this->assertStringContainsString('const activeFollowUpStep', $detail);
        $this->assertStringContainsString('workflow-stepper', $detail);
        $this->assertStringContainsString("activeFollowUpStep === 'acknowledgement'", $detail);
        $this->assertStringContainsString("activeFollowUpStep === 'shipping'", $detail);
        $this->assertStringContainsString("activeFollowUpStep === 'logistics'", $detail);
        $this->assertStringContainsString("activeFollowUpStep === 'delivery'", $detail);
        $this->assertStringContainsString("activeFollowUpStep === 'invoice'", $detail);
        $this->assertStringContainsString("activeFollowUpStep === 'payment'", $detail);
        $this->assertStringContainsString('is_required', $detail);
        $this->assertStringContainsString('Upload Packing List', $detail);
        $this->assertStringContainsString('Optional Transport Documents', $detail);
        $this->assertStringNotContainsString('Generate Packing List', $detail);
        $this->assertStringNotContainsString('savePackingList', $detail);
    }

    public function test_supplier_delivery_responsibility_is_wired_into_sales_and_follow_up_pages(): void
    {
        $quotationCreate = file_get_contents(resource_path('js/components/QuotationCreateView.vue'));
        $followUpDetail = file_get_contents(resource_path('js/components/FollowUpDetailView.vue'));

        $this->assertStringContainsString('Supplier / Manufacturer Responsibility', $quotationCreate);
        $this->assertStringContainsString('quotation_delivery_responsibility', $followUpDetail);
        $this->assertStringContainsString('defaultDeliveryResponsibility', $followUpDetail);
        $this->assertStringContainsString('isSupplierHandledDelivery', $followUpDetail);
        $this->assertStringContainsString('Supplier Receipt', $followUpDetail);
    }

    public function test_supplier_po_create_page_has_filter_controls_and_selection_cache(): void
    {
        $supplierPoCreate = file_get_contents(resource_path('js/components/SupplierPoCreateView.vue'));

        $this->assertStringContainsString('pending_item_filters', $supplierPoCreate);
        $this->assertStringContainsString('itemFilters', $supplierPoCreate);
        $this->assertStringContainsString('selectedItemCache', $supplierPoCreate);
        $this->assertStringContainsString('loadPendingItems', $supplierPoCreate);
        $this->assertStringContainsString('Current quotations', $supplierPoCreate);
        $this->assertStringContainsString('Quotation Number', $supplierPoCreate);
        $this->assertStringContainsString('Customer', $supplierPoCreate);
        $this->assertStringContainsString('Manufacturer', $supplierPoCreate);
    }

    public function test_follow_up_dashboard_highlights_due_reminders_before_the_general_list(): void
    {
        $dashboard = file_get_contents(resource_path('js/components/FollowUpDashboardView.vue'));

        $this->assertStringContainsString('due_reminders', $dashboard);
        $this->assertStringContainsString('dueReminders', $dashboard);
        $this->assertStringContainsString('Follow-Up Reminders', $dashboard);
    }

    public function test_follow_up_dashboard_has_grouping_and_filter_controls(): void
    {
        $dashboard = file_get_contents(resource_path('js/components/FollowUpDashboardView.vue'));
        $groupView = file_get_contents(resource_path('js/components/FollowUpGroupView.vue'));

        $this->assertStringNotContainsString('Grouped Follow-Up Board', $dashboard);
        $this->assertStringContainsString('groupBy', $groupView);
        $this->assertStringContainsString('groupedFollowUps', $groupView);
        $this->assertStringContainsString('Group by', $groupView);
        $this->assertStringContainsString('Required Action', $groupView);
        $this->assertStringContainsString('Action Filter', $groupView);
    }

    public function test_app_shell_removes_operation_switch_uses_notifications_and_fixed_sidebar(): void
    {
        $shell = file_get_contents(resource_path('js/components/AppShell.vue'));
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('module-switch', $shell);
        $this->assertStringNotContainsString('<span>Operations</span>', $shell);
        $this->assertStringContainsString('/api/notifications', $shell);
        $this->assertStringContainsString('notification-dropdown', $shell);
        $this->assertStringContainsString('position: fixed;', $css);
        $this->assertStringContainsString('--sidebar-width: 240px;', $css);
        $this->assertStringContainsString('width: var(--sidebar-width);', $css);
        $this->assertStringContainsString('height: 100vh;', $css);
        $this->assertStringContainsString('overflow-y: auto;', $css);
        $this->assertStringContainsString("grid-column: 2;\n    height: 100vh;", $css);
        $this->assertStringContainsString("grid-template-columns: 1fr;\n    }\n\n    .sidebar", $css);
        $this->assertStringContainsString("grid-column: 1;\n    }\n\n    .mobile-only", $css);
        $this->assertStringNotContainsString(".nav-list {\n    display: grid;\n    flex: 1;", $css);
    }

    public function test_dashboard_fetches_live_database_payload_instead_of_static_sample_data(): void
    {
        $dashboard = file_get_contents(resource_path('js/components/DashboardView.vue'));

        $this->assertStringContainsString('/api/dashboard', $dashboard);
        $this->assertStringContainsString('loadDashboard', $dashboard);
        $this->assertStringNotContainsString("import { alerts, metrics, recentJobs, workflowStages } from '../data/dashboard';", $dashboard);
    }

    public function test_admin_trace_pages_are_registered_for_quotation_and_item_filtering(): void
    {
        $router = file_get_contents(resource_path('js/router.ts'));
        $navigation = file_get_contents(resource_path('js/data/dashboard.ts'));
        $quotationTrace = file_get_contents(resource_path('js/components/AdminQuotationTraceView.vue'));
        $itemTrace = file_get_contents(resource_path('js/components/AdminItemTraceView.vue'));

        $this->assertStringContainsString("path: '/admin/trace/quotations'", $router);
        $this->assertStringContainsString("path: '/admin/trace/items'", $router);
        $this->assertStringContainsString("path: '/admin/trace/quotations/:id'", $router);
        $this->assertStringContainsString("label: 'Quotation Trace'", $navigation);
        $this->assertStringContainsString("label: 'Item Trace'", $navigation);
        $this->assertStringContainsString('/api/admin/trace/quotations', $quotationTrace);
        $this->assertStringContainsString('/api/admin/trace/items', $itemTrace);
        $this->assertStringContainsString('downloadProtectedFile', $quotationTrace);
        $this->assertStringContainsString('/api/admin/trace/quotations/export', $quotationTrace);
        $this->assertStringContainsString('downloadProtectedFile', $itemTrace);
        $this->assertStringContainsString('/api/admin/trace/items/export', $itemTrace);
        $this->assertStringContainsString('Buyer PO', $quotationTrace);
        $this->assertStringContainsString('Supplier PO', $itemTrace);
        $this->assertStringContainsString('Timeline', $quotationTrace);
    }

    public function test_follow_up_detail_comments_are_stage_specific(): void
    {
        $detail = file_get_contents(resource_path('js/components/FollowUpDetailView.vue'));

        $this->assertStringContainsString('comments_by_stage', $detail);
        $this->assertStringContainsString('activeStageComments', $detail);
        $this->assertStringContainsString('stage: activeFollowUpStep.value', $detail);
        $this->assertStringContainsString('Stage Comments', $detail);
    }

    public function test_follow_up_detail_shows_full_audit_timeline_with_elapsed_durations(): void
    {
        $detail = file_get_contents(resource_path('js/components/FollowUpDetailView.vue'));

        $this->assertStringContainsString('timeline_events', $detail);
        $this->assertStringContainsString('Full Timeline', $detail);
        $this->assertStringContainsString('elapsed_from_previous_label', $detail);
    }

    public function test_follow_up_detail_only_renders_full_timeline_for_admin_users(): void
    {
        $detail = file_get_contents(resource_path('js/components/FollowUpDetailView.vue'));

        $this->assertStringContainsString('currentUser', $detail);
        $this->assertStringContainsString('isAdminUser', $detail);
        $this->assertStringContainsString('v-if="isAdminUser"', $detail);
    }
}
