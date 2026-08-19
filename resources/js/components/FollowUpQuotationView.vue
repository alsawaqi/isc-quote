<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowLeft, CalendarClock, CheckCircle2, Clock3, Eye, FileText, Layers3, Loader2, MessageSquare, PackageCheck, RefreshCcw, Save, Send, SplitSquareHorizontal, Truck, X } from 'lucide-vue-next';
import { requestJson } from '../auth';
import { humanizeStatus } from '../utils/format';

interface QuotationSummary {
    quotation_id: number;
    quotation_reference: string | null;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    buyer_po_numbers: string[];
    supplier_po_references: string[];
    manufacturers: string[];
    follow_up_items_count: number;
    open_groups_count: number;
    ready_for_invoice_count: number;
    invoiced_count: number;
    oldest_next_follow_up_at: string | null;
}

interface QuotationHeader {
    id: number;
    quotation_reference: string;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    salesperson_name: string | null;
    rfq_number: string | null;
    pr_number: string | null;
    rfq_title: string | null;
    closing_at: string | null;
    payment_term_days: number;
    payment_terms_extra: string | null;
    payment_customer_type: string;
    payment_customer_type_label: string;
    payment_schedule_summary: string;
    payment_schedules: PaymentScheduleRecord[];
    delivery_period_min: number;
    delivery_period_max: number;
    delivery_period_unit: string;
    delivery_period_type: string;
    accepted_invoice_currency: string;
    incoterm_code: string | null;
    incoterm_name: string | null;
    delivery_responsibility: string;
    status: string;
}

interface PaymentScheduleRecord {
    id: number;
    line_number: number;
    label: string;
    payment_method_label: string;
    payment_percentage: string;
    due_text: string;
    notes: string | null;
    summary: string;
}

type DeliveryResponsibility = 'isc' | 'buyer_agent' | 'supplier';
type BulkAction = 'reminder_update' | 'comment_add' | 'acknowledgement_record' | 'shipping_documents_complete' | 'documents_sent' | 'arrival_record' | 'warehouse_received' | 'buyer_received';
type FollowUpStage = 'acknowledgement' | 'shipping' | 'logistics' | 'delivery' | 'invoice' | 'payment';

interface LogisticsCaseSummary {
    id: number;
    delivery_responsibility: DeliveryResponsibility;
    status: string;
    eta_at: string | null;
    agent_name: string | null;
    agent_contact: string | null;
    documents_sent_at: string | null;
    arrived_at: string | null;
    warehouse_received_at: string | null;
    buyer_received_at: string | null;
    received_quantity: string | null;
    goods_condition: string | null;
    received_location: string | null;
    remarks: string | null;
}

interface FollowUpItem {
    id: number;
    supplier_po_line_id: number | null;
    quotation_item_id: number;
    supplier_po_reference: string | null;
    buyer_po_number: string | null;
    follow_up_group_key: string | null;
    follow_up_group_name: string | null;
    follow_up_group_mode: string;
    supplier_company_name: string | null;
    manufacturer_name: string | null;
    factory_name: string | null;
    factory_location: string | null;
    product_code: string | null;
    product_name: string | null;
    title: string | null;
    quantity: string | null;
    uom: string | null;
    status: string;
    status_label: string;
    current_stage: FollowUpStage;
    current_stage_label: string;
    next_follow_up_at: string | null;
    last_comment_at: string | null;
    acknowledgement_received_at: string | null;
    shipping_documents_complete: boolean;
    logistics_case: LogisticsCaseSummary | null;
    invoice: { invoice_reference: string; status: string } | null;
}

interface FollowUpGroup {
    group_key: string;
    group_name: string | null;
    workflow_mode: 'shared' | 'individual';
    is_persisted_group: boolean;
    item_count: number;
    status_labels: string[];
    stage_labels: string[];
    manufacturer_names: string[];
    factory_names: string[];
    supplier_po_references: string[];
    next_follow_up_at: string | null;
    items: Array<{
        id: number;
        product_code: string | null;
        title: string | null;
        manufacturer_name: string | null;
        factory_name: string | null;
        factory_location: string | null;
        status_label: string;
        current_stage_label: string;
        supplier_po_reference: string | null;
        eta_at?: string | null;
    }>;
}

interface InvoiceScope {
    quotation_total_items: number;
    ready_for_invoice_items: number;
    invoiced_items: number;
    open_invoice_groups: number;
    supports_partial_invoices: boolean;
    supports_full_quotation_invoice: boolean;
}

interface QuotationWorkspace {
    quotation: QuotationHeader;
    buyer_pos: Array<{ id: number; po_number: string; po_date: string | null; po_value: string; currency: string; status: string }>;
    supplier_pos: Array<{ id: number; po_reference: string; supplier_company_name: string | null }>;
    terms: Array<{ id: number; title: string; description: string }>;
    items: FollowUpItem[];
    groups: FollowUpGroup[];
    invoice_scope: InvoiceScope;
}

type Toast = { type: 'error' | 'success'; message: string };

const route = useRoute();
const router = useRouter();
const summaries = ref<QuotationSummary[]>([]);
const workspace = ref<QuotationWorkspace | null>(null);
const selectedFollowUpItemIds = ref<number[]>([]);
const isLoading = ref(false);
const isSavingGroup = ref(false);
const isSavingBulkAction = ref(false);
const isEtaModalOpen = ref(false);
const isSavingEta = ref(false);
const toast = ref<Toast | null>(null);
const groupForm = reactive({
    group_name: '',
    workflow_mode: 'shared' as 'shared' | 'individual',
});
const bulkActionOptions: Array<{ value: BulkAction; label: string }> = [
    { value: 'comment_add', label: 'Add Comment' },
    { value: 'reminder_update', label: 'Update Reminder' },
    { value: 'acknowledgement_record', label: 'Record Acknowledgement' },
    { value: 'shipping_documents_complete', label: 'Complete Shipping Documents' },
    { value: 'documents_sent', label: 'Documents Sent' },
    { value: 'arrival_record', label: 'Arrival / Supplier Receipt' },
    { value: 'warehouse_received', label: 'Warehouse Received' },
    { value: 'buyer_received', label: 'Buyer Received' },
];
const followUpStageOptions: Array<{ value: FollowUpStage; label: string }> = [
    { value: 'acknowledgement', label: 'Order Acknowledgement' },
    { value: 'shipping', label: 'Shipping Details' },
    { value: 'logistics', label: 'ETA / Logistics' },
    { value: 'delivery', label: 'Delivery / Receipt' },
    { value: 'invoice', label: 'Invoice' },
    { value: 'payment', label: 'Payment / Close' },
];
const bulkActionForm = reactive({
    action: 'comment_add' as BulkAction,
    reminder_interval_value: 3,
    reminder_interval_unit: 'days' as 'days' | 'weeks' | 'months' | 'custom',
    next_follow_up_at: '',
    comment: '',
    stage: 'acknowledgement' as FollowUpStage,
    communication_type: 'email',
    contacted_person: '',
    next_action: '',
    acknowledgement_received_at: '',
    acknowledgement_notes: '',
    documents_sent_at: '',
    arrived_at: '',
    warehouse_received_at: '',
    buyer_received_at: '',
    received_location: '',
    received_quantity: '',
    goods_condition: '',
    agent_name: '',
    agent_contact: '',
    remarks: '',
});
const etaTargetIds = ref<number[]>([]);
const etaForm = reactive({
    eta_at: '',
    delivery_responsibility: 'isc' as DeliveryResponsibility,
    agent_name: '',
    agent_contact: '',
    remarks: '',
});

const quotationId = computed(() => (route.params.id ? Number(route.params.id) : null));
const selectedItems = computed(() => workspace.value?.items.filter((item) => selectedFollowUpItemIds.value.includes(item.id)) ?? []);
const etaModalItems = computed(() => workspace.value?.items.filter((item) => etaTargetIds.value.includes(item.id)) ?? []);
const allItemsSelected = computed(() => Boolean(workspace.value?.items.length && selectedFollowUpItemIds.value.length === workspace.value.items.length));
const canSubmitBulkAction = computed(() => selectedFollowUpItemIds.value.length > 0 && isBulkActionReady() && !isSavingBulkAction.value);
const canSaveEtaUpdate = computed(() => etaTargetIds.value.length > 0 && etaForm.eta_at.length > 0 && !isSavingEta.value);

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function formatDate(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Date(value.replace(' ', 'T')).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Date(value.replace(' ', 'T')).toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function toDateTimeLocalValue(value: string | null): string {
    return value ? value.replace(' ', 'T').slice(0, 16) : '';
}

function optionalText(value: string): string | null {
    const trimmed = value.trim();

    return trimmed.length > 0 ? trimmed : null;
}

function defaultDeliveryResponsibility(): DeliveryResponsibility {
    if (!workspace.value) {
        return 'isc';
    }

    if (workspace.value.quotation.delivery_responsibility === 'buyer') {
        return 'buyer_agent';
    }

    if (workspace.value.quotation.delivery_responsibility === 'supplier') {
        return 'supplier';
    }

    return 'isc';
}

function statusLabel(status: string): string {
    return humanizeStatus(status);
}

function listText(values: string[]): string {
    return values.length > 0 ? values.join(', ') : '-';
}

function factoryLabel(item: { factory_name?: string | null; factory_location?: string | null }): string {
    return [item.factory_name, item.factory_location].filter(Boolean).join(' - ') || '-';
}

function toggleItemSelection(itemId: number): void {
    if (selectedFollowUpItemIds.value.includes(itemId)) {
        selectedFollowUpItemIds.value = selectedFollowUpItemIds.value.filter((id) => id !== itemId);
        return;
    }

    selectedFollowUpItemIds.value = [...selectedFollowUpItemIds.value, itemId];
}

function toggleAllItems(): void {
    if (!workspace.value) {
        return;
    }

    selectedFollowUpItemIds.value = allItemsSelected.value ? [] : workspace.value.items.map((item) => item.id);
}

function isBulkActionReady(): boolean {
    switch (bulkActionForm.action) {
        case 'reminder_update':
            return bulkActionForm.reminder_interval_unit === 'custom'
                ? bulkActionForm.next_follow_up_at.length > 0
                : bulkActionForm.reminder_interval_value > 0;
        case 'comment_add':
            return bulkActionForm.comment.trim().length > 0;
        case 'acknowledgement_record':
            return bulkActionForm.acknowledgement_received_at.length > 0;
        case 'shipping_documents_complete':
            return true;
        case 'documents_sent':
            return bulkActionForm.documents_sent_at.length > 0;
        case 'arrival_record':
            return bulkActionForm.arrived_at.length > 0;
        case 'warehouse_received':
            return bulkActionForm.warehouse_received_at.length > 0 && bulkActionForm.received_location.trim().length > 0 && bulkActionForm.goods_condition.trim().length > 0;
        case 'buyer_received':
            return bulkActionForm.buyer_received_at.length > 0 && bulkActionForm.goods_condition.trim().length > 0;
    }

    return false;
}

function buildBulkActionPayload(): Record<string, unknown> {
    const payload: Record<string, unknown> = {
        action: bulkActionForm.action,
        follow_up_item_ids: selectedFollowUpItemIds.value,
    };

    switch (bulkActionForm.action) {
        case 'reminder_update':
            payload.reminder_interval_unit = bulkActionForm.reminder_interval_unit;

            if (bulkActionForm.reminder_interval_unit === 'custom') {
                payload.next_follow_up_at = bulkActionForm.next_follow_up_at;
            } else {
                payload.reminder_interval_value = bulkActionForm.reminder_interval_value;
            }
            break;
        case 'comment_add':
            payload.comment = bulkActionForm.comment.trim();
            payload.stage = bulkActionForm.stage;
            payload.communication_type = optionalText(bulkActionForm.communication_type);
            payload.contacted_person = optionalText(bulkActionForm.contacted_person);
            payload.next_action = optionalText(bulkActionForm.next_action);
            break;
        case 'acknowledgement_record':
            payload.acknowledgement_received_at = bulkActionForm.acknowledgement_received_at;
            payload.acknowledgement_notes = optionalText(bulkActionForm.acknowledgement_notes);
            break;
        case 'shipping_documents_complete':
            break;
        case 'documents_sent':
            payload.documents_sent_at = bulkActionForm.documents_sent_at;
            payload.agent_name = optionalText(bulkActionForm.agent_name);
            payload.agent_contact = optionalText(bulkActionForm.agent_contact);
            payload.remarks = optionalText(bulkActionForm.remarks);
            break;
        case 'arrival_record':
            payload.arrived_at = bulkActionForm.arrived_at;
            payload.remarks = optionalText(bulkActionForm.remarks);
            break;
        case 'warehouse_received':
            payload.warehouse_received_at = bulkActionForm.warehouse_received_at;
            payload.received_location = bulkActionForm.received_location.trim();
            payload.goods_condition = bulkActionForm.goods_condition.trim();
            payload.remarks = optionalText(bulkActionForm.remarks);

            if (bulkActionForm.received_quantity.trim().length > 0) {
                payload.received_quantity = bulkActionForm.received_quantity.trim();
            }
            break;
        case 'buyer_received':
            payload.buyer_received_at = bulkActionForm.buyer_received_at;
            payload.goods_condition = bulkActionForm.goods_condition.trim();
            payload.remarks = optionalText(bulkActionForm.remarks);

            if (bulkActionForm.received_quantity.trim().length > 0) {
                payload.received_quantity = bulkActionForm.received_quantity.trim();
            }
            break;
    }

    return payload;
}

async function loadQuotationSummaries(): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: QuotationSummary[] }>('/api/follow-up/quotations');
        summaries.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation follow-up list.');
    } finally {
        isLoading.value = false;
    }
}

async function loadQuotationDetail(id: number): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: QuotationWorkspace }>(`/api/follow-up/quotations/${id}`);
        workspace.value = payload.data;
        selectedFollowUpItemIds.value = [];
        groupForm.group_name = '';
        groupForm.workflow_mode = 'shared';
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation workspace.');
    } finally {
        isLoading.value = false;
    }
}

async function createFollowUpGroup(): Promise<void> {
    if (!quotationId.value || selectedFollowUpItemIds.value.length === 0 || groupForm.group_name.trim().length === 0) {
        return;
    }

    isSavingGroup.value = true;

    try {
        const payload = await requestJson<{ message: string; data: QuotationWorkspace }>(`/api/follow-up/quotations/${quotationId.value}/groups`, {
            method: 'POST',
            body: JSON.stringify({
                group_name: groupForm.group_name.trim(),
                workflow_mode: groupForm.workflow_mode,
                follow_up_item_ids: selectedFollowUpItemIds.value,
            }),
        });
        workspace.value = payload.data;
        selectedFollowUpItemIds.value = [];
        groupForm.group_name = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save follow-up group.');
    } finally {
        isSavingGroup.value = false;
    }
}

async function splitFollowUpGroup(groupKey: string): Promise<void> {
    if (!quotationId.value) {
        return;
    }

    isSavingGroup.value = true;

    try {
        const payload = await requestJson<{ message: string; data: QuotationWorkspace }>(`/api/follow-up/quotations/${quotationId.value}/groups/${groupKey}`, {
            method: 'DELETE',
        });
        workspace.value = payload.data;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to split follow-up group.');
    } finally {
        isSavingGroup.value = false;
    }
}

async function submitBulkAction(): Promise<void> {
    if (!quotationId.value || !canSubmitBulkAction.value) {
        return;
    }

    isSavingBulkAction.value = true;

    try {
        const payload = await requestJson<{ message: string; data: QuotationWorkspace }>(`/api/follow-up/quotations/${quotationId.value}/bulk-action`, {
            method: 'POST',
            body: JSON.stringify(buildBulkActionPayload()),
        });
        workspace.value = payload.data;
        selectedFollowUpItemIds.value = [];
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to update selected follow-up items.');
    } finally {
        isSavingBulkAction.value = false;
    }
}

function openEtaModal(item?: FollowUpItem): void {
    const targetIds = item ? [item.id] : [...selectedFollowUpItemIds.value];

    if (targetIds.length === 0) {
        showToast('error', 'Select at least one item for ETA.');
        return;
    }

    etaTargetIds.value = targetIds;
    const firstItem = item ?? selectedItems.value[0];
    const firstCase = firstItem?.logistics_case;
    etaForm.eta_at = toDateTimeLocalValue(firstCase?.eta_at ?? null);
    etaForm.delivery_responsibility = firstCase?.delivery_responsibility ?? defaultDeliveryResponsibility();
    etaForm.agent_name = firstCase?.agent_name ?? '';
    etaForm.agent_contact = firstCase?.agent_contact ?? '';
    etaForm.remarks = '';
    isEtaModalOpen.value = true;
}

function closeEtaModal(): void {
    if (isSavingEta.value) {
        return;
    }

    isEtaModalOpen.value = false;
    etaTargetIds.value = [];
}

async function saveEtaUpdate(): Promise<void> {
    if (!quotationId.value || !canSaveEtaUpdate.value) {
        return;
    }

    isSavingEta.value = true;

    try {
        const payload = await requestJson<{ message: string; data: QuotationWorkspace }>(`/api/follow-up/quotations/${quotationId.value}/bulk-action`, {
            method: 'POST',
            body: JSON.stringify({
                action: 'eta_update',
                follow_up_item_ids: etaTargetIds.value,
                eta_at: etaForm.eta_at,
                delivery_responsibility: etaForm.delivery_responsibility,
                agent_name: optionalText(etaForm.agent_name),
                agent_contact: optionalText(etaForm.agent_contact),
                remarks: optionalText(etaForm.remarks),
            }),
        });
        workspace.value = payload.data;
        selectedFollowUpItemIds.value = [];
        isEtaModalOpen.value = false;
        etaTargetIds.value = [];
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to update ETA.');
    } finally {
        isSavingEta.value = false;
    }
}

function refresh(): void {
    if (quotationId.value) {
        void loadQuotationDetail(quotationId.value);
        return;
    }

    void loadQuotationSummaries();
}

watch(
    quotationId,
    (id) => {
        if (id) {
            void loadQuotationDetail(id);
        } else {
            workspace.value = null;
            void loadQuotationSummaries();
        }
    },
    { immediate: true },
);
</script>

<template>
    <section class="page-scaffold follow-up-page quotation-follow-up-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>Follow-Up</p>
                <h1>{{ workspace ? workspace.quotation.quotation_reference : 'Quotation Follow-Up' }}</h1>
            </div>

            <div class="quotation-title-actions">
                <button v-if="workspace" class="secondary-action icon-gap" type="button" @click="router.push('/follow-up/quotations')">
                    <ArrowLeft :size="17" aria-hidden="true" />
                    Quotations
                </button>
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="refresh">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
            </div>
        </div>

        <div v-if="isLoading" class="crud-empty">
            <Loader2 class="spin-icon" :size="20" aria-hidden="true" />
            Loading quotation follow-up...
        </div>

        <template v-else-if="!workspace">
            <section class="table-panel module-table follow-up-list" aria-labelledby="quotation-follow-up-list-title">
                <div class="panel-title">
                    <div>
                        <h2 id="quotation-follow-up-list-title">Buyer Quotations</h2>
                        <p>Open a quotation to view its commercial details, all items, workflow groups, and invoice scope.</p>
                    </div>
                </div>

                <div class="module-records">
                    <div class="module-record head quotation-follow-up-row">
                        <span>Quotation</span>
                        <span>Buyer</span>
                        <span>Buyer PO</span>
                        <span>Supplier PO</span>
                        <span>Items</span>
                        <span>Groups</span>
                        <span>Invoice</span>
                        <span>Action</span>
                    </div>

                    <div v-if="summaries.length === 0" class="crud-empty">No quotation follow-up workspaces found.</div>

                    <div v-for="summary in summaries" v-else :key="summary.quotation_id" class="module-record quotation-follow-up-row">
                        <strong class="job-ref">{{ summary.quotation_reference ?? '-' }}</strong>
                        <span>{{ summary.buyer_company_name ?? '-' }}</span>
                        <span>{{ listText(summary.buyer_po_numbers) }}</span>
                        <span>{{ listText(summary.supplier_po_references) }}</span>
                        <span>{{ summary.follow_up_items_count }}</span>
                        <span>{{ summary.open_groups_count }}</span>
                        <span>{{ summary.invoiced_count }} / {{ summary.follow_up_items_count }}</span>
                        <button class="table-link-button follow-up-view-button" type="button" @click="router.push(`/follow-up/quotations/${summary.quotation_id}`)">
                            <Eye :size="15" aria-hidden="true" />
                            Open
                        </button>
                    </div>
                </div>
            </section>
        </template>

        <template v-else>
            <div class="module-stats follow-up-stats">
                <article class="module-stat">
                    <span>Items</span>
                    <strong>{{ workspace.invoice_scope.quotation_total_items }}</strong>
                </article>
                <article class="module-stat">
                    <span>Workflow Groups</span>
                    <strong>{{ workspace.groups.length }}</strong>
                </article>
                <article class="module-stat">
                    <span>Ready to Invoice</span>
                    <strong>{{ workspace.invoice_scope.ready_for_invoice_items }}</strong>
                </article>
                <article class="module-stat">
                    <span>Invoiced</span>
                    <strong>{{ workspace.invoice_scope.invoiced_items }}</strong>
                </article>
            </div>

            <section class="quotation-follow-up-layout">
                <article class="follow-up-panel quotation-follow-up-details">
                    <header>
                        <div>
                            <p>Quotation Details</p>
                            <h2>{{ workspace.quotation.buyer_company_name ?? '-' }}</h2>
                        </div>
                        <FileText :size="22" aria-hidden="true" />
                    </header>
                    <dl class="quotation-follow-up-dl">
                        <div>
                            <dt>RFQ Title</dt>
                            <dd>{{ workspace.quotation.rfq_title ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>RFQ / PR</dt>
                            <dd>{{ workspace.quotation.rfq_number ?? '-' }} / {{ workspace.quotation.pr_number ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>{{ workspace.quotation.payment_customer_type_label }}</dd>
                        </div>
                        <div>
                            <dt>Incoterm</dt>
                            <dd>{{ workspace.quotation.incoterm_code ?? '-' }} - {{ workspace.quotation.incoterm_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Delivery</dt>
                            <dd>{{ workspace.quotation.delivery_period_min }} to {{ workspace.quotation.delivery_period_max }} {{ workspace.quotation.delivery_period_type }} {{ workspace.quotation.delivery_period_unit }}</dd>
                        </div>
                        <div>
                            <dt>Currency</dt>
                            <dd>{{ workspace.quotation.accepted_invoice_currency }}</dd>
                        </div>
                        <div>
                            <dt>Responsibility</dt>
                            <dd>{{ statusLabel(workspace.quotation.delivery_responsibility) }}</dd>
                        </div>
                    </dl>

                    <div class="quotation-follow-up-terms payment-agreement-list">
                        <strong>Payment Schedule</strong>
                        <p class="empty-note">{{ workspace.quotation.payment_schedule_summary }}</p>
                        <article v-for="schedule in workspace.quotation.payment_schedules" :key="schedule.id">
                            <span>{{ schedule.label }} - {{ schedule.payment_percentage }}%</span>
                            <p>{{ schedule.payment_method_label }} | {{ schedule.due_text }}</p>
                        </article>
                    </div>

                    <div class="quotation-follow-up-linked">
                        <div>
                            <strong>Buyer PO</strong>
                            <span v-if="workspace.buyer_pos.length === 0">-</span>
                            <span v-for="buyerPo in workspace.buyer_pos" v-else :key="buyerPo.id">
                                {{ buyerPo.po_number }} - {{ buyerPo.currency }} {{ buyerPo.po_value }}
                            </span>
                        </div>
                        <div>
                            <strong>Supplier PO</strong>
                            <span v-if="workspace.supplier_pos.length === 0">-</span>
                            <span v-for="supplierPo in workspace.supplier_pos" v-else :key="supplierPo.id">
                                {{ supplierPo.po_reference }} - {{ supplierPo.supplier_company_name ?? '-' }}
                            </span>
                        </div>
                    </div>

                    <div v-if="workspace.terms.length > 0" class="quotation-follow-up-terms">
                        <strong>Terms</strong>
                        <article v-for="term in workspace.terms" :key="term.id">
                            <span>{{ term.title }}</span>
                            <p>{{ term.description }}</p>
                        </article>
                    </div>
                </article>

                <article class="follow-up-panel quotation-follow-up-details">
                    <header>
                        <div>
                            <p>Invoice Scope</p>
                            <h2>{{ workspace.invoice_scope.invoiced_items }} / {{ workspace.invoice_scope.quotation_total_items }} Items</h2>
                        </div>
                        <Layers3 :size="22" aria-hidden="true" />
                    </header>
                    <dl class="quotation-follow-up-dl">
                        <div>
                            <dt>Partial invoices</dt>
                            <dd>{{ workspace.invoice_scope.supports_partial_invoices ? 'Available by item/group' : 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt>Full quotation invoice</dt>
                            <dd>{{ workspace.invoice_scope.supports_full_quotation_invoice ? 'Available after all items are ready' : 'Not available' }}</dd>
                        </div>
                        <div>
                            <dt>Open invoice groups</dt>
                            <dd>{{ workspace.invoice_scope.open_invoice_groups }}</dd>
                        </div>
                    </dl>
                </article>
            </section>

            <section class="table-panel module-table quotation-follow-up-grouping" aria-labelledby="quotation-follow-up-items-title">
                <div class="panel-title">
                    <div>
                        <h2 id="quotation-follow-up-items-title">Quotation Items</h2>
                        <p>Select items that should share the same acknowledgement, shipping, ETA, delivery, invoice, and payment track.</p>
                    </div>
                    <div class="quotation-follow-up-selection-tools">
                        <button class="secondary-action compact-action" type="button" @click="toggleAllItems">
                            <CheckCircle2 :size="16" aria-hidden="true" />
                            {{ allItemsSelected ? 'Clear' : 'Select All' }}
                        </button>
                        <button class="primary-action compact-action" type="button" :disabled="selectedFollowUpItemIds.length === 0" @click="openEtaModal()">
                            <CalendarClock :size="16" aria-hidden="true" />
                            ETA Selected
                        </button>
                    </div>
                </div>

                <div class="quotation-follow-up-group-form">
                    <label>
                        <span>Group Name</span>
                        <input v-model.trim="groupForm.group_name" type="text" placeholder="ABB shared shipment" />
                    </label>
                    <label>
                        <span>Workflow Mode</span>
                        <select v-model="groupForm.workflow_mode">
                            <option value="shared">Shared workflow</option>
                            <option value="individual">Individual tracking label</option>
                        </select>
                    </label>
                    <button class="primary-action compact-action" type="button" :disabled="selectedFollowUpItemIds.length === 0 || !groupForm.group_name || isSavingGroup" @click="createFollowUpGroup">
                        <Loader2 v-if="isSavingGroup" class="spin-icon" :size="17" aria-hidden="true" />
                        <Save v-else :size="17" aria-hidden="true" />
                        Create Group
                    </button>
                    <span>{{ selectedFollowUpItemIds.length }} selected</span>
                </div>

                <form class="quotation-follow-up-action-panel" @submit.prevent="submitBulkAction">
                    <div class="quotation-follow-up-action-head">
                        <div>
                            <strong>Selected Item Update</strong>
                            <span>{{ selectedFollowUpItemIds.length }} selected</span>
                        </div>
                        <button class="secondary-action compact-action" type="button" :disabled="selectedFollowUpItemIds.length === 0" @click="openEtaModal()">
                            <Clock3 :size="16" aria-hidden="true" />
                            ETA
                        </button>
                    </div>

                    <div class="quotation-follow-up-action-grid">
                        <label>
                            <span>Action</span>
                            <select v-model="bulkActionForm.action">
                                <option v-for="option in bulkActionOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>

                        <template v-if="bulkActionForm.action === 'reminder_update'">
                            <label>
                                <span>Interval</span>
                                <input v-model.number="bulkActionForm.reminder_interval_value" type="number" min="1" :disabled="bulkActionForm.reminder_interval_unit === 'custom'" />
                            </label>
                            <label>
                                <span>Unit</span>
                                <select v-model="bulkActionForm.reminder_interval_unit">
                                    <option value="days">Days</option>
                                    <option value="weeks">Weeks</option>
                                    <option value="months">Months</option>
                                    <option value="custom">Custom Date</option>
                                </select>
                            </label>
                            <label v-if="bulkActionForm.reminder_interval_unit === 'custom'">
                                <span>Next Follow-Up</span>
                                <input v-model="bulkActionForm.next_follow_up_at" type="datetime-local" />
                            </label>
                        </template>

                        <template v-else-if="bulkActionForm.action === 'comment_add'">
                            <label>
                                <span>Stage</span>
                                <select v-model="bulkActionForm.stage">
                                    <option v-for="option in followUpStageOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </label>
                            <label>
                                <span>Communication</span>
                                <input v-model="bulkActionForm.communication_type" type="text" />
                            </label>
                            <label>
                                <span>Contacted Person</span>
                                <input v-model="bulkActionForm.contacted_person" type="text" />
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Comment</span>
                                <textarea v-model="bulkActionForm.comment" rows="3"></textarea>
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Next Action</span>
                                <input v-model="bulkActionForm.next_action" type="text" />
                            </label>
                        </template>

                        <template v-else-if="bulkActionForm.action === 'acknowledgement_record'">
                            <label>
                                <span>Received At</span>
                                <input v-model="bulkActionForm.acknowledgement_received_at" type="datetime-local" />
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Notes</span>
                                <textarea v-model="bulkActionForm.acknowledgement_notes" rows="3"></textarea>
                            </label>
                        </template>

                        <template v-else-if="bulkActionForm.action === 'documents_sent'">
                            <label>
                                <span>Sent At</span>
                                <input v-model="bulkActionForm.documents_sent_at" type="datetime-local" />
                            </label>
                            <label>
                                <span>Agent Name</span>
                                <input v-model="bulkActionForm.agent_name" type="text" />
                            </label>
                            <label>
                                <span>Agent Contact</span>
                                <input v-model="bulkActionForm.agent_contact" type="text" />
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Remarks</span>
                                <textarea v-model="bulkActionForm.remarks" rows="3"></textarea>
                            </label>
                        </template>

                        <template v-else-if="bulkActionForm.action === 'arrival_record'">
                            <label>
                                <span>Arrived At</span>
                                <input v-model="bulkActionForm.arrived_at" type="datetime-local" />
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Remarks</span>
                                <textarea v-model="bulkActionForm.remarks" rows="3"></textarea>
                            </label>
                        </template>

                        <template v-else-if="bulkActionForm.action === 'warehouse_received'">
                            <label>
                                <span>Received At</span>
                                <input v-model="bulkActionForm.warehouse_received_at" type="datetime-local" />
                            </label>
                            <label>
                                <span>Location</span>
                                <input v-model="bulkActionForm.received_location" type="text" />
                            </label>
                            <label>
                                <span>Quantity</span>
                                <input v-model="bulkActionForm.received_quantity" type="number" min="0.001" step="0.001" placeholder="Full" />
                            </label>
                            <label>
                                <span>Condition</span>
                                <input v-model="bulkActionForm.goods_condition" type="text" />
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Remarks</span>
                                <textarea v-model="bulkActionForm.remarks" rows="3"></textarea>
                            </label>
                        </template>

                        <template v-else-if="bulkActionForm.action === 'buyer_received'">
                            <label>
                                <span>Received At</span>
                                <input v-model="bulkActionForm.buyer_received_at" type="datetime-local" />
                            </label>
                            <label>
                                <span>Quantity</span>
                                <input v-model="bulkActionForm.received_quantity" type="number" min="0.001" step="0.001" placeholder="Full" />
                            </label>
                            <label>
                                <span>Condition</span>
                                <input v-model="bulkActionForm.goods_condition" type="text" />
                            </label>
                            <label class="quotation-follow-up-wide">
                                <span>Remarks</span>
                                <textarea v-model="bulkActionForm.remarks" rows="3"></textarea>
                            </label>
                        </template>
                    </div>

                    <footer class="quotation-follow-up-action-footer">
                        <span v-if="bulkActionForm.action === 'shipping_documents_complete'">
                            Required documents must already be uploaded for each selected item.
                        </span>
                        <button class="primary-action compact-action" type="submit" :disabled="!canSubmitBulkAction">
                            <Loader2 v-if="isSavingBulkAction" class="spin-icon" :size="17" aria-hidden="true" />
                            <MessageSquare v-else-if="bulkActionForm.action === 'comment_add'" :size="17" aria-hidden="true" />
                            <PackageCheck v-else-if="bulkActionForm.action === 'shipping_documents_complete'" :size="17" aria-hidden="true" />
                            <Send v-else-if="bulkActionForm.action === 'documents_sent'" :size="17" aria-hidden="true" />
                            <Truck v-else-if="bulkActionForm.action === 'arrival_record' || bulkActionForm.action === 'warehouse_received' || bulkActionForm.action === 'buyer_received'" :size="17" aria-hidden="true" />
                            <Save v-else :size="17" aria-hidden="true" />
                            Apply
                        </button>
                    </footer>
                </form>

                <div class="quotation-follow-up-items">
                    <article v-for="item in workspace.items" :key="item.id" class="quotation-follow-up-item">
                        <label>
                            <input type="checkbox" :checked="selectedFollowUpItemIds.includes(item.id)" @change="toggleItemSelection(item.id)" />
                            <span>
                                <strong>{{ item.product_code ? `${item.product_code} - ` : '' }}{{ item.title ?? item.product_name ?? '-' }}</strong>
                                <small>{{ item.manufacturer_name ?? '-' }} | Factory {{ factoryLabel(item) }} | {{ item.supplier_po_reference ?? '-' }} | {{ item.buyer_po_number ?? '-' }}</small>
                            </span>
                        </label>
                        <div class="quotation-follow-up-status">
                            <span class="stage-pill amber">{{ item.current_stage_label }}</span>
                            <small>{{ item.status_label }}</small>
                        </div>
                        <div class="quotation-follow-up-item-meta">
                            <span>{{ item.follow_up_group_name ?? 'Individual item' }}</span>
                            <small>Next: {{ formatDate(item.next_follow_up_at) }}</small>
                        </div>
                        <div class="quotation-follow-up-eta">
                            <span>ETA</span>
                            <strong>{{ formatDateTime(item.logistics_case?.eta_at ?? null) }}</strong>
                        </div>
                        <div class="quotation-follow-up-item-actions">
                            <button class="secondary-action compact-action" type="button" @click="openEtaModal(item)">
                                <CalendarClock :size="15" aria-hidden="true" />
                                ETA
                            </button>
                            <button class="table-link-button follow-up-view-button" type="button" @click="router.push(`/follow-up/${item.id}`)">
                                <Eye :size="15" aria-hidden="true" />
                                Item
                            </button>
                        </div>
                    </article>
                </div>
            </section>

            <section class="table-panel module-table quotation-follow-up-grouping" aria-labelledby="quotation-follow-up-groups-title">
                <div class="panel-title">
                    <div>
                        <h2 id="quotation-follow-up-groups-title">Workflow Groups</h2>
                        <p>Shared groups let the follow-up person treat multiple items as one operational track when supplier documents apply to all of them.</p>
                    </div>
                </div>

                <div class="quotation-follow-up-groups">
                    <article v-for="group in workspace.groups" :key="group.group_key" class="quotation-follow-up-group-card">
                        <header>
                            <div>
                                <h3>{{ group.group_name }}</h3>
                                <p>{{ group.item_count }} item{{ group.item_count === 1 ? '' : 's' }} - {{ group.workflow_mode }}</p>
                            </div>
                            <span class="stage-pill teal">{{ group.stage_labels.join(', ') }}</span>
                        </header>
                        <div class="quotation-follow-up-chip-row">
                            <span>{{ listText(group.manufacturer_names) }}</span>
                            <span>Factory {{ listText(group.factory_names) }}</span>
                            <span>{{ listText(group.supplier_po_references) }}</span>
                            <span>Next: {{ formatDate(group.next_follow_up_at) }}</span>
                        </div>
                        <div class="quotation-follow-up-mini-items">
                            <span v-for="groupItem in group.items" :key="groupItem.id">{{ groupItem.product_code ? `${groupItem.product_code} - ` : '' }}{{ groupItem.title ?? '-' }} | Factory {{ factoryLabel(groupItem) }}</span>
                        </div>
                        <button v-if="group.is_persisted_group" class="secondary-action compact-action" type="button" :disabled="isSavingGroup" @click="splitFollowUpGroup(group.group_key)">
                            <SplitSquareHorizontal :size="16" aria-hidden="true" />
                            Split
                        </button>
                    </article>
                </div>
            </section>
        </template>

        <div v-if="isEtaModalOpen" class="modal-backdrop" @click.self="closeEtaModal">
            <article class="crud-modal eta-update-modal" aria-labelledby="eta-update-title">
                <header>
                    <div>
                        <h2 id="eta-update-title">ETA Update</h2>
                        <p>{{ etaModalItems.length }} item{{ etaModalItems.length === 1 ? '' : 's' }}</p>
                    </div>
                    <button class="top-icon-button" type="button" :disabled="isSavingEta" @click="closeEtaModal">
                        <X :size="18" aria-hidden="true" />
                    </button>
                </header>

                <div class="eta-update-body">
                    <div class="eta-update-items">
                        <article v-for="item in etaModalItems" :key="item.id">
                            <strong>{{ item.product_code ? `${item.product_code} - ` : '' }}{{ item.title ?? item.product_name ?? '-' }}</strong>
                            <span>{{ item.buyer_po_number ?? '-' }} | {{ item.supplier_po_reference ?? '-' }}</span>
                            <small>Current ETA: {{ formatDateTime(item.logistics_case?.eta_at ?? null) }}</small>
                        </article>
                    </div>

                    <form class="eta-update-form" @submit.prevent="saveEtaUpdate">
                        <label>
                            <span>ETA</span>
                            <input v-model="etaForm.eta_at" type="datetime-local" :disabled="isSavingEta" />
                        </label>
                        <label>
                            <span>Delivery Responsibility</span>
                            <select v-model="etaForm.delivery_responsibility" :disabled="isSavingEta">
                                <option value="isc">ISC / Internal Delivery</option>
                                <option value="buyer_agent">Buyer Agent</option>
                                <option value="supplier">Supplier / Manufacturer</option>
                            </select>
                        </label>
                        <label>
                            <span>Agent Name</span>
                            <input v-model="etaForm.agent_name" type="text" :disabled="isSavingEta" />
                        </label>
                        <label>
                            <span>Agent Contact</span>
                            <input v-model="etaForm.agent_contact" type="text" :disabled="isSavingEta" />
                        </label>
                        <label class="quotation-follow-up-wide">
                            <span>Remarks</span>
                            <textarea v-model="etaForm.remarks" rows="3" :disabled="isSavingEta"></textarea>
                        </label>

                        <footer>
                            <button class="secondary-action compact-action" type="button" :disabled="isSavingEta" @click="closeEtaModal">
                                Cancel
                            </button>
                            <button class="primary-action compact-action" type="submit" :disabled="!canSaveEtaUpdate">
                                <Loader2 v-if="isSavingEta" class="spin-icon" :size="17" aria-hidden="true" />
                                <CalendarClock v-else :size="17" aria-hidden="true" />
                                Save ETA
                            </button>
                        </footer>
                    </form>
                </div>
            </article>
        </div>
    </section>
</template>
