<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { AlertTriangle, Bell, CheckCircle2, Clock3, Eye, Loader2, RefreshCcw, Search } from 'lucide-vue-next';
import { requestJson } from '../auth';

interface FollowUpSummary {
    total: number;
    due_today: number;
    overdue: number;
    upcoming: number;
}

interface FollowUpItem {
    id: number;
    supplier_po_reference: string | null;
    quotation_reference: string | null;
    buyer_po_number: string | null;
    buyer_company_name: string | null;
    supplier_company_name: string | null;
    manufacturer_name: string | null;
    product_name: string | null;
    title: string | null;
    status: string;
    current_stage_label: string;
    next_follow_up_at: string | null;
}

interface FollowUpOption {
    value: string;
    label: string;
}

interface FollowUpGroup {
    key: string;
    label: string;
    count: number;
    overdue_count: number;
    due_today_count: number;
    oldest_next_follow_up_at: string | null;
    items: FollowUpItem[];
}

interface FollowUpFilters {
    search: string;
    action: string;
    stage: string;
}

interface FollowUpFilterOptions {
    group_by: FollowUpOption[];
    action: FollowUpOption[];
    stage: FollowUpOption[];
}

type Toast = { type: 'error' | 'success'; message: string };

const router = useRouter();
const items = ref<FollowUpItem[]>([]);
const groups = ref<FollowUpGroup[]>([]);
const groupBy = ref('action');
const actionFilter = ref('all');
const stageFilter = ref('all');
const filterOptions = ref<FollowUpFilterOptions>({
    group_by: [
        { value: 'action', label: 'Required Action' },
        { value: 'job', label: 'Job / Quotation' },
        { value: 'buyer', label: 'Buyer' },
        { value: 'supplier_po', label: 'Supplier PO' },
        { value: 'buyer_po', label: 'Buyer PO' },
        { value: 'manufacturer', label: 'Manufacturer' },
        { value: 'stage', label: 'Workflow Stage' },
    ],
    action: [{ value: 'all', label: 'All Actions' }],
    stage: [{ value: 'all', label: 'All Stages' }],
});
const summary = ref<FollowUpSummary>({
    total: 0,
    due_today: 0,
    overdue: 0,
    upcoming: 0,
});
const isLoading = ref(false);
const search = ref('');
const toast = ref<Toast | null>(null);
let searchTimer: number | undefined;

const groupedFollowUps = computed(() => groups.value);

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function dueLabel(date: string | null): string {
    if (!date) {
        return 'Not set';
    }

    return new Date(date.replace(' ', 'T')).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function groupTone(group: FollowUpGroup): 'danger' | 'warning' | 'neutral' {
    if (group.overdue_count > 0 || group.key === 'overdue') {
        return 'danger';
    }

    if (group.due_today_count > 0 || group.key === 'due_today' || group.key === 'due_next_7_days') {
        return 'warning';
    }

    return 'neutral';
}

function resetFilters(): void {
    groupBy.value = 'action';
    actionFilter.value = 'all';
    stageFilter.value = 'all';
    search.value = '';
}

async function loadFollowUps(): Promise<void> {
    isLoading.value = true;

    try {
        const params = new URLSearchParams({
            group_by: groupBy.value,
            action: actionFilter.value,
            stage: stageFilter.value,
        });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        const payload = await requestJson<{
            summary: FollowUpSummary;
            group_by: string;
            filters: FollowUpFilters;
            filter_options: FollowUpFilterOptions;
            groups: FollowUpGroup[];
            data: FollowUpItem[];
        }>(`/api/follow-up?${params.toString()}`);
        summary.value = payload.summary;
        groupBy.value = payload.group_by;
        actionFilter.value = payload.filters.action;
        stageFilter.value = payload.filters.stage;
        filterOptions.value = payload.filter_options;
        groups.value = payload.groups;
        items.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load grouped follow-up items.');
    } finally {
        isLoading.value = false;
    }
}

watch([groupBy, actionFilter, stageFilter], () => {
    loadFollowUps();
});

watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        loadFollowUps();
    }, 320);
});

onMounted(loadFollowUps);
</script>

<template>
    <section class="page-scaffold follow-up-page">
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
                <h1>Grouped Tracking</h1>
            </div>

            <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadFollowUps">
                <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                Refresh
            </button>
        </div>

        <div class="module-stats follow-up-stats">
            <article class="module-stat">
                <span>Total Items</span>
                <strong>{{ summary.total }}</strong>
            </article>
            <article class="module-stat">
                <span>Due Today</span>
                <strong>{{ summary.due_today }}</strong>
            </article>
            <article class="module-stat danger-stat">
                <span>Overdue</span>
                <strong>{{ summary.overdue }}</strong>
            </article>
            <article class="module-stat">
                <span>Upcoming</span>
                <strong>{{ summary.upcoming }}</strong>
            </article>
        </div>

        <section class="follow-up-control-panel" aria-label="Follow-up grouping and filters">
            <label>
                <span>Group by</span>
                <select v-model="groupBy">
                    <option v-for="option in filterOptions.group_by" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label>
                <span>Action Filter</span>
                <select v-model="actionFilter">
                    <option v-for="option in filterOptions.action" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label>
                <span>Stage</span>
                <select v-model="stageFilter">
                    <option v-for="option in filterOptions.stage" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <label class="follow-up-search-control">
                <span>Search</span>
                <span class="mini-search">
                    <Search :size="16" aria-hidden="true" />
                    <input v-model="search" type="search" placeholder="Buyer, PO, product..." />
                </span>
            </label>
            <button class="secondary-action compact-action" type="button" @click="resetFilters">Reset</button>
        </section>

        <section class="table-panel module-table follow-up-group-panel" aria-labelledby="follow-up-groups-title">
            <div class="panel-title">
                <div>
                    <h2 id="follow-up-groups-title">Grouped Follow-Up Board</h2>
                    <p>Grouped views keep large product lists readable while urgent reminders stay visible.</p>
                </div>
            </div>

            <div v-if="isLoading" class="crud-empty">
                <Loader2 :size="20" aria-hidden="true" />
                Loading grouped follow-up board...
            </div>

            <div v-else-if="groupedFollowUps.length === 0" class="crud-empty">No grouped follow-up items found.</div>

            <div v-else class="follow-up-group-grid">
                <article v-for="group in groupedFollowUps" :key="group.key" class="follow-up-group-card" :class="groupTone(group)">
                    <header>
                        <div>
                            <h3>{{ group.label }}</h3>
                            <p>{{ group.count }} item{{ group.count === 1 ? '' : 's' }}</p>
                        </div>
                        <span class="follow-up-group-count">{{ group.count }}</span>
                    </header>

                    <div class="follow-up-group-meta">
                        <span v-if="group.overdue_count > 0" class="follow-up-due danger">
                            <Bell :size="14" aria-hidden="true" />
                            {{ group.overdue_count }} overdue
                        </span>
                        <span v-if="group.due_today_count > 0" class="follow-up-due warning">
                            <Clock3 :size="14" aria-hidden="true" />
                            {{ group.due_today_count }} due today
                        </span>
                        <span class="follow-up-due neutral">
                            <Clock3 :size="14" aria-hidden="true" />
                            Oldest: {{ dueLabel(group.oldest_next_follow_up_at) }}
                        </span>
                    </div>

                    <div class="follow-up-group-items">
                        <button v-for="item in group.items" :key="item.id" type="button" class="follow-up-group-item" @click="router.push(`/follow-up/${item.id}`)">
                            <strong>{{ item.title ?? item.product_name ?? item.supplier_po_reference ?? 'Follow-up item' }}</strong>
                            <span>{{ item.buyer_company_name ?? '-' }} - {{ item.supplier_po_reference ?? '-' }}</span>
                            <small>
                                {{ item.current_stage_label }} - {{ dueLabel(item.next_follow_up_at) }}
                            </small>
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <section class="table-panel module-table follow-up-list" aria-labelledby="follow-up-group-list-title">
            <div class="panel-title">
                <div>
                    <h2 id="follow-up-group-list-title">Filtered Products</h2>
                    <p>The table remains available after grouping for direct lookup and quick navigation.</p>
                </div>
            </div>

            <div class="module-records">
                <div class="module-record head follow-up-row">
                    <span>Supplier PO</span>
                    <span>Quotation</span>
                    <span>Buyer PO</span>
                    <span>Buyer</span>
                    <span>Supplier</span>
                    <span>Product</span>
                    <span>Stage</span>
                    <span>Next Follow-Up</span>
                    <span>Action</span>
                </div>

                <div v-if="isLoading" class="crud-empty">
                    <Loader2 :size="20" aria-hidden="true" />
                    Loading follow-up items...
                </div>

                <div v-else-if="items.length === 0" class="crud-empty">No follow-up items found.</div>

                <div v-for="item in items" v-else :key="item.id" class="module-record follow-up-row">
                    <strong class="job-ref">{{ item.supplier_po_reference ?? '-' }}</strong>
                    <span>{{ item.quotation_reference ?? '-' }}</span>
                    <span>{{ item.buyer_po_number ?? '-' }}</span>
                    <span>{{ item.buyer_company_name ?? '-' }}</span>
                    <span>{{ item.supplier_company_name ?? '-' }}</span>
                    <span>{{ item.title ?? '-' }}</span>
                    <span class="stage-pill" :class="item.status === 'closed' ? 'teal' : 'amber'">
                        {{ item.current_stage_label }}
                    </span>
                    <span class="follow-up-due neutral">{{ dueLabel(item.next_follow_up_at) }}</span>
                    <button class="table-link-button follow-up-view-button" type="button" @click="router.push(`/follow-up/${item.id}`)">
                        <Eye :size="15" aria-hidden="true" />
                        View
                    </button>
                </div>
            </div>
        </section>
    </section>
</template>
