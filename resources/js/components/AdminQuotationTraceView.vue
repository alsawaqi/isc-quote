<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { AlertTriangle, ArrowLeft, CheckCircle2, Clock3, Eye, Loader2, RefreshCcw, Search } from 'lucide-vue-next';
import { requestJson } from '../auth';
import { humanizeStatus } from '../utils/format';

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

interface TraceSummary {
    quotations: number;
    items: number;
    buyer_pos?: number;
    supplier_pos: number;
    open_follow_ups: number;
    overdue_follow_ups: number;
}

interface TraceComment {
    id: number;
    stage_label: string;
    comment: string;
    communication_type: string | null;
    contacted_person: string | null;
    next_action: string | null;
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

interface TraceItem {
    id: number;
    quotation_item_id: number;
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
    elapsed_label: string | null;
    comments?: TraceComment[];
    timeline_events?: TraceTimelineEvent[];
    follow_up_url: string | null;
}

interface QuotationTraceRecord {
    id: number;
    quotation_reference: string;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    salesperson_name: string | null;
    rfq_number: string | null;
    pr_number: string | null;
    status: string;
    status_label: string;
    current_stage_label: string;
    accepted_invoice_currency: string;
    items_count: number;
    items_sum_total_price: string | null;
    buyer_po_numbers: string[];
    supplier_po_references: string[];
    latest_comment: TraceComment | null;
    open_items: number;
    elapsed_label: string | null;
    detail_url: string;
    items: TraceItem[];
}

interface QuotationTraceDetail extends QuotationTraceRecord {
    supplier_company_name: string | null;
    supplier_contact_name: string | null;
    incoterm_code: string | null;
    closing_at: string | null;
    buyer_pos: Array<{
        id: number;
        po_number: string;
        po_date: string | null;
        po_value: string;
        currency: string;
        status_label: string;
        quotation_version_number: number | null;
    }>;
    items: TraceItem[];
}

type Toast = { type: 'error' | 'success'; message: string };

const emptyOptions: TraceFilterOptions = {
    buyer_id: [],
    supplier_id: [],
    manufacturer_id: [],
    quotation_status: [],
    follow_up_status: [],
};

const route = useRoute();
const router = useRouter();
const records = ref<QuotationTraceRecord[]>([]);
const detail = ref<QuotationTraceDetail | null>(null);
const summary = ref<TraceSummary>({
    quotations: 0,
    items: 0,
    buyer_pos: 0,
    supplier_pos: 0,
    open_follow_ups: 0,
    overdue_follow_ups: 0,
});
const filterOptions = ref<TraceFilterOptions>(emptyOptions);
const search = ref('');
const status = ref('all');
const followUpStatus = ref('all');
const buyerId = ref('all');
const supplierId = ref('all');
const manufacturerId = ref('all');
const isLoading = ref(false);
const toast = ref<Toast | null>(null);
let searchTimer: number | undefined;

const isDetailMode = computed(() => Boolean(route.params.id));

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

function joined(values: string[]): string {
    return values.length > 0 ? values.join(', ') : '-';
}

function resetFilters(): void {
    search.value = '';
    status.value = 'all';
    followUpStatus.value = 'all';
    buyerId.value = 'all';
    supplierId.value = 'all';
    manufacturerId.value = 'all';
}

function traceParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (search.value.trim()) params.set('search', search.value.trim());
    if (status.value !== 'all') params.set('status', status.value);
    if (followUpStatus.value !== 'all') params.set('follow_up_status', followUpStatus.value);
    if (buyerId.value !== 'all') params.set('buyer_id', buyerId.value);
    if (supplierId.value !== 'all') params.set('supplier_id', supplierId.value);
    if (manufacturerId.value !== 'all') params.set('manufacturer_id', manufacturerId.value);
    return params;
}

async function loadQuotations(): Promise<void> {
    isLoading.value = true;

    try {
        const params = traceParams();
        const payload = await requestJson<{
            summary: TraceSummary;
            filter_options: TraceFilterOptions;
            data: QuotationTraceRecord[];
        }>(`/api/admin/trace/quotations${params.toString() ? `?${params.toString()}` : ''}`);
        summary.value = payload.summary;
        filterOptions.value = payload.filter_options;
        records.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation trace.');
    } finally {
        isLoading.value = false;
    }
}

async function loadDetail(): Promise<void> {
    if (!route.params.id) {
        return;
    }

    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: QuotationTraceDetail }>(`/api/admin/trace/quotations/${route.params.id}`);
        detail.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation trace detail.');
    } finally {
        isLoading.value = false;
    }
}

function loadCurrentMode(): void {
    if (isDetailMode.value) {
        loadDetail();
        return;
    }

    loadQuotations();
}

watch([status, followUpStatus, buyerId, supplierId, manufacturerId], () => {
    if (!isDetailMode.value) {
        loadQuotations();
    }
});

watch(search, () => {
    if (isDetailMode.value) {
        return;
    }

    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(loadQuotations, 320);
});

watch(
    () => route.params.id,
    () => {
        detail.value = null;
        loadCurrentMode();
    },
);

onMounted(loadCurrentMode);
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
                <h1>{{ isDetailMode ? 'Quotation Timeline' : 'Quotation Trace' }}</h1>
            </div>

            <div class="quotation-title-actions">
                <button v-if="isDetailMode" class="secondary-action icon-gap" type="button" @click="router.push('/admin/trace/quotations')">
                    <ArrowLeft :size="17" aria-hidden="true" />
                    Back
                </button>
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadCurrentMode">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
            </div>
        </div>

        <template v-if="!isDetailMode">
            <div class="module-stats trace-stats">
                <article class="module-stat">
                    <span>Quotations</span>
                    <strong>{{ summary.quotations }}</strong>
                </article>
                <article class="module-stat">
                    <span>Items</span>
                    <strong>{{ summary.items }}</strong>
                </article>
                <article class="module-stat">
                    <span>Supplier POs</span>
                    <strong>{{ summary.supplier_pos }}</strong>
                </article>
                <article class="module-stat danger-stat">
                    <span>Overdue Follow-Ups</span>
                    <strong>{{ summary.overdue_follow_ups }}</strong>
                </article>
            </div>

            <section class="follow-up-control-panel trace-filter-panel" aria-label="Quotation trace filters">
                <label>
                    <span>Quotation Status</span>
                    <select v-model="status">
                        <option value="all">All Statuses</option>
                        <option v-for="option in filterOptions.quotation_status" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label>
                    <span>Follow-Up Status</span>
                    <select v-model="followUpStatus">
                        <option value="all">All Follow-Ups</option>
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
                        <input v-model="search" type="search" placeholder="Quotation, buyer, Buyer PO..." />
                    </span>
                </label>
                <button class="secondary-action compact-action" type="button" @click="resetFilters">Reset</button>
            </section>

            <section class="table-panel module-table trace-list" aria-labelledby="quotation-trace-title">
                <div class="panel-title">
                    <div>
                        <h2 id="quotation-trace-title">Current Quotations</h2>
                        <p>Filter by quotation, Buyer PO, Supplier PO, buyer, product, or current follow-up state.</p>
                    </div>
                </div>

                <div class="module-records">
                    <div class="module-record head trace-quotation-row">
                        <span>Quotation</span>
                        <span>Buyer</span>
                        <span>Buyer PO</span>
                        <span>Supplier PO</span>
                        <span>Stage</span>
                        <span>Items</span>
                        <span>Latest Comment</span>
                        <span>Action</span>
                    </div>

                    <div v-if="isLoading" class="crud-empty">
                        <Loader2 :size="20" aria-hidden="true" />
                        Loading quotation trace...
                    </div>

                    <div v-else-if="records.length === 0" class="crud-empty">No quotation trace records found.</div>

                    <div v-for="record in records" v-else :key="record.id" class="module-record trace-quotation-row">
                        <strong class="job-ref">{{ record.quotation_reference }}</strong>
                        <span>{{ record.buyer_company_name ?? '-' }}</span>
                        <span>{{ joined(record.buyer_po_numbers) }}</span>
                        <span>{{ joined(record.supplier_po_references) }}</span>
                        <span class="stage-pill teal">{{ record.current_stage_label }}</span>
                        <span>{{ record.items_count }} item{{ record.items_count === 1 ? '' : 's' }}</span>
                        <span>{{ record.latest_comment?.comment ?? '-' }}</span>
                        <button class="table-link-button follow-up-view-button" type="button" @click="router.push(record.detail_url)">
                            <Eye :size="15" aria-hidden="true" />
                            View
                        </button>
                    </div>
                </div>
            </section>
        </template>

        <template v-else>
            <div v-if="isLoading" class="crud-empty">
                <Loader2 :size="20" aria-hidden="true" />
                Loading quotation timeline...
            </div>

            <div v-else-if="!detail" class="crud-empty">Quotation trace detail was not found.</div>

            <template v-else>
                <div class="module-stats trace-stats">
                    <article class="module-stat">
                        <span>Quotation</span>
                        <strong>{{ detail.quotation_reference }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Buyer</span>
                        <strong>{{ detail.buyer_company_name ?? '-' }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Buyer PO</span>
                        <strong>{{ joined(detail.buyer_po_numbers) }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Status</span>
                        <strong>{{ detail.current_stage_label }}</strong>
                    </article>
                </div>

                <section class="trace-detail-grid">
                    <article v-for="item in detail.items" :key="item.quotation_item_id" class="trace-item-card">
                        <header>
                            <div>
                                <small>{{ item.manufacturer_name ?? '-' }}</small>
                                <h2>{{ item.title ?? item.product_name ?? 'Product item' }}</h2>
                            </div>
                            <span class="stage-pill teal">{{ item.status_label }}</span>
                        </header>

                        <dl class="trace-meta-grid">
                            <div>
                                <dt>Buyer PO</dt>
                                <dd>{{ item.buyer_po_number ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Supplier PO</dt>
                                <dd>{{ item.supplier_po_reference ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Supplier</dt>
                                <dd>{{ item.supplier_company_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Quantity</dt>
                                <dd>{{ item.quantity }} {{ item.uom ?? '' }}</dd>
                            </div>
                            <div>
                                <dt>Next Follow-Up</dt>
                                <dd>{{ formatDate(item.next_follow_up_at) }}</dd>
                            </div>
                            <div>
                                <dt>Elapsed</dt>
                                <dd>{{ item.elapsed_label ?? '-' }}</dd>
                            </div>
                        </dl>

                        <section class="trace-comments">
                            <h3>Comments</h3>
                            <p v-if="!item.comments || item.comments.length === 0">No comments recorded for this product yet.</p>
                            <article v-for="comment in item.comments" v-else :key="comment.id">
                                <strong>{{ comment.stage_label }}</strong>
                                <span>{{ comment.comment }}</span>
                                <small>{{ comment.created_by_name ?? '-' }} - {{ formatDate(comment.created_at) }}</small>
                            </article>
                        </section>

                        <section class="full-timeline-list trace-timeline" aria-label="Timeline">
                            <h3>Timeline</h3>
                            <article v-for="event in item.timeline_events" :key="event.id">
                                <span class="timeline-dot"></span>
                                <div>
                                    <div class="timeline-event-top">
                                        <strong>{{ event.stage_label }}</strong>
                                        <small>{{ formatDate(event.occurred_at) }}</small>
                                    </div>
                                    <p>{{ event.summary }}</p>
                                    <small v-if="event.elapsed_from_previous_label" class="timeline-elapsed">
                                        <Clock3 :size="13" aria-hidden="true" />
                                        {{ event.elapsed_from_previous_label }}
                                    </small>
                                </div>
                            </article>
                        </section>
                    </article>
                </section>
            </template>
        </template>
    </section>
</template>
