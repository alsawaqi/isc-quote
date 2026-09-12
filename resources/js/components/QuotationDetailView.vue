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
    product_code: string | null;
    manufacturer_name: string | null;
    title: string;
    quantity: string;
    uom: string;
    incoterm_code?: string | null;
    unit_price: string;
    vat_rate: string;
    total_price: string;
    total_with_vat?: string;
}

interface QuotationTerm {
    id: number;
    title: string;
    description: string;
}

interface PaymentScheduleRecord {
    id: number;
    label: string;
    payment_method_label: string;
    payment_percentage: string;
    due_event_label: string;
    due_offset_days: number | null;
    due_date: string | null;
    notes: string | null;
    summary: string;
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
        commercial?: {
            docx: string;
            pdf: string;
        };
        technical?: {
            docx: string;
            pdf: string;
        };
    };
}

interface BuyerPoItem {
    id: number;
    buyer_po_id: number;
    quotation_item_id: number;
    line_number: number;
    buyer_item_code: string | null;
    product_code: string | null;
    product_name: string | null;
    title: string | null;
    manufacturer_name: string | null;
    quantity: string;
    uom: string;
    unit_price: string;
    total_amount: string;
    currency: string;
    status: string;
}

interface BuyerPo {
    id: number;
    quotation_version_number: number;
    po_number: string;
    po_date: string;
    po_value: string;
    currency: string;
    original_file_name: string | null;
    download_url: string | null;
    items: BuyerPoItem[];
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
    rfq_title: string | null;
    rfq_display_title: string | null;
    closing_at: string | null;
    payment_terms_extra: string | null;
    payment_customer_type_label: string;
    payment_schedule_summary: string;
    payment_schedules: PaymentScheduleRecord[];
    delivery_period_min: number;
    delivery_period_max: number;
    delivery_period_type: string;
    delivery_period_unit: 'weeks';
    accepted_invoice_currency: string;
    vat_pricing: 'exclusive' | 'inclusive';
    charges: Array<{ line_number: number; label: string; amount: string }>;
    discounts: Array<{ line_number: number; label: string; discount_type: 'fixed' | 'percentage'; amount: string }>;
    totals: {
        items_subtotal: string;
        vat_total: string;
        charges_total: string;
        discounts_total: string;
        grand_total: string;
    };
    incoterm_code: string | null;
    status: string;
    items: QuotationItem[];
    terms: QuotationTerm[];
    versions: QuotationVersion[];
    buyer_po: BuyerPo | null;
    buyer_pos: BuyerPo[];
    activity_logs: ActivityLog[];
}

type Toast = { type: 'error' | 'success'; message: string };
type BuyerPoItemForm = {
    selected: boolean;
    quotation_item_id: number;
    buyer_item_code: string;
    po_number: string;
    po_date: string;
    po_value: string;
    po_file: File | null;
};

const route = useRoute();
const router = useRouter();
const quotation = ref<QuotationDetail | null>(null);
const activeWorkspaceTab = ref<'quotation' | 'buyer-po'>('quotation');
const isLoading = ref(false);
const isSavingBuyerPo = ref(false);
const downloadingBuyerPoId = ref<number | null>(null);
const toast = ref<Toast | null>(null);
const buyerPoItemForms = reactive<Record<number, BuyerPoItemForm>>({});

const quotationId = computed(() => Number(route.params.id));
const latestVersion = computed(() => {
    return [...(quotation.value?.versions ?? [])].sort((a, b) => b.version_number - a.version_number)[0] ?? null;
});
const subtotal = computed(() => {
    return Number(quotation.value?.totals.items_subtotal ?? 0);
});
const vatTotal = computed(() => {
    return Number(quotation.value?.totals.vat_total ?? 0);
});
const chargesTotal = computed(() => Number(quotation.value?.totals.charges_total ?? 0));
const discountsTotal = computed(() => Number(quotation.value?.totals.discounts_total ?? 0));
const grandTotal = computed(() => Number(quotation.value?.totals.grand_total ?? subtotal.value + vatTotal.value));
const buyerPoCoveredItemIds = computed(() => {
    return new Set((quotation.value?.buyer_pos ?? []).flatMap((buyerPo) => buyerPo.items.map((item) => item.quotation_item_id)));
});
const pendingBuyerPoItems = computed(() => {
    return (quotation.value?.items ?? []).filter((item) => !buyerPoCoveredItemIds.value.has(item.id));
});
const selectedBuyerPoRows = computed(() => {
    return pendingBuyerPoItems.value
        .map((item) => buyerPoItemForms[item.id])
        .filter((row): row is BuyerPoItemForm => Boolean(row?.selected));
});
const buyerPoRecordedTotal = computed(() => {
    return (quotation.value?.buyer_pos ?? []).reduce((sum, buyerPo) => sum + Number(buyerPo.po_value), 0);
});
const buyerPoFormTotal = computed(() => {
    return selectedBuyerPoRows.value.reduce((sum, row) => sum + Number(row.po_value || 0), 0);
});
const canCreateBuyerPo = computed(() => {
    const rows = selectedBuyerPoRows.value;

    return (
        Boolean(quotation.value) &&
        Boolean(latestVersion.value) &&
        rows.length > 0 &&
        rows.every((row) => row.po_number.trim().length > 0 && row.po_date.length > 0 && Number(row.po_value) >= 0 && Boolean(row.po_file)) &&
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

function discountLabel(discount: QuotationDetail['discounts'][number]): string {
    return discount.discount_type === 'percentage' ? `${money(Number(discount.amount))}%` : money(Number(discount.amount));
}

function itemPoAmount(item: QuotationItem): string {
    return Number(item.total_price).toFixed(3);
}

function resetBuyerPoItemForms(detail: QuotationDetail): void {
    Object.keys(buyerPoItemForms).forEach((key) => {
        delete buyerPoItemForms[Number(key)];
    });

    const coveredIds = new Set(detail.buyer_pos.flatMap((buyerPo) => buyerPo.items.map((item) => item.quotation_item_id)));

    detail.items
        .filter((item) => !coveredIds.has(item.id))
        .forEach((item) => {
            buyerPoItemForms[item.id] = {
                selected: true,
                quotation_item_id: item.id,
                buyer_item_code: '',
                po_number: '',
                po_date: '',
                po_value: itemPoAmount(item),
                po_file: null,
            };
        });
}

async function loadDetail(): Promise<void> {
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: QuotationDetail }>(`/api/quotations/${quotationId.value}`);
        payload.data.buyer_pos ??= payload.data.buyer_po ? [payload.data.buyer_po] : [];
        quotation.value = payload.data;
        resetBuyerPoItemForms(payload.data);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation details.');
    } finally {
        isLoading.value = false;
    }
}

async function downloadVersion(version: QuotationVersion, format: 'docx' | 'pdf', documentType: 'commercial' | 'technical' = 'commercial'): Promise<void> {
    try {
        const filename = `${version.quotation_reference}-${documentType}-rev-${version.version_number}.${format}`;
        const url = version.downloads[documentType]?.[format] ?? version.downloads[format];
        await downloadProtectedFile(url, format === 'docx' ? filename.toUpperCase() : filename);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download quotation file.');
    }
}

async function downloadBuyerPo(buyerPo: BuyerPo): Promise<void> {
    if (!buyerPo?.download_url) {
        return;
    }

    downloadingBuyerPoId.value = buyerPo.id;

    try {
        await downloadProtectedFile(buyerPo.download_url, buyerPo.original_file_name ?? `buyer-po-${buyerPo.po_number}`);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download the buyer PO file.');
    } finally {
        downloadingBuyerPoId.value = null;
    }
}

function handleBuyerPoItemFile(itemId: number, event: Event): void {
    const itemForm = buyerPoItemForms[itemId];

    if (!itemForm) {
        return;
    }

    itemForm.selected = true;
    itemForm.po_file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function selectBuyerPoItem(itemId: number): void {
    const itemForm = buyerPoItemForms[itemId];

    if (itemForm) {
        itemForm.selected = true;
    }
}

async function submitBuyerPo(): Promise<void> {
    if (!canCreateBuyerPo.value || !quotation.value) {
        return;
    }

    isSavingBuyerPo.value = true;

    try {
        const formData = new FormData();
        selectedBuyerPoRows.value.forEach((row, index) => {
            formData.append(`items[${index}][quotation_item_id]`, String(row.quotation_item_id));
            formData.append(`items[${index}][buyer_item_code]`, row.buyer_item_code.trim());
            formData.append(`items[${index}][po_number]`, row.po_number.trim());
            formData.append(`items[${index}][po_date]`, row.po_date);
            formData.append(`items[${index}][po_value]`, row.po_value);

            if (row.po_file) {
                formData.append(`items[${index}][po_file]`, row.po_file);
            }
        });

        const payload = await requestFormData<{
            message: string;
            data: {
                buyer_po: BuyerPo | null;
                buyer_pos: BuyerPo[];
                status: string;
            };
        }>(`/api/quotations/${quotation.value.id}/buyer-po`, formData);
        quotation.value = {
            ...quotation.value,
            buyer_po: payload.data.buyer_po,
            buyer_pos: payload.data.buyer_pos,
            status: payload.data.status,
        };
        resetBuyerPoItemForms(quotation.value);
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
                        <span>Total incl. VAT</span>
                        <strong>{{ quotation.accepted_invoice_currency }} {{ money(grandTotal) }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Buyer PO</span>
                        <strong>{{ quotation.buyer_pos.length ? `${quotation.buyer_pos.length} PO(s)` : '-' }}</strong>
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
                                <dt>RFQ Title</dt>
                                <dd>{{ quotation.rfq_display_title ?? quotation.rfq_title ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>RFQ / PR</dt>
                                <dd>{{ quotation.rfq_number ?? '-' }} / {{ quotation.pr_number ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>Closing</dt>
                                <dd>{{ quotation.closing_at ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt>VAT Pricing</dt>
                                <dd>{{ quotation.vat_pricing === 'inclusive' ? 'VAT Inclusive' : 'VAT Exclusive' }}</dd>
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
                                    <button class="secondary-action icon-gap" type="button" @click="downloadVersion(version, 'docx', 'commercial')">
                                        <Download :size="16" aria-hidden="true" />
                                        Commercial Word
                                    </button>
                                    <button class="secondary-action icon-gap" type="button" @click="downloadVersion(version, 'pdf', 'commercial')">
                                        <Download :size="16" aria-hidden="true" />
                                        Commercial PDF
                                    </button>
                                    <button class="secondary-action icon-gap" type="button" @click="downloadVersion(version, 'docx', 'technical')">
                                        <Download :size="16" aria-hidden="true" />
                                        Technical Word
                                    </button>
                                    <button class="secondary-action icon-gap" type="button" @click="downloadVersion(version, 'pdf', 'technical')">
                                        <Download :size="16" aria-hidden="true" />
                                        Technical PDF
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <section class="review-block">
                        <h3>Payment Plan</h3>
                        <div class="payment-agreement-list">
                            <p class="empty-note">{{ quotation.payment_schedule_summary }}</p>
                            <article v-for="schedule in quotation.payment_schedules" :key="schedule.id">
                                <strong>{{ schedule.label }} - {{ schedule.payment_percentage }}%</strong>
                                <span>{{ schedule.summary }}</span>
                                <small v-if="schedule.notes">{{ schedule.notes }}</small>
                            </article>
                        </div>
                    </section>

                    <section class="review-block">
                        <h3>Products</h3>
                        <div class="review-list">
                            <article v-for="item in quotation.items" :key="item.id">
                                <strong>{{ item.line_number }}. {{ item.product_code ?? '-' }} - {{ item.title }}</strong>
                                <span>{{ item.manufacturer_name ?? '-' }} - {{ item.quantity }} {{ item.uom }} x {{ item.unit_price }} {{ quotation.vat_pricing === 'inclusive' ? '(includes ' + item.vat_rate + '% VAT)' : '+ ' + item.vat_rate + '% VAT' }}</span>
                                <small>Delivery: {{ quotation.delivery_period_min }} to {{ quotation.delivery_period_max }} {{ quotation.delivery_period_type }} {{ quotation.delivery_period_unit }} | {{ item.incoterm_code ?? quotation.incoterm_code ?? '-' }}</small>
                                <b>{{ quotation.accepted_invoice_currency }} {{ money(Number(item.total_with_vat ?? item.total_price)) }}</b>
                            </article>
                        </div>
                        <div v-if="quotation.charges.length || quotation.discounts.length" class="review-list compact">
                            <article v-for="charge in quotation.charges" :key="`charge-${charge.line_number}`">
                                <strong>Charge: {{ charge.label }}</strong>
                                <span>Additional quotation charge</span>
                                <b>{{ quotation.accepted_invoice_currency }} {{ money(Number(charge.amount)) }}</b>
                            </article>
                            <article v-for="discount in quotation.discounts" :key="`discount-${discount.line_number}`">
                                <strong>Discount: {{ discount.label }}</strong>
                                <span>{{ discount.discount_type === 'percentage' ? 'Percentage discount' : 'Fixed discount' }}</span>
                                <b>- {{ discount.discount_type === 'fixed' ? quotation.accepted_invoice_currency + ' ' : '' }}{{ discountLabel(discount) }}</b>
                            </article>
                        </div>
                        <div class="review-total">
                            <span>Subtotal / VAT after discounts / Charges / Discounts</span>
                            <strong>{{ quotation.accepted_invoice_currency }} {{ money(subtotal) }} / {{ money(vatTotal) }} / {{ money(chargesTotal) }} / {{ money(discountsTotal) }}</strong>
                        </div>
                        <div class="review-total grand">
                            <span>Grand Total</span>
                            <strong>{{ quotation.accepted_invoice_currency }} {{ money(grandTotal) }}</strong>
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
                    <span>{{ buyerPoCoveredItemIds.size ? `${buyerPoCoveredItemIds.size} / ${quotation.items.length} items` : 'Pending PO' }}</span>
                </div>

                <div class="module-stats">
                    <article class="module-stat">
                        <span>Accepted Version</span>
                        <strong>{{ latestVersion ? `V${latestVersion.version_number}` : '-' }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Quotation Value</span>
                        <strong>{{ quotation.accepted_invoice_currency }} {{ money(grandTotal) }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Buyer PO Value</span>
                        <strong>{{ quotation.accepted_invoice_currency }} {{ money(buyerPoRecordedTotal) }}</strong>
                    </article>
                    <article class="module-stat">
                        <span>Pending Items</span>
                        <strong>{{ pendingBuyerPoItems.length }}</strong>
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

                        <div v-if="quotation.buyer_pos.length" class="review-list compact">
                            <article v-for="buyerPo in quotation.buyer_pos" :key="buyerPo.id">
                                <strong>{{ buyerPo.po_number }} - {{ buyerPo.currency }} {{ buyerPo.po_value }}</strong>
                                <span>{{ buyerPo.po_date }} | Version {{ buyerPo.quotation_version_number }}</span>
                                <button
                                    v-if="buyerPo.download_url"
                                    class="table-link-button"
                                    type="button"
                                    :disabled="downloadingBuyerPoId === buyerPo.id"
                                    @click="downloadBuyerPo(buyerPo)"
                                >
                                    <Loader2 v-if="downloadingBuyerPoId === buyerPo.id" class="spin-icon" :size="15" aria-hidden="true" />
                                    <Download v-else :size="15" aria-hidden="true" />
                                    {{ buyerPo.original_file_name ?? 'Download buyer PO' }}
                                </button>
                                <small v-for="poItem in buyerPo.items" :key="poItem.id">
                                    {{ poItem.product_code ?? '-' }} | Buyer Item {{ poItem.buyer_item_code ?? '-' }} | {{ poItem.currency }} {{ poItem.total_amount }}
                                </small>
                            </article>
                        </div>

                        <form v-if="latestVersion && pendingBuyerPoItems.length" class="buyer-po-form" @submit.prevent="submitBuyerPo">
                            <div class="supplier-po-items">
                                <article v-for="item in pendingBuyerPoItems" :key="item.id" class="supplier-po-item-card buyer-po-item-card">
                                    <label class="buyer-po-item-select">
                                        <input v-model="buyerPoItemForms[item.id].selected" type="checkbox" />
                                        <span>
                                            <strong>{{ item.product_code ?? '-' }} - {{ item.title }}</strong>
                                            <small>{{ item.manufacturer_name ?? '-' }} | {{ item.quantity }} {{ item.uom }} | {{ quotation.accepted_invoice_currency }} {{ item.total_price }}</small>
                                        </span>
                                    </label>

                                    <div class="buyer-po-item-fields">
                                        <label class="quote-field">
                                            <span>Buyer Item Code</span>
                                            <input v-model.trim="buyerPoItemForms[item.id].buyer_item_code" type="text" maxlength="100" @focus="selectBuyerPoItem(item.id)" />
                                        </label>

                                        <label class="quote-field">
                                            <span>Buyer PO / LPO Number<b>*</b></span>
                                            <input v-model.trim="buyerPoItemForms[item.id].po_number" type="text" maxlength="100" :required="buyerPoItemForms[item.id].selected" @focus="selectBuyerPoItem(item.id)" />
                                        </label>

                                        <label class="quote-field">
                                            <span>PO Date<b>*</b></span>
                                            <input v-model="buyerPoItemForms[item.id].po_date" type="date" :required="buyerPoItemForms[item.id].selected" @focus="selectBuyerPoItem(item.id)" />
                                        </label>

                                        <label class="quote-field">
                                            <span>Item PO Amount<b>*</b></span>
                                            <input v-model="buyerPoItemForms[item.id].po_value" type="number" min="0" step="0.001" :required="buyerPoItemForms[item.id].selected" @focus="selectBuyerPoItem(item.id)" />
                                        </label>

                                        <label class="quote-field">
                                            <span>Buyer PO File<b>*</b></span>
                                            <input
                                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                                type="file"
                                                :required="buyerPoItemForms[item.id].selected"
                                                @focus="selectBuyerPoItem(item.id)"
                                                @change="handleBuyerPoItemFile(item.id, $event)"
                                            />
                                        </label>
                                    </div>
                                </article>
                            </div>

                            <footer class="buyer-po-form-actions">
                                <span>{{ quotation.accepted_invoice_currency }} {{ money(buyerPoFormTotal) }} | Version {{ latestVersion.version_number }}</span>
                                <button class="primary-action compact-action icon-gap" type="submit" :disabled="!canCreateBuyerPo">
                                    <Loader2 v-if="isSavingBuyerPo" class="spin-icon" :size="17" aria-hidden="true" />
                                    <Save v-else :size="17" aria-hidden="true" />
                                    {{ isSavingBuyerPo ? 'Saving...' : 'Save Buyer PO Items' }}
                                </button>
                            </footer>
                        </form>

                        <div v-else-if="latestVersion && pendingBuyerPoItems.length === 0" class="terms-empty">
                            All quotation items have buyer PO details.
                        </div>
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
