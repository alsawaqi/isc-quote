<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowLeft, CheckCircle2, Eye, FileText, Layers3, Loader2, RefreshCcw, Save, SplitSquareHorizontal } from 'lucide-vue-next';
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
    closing_at: string | null;
    payment_term_days: number;
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

interface FollowUpItem {
    id: number;
    quotation_item_id: number;
    supplier_po_reference: string | null;
    buyer_po_number: string | null;
    follow_up_group_key: string | null;
    follow_up_group_name: string | null;
    follow_up_group_mode: string;
    supplier_company_name: string | null;
    manufacturer_name: string | null;
    product_name: string | null;
    title: string | null;
    quantity: string | null;
    uom: string | null;
    status: string;
    status_label: string;
    current_stage_label: string;
    next_follow_up_at: string | null;
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
    supplier_po_references: string[];
    next_follow_up_at: string | null;
    items: Array<{
        id: number;
        title: string | null;
        manufacturer_name: string | null;
        status_label: string;
        current_stage_label: string;
        supplier_po_reference: string | null;
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
const toast = ref<Toast | null>(null);
const groupForm = reactive({
    group_name: '',
    workflow_mode: 'shared' as 'shared' | 'individual',
});

const quotationId = computed(() => (route.params.id ? Number(route.params.id) : null));
const selectedItems = computed(() => workspace.value?.items.filter((item) => selectedFollowUpItemIds.value.includes(item.id)) ?? []);

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

function statusLabel(status: string): string {
    return humanizeStatus(status);
}

function listText(values: string[]): string {
    return values.length > 0 ? values.join(', ') : '-';
}

function toggleItemSelection(itemId: number): void {
    if (selectedFollowUpItemIds.value.includes(itemId)) {
        selectedFollowUpItemIds.value = selectedFollowUpItemIds.value.filter((id) => id !== itemId);
        return;
    }

    selectedFollowUpItemIds.value = [...selectedFollowUpItemIds.value, itemId];
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
                            <dt>RFQ / PR</dt>
                            <dd>{{ workspace.quotation.rfq_number ?? '-' }} / {{ workspace.quotation.pr_number ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>{{ workspace.quotation.payment_term_days }} days from invoice</dd>
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

                <div class="quotation-follow-up-items">
                    <article v-for="item in workspace.items" :key="item.id" class="quotation-follow-up-item">
                        <label>
                            <input type="checkbox" :checked="selectedFollowUpItemIds.includes(item.id)" @change="toggleItemSelection(item.id)" />
                            <span>
                                <strong>{{ item.title ?? item.product_name ?? '-' }}</strong>
                                <small>{{ item.manufacturer_name ?? '-' }} | {{ item.supplier_po_reference ?? '-' }} | {{ item.buyer_po_number ?? '-' }}</small>
                            </span>
                        </label>
                        <span class="stage-pill amber">{{ item.current_stage_label }}</span>
                        <span>{{ item.follow_up_group_name ?? 'Individual item' }}</span>
                        <button class="table-link-button follow-up-view-button" type="button" @click="router.push(`/follow-up/${item.id}`)">
                            <Eye :size="15" aria-hidden="true" />
                            Item
                        </button>
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
                            <span>{{ listText(group.supplier_po_references) }}</span>
                            <span>Next: {{ formatDate(group.next_follow_up_at) }}</span>
                        </div>
                        <div class="quotation-follow-up-mini-items">
                            <span v-for="groupItem in group.items" :key="groupItem.id">{{ groupItem.title ?? '-' }}</span>
                        </div>
                        <button v-if="group.is_persisted_group" class="secondary-action compact-action" type="button" :disabled="isSavingGroup" @click="splitFollowUpGroup(group.group_key)">
                            <SplitSquareHorizontal :size="16" aria-hidden="true" />
                            Split
                        </button>
                    </article>
                </div>
            </section>
        </template>
    </section>
</template>
