<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { AlertTriangle, CheckCircle2, Loader2, Plus, RefreshCcw, Search } from 'lucide-vue-next';
import { requestJson } from '../auth';
import { humanizeStatus } from '../utils/format';

interface QuotationRecord {
    id: number;
    quotation_reference: string;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    rfq_number: string | null;
    pr_number: string | null;
    closing_at: string | null;
    accepted_invoice_currency: string;
    incoterm_code: string | null;
    items_count: number;
    items_sum_total_price: string | null;
    status: string;
}

type Toast = { type: 'error' | 'success'; message: string };

const router = useRouter();
const quotations = ref<QuotationRecord[]>([]);
const isLoading = ref(false);
const search = ref('');
const toast = ref<Toast | null>(null);

const filteredQuotations = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return quotations.value;
    }

    return quotations.value.filter((quotation) =>
        [
            quotation.quotation_reference,
            quotation.buyer_company_name,
            quotation.buyer_contact_name,
            quotation.rfq_number,
            quotation.pr_number,
            quotation.incoterm_code,
        ]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(term)),
    );
});

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

async function loadQuotations(): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: QuotationRecord[] }>('/api/quotations');
        quotations.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotations.');
    } finally {
        isLoading.value = false;
    }
}

onMounted(loadQuotations);
</script>

<template>
    <section class="page-scaffold quotation-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>Sales</p>
                <h1>Quotations</h1>
            </div>

            <div class="quotation-title-actions">
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadQuotations">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
                <button class="primary-action compact-action" type="button" @click="router.push('/quotations/create')">
                    <Plus :size="17" aria-hidden="true" />
                    New Quotation
                </button>
            </div>
        </div>

        <div class="module-stats">
            <article class="module-stat">
                <span>Total Quotations</span>
                <strong>{{ quotations.length }}</strong>
            </article>
            <article class="module-stat">
                <span>Draft Quotations</span>
                <strong>{{ quotations.filter((quotation) => quotation.status === 'draft').length }}</strong>
            </article>
        </div>

        <section class="table-panel module-table quotation-list" aria-labelledby="quotation-list-title">
            <div class="panel-title">
                <h2 id="quotation-list-title">Current Quotations</h2>
                <label class="mini-search">
                    <Search :size="16" aria-hidden="true" />
                    <input v-model="search" type="search" placeholder="Search" />
                </label>
            </div>

            <div class="module-records">
                <div class="module-record head quotation-row">
                    <span>Quotation Ref</span>
                    <span>Buyer</span>
                    <span>Contact</span>
                    <span>RFQ / PR</span>
                    <span>Closing</span>
                    <span>Items</span>
                    <span>Status</span>
                    <span>Action</span>
                </div>

                <div v-if="isLoading" class="crud-empty">
                    <Loader2 :size="20" aria-hidden="true" />
                    Loading quotations...
                </div>

                <div v-else-if="filteredQuotations.length === 0" class="crud-empty">No quotations found.</div>

                <div v-for="quotation in filteredQuotations" v-else :key="quotation.id" class="module-record quotation-row">
                    <strong class="job-ref">{{ quotation.quotation_reference }}</strong>
                    <span>{{ quotation.buyer_company_name ?? '-' }}</span>
                    <span>{{ quotation.buyer_contact_name ?? '-' }}</span>
                    <span>{{ quotation.rfq_number ?? '-' }} / {{ quotation.pr_number ?? '-' }}</span>
                    <span>{{ quotation.closing_at ?? '-' }}</span>
                    <span>{{ quotation.items_count }} items</span>
                    <span class="stage-pill teal">{{ humanizeStatus(quotation.status) }}</span>
                    <button class="table-link-button" type="button" @click="router.push(`/quotations/${quotation.id}`)">View</button>
                </div>
            </div>
        </section>
    </section>
</template>
