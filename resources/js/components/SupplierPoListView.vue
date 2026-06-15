<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    Pencil,
    Loader2,
    Plus,
    RefreshCcw,
    Search,
} from 'lucide-vue-next';
import { downloadProtectedFile, requestJson } from '../auth';
import { humanizeStatus } from '../utils/format';

interface SupplierPoRecord {
    id: number;
    po_reference: string;
    supplier_company_name: string | null;
    supplier_contact_name: string | null;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    incoterm_code: string | null;
    accepted_invoice_currency: string;
    lines_count: number;
    total_amount: string;
    status: string;
    created_by_name: string | null;
    finalized_at: string | null;
    downloads: {
        docx: string;
        pdf: string;
    };
}

type Toast = { type: 'error' | 'success'; message: string };

const router = useRouter();
const supplierPos = ref<SupplierPoRecord[]>([]);
const isLoading = ref(false);
const search = ref('');
const toast = ref<Toast | null>(null);

const filteredSupplierPos = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return supplierPos.value;
    }

    return supplierPos.value.filter((supplierPo) =>
        [
            supplierPo.po_reference,
            supplierPo.supplier_company_name,
            supplierPo.supplier_contact_name,
            supplierPo.buyer_company_name,
            supplierPo.buyer_contact_name,
            supplierPo.incoterm_code,
            supplierPo.status,
        ]
            .filter(Boolean)
            .some((value) => String(value).toLowerCase().includes(term)),
    );
});

const issuedCount = computed(() => supplierPos.value.filter((supplierPo) => supplierPo.status === 'issued').length);
const totalValue = computed(() => supplierPos.value.reduce((sum, supplierPo) => sum + Number(supplierPo.total_amount), 0));

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function money(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

async function loadSupplierPos(): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: SupplierPoRecord[] }>('/api/supplier-pos');
        supplierPos.value = payload.data;
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load supplier POs.');
    } finally {
        isLoading.value = false;
    }
}

async function downloadDocument(supplierPo: SupplierPoRecord, format: 'docx' | 'pdf'): Promise<void> {
    try {
        await downloadProtectedFile(supplierPo.downloads[format], `${supplierPo.po_reference}.${format}`);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download supplier PO.');
    }
}

onMounted(loadSupplierPos);
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
                <p>Procurement</p>
                <h1>Supplier POs</h1>
            </div>

            <div class="quotation-title-actions">
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadSupplierPos">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
                <button class="primary-action compact-action" type="button" @click="router.push('/supplier-pos/create')">
                    <Plus :size="17" aria-hidden="true" />
                    New Supplier PO
                </button>
            </div>
        </div>

        <div class="module-stats">
            <article class="module-stat">
                <span>Total Supplier POs</span>
                <strong>{{ supplierPos.length }}</strong>
            </article>
            <article class="module-stat">
                <span>Issued Supplier POs</span>
                <strong>{{ issuedCount }}</strong>
            </article>
            <article class="module-stat">
                <span>Total Value</span>
                <strong>{{ money(totalValue) }}</strong>
            </article>
        </div>

        <section class="table-panel module-table quotation-list supplier-po-list" aria-labelledby="supplier-po-list-title">
            <div class="panel-title">
                <h2 id="supplier-po-list-title">Current Supplier POs</h2>
                <label class="mini-search">
                    <Search :size="16" aria-hidden="true" />
                    <input v-model="search" type="search" placeholder="Search" />
                </label>
            </div>

            <div class="module-records">
                <div class="module-record head supplier-po-row">
                    <span>PO Ref</span>
                    <span>Supplier</span>
                    <span>Contact</span>
                    <span>Buyer</span>
                    <span>Incoterm</span>
                    <span>Items</span>
                    <span>Total</span>
                    <span>Status</span>
                    <span>Downloads</span>
                    <span>Action</span>
                </div>

                <div v-if="isLoading" class="crud-empty">
                    <Loader2 :size="20" aria-hidden="true" />
                    Loading supplier POs...
                </div>

                <div v-else-if="filteredSupplierPos.length === 0" class="crud-empty">No supplier POs found.</div>

                <div v-for="supplierPo in filteredSupplierPos" v-else :key="supplierPo.id" class="module-record supplier-po-row">
                    <strong class="job-ref">{{ supplierPo.po_reference }}</strong>
                    <span>{{ supplierPo.supplier_company_name ?? '-' }}</span>
                    <span>{{ supplierPo.supplier_contact_name ?? '-' }}</span>
                    <span>{{ supplierPo.buyer_company_name ?? '-' }}</span>
                    <span>{{ supplierPo.incoterm_code ?? '-' }}</span>
                    <span>{{ supplierPo.lines_count }} items</span>
                    <span>{{ supplierPo.accepted_invoice_currency }} {{ supplierPo.total_amount }}</span>
                    <span class="stage-pill teal">{{ humanizeStatus(supplierPo.status) }}</span>
                    <span class="download-actions compact-downloads">
                        <button class="table-link-button" type="button" @click="downloadDocument(supplierPo, 'docx')">
                            <Download :size="15" aria-hidden="true" />
                            Word
                        </button>
                        <button class="table-link-button" type="button" @click="downloadDocument(supplierPo, 'pdf')">
                            <Download :size="15" aria-hidden="true" />
                            PDF
                        </button>
                    </span>
                    <button class="table-link-button" type="button" @click="router.push(`/supplier-pos/${supplierPo.id}/edit`)">
                        <Pencil :size="15" aria-hidden="true" />
                        Edit
                    </button>
                </div>
            </div>
        </section>
    </section>
</template>
