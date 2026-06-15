<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
    AlertTriangle,
    Bell,
    CheckCircle2,
    Clock3,
    Eye,
    FileText,
    LayoutDashboard,
    Loader2,
    RefreshCcw,
} from 'lucide-vue-next';
import { requestJson } from '../auth';

interface FollowUpSummary {
    total: number;
    awaiting_acknowledgement: number;
    acknowledged: number;
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
    title: string | null;
    status: string;
    current_stage_label: string;
    next_follow_up_at: string | null;
    due_state?: 'overdue' | 'due_today' | 'due_next_7_days';
    days_overdue?: number;
    latest_comment: { comment: string; stage_label: string; created_at: string | null } | null;
}

type Toast = { type: 'error' | 'success'; message: string };

const router = useRouter();
const items = ref<FollowUpItem[]>([]);
const dueReminders = ref<FollowUpItem[]>([]);
const summary = ref<FollowUpSummary>({
    total: 0,
    awaiting_acknowledgement: 0,
    acknowledged: 0,
    due_today: 0,
    overdue: 0,
    upcoming: 0,
});
const isLoading = ref(false);
const toast = ref<Toast | null>(null);

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

function dueClass(date: string | null): string {
    if (!date) {
        return 'neutral';
    }

    const today = new Date();
    const due = new Date(date.replace(' ', 'T'));
    today.setHours(0, 0, 0, 0);
    due.setHours(0, 0, 0, 0);

    if (due < today) {
        return 'danger';
    }

    if (due.getTime() === today.getTime()) {
        return 'warning';
    }

    return 'neutral';
}

function dueStateLabel(item: FollowUpItem): string {
    if (item.due_state === 'overdue') {
        return item.days_overdue && item.days_overdue > 0 ? `${item.days_overdue} days overdue` : 'Overdue';
    }

    if (item.due_state === 'due_today') {
        return 'Due today';
    }

    return dueLabel(item.next_follow_up_at);
}

async function loadFollowUps(): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{
            summary: FollowUpSummary;
            due_reminders: FollowUpItem[];
            data: FollowUpItem[];
        }>('/api/follow-up?group_by=action');
        summary.value = payload.summary;
        dueReminders.value = payload.due_reminders;
        items.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load follow-up items.');
    } finally {
        isLoading.value = false;
    }
}

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
                <p>Operations</p>
                <h1>Follow-Up</h1>
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
                <span>Awaiting Acknowledgement</span>
                <strong>{{ summary.awaiting_acknowledgement }}</strong>
            </article>
            <article class="module-stat">
                <span>Due Today</span>
                <strong>{{ summary.due_today }}</strong>
            </article>
            <article class="module-stat danger-stat">
                <span>Overdue</span>
                <strong>{{ summary.overdue }}</strong>
            </article>
        </div>

        <section class="follow-up-workspace-grid" aria-label="Follow-up workspaces">
            <button class="workspace-action-card active" type="button" @click="router.push('/follow-up/groups')">
                <span class="workspace-action-icon">
                    <LayoutDashboard :size="22" aria-hidden="true" />
                </span>
                <span>
                    <strong>Follow-Up Groups</strong>
                    <small>Group products by action, buyer, supplier PO, manufacturer, or stage.</small>
                </span>
            </button>
            <button class="workspace-action-card" type="button" @click="router.push('/follow-up/visualization')">
                <span class="workspace-action-icon amber">
                    <FileText :size="22" aria-hidden="true" />
                </span>
                <span>
                    <strong>Visualization</strong>
                    <small>See the tracking load by action and workflow stage.</small>
                </span>
            </button>
        </section>

        <section class="table-panel module-table follow-up-reminders-panel" aria-labelledby="follow-up-reminders-title">
            <div class="panel-title">
                <div>
                    <h2 id="follow-up-reminders-title">Follow-Up Reminders</h2>
                    <p>Due and overdue products that need a progress comment after the next communication.</p>
                </div>
            </div>

            <div v-if="isLoading" class="crud-empty">
                <Loader2 :size="20" aria-hidden="true" />
                Loading reminder queue...
            </div>

            <div v-else-if="dueReminders.length === 0" class="crud-empty">No due follow-ups right now.</div>

            <div v-else class="follow-up-reminder-grid">
                <article v-for="reminder in dueReminders" :key="reminder.id" class="follow-up-reminder-card" :class="reminder.due_state">
                    <div class="follow-up-reminder-top">
                        <span class="follow-up-due" :class="reminder.due_state === 'overdue' ? 'danger' : 'warning'">
                            <Bell v-if="reminder.due_state === 'overdue'" :size="14" aria-hidden="true" />
                            <Clock3 v-else :size="14" aria-hidden="true" />
                            {{ dueStateLabel(reminder) }}
                        </span>
                        <span class="stage-pill" :class="reminder.due_state === 'overdue' ? 'amber' : 'teal'">
                            {{ reminder.current_stage_label }}
                        </span>
                    </div>
                    <strong>{{ reminder.title ?? reminder.supplier_po_reference ?? 'Follow-up item' }}</strong>
                    <p>{{ reminder.buyer_company_name ?? '-' }} - {{ reminder.supplier_company_name ?? '-' }}</p>
                    <small v-if="reminder.latest_comment">
                        Last {{ reminder.latest_comment.stage_label }}: {{ reminder.latest_comment.comment }}
                    </small>
                    <small v-else>No progress comment recorded yet.</small>
                    <button class="table-link-button follow-up-view-button" type="button" @click="router.push(`/follow-up/${reminder.id}`)">
                        <Eye :size="15" aria-hidden="true" />
                        Follow Up
                    </button>
                </article>
            </div>
        </section>

        <section class="table-panel module-table follow-up-list" aria-labelledby="follow-up-list-title">
            <div class="panel-title">
                <div>
                    <h2 id="follow-up-list-title">Live Follow-Up Queue</h2>
                    <p>Each row is one product linked back to its quotation, buyer PO, supplier PO, and tracking stage.</p>
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
                    <span class="follow-up-due" :class="dueClass(item.next_follow_up_at)">
                        <Bell v-if="dueClass(item.next_follow_up_at) === 'danger'" :size="14" aria-hidden="true" />
                        <Clock3 v-else :size="14" aria-hidden="true" />
                        {{ dueLabel(item.next_follow_up_at) }}
                    </span>
                    <button class="table-link-button follow-up-view-button" type="button" @click="router.push(`/follow-up/${item.id}`)">
                        <Eye :size="15" aria-hidden="true" />
                        View
                    </button>
                </div>
            </div>
        </section>
    </section>
</template>
