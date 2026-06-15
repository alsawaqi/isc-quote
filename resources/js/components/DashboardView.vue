<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
    AlertTriangle,
    Bell,
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock3,
    ClipboardList,
    FileText,
    Info,
    Loader2,
    MoreVertical,
    ReceiptText,
    RefreshCw,
    ShoppingCart,
    TrendingUp,
    Truck,
} from 'lucide-vue-next';
import { requestJson } from '../auth';
import type { AlertItem, JobRow, Metric, RoleSlug, WorkflowStage } from '../types';

const props = defineProps<{
    role: RoleSlug;
    activeSection: string;
    userName: string;
}>();

interface DashboardPayload {
    generatedAt: string | null;
    metrics: Metric[];
    workflowStages: WorkflowStage[];
    recentJobs: JobRow[];
    alerts: AlertItem[];
}

const emptyDashboard: DashboardPayload = {
    generatedAt: null,
    metrics: [],
    workflowStages: [],
    recentJobs: [],
    alerts: [],
};

const dashboard = ref<DashboardPayload>({ ...emptyDashboard });
const isLoading = ref(false);
const dashboardError = ref('');

const iconMap = {
    Bell,
    Clock3,
    ClipboardList,
    FileText,
    ReceiptText,
    ShoppingCart,
    Truck,
};

const roleTitle = computed(() => {
    if (props.role === 'salesperson') {
        return 'Salesperson Dashboard';
    }

    if (props.role === 'follow-up') {
        return 'Follow-Up Dashboard';
    }

    return 'Operations Dashboard';
});

const firstName = computed(() => props.userName.split(' ')[0] ?? 'there');

const sectionSummary = computed(() => {
    if (props.activeSection === 'Quotations') {
        return 'Create and revise quotations, then hand accepted versions into buyer PO processing.';
    }

    if (props.activeSection === 'Follow-Up') {
        return 'Track acknowledgements, delivery dates, reminders, shipping documents, and dispatch work.';
    }

    if (props.activeSection !== 'Dashboard') {
        return `${props.activeSection} master data management.`;
    }

    return `Welcome back, ${firstName.value}. Here's what's happening with your operations.`;
});

const visibleMetrics = computed(() => {
    if (props.role === 'salesperson') {
        return dashboard.value.metrics.filter((metric) => ['Pending Quotations', 'Buyer POs Pending'].includes(metric.label));
    }

    if (props.role === 'follow-up') {
        return dashboard.value.metrics.filter((metric) => ['Supplier POs Awaiting Ack', 'Follow-Ups Due'].includes(metric.label));
    }

    return dashboard.value.metrics;
});

const generatedDate = computed(() => {
    if (!dashboard.value.generatedAt) {
        return '-';
    }

    return new Date(dashboard.value.generatedAt.replace(' ', 'T')).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
});

const generatedTime = computed(() => {
    if (!dashboard.value.generatedAt) {
        return '-';
    }

    return new Date(dashboard.value.generatedAt.replace(' ', 'T')).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
});

function dashboardIcon(icon: string) {
    return iconMap[icon as keyof typeof iconMap] ?? FileText;
}

async function loadDashboard(): Promise<void> {
    isLoading.value = true;
    dashboardError.value = '';

    try {
        dashboard.value = await requestJson<DashboardPayload>('/api/dashboard');
    } catch (error) {
        dashboardError.value = error instanceof Error ? error.message : 'Unable to load dashboard.';
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadDashboard);
</script>

<template>
    <section class="dashboard-view">
        <div class="dashboard-titlebar">
            <div class="page-title">
                <h1>{{ roleTitle }}</h1>
                <p>{{ sectionSummary }}</p>
            </div>

            <div class="date-control">
                <CalendarDays :size="16" aria-hidden="true" />
                <span>{{ generatedDate }}</span>
                <span>{{ generatedTime }}</span>
                <button type="button" aria-label="Refresh dashboard" :disabled="isLoading" @click="loadDashboard">
                    <RefreshCw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                </button>
            </div>
        </div>

        <div v-if="dashboardError" class="dashboard-error" role="alert">
            <AlertTriangle :size="18" aria-hidden="true" />
            <span>{{ dashboardError }}</span>
        </div>

        <div class="metric-grid">
            <article v-if="isLoading && visibleMetrics.length === 0" class="metric-card loading-card">
                <Loader2 :size="24" aria-hidden="true" />
                <strong>Loading dashboard...</strong>
            </article>
            <article v-for="metric in visibleMetrics" :key="metric.label" class="metric-card" :class="metric.tone">
                <div class="metric-icon">
                    <component :is="dashboardIcon(metric.icon)" :size="30" aria-hidden="true" />
                </div>
                <div class="metric-body">
                    <span>
                        {{ metric.label }}
                        <Info :size="14" aria-hidden="true" />
                    </span>
                    <strong>{{ metric.value }}</strong>
                    <p>
                        <TrendingUp :size="14" aria-hidden="true" />
                        <b>{{ metric.change }}</b>
                        {{ metric.note }}
                    </p>
                </div>
                <ChevronRight :size="19" class="metric-chevron" aria-hidden="true" />
            </article>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-main">
                <section class="workflow-panel" aria-label="Quotation to procurement workflow">
                    <div class="panel-title compact">
                        <h2>Quotation to Procurement Workflow</h2>
                    </div>

                    <div v-if="isLoading && dashboard.workflowStages.length === 0" class="crud-empty">
                        <Loader2 :size="20" aria-hidden="true" />
                        Loading workflow...
                    </div>
                    <div v-else class="workflow-lane">
                        <div
                            v-for="(stage, index) in dashboard.workflowStages"
                            :key="stage.label"
                            class="workflow-node"
                            :class="{ dashed: stage.dashed }"
                        >
                            <div class="workflow-top">
                                <span class="workflow-bubble" :class="stage.tone">
                                    <component :is="dashboardIcon(stage.icon)" :size="23" aria-hidden="true" />
                                </span>
                                <i v-if="index < dashboard.workflowStages.length - 1" class="workflow-connector" :class="{ dashed: dashboard.workflowStages[index + 1]?.dashed }"></i>
                            </div>
                            <div class="workflow-copy">
                                <span>{{ stage.label }}</span>
                                <strong :class="stage.tone">{{ stage.value }}</strong>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="table-panel" aria-labelledby="recent-jobs-title">
                    <div class="panel-title">
                        <h2 id="recent-jobs-title">Recent Jobs</h2>
                        <button type="button">
                            View all jobs
                            <ChevronRight :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="job-table">
                        <div class="job-row head">
                            <span>Job Ref</span>
                            <span>Buyer</span>
                            <span>Supplier</span>
                            <span>Stage</span>
                            <span>Owner</span>
                            <span>Due</span>
                            <span></span>
                        </div>

                        <div v-if="isLoading && dashboard.recentJobs.length === 0" class="crud-empty">
                            <Loader2 :size="20" aria-hidden="true" />
                            Loading recent jobs...
                        </div>
                        <div v-else-if="dashboard.recentJobs.length === 0" class="crud-empty">No live jobs found.</div>
                        <div v-for="job in dashboard.recentJobs" v-else :key="job.jobRef" class="job-row">
                            <strong class="job-ref">{{ job.jobRef }}</strong>
                            <span>{{ job.buyer }}</span>
                            <span>{{ job.supplier }}</span>
                            <span>
                                <mark class="stage-pill" :class="job.stageTone">{{ job.stage }}</mark>
                            </span>
                            <span class="owner-cell">
                                <i>{{ job.ownerInitials }}</i>
                                {{ job.owner }}
                            </span>
                            <span class="due-cell" :class="job.dueTone">{{ job.due }}</span>
                            <button class="row-menu" type="button" aria-label="Job actions">
                                <MoreVertical :size="17" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <footer class="table-footer">
                        <span>Showing {{ dashboard.recentJobs.length === 0 ? 0 : 1 }} to {{ dashboard.recentJobs.length }} of {{ dashboard.recentJobs.length }} jobs</span>
                        <div class="pager">
                            <button type="button" aria-label="Previous page" disabled>
                                <ChevronLeft :size="16" aria-hidden="true" />
                            </button>
                            <button class="active" type="button">1</button>
                            <button type="button" aria-label="Next page" disabled>
                                <ChevronRight :size="16" aria-hidden="true" />
                            </button>
                            <button class="page-size" type="button">
                                10 / page
                                <ChevronDown :size="15" aria-hidden="true" />
                            </button>
                        </div>
                    </footer>
                </section>
            </div>

            <aside class="alert-panel" aria-labelledby="alerts-title">
                <div class="panel-title alerts-title">
                    <h2 id="alerts-title">Follow-Up Alerts</h2>
                    <button type="button">
                        View all
                        <ChevronRight :size="16" aria-hidden="true" />
                    </button>
                </div>

                <div class="alert-list">
                    <div v-if="isLoading && dashboard.alerts.length === 0" class="crud-empty">
                        <Loader2 :size="20" aria-hidden="true" />
                        Loading alerts...
                    </div>
                    <div v-else-if="dashboard.alerts.length === 0" class="crud-empty">No due follow-up alerts.</div>
                    <article v-for="alert in dashboard.alerts" v-else :key="alert.jobRef" class="alert-item" :class="alert.tone">
                        <span class="alert-icon">
                            <component :is="dashboardIcon(alert.icon)" :size="25" aria-hidden="true" />
                        </span>
                        <div class="alert-body">
                            <mark>{{ alert.title }}</mark>
                            <strong>{{ alert.jobRef }}</strong>
                            <p>{{ alert.detail }}</p>
                        </div>
                        <div class="alert-due">
                            <span>{{ alert.dueLabel }}</span>
                            <strong>{{ alert.dueValue }}</strong>
                        </div>
                    </article>
                </div>

                <button class="view-alerts-button" type="button">View all alerts</button>
            </aside>
        </div>
    </section>
</template>
