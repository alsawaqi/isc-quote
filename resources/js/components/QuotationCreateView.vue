<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowLeft,
    Building2,
    CalendarClock,
    CheckCircle2,
    CreditCard,
    Download,
    FileText,
    Loader2,
    Plus,
    Save,
    Trash2,
    Truck,
    UserRound,
} from 'lucide-vue-next';
import { downloadProtectedFile, requestJson } from '../auth';
import RichTextEditor from './RichTextEditor.vue';

interface SupplierOption {
    company_id: number;
    company_name: string;
    company_code: string | null;
    contact_id: number;
    contact_name: string;
}

interface BuyerOption {
    id: number;
    name: string;
    company_code: string;
}

interface BuyerContactOption {
    id: number;
    company_id: number;
    name: string;
    email?: string | null;
    mobile?: string | null;
}

interface SelectOption {
    id: number | string;
    code?: string;
    name: string;
}

interface QuotationOptions {
    supplier: SupplierOption | null;
    buyers: BuyerOption[];
    buyer_contacts: BuyerContactOption[];
    incoterms: SelectOption[];
    manufacturers: SelectOption[];
    currencies: SelectOption[];
    period_units: SelectOption[];
    uoms: SelectOption[];
    delivery_responsibilities: SelectOption[];
    term_defaults: TermDefault[];
}

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
    delivery_responsibility: string;
    status: string;
}

interface QuotationItemForm {
    key: number;
    manufacturer_id: string;
    product_name: string;
    title: string;
    buyer_description: string;
    manufacturer_description: string;
    quantity: number;
    uom: string;
    unit_price: number;
    vat_rate: number;
}

interface TermDefault {
    key: string;
    title: string;
}

interface QuotationTermForm {
    key: string | null;
    title: string;
    description: string;
    isDefault: boolean;
    localKey: number;
}

interface QuotationVersionRecord {
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

interface ExistingQuotationItem {
    id: number;
    manufacturer_id: number;
    product_name: string;
    title: string;
    buyer_description: string | null;
    manufacturer_description: string | null;
    quantity: string;
    uom: string;
    unit_price: string;
    vat_rate?: string;
}

interface ExistingQuotationTerm {
    id: number;
    key: string | null;
    title: string;
    description: string;
    is_required_default: boolean;
}

interface ExistingQuotationDetail extends QuotationRecord {
    quotation_validity_value: number;
    quotation_validity_unit: string;
    payment_term_days: number;
    delivery_period_min: number;
    delivery_period_max: number;
    delivery_period_unit: string;
    delivery_period_type: string;
    buyer_company_id: number;
    buyer_contact_id: number;
    incoterm_id: number;
    items: ExistingQuotationItem[];
    terms: ExistingQuotationTerm[];
}

type Toast = { type: 'error' | 'success'; message: string };

const fallbackTermDefaults: TermDefault[] = [
    { key: 'cancellation', title: 'Cancellation' },
    { key: 'scope_of_work', title: 'Scope of Work' },
    { key: 'delivery_term', title: 'Delivery Term' },
    { key: 'warranty', title: 'Warranty' },
    { key: 'force_majeure', title: 'Force Majeure' },
];

const route = useRoute();
const router = useRouter();
const options = ref<QuotationOptions>({
    supplier: null,
    buyers: [],
    buyer_contacts: [],
    incoterms: [],
    manufacturers: [],
    currencies: [],
    period_units: [],
    uoms: [],
    delivery_responsibilities: [],
    term_defaults: [],
});
const currentQuotation = ref<QuotationRecord | null>(null);
const activeStep = ref<1 | 2 | 3 | 4>(1);
const isLoading = ref(false);
const isSavingStepOne = ref(false);
const isSavingItems = ref(false);
const isSavingTerms = ref(false);
const isFinalizing = ref(false);
const termsSaved = ref(false);
const createdVersion = ref<QuotationVersionRecord | null>(null);
const toast = ref<Toast | null>(null);
const isEditMode = computed(() => route.name === 'quotations-edit' && Boolean(route.params.id));
const editQuotationId = computed(() => Number(route.params.id));
const pageTitle = computed(() => (isEditMode.value ? 'Edit Quotation' : 'Create Quotation'));
const stepOneButtonLabel = computed(() => {
    if (isSavingStepOne.value) {
        return 'Saving...';
    }

    return currentQuotation.value ? 'Update & Continue' : 'Save & Continue';
});

const form = reactive({
    buyer_company_id: '',
    buyer_contact_id: '',
    rfq_number: '',
    pr_number: '',
    closing_at: '',
    quotation_validity_value: 30,
    quotation_validity_unit: 'days',
    payment_term_days: 45,
    delivery_period_min: 22,
    delivery_period_max: 24,
    delivery_period_unit: 'weeks',
    delivery_period_type: 'working',
    accepted_invoice_currency: 'OMR',
    incoterm_id: '',
    delivery_responsibility: 'isc',
});

const items = ref<QuotationItemForm[]>([]);
const requiredTerms = ref<QuotationTermForm[]>([]);
const customTerms = ref<QuotationTermForm[]>([]);

const filteredBuyerContacts = computed(() => {
    return options.value.buyer_contacts.filter((contact) => String(contact.company_id) === String(form.buyer_company_id));
});

const selectedBuyer = computed(() => {
    return options.value.buyers.find((buyer) => String(buyer.id) === String(form.buyer_company_id)) ?? null;
});

const selectedBuyerContact = computed(() => {
    return filteredBuyerContacts.value.find((contact) => String(contact.id) === String(form.buyer_contact_id)) ?? null;
});

const selectedIncoterm = computed(() => {
    return options.value.incoterms.find((incoterm) => String(incoterm.id) === String(form.incoterm_id)) ?? null;
});

const canSaveStepOne = computed(() => {
    return (
        Boolean(options.value.supplier) &&
        Boolean(form.buyer_company_id) &&
        Boolean(form.buyer_contact_id) &&
        Boolean(form.closing_at) &&
        Boolean(form.quotation_validity_value) &&
        Boolean(form.payment_term_days || form.payment_term_days === 0) &&
        Boolean(form.delivery_period_min || form.delivery_period_min === 0) &&
        Boolean(form.delivery_period_max || form.delivery_period_max === 0) &&
        Boolean(form.accepted_invoice_currency) &&
        Boolean(form.incoterm_id) &&
        !isSavingStepOne.value
    );
});

const canSaveItems = computed(() => {
    return (
        Boolean(currentQuotation.value) &&
        items.value.length > 0 &&
        items.value.every(
            (item) =>
                item.manufacturer_id &&
                item.product_name.trim() &&
                item.title.trim() &&
                hasRichTextContent(item.buyer_description) &&
                item.quantity > 0 &&
                item.uom.trim() &&
                item.unit_price >= 0 &&
                item.vat_rate >= 0 &&
                item.vat_rate <= 100,
        ) &&
        !isSavingItems.value
    );
});

const canSaveTerms = computed(() => {
    const defaultsReady = requiredTerms.value.length >= 5 && requiredTerms.value.every((term) => term.description.trim());
    const customTermsReady = customTerms.value.every((term) => {
        const hasTitle = Boolean(term.title.trim());
        const hasDescription = Boolean(term.description.trim());

        return (!hasTitle && !hasDescription) || (hasTitle && hasDescription);
    });

    return Boolean(currentQuotation.value) && defaultsReady && customTermsReady && !isSavingTerms.value;
});

const quotationSubtotal = computed(() => {
    return items.value.reduce((total, item) => total + lineTotal(item), 0);
});

const quotationVatTotal = computed(() => {
    return items.value.reduce((total, item) => total + lineVatAmount(item), 0);
});

const quotationGrandTotal = computed(() => quotationSubtotal.value + quotationVatTotal.value);

const canFinalize = computed(() => Boolean(currentQuotation.value) && termsSaved.value && !isFinalizing.value);

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function optionName(optionsList: SelectOption[], id: string): string {
    return optionsList.find((option) => String(option.id) === String(id))?.name ?? id;
}

function responsibilityLabel(value: string): string {
    if (value === 'supplier') {
        return 'Supplier / Manufacturer Responsibility';
    }

    return options.value.delivery_responsibilities.find((item) => String(item.id) === value)?.name ?? value;
}

function lineTotal(item: QuotationItemForm): number {
    return Number(item.quantity || 0) * Number(item.unit_price || 0);
}

function lineVatAmount(item: QuotationItemForm): number {
    return lineTotal(item) * (Number(item.vat_rate || 0) / 100);
}

function lineTotalWithVat(item: QuotationItemForm): number {
    return lineTotal(item) + lineVatAmount(item);
}

function hasRichTextContent(value: string): boolean {
    const text = value
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .trim();

    return text.length > 0;
}

function money(value: number): string {
    return value.toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

function apiDateTime(value: string): string | null {
    return value ? value.replace('T', ' ') + (value.length === 16 ? ':00' : '') : null;
}

function localDateTime(value: string | null): string {
    return value ? value.replace(' ', 'T').slice(0, 16) : '';
}

function addItem(): void {
    items.value.push({
        key: Date.now() + items.value.length,
        manufacturer_id: '',
        product_name: '',
        title: '',
        buyer_description: '',
        manufacturer_description: '',
        quantity: 1,
        uom: 'EA',
        unit_price: 0,
        vat_rate: 0,
    });
}

function removeItem(index: number): void {
    items.value.splice(index, 1);
}

function initializeRequiredTerms(defaults: TermDefault[]): void {
    requiredTerms.value = defaults.map((term, index) => ({
        key: term.key,
        title: term.title,
        description: '',
        isDefault: true,
        localKey: Date.now() + index,
    }));
}

function addCustomTerm(): void {
    customTerms.value.push({
        key: null,
        title: '',
        description: '',
        isDefault: false,
        localKey: Date.now() + customTerms.value.length,
    });
}

function removeCustomTerm(index: number): void {
    customTerms.value.splice(index, 1);
}

function preparedTerms(): Array<{ key: string | null; title: string; description: string }> {
    return [
        ...requiredTerms.value.map((term) => ({
            key: term.key,
            title: term.title.trim(),
            description: term.description.trim(),
        })),
        ...customTerms.value
            .filter((term) => term.title.trim() || term.description.trim())
            .map((term) => ({
                key: null,
                title: term.title.trim(),
                description: term.description.trim(),
            })),
    ];
}

function populateExistingQuotation(detail: ExistingQuotationDetail): void {
    currentQuotation.value = detail;
    form.buyer_company_id = String(detail.buyer_company_id);
    form.buyer_contact_id = String(detail.buyer_contact_id);
    form.rfq_number = detail.rfq_number ?? '';
    form.pr_number = detail.pr_number ?? '';
    form.closing_at = localDateTime(detail.closing_at);
    form.quotation_validity_value = Number(detail.quotation_validity_value);
    form.quotation_validity_unit = detail.quotation_validity_unit;
    form.payment_term_days = Number(detail.payment_term_days);
    form.delivery_period_min = Number(detail.delivery_period_min);
    form.delivery_period_max = Number(detail.delivery_period_max);
    form.delivery_period_unit = detail.delivery_period_unit;
    form.delivery_period_type = detail.delivery_period_type;
    form.accepted_invoice_currency = detail.accepted_invoice_currency;
    form.incoterm_id = String(detail.incoterm_id);
    form.delivery_responsibility = detail.delivery_responsibility;

    items.value = detail.items.map((item, index) => ({
        key: Date.now() + index,
        manufacturer_id: String(item.manufacturer_id),
        product_name: item.product_name,
        title: item.title,
        buyer_description: item.buyer_description ?? '',
        manufacturer_description: item.manufacturer_description ?? '',
        quantity: Number(item.quantity),
        uom: item.uom,
        unit_price: Number(item.unit_price),
        vat_rate: Number(item.vat_rate ?? 0),
    }));

    const defaults = options.value.term_defaults.length > 0 ? options.value.term_defaults : fallbackTermDefaults;
    requiredTerms.value = defaults.map((term, index) => {
        const existing = detail.terms.find((candidate) => candidate.key === term.key);

        return {
            key: term.key,
            title: term.title,
            description: existing?.description ?? '',
            isDefault: true,
            localKey: Date.now() + index,
        };
    });
    customTerms.value = detail.terms
        .filter((term) => !term.is_required_default)
        .map((term, index) => ({
            key: null,
            title: term.title,
            description: term.description,
            isDefault: false,
            localKey: Date.now() + 100 + index,
        }));
    termsSaved.value = detail.terms.length >= 5;
    createdVersion.value = null;

    if (items.value.length === 0) {
        addItem();
    }
}

async function loadExistingQuotation(): Promise<void> {
    const payload = await requestJson<{ data: ExistingQuotationDetail }>(`/api/quotations/${editQuotationId.value}`);
    populateExistingQuotation(payload.data);
}

async function loadOptions(): Promise<void> {
    isLoading.value = true;

    try {
        options.value = await requestJson<QuotationOptions>('/api/quotations/create-options');

        if (requiredTerms.value.length === 0) {
            initializeRequiredTerms(options.value.term_defaults.length > 0 ? options.value.term_defaults : fallbackTermDefaults);
        }

        if (isEditMode.value) {
            await loadExistingQuotation();
        } else if (items.value.length === 0) {
            addItem();
        }
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load quotation setup.');
    } finally {
        isLoading.value = false;
    }
}

async function submitStepOne(): Promise<void> {
    if (!canSaveStepOne.value) {
        return;
    }

    isSavingStepOne.value = true;

    try {
        const isUpdating = Boolean(currentQuotation.value);
        const payload = await requestJson<{ message: string; data: QuotationRecord }>(isUpdating ? `/api/quotations/${currentQuotation.value?.id}` : '/api/quotations', {
            method: isUpdating ? 'PUT' : 'POST',
            body: JSON.stringify({
                buyer_company_id: Number(form.buyer_company_id),
                buyer_contact_id: Number(form.buyer_contact_id),
                rfq_number: form.rfq_number.trim() || null,
                pr_number: form.pr_number.trim() || null,
                closing_at: apiDateTime(form.closing_at),
                quotation_validity_value: Number(form.quotation_validity_value),
                quotation_validity_unit: form.quotation_validity_unit,
                payment_term_days: Number(form.payment_term_days),
                delivery_period_min: Number(form.delivery_period_min),
                delivery_period_max: Number(form.delivery_period_max),
                delivery_period_unit: form.delivery_period_unit,
                delivery_period_type: form.delivery_period_type,
                accepted_invoice_currency: form.accepted_invoice_currency,
                incoterm_id: Number(form.incoterm_id),
                delivery_responsibility: form.delivery_responsibility,
            }),
        });

        currentQuotation.value = payload.data;
        activeStep.value = 2;
        createdVersion.value = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save quotation.');
    } finally {
        isSavingStepOne.value = false;
    }
}

async function submitItems(): Promise<void> {
    if (!canSaveItems.value || !currentQuotation.value) {
        return;
    }

    isSavingItems.value = true;

    try {
        const payload = await requestJson<{ message: string }>(`/api/quotations/${currentQuotation.value.id}/items`, {
            method: 'POST',
            body: JSON.stringify({
                items: items.value.map((item) => ({
                    manufacturer_id: Number(item.manufacturer_id),
                    product_name: item.product_name.trim(),
                    title: item.title.trim(),
                    buyer_description: item.buyer_description,
                    manufacturer_description: item.manufacturer_description || item.buyer_description,
                    quantity: Number(item.quantity),
                    uom: item.uom.trim(),
                    unit_price: Number(item.unit_price),
                    vat_rate: Number(item.vat_rate),
                })),
            }),
        });

        activeStep.value = 3;
        termsSaved.value = false;
        createdVersion.value = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save quotation items.');
    } finally {
        isSavingItems.value = false;
    }
}

async function submitTerms(): Promise<void> {
    if (!canSaveTerms.value || !currentQuotation.value) {
        return;
    }

    isSavingTerms.value = true;

    try {
        const payload = await requestJson<{ message: string }>(`/api/quotations/${currentQuotation.value.id}/terms`, {
            method: 'POST',
            body: JSON.stringify({
                terms: preparedTerms(),
            }),
        });

        termsSaved.value = true;
        createdVersion.value = null;
        activeStep.value = 4;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save quotation terms.');
    } finally {
        isSavingTerms.value = false;
    }
}

async function finalizeQuotation(): Promise<void> {
    if (!canFinalize.value || !currentQuotation.value) {
        return;
    }

    isFinalizing.value = true;

    try {
        const payload = await requestJson<{ message: string; data: QuotationVersionRecord }>(`/api/quotations/${currentQuotation.value.id}/finalize`, {
            method: 'POST',
        });

        createdVersion.value = payload.data;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to create quotation version.');
    } finally {
        isFinalizing.value = false;
    }
}

async function downloadVersion(format: 'docx' | 'pdf'): Promise<void> {
    if (!createdVersion.value) {
        return;
    }

    try {
        await downloadProtectedFile(
            createdVersion.value.downloads[format],
            `${createdVersion.value.quotation_reference}-rev-${createdVersion.value.version_number}.${format}`,
        );
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download the quotation.');
    }
}

watch(
    () => form.buyer_company_id,
    () => {
        if (!filteredBuyerContacts.value.some((contact) => String(contact.id) === String(form.buyer_contact_id))) {
            form.buyer_contact_id = '';
        }
    },
);

onMounted(loadOptions);
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
                <h1>{{ pageTitle }}</h1>
            </div>

            <button class="secondary-action icon-gap" type="button" @click="router.push(isEditMode ? `/quotations/${editQuotationId}` : '/quotations')">
                <ArrowLeft :size="17" aria-hidden="true" />
                {{ isEditMode ? 'Back to Quotation' : 'Back to Quotations' }}
            </button>
        </div>

        <nav class="quotation-steps" aria-label="Quotation steps">
            <button type="button" :class="{ active: activeStep === 1, done: Boolean(currentQuotation) }" @click="activeStep = 1">
                <strong>1</strong>
                Buyer & Commercial
            </button>
            <button type="button" :class="{ active: activeStep === 2, disabled: !currentQuotation }" :disabled="!currentQuotation" @click="activeStep = 2">
                <strong>2</strong>
                Products
            </button>
            <button type="button" :class="{ active: activeStep === 3, done: termsSaved, disabled: !currentQuotation }" :disabled="!currentQuotation" @click="activeStep = 3">
                <strong>3</strong>
                Terms & Conditions
            </button>
            <button type="button" :class="{ active: activeStep === 4, disabled: !termsSaved }" :disabled="!termsSaved" @click="activeStep = 4">
                <strong>4</strong>
                Review & Create
            </button>
        </nav>

        <div v-if="activeStep === 1" class="quotation-grid">
            <form class="quotation-form-panel" @submit.prevent="submitStepOne">
                <header>
                    <span class="panel-mark">
                        <FileText :size="20" aria-hidden="true" />
                    </span>
                    <div>
                        <h2>Step 1</h2>
                        <p>Buyer, references, closing date, payment, delivery, currency, and Incoterm</p>
                    </div>
                </header>

                <div class="readonly-strip">
                    <div>
                        <Building2 :size="18" aria-hidden="true" />
                        <span>Supplier</span>
                        <strong>{{ options.supplier?.company_name ?? 'Not configured' }}</strong>
                    </div>
                    <div>
                        <UserRound :size="18" aria-hidden="true" />
                        <span>Supplier Contact</span>
                        <strong>{{ options.supplier?.contact_name ?? 'Not configured' }}</strong>
                    </div>
                </div>

                <div class="quotation-form-grid">
                    <label class="quote-field">
                        <span>Buyer Company<b>*</b></span>
                        <select v-model="form.buyer_company_id" required aria-label="Buyer Company">
                            <option value="">Select company</option>
                            <option v-for="buyer in options.buyers" :key="buyer.id" :value="buyer.id">
                                {{ buyer.name }} - {{ buyer.company_code }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Buyer Contact<b>*</b></span>
                        <select v-model="form.buyer_contact_id" required :disabled="!form.buyer_company_id" aria-label="Buyer Contact">
                            <option value="">Select contact</option>
                            <option v-for="contact in filteredBuyerContacts" :key="contact.id" :value="contact.id">
                                {{ contact.name }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>RFQ Number</span>
                        <input v-model.trim="form.rfq_number" type="text" placeholder="Optional" maxlength="100" aria-label="RFQ Number" />
                    </label>

                    <label class="quote-field">
                        <span>PR Number</span>
                        <input v-model.trim="form.pr_number" type="text" placeholder="Optional" maxlength="100" aria-label="PR Number" />
                    </label>

                    <label class="quote-field">
                        <span>Closing Date & Time<b>*</b></span>
                        <input v-model="form.closing_at" type="datetime-local" required aria-label="Closing Date and Time" />
                    </label>

                    <label class="quote-field compact-pair">
                        <span>Quotation Validity<b>*</b></span>
                        <input v-model.number="form.quotation_validity_value" type="number" min="1" max="3650" required aria-label="Quotation validity value" />
                        <select v-model="form.quotation_validity_unit" required aria-label="Quotation validity unit">
                            <option v-for="unit in options.period_units" :key="String(unit.id)" :value="unit.id">
                                {{ unit.name }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Payment Terms<b>*</b></span>
                        <div class="inline-sentence">
                            <span>Within</span>
                            <input v-model.number="form.payment_term_days" type="number" min="0" max="3650" required aria-label="Payment term days" />
                            <span>days from invoice</span>
                        </div>
                    </label>

                    <label class="quote-field delivery-field">
                        <span>Delivery Period<b>*</b></span>
                        <div class="delivery-controls">
                            <input v-model.number="form.delivery_period_min" type="number" min="0" max="3650" required aria-label="Delivery from" />
                            <span>to</span>
                            <input v-model.number="form.delivery_period_max" type="number" min="0" max="3650" required aria-label="Delivery to" />
                            <select v-model="form.delivery_period_type" required aria-label="Delivery period type">
                                <option value="working">Working</option>
                                <option value="calendar">Calendar</option>
                            </select>
                            <select v-model="form.delivery_period_unit" required aria-label="Delivery period unit">
                                <option v-for="unit in options.period_units" :key="String(unit.id)" :value="unit.id">
                                    {{ unit.name }}
                                </option>
                            </select>
                        </div>
                    </label>

                    <label class="quote-field">
                        <span>Accepted Invoice Currency<b>*</b></span>
                        <select v-model="form.accepted_invoice_currency" required aria-label="Accepted Invoice Currency">
                            <option v-for="currency in options.currencies" :key="String(currency.id)" :value="currency.id">
                                {{ currency.name }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Incoterm<b>*</b></span>
                        <select v-model="form.incoterm_id" required aria-label="Incoterm">
                            <option value="">Select Incoterm</option>
                            <option v-for="incoterm in options.incoterms" :key="String(incoterm.id)" :value="incoterm.id">
                                {{ incoterm.code }} - {{ incoterm.name }}
                            </option>
                        </select>
                    </label>

                    <fieldset class="quote-field responsibility-field">
                        <legend>Delivery Responsibility<b>*</b></legend>
                        <label v-for="item in options.delivery_responsibilities" :key="String(item.id)">
                            <input v-model="form.delivery_responsibility" type="radio" name="delivery_responsibility" :value="item.id" />
                            <span>{{ responsibilityLabel(String(item.id)) }}</span>
                        </label>
                    </fieldset>
                </div>

                <footer>
                    <button class="primary-action compact-action" type="submit" :disabled="!canSaveStepOne">
                        <Loader2 v-if="isSavingStepOne" class="spin-icon" :size="17" aria-hidden="true" />
                        <Save v-else :size="17" aria-hidden="true" />
                        {{ stepOneButtonLabel }}
                    </button>
                </footer>
            </form>

            <aside class="quotation-preview-panel">
                <section class="preview-card">
                    <header>
                        <CalendarClock :size="19" aria-hidden="true" />
                        <h2>Commercial Terms</h2>
                    </header>
                    <dl>
                        <div>
                            <dt>Buyer</dt>
                            <dd>{{ selectedBuyer?.name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Contact</dt>
                            <dd>{{ selectedBuyerContact?.name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Closing</dt>
                            <dd>{{ form.closing_at ? apiDateTime(form.closing_at) : '-' }}</dd>
                        </div>
                        <div>
                            <dt>Validity</dt>
                            <dd>{{ form.quotation_validity_value }} {{ optionName(options.period_units, form.quotation_validity_unit) }}</dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>Within {{ form.payment_term_days }} days from invoice</dd>
                        </div>
                    </dl>
                </section>

                <section class="preview-card">
                    <header>
                        <Truck :size="19" aria-hidden="true" />
                        <h2>Delivery</h2>
                    </header>
                    <dl>
                        <div>
                            <dt>Period</dt>
                            <dd>
                                {{ form.delivery_period_min }} to {{ form.delivery_period_max }}
                                {{ form.delivery_period_type }} {{ optionName(options.period_units, form.delivery_period_unit) }}
                            </dd>
                        </div>
                        <div>
                            <dt>Incoterm</dt>
                            <dd>{{ selectedIncoterm?.code ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Responsibility</dt>
                            <dd>{{ responsibilityLabel(form.delivery_responsibility) }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="preview-card">
                    <header>
                        <CreditCard :size="19" aria-hidden="true" />
                        <h2>Currency</h2>
                    </header>
                    <strong class="currency-preview">{{ form.accepted_invoice_currency }}</strong>
                </section>
            </aside>
        </div>

        <section v-else-if="activeStep === 2" class="quotation-form-panel quotation-items-panel">
            <header>
                <span class="panel-mark">
                    <FileText :size="20" aria-hidden="true" />
                </span>
                <div class="quotation-total-stack">
                    <h2>Step 2</h2>
                    <p>{{ currentQuotation?.quotation_reference }} products, descriptions, quantities, and prices</p>
                </div>
                <button class="secondary-action icon-gap" type="button" @click="addItem">
                    <Plus :size="17" aria-hidden="true" />
                    Add Product
                </button>
            </header>

            <datalist id="uom-options">
                <option v-for="uom in options.uoms" :key="String(uom.id)" :value="String(uom.id)">{{ uom.name }}</option>
            </datalist>

            <div class="line-items-table">
                <article v-for="(item, index) in items" :key="item.key" class="line-item-card">
                    <div class="line-item-head">
                        <strong>Line {{ index + 1 }}</strong>
                        <button v-if="items.length > 1" type="button" aria-label="Remove product" @click="removeItem(index)">
                            <Trash2 :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="line-item-grid">
                        <label class="quote-field">
                            <span>Manufacturer<b>*</b></span>
                            <select v-model="item.manufacturer_id" required :aria-label="`Line ${index + 1} Manufacturer`">
                                <option value="">Select manufacturer</option>
                                <option v-for="manufacturer in options.manufacturers" :key="String(manufacturer.id)" :value="manufacturer.id">
                                    {{ manufacturer.name }}
                                </option>
                            </select>
                        </label>
                        <label class="quote-field">
                            <span>Product Name<b>*</b></span>
                            <input v-model.trim="item.product_name" type="text" required :aria-label="`Line ${index + 1} Product Name`" />
                        </label>
                        <label class="quote-field">
                            <span>Title<b>*</b></span>
                            <input v-model.trim="item.title" type="text" required :aria-label="`Line ${index + 1} Title`" />
                        </label>
                        <label class="quote-field">
                            <span>Quantity<b>*</b></span>
                            <input v-model.number="item.quantity" type="number" min="0.001" step="0.001" required :aria-label="`Line ${index + 1} Quantity`" />
                        </label>
                        <label class="quote-field">
                            <span>UOM<b>*</b></span>
                            <input v-model.trim="item.uom" list="uom-options" type="text" required :aria-label="`Line ${index + 1} UOM`" />
                        </label>
                        <label class="quote-field">
                            <span>Unit Price<b>*</b></span>
                            <input v-model.number="item.unit_price" type="number" min="0" step="0.001" required :aria-label="`Line ${index + 1} Unit Price`" />
                        </label>
                        <label class="quote-field">
                            <span>VAT %<b>*</b></span>
                            <input v-model.number="item.vat_rate" type="number" min="0" max="100" step="0.001" required :aria-label="`Line ${index + 1} VAT percentage`" />
                        </label>
                        <div class="quote-field total-field">
                            <span>Total excl. VAT</span>
                            <strong>{{ money(lineTotal(item)) }}</strong>
                        </div>
                    </div>

                    <div class="description-grid">
                        <label class="quote-field editor-field">
                            <span>Buyer Description<b>*</b></span>
                            <RichTextEditor v-model="item.buyer_description" placeholder="Description visible on the quotation" />
                        </label>
                        <label class="quote-field editor-field">
                            <span>Manufacturer Description</span>
                            <RichTextEditor v-model="item.manufacturer_description" placeholder="Buyer description plus internal/manufacturer-only notes" />
                        </label>
                    </div>
                </article>
            </div>

            <footer class="items-footer">
                <div>
                    <span>Subtotal excl. VAT</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(quotationSubtotal) }}</strong>
                    <span>VAT</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(quotationVatTotal) }}</strong>
                    <span>Total incl. VAT</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(quotationGrandTotal) }}</strong>
                </div>
                <button class="primary-action compact-action" type="button" :disabled="!canSaveItems" @click="submitItems">
                    <Loader2 v-if="isSavingItems" class="spin-icon" :size="17" aria-hidden="true" />
                    <Save v-else :size="17" aria-hidden="true" />
                    {{ isSavingItems ? 'Saving...' : 'Save Products' }}
                </button>
            </footer>
        </section>

        <section v-else-if="activeStep === 3" class="quotation-form-panel quotation-terms-panel">
            <header>
                <span class="panel-mark">
                    <FileText :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2>Step 3</h2>
                    <p>{{ currentQuotation?.quotation_reference }} terms and conditions for the commercial offer</p>
                </div>
                <button class="secondary-action icon-gap" type="button" @click="addCustomTerm">
                    <Plus :size="17" aria-hidden="true" />
                    Add Term
                </button>
            </header>

            <div class="terms-body">
                <section class="standard-terms" aria-labelledby="standard-terms-title">
                    <div class="terms-section-title">
                        <h3 id="standard-terms-title">Standard Clauses</h3>
                        <span>Required</span>
                    </div>

                    <label v-for="term in requiredTerms" :key="term.localKey" class="term-row">
                        <strong>{{ term.title }}</strong>
                        <textarea
                            v-model.trim="term.description"
                            rows="4"
                            :aria-label="`${term.title} description`"
                            placeholder="Enter clause text"
                            required
                        ></textarea>
                    </label>
                </section>

                <section class="custom-terms" aria-labelledby="custom-terms-title">
                    <div class="terms-section-title">
                        <h3 id="custom-terms-title">Additional Terms</h3>
                        <button class="secondary-action icon-gap" type="button" @click="addCustomTerm">
                            <Plus :size="16" aria-hidden="true" />
                            Add Term
                        </button>
                    </div>

                    <div v-if="customTerms.length === 0" class="terms-empty">No additional terms added.</div>

                    <article v-for="(term, index) in customTerms" :key="term.localKey" class="custom-term-row">
                        <label class="quote-field">
                            <span>Title<b>*</b></span>
                            <input v-model.trim="term.title" type="text" :aria-label="`Additional term ${index + 1} title`" placeholder="Term title" />
                        </label>

                        <label class="quote-field">
                            <span>Description<b>*</b></span>
                            <textarea
                                v-model.trim="term.description"
                                rows="4"
                                :aria-label="`Additional term ${index + 1} description`"
                                placeholder="Term description"
                            ></textarea>
                        </label>

                        <button type="button" aria-label="Remove additional term" @click="removeCustomTerm(index)">
                            <Trash2 :size="16" aria-hidden="true" />
                        </button>
                    </article>
                </section>
            </div>

            <footer class="items-footer terms-footer">
                <div>
                    <span>Terms Ready</span>
                    <strong>{{ requiredTerms.length + customTerms.filter((term) => term.title.trim() || term.description.trim()).length }}</strong>
                </div>
                <div class="terms-actions">
                    <button class="secondary-action" type="button" @click="activeStep = 2">Edit Products</button>
                    <button class="primary-action compact-action" type="button" :disabled="!canSaveTerms" @click="submitTerms">
                        <Loader2 v-if="isSavingTerms" class="spin-icon" :size="17" aria-hidden="true" />
                        <Save v-else :size="17" aria-hidden="true" />
                        {{ isSavingTerms ? 'Saving...' : 'Save Terms & Review' }}
                    </button>
                </div>
            </footer>

            <div v-if="termsSaved" class="terms-saved-banner">
                <CheckCircle2 :size="18" aria-hidden="true" />
                Terms and conditions have been saved for this quotation.
            </div>
        </section>

        <section v-else class="quotation-form-panel quotation-review-panel">
            <header>
                <span class="panel-mark">
                    <CheckCircle2 :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2>Review & Create</h2>
                    <p>Check the quotation details, then create the controlled revision documents.</p>
                </div>
            </header>

            <div class="review-layout">
                <section class="review-block">
                    <h3>Quotation</h3>
                    <dl>
                        <div>
                            <dt>Reference</dt>
                            <dd>{{ currentQuotation?.quotation_reference }}</dd>
                        </div>
                        <div>
                            <dt>Buyer</dt>
                            <dd>{{ selectedBuyer?.name ?? currentQuotation?.buyer_company_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Buyer Contact</dt>
                            <dd>{{ selectedBuyerContact?.name ?? currentQuotation?.buyer_contact_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>RFQ / PR</dt>
                            <dd>{{ form.rfq_number || '-' }} / {{ form.pr_number || '-' }}</dd>
                        </div>
                        <div>
                            <dt>Closing</dt>
                            <dd>{{ form.closing_at ? apiDateTime(form.closing_at) : '-' }}</dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>Within {{ form.payment_term_days }} days from invoice</dd>
                        </div>
                        <div>
                            <dt>Delivery</dt>
                            <dd>
                                {{ form.delivery_period_min }} to {{ form.delivery_period_max }}
                                {{ form.delivery_period_type }} {{ optionName(options.period_units, form.delivery_period_unit) }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="review-block">
                    <h3>Products</h3>
                    <div class="review-list">
                        <article v-for="(item, index) in items" :key="item.key">
                            <strong>{{ index + 1 }}. {{ item.title }}</strong>
                            <span>{{ optionName(options.manufacturers, item.manufacturer_id) }} - {{ item.quantity }} {{ item.uom }} x {{ money(item.unit_price) }} + {{ money(item.vat_rate) }}% VAT</span>
                            <b>{{ form.accepted_invoice_currency }} {{ money(lineTotalWithVat(item)) }}</b>
                        </article>
                    </div>
                    <div class="review-total">
                        <span>Total incl. VAT</span>
                        <strong>{{ form.accepted_invoice_currency }} {{ money(quotationGrandTotal) }}</strong>
                    </div>
                </section>

                <section class="review-block">
                    <h3>Terms</h3>
                    <div class="review-list compact">
                        <article v-for="term in preparedTerms()" :key="`${term.key ?? term.title}-${term.title}`">
                            <strong>{{ term.title }}</strong>
                            <span>{{ term.description }}</span>
                        </article>
                    </div>
                </section>

                <section v-if="createdVersion" class="review-block created-version-card">
                    <h3>Revision Created</h3>
                    <dl>
                        <div>
                            <dt>Revision</dt>
                            <dd>Version {{ createdVersion.version_number }}</dd>
                        </div>
                        <div>
                            <dt>Created By</dt>
                            <dd>{{ createdVersion.created_by_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Created At</dt>
                            <dd>{{ createdVersion.finalized_at ?? '-' }}</dd>
                        </div>
                    </dl>
                    <div class="download-actions">
                        <button class="secondary-action icon-gap" type="button" @click="downloadVersion('docx')">
                            <Download :size="17" aria-hidden="true" />
                            Word
                        </button>
                        <button class="secondary-action icon-gap" type="button" @click="downloadVersion('pdf')">
                            <Download :size="17" aria-hidden="true" />
                            PDF
                        </button>
                    </div>
                </section>
            </div>

            <footer class="items-footer terms-footer">
                <div>
                    <span>Next Revision</span>
                    <strong>{{ createdVersion ? `V${createdVersion.version_number}` : 'Ready' }}</strong>
                </div>
                <div class="terms-actions">
                    <button class="secondary-action" type="button" @click="activeStep = 3">Edit Terms</button>
                    <button class="primary-action compact-action" type="button" :disabled="!canFinalize" @click="finalizeQuotation">
                        <Loader2 v-if="isFinalizing" class="spin-icon" :size="17" aria-hidden="true" />
                        <Save v-else :size="17" aria-hidden="true" />
                        {{ isFinalizing ? 'Creating...' : isEditMode ? 'Create Revision' : 'Create Quotation' }}
                    </button>
                    <button v-if="createdVersion" class="secondary-action" type="button" @click="router.push(`/quotations/${currentQuotation?.id}`)">
                        View Details
                    </button>
                </div>
            </footer>
        </section>
    </section>
</template>
