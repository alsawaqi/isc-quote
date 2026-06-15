<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { AlertTriangle, CheckCircle2, Eye, Loader2, RefreshCcw } from 'lucide-vue-next';
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
    buyer_company_name: string | null;
    title: string | null;
    current_stage_label: string;
}

interface FollowUpGroup {
    key: string;
    label: string;
    count: number;
    overdue_count: number;
    due_today_count: number;
    items: FollowUpItem[];
}

type Toast = { type: 'error' | 'success'; message: string };

const router = useRouter();
const summary = ref<FollowUpSummary>({
    total: 0,
    due_today: 0,
    overdue: 0,
    upcoming: 0,
});
const actionGroups = ref<FollowUpGroup[]>([]);
const stageGroups = ref<FollowUpGroup[]>([]);
const isLoading = ref(false);
const toast = ref<Toast | null>(null);

const maxActionCount = computed(() => Math.max(1, ...actionGroups.value.map((group) => group.count)));
const maxStageCount = computed(() => Math.max(1, ...stageGroups.value.map((group) => group.count)));

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function percent(value: number, max: number): string {
    return `${Math.max(8, Math.round((value / max) * 100))}%`;
}

async function loadVisualization(): Promise<void> {
    isLoading.value = true;

    try {
        const [actionPayload, stagePayload] = await Promise.all([
            requestJson<{ summary: FollowUpSummary; groups: FollowUpGroup[] }>('/api/follow-up?group_by=action'),
            requestJson<{ groups: FollowUpGroup[] }>('/api/follow-up?group_by=stage'),
        ]);

        summary.value = actionPayload.summary;
        actionGroups.value = actionPayload.groups;
        stageGroups.value = stagePayload.groups;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load follow-up visualization.');
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadVisualization);
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
                <h1>Visualization</h1>
            </div>

            <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadVisualization">
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

        <div v-if="isLoading" class="crud-empty visualization-loading">
            <Loader2 :size="20" aria-hidden="true" />
            Loading visualization...
        </div>

        <section v-else class="follow-up-visual-grid" aria-label="Follow-up visualization">
            <article class="table-panel module-table follow-up-visual-card">
                <div class="panel-title">
                    <div>
                        <h2>Required Action</h2>
                        <p>Shows where the team needs to focus first.</p>
                    </div>
                </div>

                <div class="visual-bar-list">
                    <button v-for="group in actionGroups" :key="group.key" type="button" class="visual-bar-row" @click="router.push('/follow-up/groups')">
                        <span>
                            <strong>{{ group.label }}</strong>
                            <small>{{ group.count }} item{{ group.count === 1 ? '' : 's' }}</small>
                        </span>
                        <i>
                            <b :style="{ width: percent(group.count, maxActionCount) }"></b>
                        </i>
                        <em>{{ group.overdue_count }} overdue / {{ group.due_today_count }} due today</em>
                    </button>
                </div>
            </article>

            <article class="table-panel module-table follow-up-visual-card">
                <div class="panel-title">
                    <div>
                        <h2>Workflow Stage</h2>
                        <p>Tracks how many products sit in each follow-up stage.</p>
                    </div>
                </div>

                <div class="visual-bar-list">
                    <button v-for="group in stageGroups" :key="group.key" type="button" class="visual-bar-row" @click="router.push('/follow-up/groups')">
                        <span>
                            <strong>{{ group.label }}</strong>
                            <small>{{ group.count }} item{{ group.count === 1 ? '' : 's' }}</small>
                        </span>
                        <i>
                            <b :style="{ width: percent(group.count, maxStageCount) }"></b>
                        </i>
                        <em>{{ group.overdue_count }} overdue / {{ group.due_today_count }} due today</em>
                    </button>
                </div>
            </article>
        </section>

        <section class="table-panel module-table follow-up-visual-card" aria-labelledby="visual-sample-title">
            <div class="panel-title">
                <div>
                    <h2 id="visual-sample-title">Urgent Sample</h2>
                    <p>Quick entry points from the highest-priority groups.</p>
                </div>
            </div>

            <div class="visual-sample-grid">
                <button
                    v-for="item in actionGroups.flatMap((group) => group.items).slice(0, 8)"
                    :key="item.id"
                    type="button"
                    class="follow-up-group-item"
                    @click="router.push(`/follow-up/${item.id}`)"
                >
                    <strong>{{ item.title ?? item.supplier_po_reference ?? 'Follow-up item' }}</strong>
                    <span>{{ item.buyer_company_name ?? '-' }} - {{ item.supplier_po_reference ?? '-' }}</span>
                    <small>
                        <Eye :size="13" aria-hidden="true" />
                        {{ item.current_stage_label }}
                    </small>
                </button>
            </div>
        </section>
    </section>
</template>
