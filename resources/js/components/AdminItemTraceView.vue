<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { AlertTriangle, CheckCircle2, Clock3, Download, Eye, Loader2, RefreshCcw, Search } from 'lucide-vue-next';
import { downloadProtectedFile, requestJson } from '../auth';

interface TraceOption {
    value: string;
    label: string;
}

interface TraceFilterOptions {
    buyer_id: TraceOption[];
    supplier_id: TraceOption[];
    manufacturer_id: TraceOption[];
    quotation_status: TraceOption[];
    follow_up_status: TraceOption[];
}

interface ItemTraceSummary {
    items: number;
    quotations: number;
    supplier_pos: number;
    open_follow_ups: number;
    overdue_follow_ups: number;
}

interface TraceComment {
    id: number;
    stage_label: string;
    comment: string;
    created_by_name: string | null;
    created_at: string | null;
}

interface TraceTimelineEvent {
    id: string;
    source: string;
    stage_label: string;
    action: string;
    summary: string;
    user_name: string | null;
    occurred_at: string | null;
    elapsed_from_previous_label: string | null;
}

interface ItemTraceRecord {
    id: number;
    quotation_item_id: number;
    quotation_id: number;
    quotation_reference: string | null;
    buyer_company_name: string | null;
    buyer_po_number: string | null;
    supplier_po_reference: string | null;
    supplier_company_name: string | null;
    manufacturer_name: string | null;
    product_name: string | null;
    title: string | null;
    quantity: string;
    uom: string | null;
    status: string;
    status_label: string;
    current_stage_label: string;
    next_follow_up_at: string | null;
    latest_comment: TraceComment | null;
    comments_count: number;
    timeline_events: TraceTimelineEvent[];
    quotation_url: string;
    follow_up_url: string | null;
}

type Toast = { type: 'error' | 'success'; message: string };

const router = useRouter();
const items = ref<ItemTraceRecord[]>([]);
const summary = ref<ItemTraceSummary>({
    items: 0,
    quotations: 0,
    supplier_pos: 0,
    open_follow_ups: 0,
    overdue_follow_ups: 0,
});
const filterOptions = ref<TraceFilterOptions>({
    buyer_id: [],
    supplier_id: [],
    manufacturer_id: [],
    quotation_status: [],
    follow_up_status: [],
});
const search = ref('');
const status = ref('all');
const buyerId = ref('all');
const supplierId = ref('all');
const manufacturerId = ref('all');
const isLoading = ref(false);
const isExporting = ref(false);
const toast = ref<Toast | null>(null);
let searchTimer: number | undefined;

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '-';
    }

    return new Date(value.replace(' ', 'T')).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function resetFilters(): void {
    search.value = '';
    status.value = 'all';
    buyerId.value = 'all';
    supplierId.value = 'all';
    manufacturerId.value = 'all';
}

function traceParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (search.value.trim()) params.set('search', search.value.trim());
    if (status.value !== 'all') params.set('status', status.value);
    if (buyerId.value !== 'all') params.set('buyer_id', buyerId.value);
    if (supplierId.value !== 'all') params.set('supplier_id', supplierId.value);
    if (manufacturerId.value !== 'all') params.set('manufacturer_id', manufacturerId.value);
    return params;
}

async function loadItems(): Promise<void> {
    isLoading.value = true;

    try {
        const params = traceParams();
        const payload = await requestJson<{
            summary: ItemTraceSummary;
            filter_options: TraceFilterOptions;
            data: ItemTraceRecord[];
        }>(`/api/admin/trace/items${params.toString() ? `?${params.toString()}` : ''}`);
        summary.value = payload.summary;
        filterOptions.value = payload.filter_options;
        items.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load item trace.');
    } finally {
        isLoading.value = false;
    }
}

async function exportItems(): Promise<void> {
    isExporting.value = true;

    try {
        const params = traceParams();
        await downloadProtectedFile(`/api/admin/trace/items/export${params.toString() ? `?${params.toString()}` : ''}`, 'item-trace.csv');
        showToast('success', 'Item trace CSV downloaded.');
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to export item trace.');
    } finally {
        isExporting.value = false;
    }
}

watch([status, buyerId, supplierId, manufacturerId], loadItems);

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(loadItems, 320);
});

onMounted(loadItems);
</script>

<template>
    <section class="page-scaffold trace-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>Admin Trace</p>
                <h1>Item Trace</h1>
            </div>

            <div class="quotation-title-actions">
                <button class="secondary-action icon-gap" type="button" :disabled="isExporting || isLoading" @click="exportItems">
                    <Loader2 v-if="isExporting" class="spin-icon" :size="17" aria-hidden="true" />
                    <Download v-else :size="17" aria-hidden="true" />
                    Export CSV
                </button>
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadItems">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
            </div>
        </div>

        <div class="module-stats trace-stats">
            <article class="module-stat">
                <span>Items</span>
                <strong>{{ summary.items }}</strong>
            </article>
            <article class="module-stat">
                <span>Quotations</span>
                <strong>{{ summary.quotations }}</strong>
            </article>
            <article class="module-stat">
                <span>Supplier POs</span>
                <strong>{{ summary.supplier_pos }}</strong>
            </article>
            <article class="module-stat danger-stat">
                <span>Overdue</span>
                <strong>{{ summary.overdue_follow_ups }}</strong>
            </article>
        </div>

        <section class="follow-up-control-panel trace-filter-panel" aria-label="Item trace filters">
            <label>
                <span>Follow-Up Status</span>
                <select v-model="status">
                    <option value="all">All Statuses</option>
                    <option v-for="option in filterOptions.follow_up_status" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label>
                <span>Buyer</span>
                <select v-model="buyerId">
                    <option value="all">All Buyers</option>
                    <option v-for="option in filterOptions.buyer_id" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label>
                <span>Supplier</span>
                <select v-model="supplierId">
                    <option value="all">All Suppliers</option>
                    <option v-for="option in filterOptions.supplier_id" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label>
                <span>Manufacturer</span>
                <select v-model="manufacturerId">
                    <option value="all">All Manufacturers</option>
                    <option v-for="option in filterOptions.manufacturer_id" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label class="follow-up-search-control">
                <span>Search</span>
                <span class="mini-search">
                    <Search :size="16" aria-hidden="true" />
                    <input v-model="search" type="search" placeholder="Item, quotation, Buyer PO, Supplier PO..." />
                </span>
            </label>
            <button class="secondary-action compact-action" type="button" @click="resetFilters">Reset</button>
        </section>

        <section class="table-panel module-table trace-list" aria-labelledby="item-trace-title">
            <div class="panel-title">
                <div>
                    <h2 id="item-trace-title">All Products / Items</h2>
                    <p>Each product row shows which quotation, Buyer PO, Supplier PO, status, comments, and timeline it belongs to.</p>
                </div>
            </div>

            <div class="module-records">
                <div class="module-record head trace-item-row">
                    <span>Item</span>
                    <span>Quotation</span>
                    <span>Buyer PO</span>
                    <span>Supplier PO</span>
                    <span>Buyer</span>
                    <span>Supplier</span>
                    <span>Status</span>
                    <span>Comment</span>
                    <span>Action</span>
                </div>

                <div v-if="isLoading" class="crud-empty">
                    <Loader2 :size="20" aria-hidden="true" />
                    Loading item trace...
                </div>

                <div v-else-if="items.length === 0" class="crud-empty">No item trace records found.</div>

                <div v-for="item in items" v-else :key="item.quotation_item_id" class="module-record trace-item-row">
                    <strong class="job-ref">{{ item.title ?? item.product_name ?? '-' }}</strong>
                    <span>{{ item.quotation_reference ?? '-' }}</span>
                    <span>{{ item.buyer_po_number ?? '-' }}</span>
                    <span>{{ item.supplier_po_reference ?? '-' }}</span>
                    <span>{{ item.buyer_company_name ?? '-' }}</span>
                    <span>{{ item.supplier_company_name ?? '-' }}</span>
                    <span class="stage-pill teal">{{ item.status_label }}</span>
                    <span>{{ item.latest_comment?.comment ?? '-' }}</span>
                    <span class="trace-actions">
                        <button class="table-link-button follow-up-view-button" type="button" @click="router.push(item.quotation_url)">
                            <Eye :size="15" aria-hidden="true" />
                            Quote
                        </button>
                        <button
                            v-if="item.follow_up_url"
                            class="table-link-button follow-up-view-button"
                            type="button"
                            @click="router.push(item.follow_up_url)"
                        >
                            <Clock3 :size="15" aria-hidden="true" />
                            Follow
                        </button>
                    </span>
                </div>
            </div>
        </section>
    </section>
</template>
