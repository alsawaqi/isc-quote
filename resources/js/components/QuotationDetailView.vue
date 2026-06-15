<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Download,
    FileCheck2,
    FileText,
    Loader2,
    Pencil,
    RefreshCcw,
    Save,
    ShoppingCart,
    Upload,
} from 'lucide-vue-next';
import { downloadProtectedFile, requestFormData, requestJson } from '../auth';
import { humanizeStatus } from '../utils/format';

interface QuotationItem {
    id: number;
    line_number: number;
    manufacturer_name: string | null;
    title: string;
    quantity: string;
    uom: string;
    unit_price: string;
    total_price: string;
}

interface QuotationTerm {
    id: number;
    title: string;
    description: string;
}

interface QuotationVersion {
    id: number;
    version_number: number;
    quotation_reference: string;
    created_by_name: string | null;
    finalized_at: string | null;
    downloads: {
        docx: string;
        pdf: string;
    };
}

interface BuyerPo {
    id: number;
    quotation_version_number: number;
    po_number: string;
    po_date: string;
    po_value: string;
    currency: string;
    original_file_name: string | null;
    status: string;
    created_by_name: string | null;
    created_at: string | null;
}

interface ActivityLog {
    id: number;
    action: string;
    summary: string;
    created_at: string | null;
    user_name: string | null;
}

interface QuotationDetail {
    id: number;
    quotation_reference: string;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    supplier_company_name: string | null;
    supplier_contact_name: string | null;
    rfq_number: string | null;
    pr_number: string | null;
    closing_at: string | null;
    accepted_invoice_currency: string;
    incoterm_code: string | null;
    status: string;
    items: QuotationItem[];
    terms: QuotationTerm[];
    versions: QuotationVersion[];
    buyer_po: BuyerPo | null;
    activity_logs: ActivityLog[];
}

type Toast = { type: 'error' | 'success'; message: string };

const route = useRoute();
const router = useRouter();
const quotation = ref<QuotationDetail | null>(null);
const activeWorkspaceTab = ref<'quotation' | 'buyer-po'>('quotation');
const isLoading = ref(false);
const isSavingBuyerPo = ref(false);
const buyerPoFile = ref<File | null>(null);
const toast = ref<Toast | null>(null);

const buyerPoForm = reactive({
    po_number: '',
    po_date: '',
    po_value: '',
});

const quotationId = computed(() => Number(route.params.id));
const latestVersion = computed(() => {
    return [...(quotation.value?.versions ?? [])].sort((a, b) => b.version_number - a.version_number)[0] ?? null;
});
const subtotal = computed(() => {
    return quotation.value?.items.reduce((total, item) => total + Number(item.total_price), 0) ?? 0;
});
const canCreateBuyerPo = computed(() => {
    return (
        Boolean(quotation.value) &&
        Boolean(latestVersion.value) &&
        !quotation.value?.buyer_po &&
        buyerPoForm.po_number.trim().length > 0 &&
        buyerPoForm.po_date.length > 0 &&
        Number(buyerPoForm.po_value) >= 0 &&
        Boolean(buyerPoFile.value) &&
        !isSavingBuyerPo.value
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

function money(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

function syncBuyerPoForm(detail: QuotationDetail): void {
    buyerPoForm.po_number = detail.buyer_po?.po_number ?? '';
    buyerPoForm.po_date = detail.buyer_po?.po_date ?? '';
    buyerPoForm.po_value = detail.buyer_po?.po_value ?? money(subtotal.value).replace(/,/g, '');
    buyerPoFile.value = null;
}

async function loadDetail(): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: QuotationDetail }>(`/api/quotations/${quotationId.value}`);
        quotation.value = payload.data;
        syncBuyerPoForm(payload.data);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation details.');
    } finally {
        isLoading.value = false;
    }
}

async function downloadVersion(version: QuotationVersion, format: 'docx' | 'pdf'): Promise<void> {
    try {
        await downloadProtectedFile(version.downloads[format], `${version.quotation_reference}-rev-${version.version_number}.${format}`);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download quotation file.');
    }
}

function handleBuyerPoFile(event: Event): void {
    buyerPoFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function submitBuyerPo(): Promise<void> {
    if (!canCreateBuyerPo.value || !quotation.value) {
        return;
    }

    isSavingBuyerPo.value = true;

    try {
        const formData = new FormData();
        formData.append('po_number', buyerPoForm.po_number.trim());
        formData.append('po_date', buyerPoForm.po_date);
        formData.append('po_value', buyerPoForm.po_value);

        if (buyerPoFile.value) {
            formData.append('po_file', buyerPoFile.value);
        }

        const payload = await requestFormData<{ message: string; data: BuyerPo }>(`/api/quotations/${quotation.value.id}/buyer-po`, formData);
        quotation.value = {
            ...quotation.value,
            buyer_po: payload.data,
            status: 'buyer_po_received',
        };
        syncBuyerPoForm(quotation.value);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to create buyer PO.');
    } finally {
        isSavingBuyerPo.value = false;
    }
}

onMounted(loadDetail);
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
                <h1>{{ quotation?.quotation_reference ?? 'Quotation Details' }}</h1>
            </div>

            <div class="quotation-title-actions">
                <button class="secondary-action icon-gap" type="button" @click="router.push('/quotations')">
                    <ArrowLeft :size="17" aria-hidden="true" />
                    Quotations
                </button>
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadDetail">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
            </div>
        </div>

        <div v-if="isLoading && !quotation" class="crud-empty">
            <Loader2 class="spin-icon" :size="20" aria-hidden="true" />
            Loading quotation details...
        </div>

        <template v-else-if="quotation">
            <div class="quotation-workspace-actions" role="tablist" aria-label="Quotation workspace">
                <button
                    class="workspace-action-card"
                    :class="{ active: activeWorkspaceTab === 'quotation' }"
                    type="button"
                    role="tab"
                    :aria-selected="activeWorkspaceTab === 'quotation'"
                    @click="activeWorkspaceTab = 'quotation'"
                >
                    <span class="workspace-action-icon">
                        <FileText :size="22" aria-hidden="true" />
                    </span>
                    <span>
                        <strong>View Quotation</strong>
                        <small>Commercial details, revision copies, downloads, and activity log</small>
                    </span>
                </button>

                <button
                    class="workspace-action-card"
                    :class="{ active: activeWorkspaceTab === 'buyer-po' }"
                    type="button"
                    role="tab"
                    :aria-selected="activeWorkspaceTab === 'buyer-po'"
                    @click="activeWorkspaceTab = 'buyer-po'"
                >
                    <span class="workspace-action-icon amber">
                        <ShoppingCart :size="22" aria-hidden="true" />
                    </span>
                    <span>
                        <strong>Create Buyer PO</strong>
                        <small>Record the buyer purchase order against the final quotation version</small>
                    </span>
                </button>
            </div>

            <template v-if="activeWorkspaceTab === 'quotation'">
                <div class="quotation-tab-toolbar">
                    <div>
                        <span>Quotation workspace</span>
                        <strong>{{ latestVersion ? `Latest revision V${latestVersion.version_number}` : 'No revision created yet' }}</strong>
                    </div>
                    <button class="primary-action compact-action icon-gap" type="button" @click="router.push(`/quotations/${quotation.id}/edit`)">
                        <Pencil :size="17" aria-hidden="true" />
                        Edit Quotation
                    </button>
                </div>

                <div class="module-stats">
                    <article class="module-stat">
                        <span>Status</span>
                        <strong>{{ humanizeStatus(quotation.status) }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Versions</span>
                        <strong>{{ quotation.versions.length }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Total</span>
                        <strong>{{ quotation.accepted_invoice_currency }} {{ money(subtotal) }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Buyer PO</span>
                        <strong>{{ quotation.buyer_po ? quotation.buyer_po.po_number : '-' }}</strong>
                    </article>
                </div>

                <div class="review-layout quotation-detail-layout">
                    <section class="review-block">
                        <h3>Commercial Details</h3>
                        <dl>
                            <div>
                                <dt>Buyer</dt>
                                <dd>{{ quotation.buyer_company_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Buyer Contact</dt>
                                <dd>{{ quotation.buyer_contact_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Supplier</dt>
                                <dd>{{ quotation.supplier_company_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Supplier Contact</dt>
                                <dd>{{ quotation.supplier_contact_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>RFQ / PR</dt>
                                <dd>{{ quotation.rfq_number ?? '-' }} / {{ quotation.pr_number ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Closing</dt>
                                <dd>{{ quotation.closing_at ?? '-' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="review-block">
                        <h3>Revision Copies</h3>
                        <div v-if="quotation.versions.length === 0" class="terms-empty">No quotation versions created yet.</div>
                        <div v-else class="version-list">
                            <article v-for="version in quotation.versions" :key="version.id" class="version-row">
                                <div>
                                    <strong>Version {{ version.version_number }}</strong>
                                    <span>{{ version.finalized_at ?? '-' }} by {{ version.created_by_name ?? '-' }}</span>
                                </div>
                                <div class="download-actions">
                                    <button class="secondary-action icon-gap" type="button" @click="downloadVersion(version, 'docx')">
                                        <Download :size="16" aria-hidden="true" />
                                        Word
                                    </button>
                                    <button class="secondary-action icon-gap" type="button" @click="downloadVersion(version, 'pdf')">
                                        <Download :size="16" aria-hidden="true" />
                                        PDF
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="review-block">
                        <h3>Products</h3>
                        <div class="review-list">
                            <article v-for="item in quotation.items" :key="item.id">
                                <strong>{{ item.line_number }}. {{ item.title }}</strong>
                                <span>{{ item.manufacturer_name ?? '-' }} - {{ item.quantity }} {{ item.uom }} x {{ item.unit_price }}</span>
                                <b>{{ quotation.accepted_invoice_currency }} {{ item.total_price }}</b>
                            </article>
                        </div>
                    </section>

                    <section class="review-block">
                        <h3>Activity Log</h3>
                        <div v-if="quotation.activity_logs.length === 0" class="terms-empty">No activity yet.</div>
                        <div v-else class="timeline-list">
                            <article v-for="log in quotation.activity_logs" :key="log.id">
                                <span>{{ log.created_at ?? '-' }}</span>
                                <strong>{{ log.summary }}</strong>
                            </article>
                        </div>
                    </section>
                </div>
            </template>

            <template v-else>
                <div class="buyer-po-hero">
                    <div class="buyer-po-hero-mark">
                        <FileCheck2 :size="28" aria-hidden="true" />
                    </div>
                    <div>
                        <p>{{ quotation.quotation_reference }}</p>
                        <h2>{{ quotation.buyer_company_name ?? 'Buyer PO' }}</h2>
                    </div>
                    <span>{{ quotation.buyer_po ? 'PO received' : 'Pending PO' }}</span>
                </div>

                <div class="module-stats">
                    <article class="module-stat">
                        <span>Accepted Version</span>
                        <strong>{{ latestVersion ? `V${latestVersion.version_number}` : '-' }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Quotation Value</span>
                        <strong>{{ quotation.accepted_invoice_currency }} {{ money(subtotal) }}</strong>
                    </article>
                </div>

                <div class="buyer-po-layout">
                    <section class="review-block buyer-po-stage-card">
                        <div class="buyer-po-section-title">
                            <span class="workspace-action-icon amber">
                                <FileCheck2 :size="20" aria-hidden="true" />
                            </span>
                            <div>
                                <h3>Buyer PO Details</h3>
                                <p>Record the buyer LPO/PO after they approve the final quotation version.</p>
                            </div>
                        </div>

                        <div v-if="!latestVersion" class="form-warning">
                            <AlertTriangle :size="18" aria-hidden="true" />
                            Create a quotation revision first, then record the buyer PO against that final version.
                        </div>

                        <dl v-else-if="quotation.buyer_po" class="buyer-po-summary">
                            <div>
                                <dt>PO Number</dt>
                                <dd>{{ quotation.buyer_po.po_number }}</dd>
                            </div>
                            <div>
                                <dt>PO Date</dt>
                                <dd>{{ quotation.buyer_po.po_date }}</dd>
                            </div>
                            <div>
                                <dt>PO Value</dt>
                                <dd>{{ quotation.buyer_po.currency }} {{ quotation.buyer_po.po_value }}</dd>
                            </div>
                            <div>
                                <dt>Accepted Version</dt>
                                <dd>Version {{ quotation.buyer_po.quotation_version_number }}</dd>
                            </div>
                            <div>
                                <dt>Uploaded File</dt>
                                <dd>{{ quotation.buyer_po.original_file_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Created By</dt>
                                <dd>{{ quotation.buyer_po.created_by_name ?? '-' }}</dd>
                            </div>
                        </dl>

                        <form v-else class="buyer-po-form" @submit.prevent="submitBuyerPo">
                            <label class="quote-field">
                                <span>Buyer PO / LPO Number<b>*</b></span>
                                <input v-model.trim="buyerPoForm.po_number" type="text" maxlength="100" required placeholder="4502757812" />
                            </label>

                            <label class="quote-field">
                                <span>PO Date<b>*</b></span>
                                <input v-model="buyerPoForm.po_date" type="date" required />
                            </label>

                            <label class="quote-field">
                                <span>PO Value<b>*</b></span>
                                <input v-model="buyerPoForm.po_value" type="number" min="0" step="0.001" required />
                            </label>

                            <label class="quote-field">
                                <span>Buyer PO File<b>*</b></span>
                                <input accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" type="file" required @change="handleBuyerPoFile" />
                            </label>

                            <footer class="buyer-po-form-actions">
                                <span>Will link to Version {{ latestVersion.version_number }}</span>
                                <button class="primary-action compact-action icon-gap" type="submit" :disabled="!canCreateBuyerPo">
                                    <Loader2 v-if="isSavingBuyerPo" class="spin-icon" :size="17" aria-hidden="true" />
                                    <Save v-else :size="17" aria-hidden="true" />
                                    {{ isSavingBuyerPo ? 'Creating...' : 'Create Buyer PO' }}
                                </button>
                            </footer>
                        </form>
                    </section>

                    <section class="review-block">
                        <div class="buyer-po-section-title">
                            <span class="workspace-action-icon">
                                <Upload :size="20" aria-hidden="true" />
                            </span>
                            <div>
                                <h3>Quotation Snapshot</h3>
                                <p>The buyer PO will inherit this quotation context.</p>
                            </div>
                        </div>

                        <dl>
                            <div>
                                <dt>Buyer</dt>
                                <dd>{{ quotation.buyer_company_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Buyer Contact</dt>
                                <dd>{{ quotation.buyer_contact_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Supplier</dt>
                                <dd>{{ quotation.supplier_company_name ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Items</dt>
                                <dd>{{ quotation.items.length }}</dd>
                            </div>
                            <div>
                                <dt>Latest Version</dt>
                                <dd>{{ latestVersion ? `Version ${latestVersion.version_number}` : '-' }}</dd>
                            </div>
                            <div>
                                <dt>Currency</dt>
                                <dd>{{ quotation.accepted_invoice_currency }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>
            </template>
        </template>
    </section>
</template>
