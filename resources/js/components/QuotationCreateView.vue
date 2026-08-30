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
import { sanitizeRichTextHtml } from '../richText';

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
    symbol?: string | null;
    display_name?: string;
    required?: boolean;
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
    payment_customer_types: SelectOption[];
    payment_methods: SelectOption[];
    payment_due_events: SelectOption[];
    term_defaults: TermDefault[];
}

interface QuotationRecord {
    id: number;
    quotation_reference: string;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    rfq_number: string | null;
    pr_number: string | null;
    rfq_title: string | null;
    rfq_display_title: string | null;
    closing_at: string | null;
    payment_term_days: number;
    payment_terms_extra: string | null;
    accepted_invoice_currency: string;
    accepted_invoice_currency_symbol?: string | null;
    accepted_invoice_currency_display?: string | null;
    vat_pricing: 'exclusive' | 'inclusive';
    charges: ExistingCharge[];
    discounts: ExistingDiscount[];
    totals: QuotationTotals;
    incoterm_code: string | null;
    delivery_responsibility: string;
    payment_customer_type: string;
    payment_customer_type_label: string;
    payment_schedule_summary: string;
    payment_schedules: ExistingPaymentSchedule[];
    status: string;
}

interface QuotationItemForm {
    key: number;
    manufacturer_id: string;
    product_code: string;
    product_name: string;
    title: string;
    buyer_description: string;
    quantity: number;
    uom: string;
    incoterm_id: string;
    unit_price: number;
    vat_rate: number;
}

interface ChargeForm {
    key: number;
    label: string;
    amount: number;
}

interface DiscountForm {
    key: number;
    label: string;
    discount_type: 'fixed' | 'percentage';
    amount: number;
}

interface TermDefault {
    key: string;
    title: string;
    required?: boolean;
}

interface QuotationTermForm {
    key: string | null;
    title: string;
    description: string;
    isDefault: boolean;
    required: boolean;
    localKey: number;
}

interface PaymentScheduleForm {
    key: number;
    label: string;
    payment_method: string;
    payment_percentage: number;
    due_timing: 'relative' | 'fixed_date';
    due_event: string;
    due_offset_days: number;
    due_date: string;
    notes: string;
}

interface QuotationVersionRecord {
    id: number;
    version_number: number;
    quotation_reference: string;
    created_by_name: string | null;
    finalized_at: string | null;
    revision_action?: 'created' | 'refreshed';
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

interface ExistingQuotationItem {
    id: number;
    manufacturer_id: number;
    product_code: string | null;
    product_name: string;
    title: string;
    buyer_description: string | null;
    quantity: string;
    uom: string;
    incoterm_id?: number | null;
    incoterm_code?: string | null;
    unit_price: string;
    vat_rate?: string;
    total_price: string;
    vat_amount?: string;
    total_with_vat?: string;
}

interface ExistingCharge {
    id?: number;
    line_number: number;
    label: string;
    amount: string;
}

interface ExistingDiscount {
    id?: number;
    line_number: number;
    label: string;
    discount_type: 'fixed' | 'percentage';
    amount: string;
}

interface QuotationTotals {
    items_subtotal: string;
    vat_total: string;
    charges_total: string;
    discounts_total: string;
    grand_total: string;
}

interface ExistingQuotationTerm {
    id: number;
    key: string | null;
    title: string;
    description: string;
    is_required_default: boolean;
}

interface ExistingPaymentSchedule {
    id: number;
    line_number: number;
    label: string;
    payment_method: string;
    payment_method_label: string;
    payment_percentage: string;
    due_timing: 'relative' | 'fixed_date';
    due_event: string | null;
    due_event_label: string;
    due_offset_days: number | null;
    due_date: string | null;
    notes: string | null;
    summary: string;
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
    payment_schedules: ExistingPaymentSchedule[];
    terms: ExistingQuotationTerm[];
}

type Toast = { type: 'error' | 'success'; message: string };

const fallbackTermDefaults: TermDefault[] = [
    { key: 'cancellation', title: 'Cancellation', required: true },
    { key: 'scope_of_work', title: 'Scope of Work', required: true },
    { key: 'delivery_term', title: 'Delivery Term', required: true },
    { key: 'warranty', title: 'Warranty', required: false },
    { key: 'force_majeure', title: 'Force Majeure', required: true },
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
    payment_customer_types: [],
    payment_methods: [],
    payment_due_events: [],
    term_defaults: [],
});
const currentQuotation = ref<QuotationRecord | null>(null);
const activeStep = ref<1 | 2 | 3 | 4 | 5>(1);
const isLoading = ref(false);
const isSavingStepOne = ref(false);
const isSavingPaymentSchedule = ref(false);
const isSavingItems = ref(false);
const isSavingTerms = ref(false);
const isFinalizing = ref(false);
const hasSavedPaymentSchedule = ref(false);
const hasSavedItems = ref(false);
const hasSavedTerms = ref(false);
const savedCommercialSnapshot = ref<string | null>(null);
const savedPaymentScheduleSnapshot = ref<string | null>(null);
const savedItemsSnapshot = ref<string | null>(null);
const savedTermsSnapshot = ref<string | null>(null);
const paymentTypeRequiresScheduleReset = ref(false);
const createdVersion = ref<QuotationVersionRecord | null>(null);
const toast = ref<Toast | null>(null);
let isHydratingQuotation = false;
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
    rfq_title: '',
    closing_at: '',
    quotation_validity_value: 30,
    quotation_validity_unit: 'days',
    payment_term_days: 45,
    payment_terms_extra: '',
    payment_customer_type: 'credit',
    delivery_period_min: 22,
    delivery_period_max: 24,
    delivery_period_unit: 'weeks',
    delivery_period_type: 'working',
    accepted_invoice_currency: 'OMR',
    vat_pricing: 'exclusive' as 'exclusive' | 'inclusive',
    incoterm_id: '',
    delivery_responsibility: 'isc',
});

const items = ref<QuotationItemForm[]>([]);
const charges = ref<ChargeForm[]>([]);
const discounts = ref<DiscountForm[]>([]);
const paymentSchedules = ref<PaymentScheduleForm[]>([]);
const requiredTerms = ref<QuotationTermForm[]>([]);
const customTerms = ref<QuotationTermForm[]>([]);

function commercialSnapshot(): string {
    return JSON.stringify({
        buyer_company_id: String(form.buyer_company_id),
        buyer_contact_id: String(form.buyer_contact_id),
        rfq_number: form.rfq_number.trim(),
        pr_number: form.pr_number.trim(),
        rfq_title: form.rfq_title.trim(),
        closing_at: form.closing_at,
        quotation_validity_value: Number(form.quotation_validity_value),
        quotation_validity_unit: form.quotation_validity_unit,
        payment_term_days: Number(form.payment_term_days),
        payment_terms_extra: form.payment_terms_extra.trim(),
        payment_customer_type: form.payment_customer_type,
        delivery_period_min: Number(form.delivery_period_min),
        delivery_period_max: Number(form.delivery_period_max),
        delivery_period_unit: form.delivery_period_unit,
        delivery_period_type: form.delivery_period_type,
        accepted_invoice_currency: form.accepted_invoice_currency,
        incoterm_id: String(form.incoterm_id),
        delivery_responsibility: form.delivery_responsibility,
    });
}

function paymentScheduleSnapshot(): string {
    return JSON.stringify(paymentSchedules.value.map((schedule) => ({
        label: schedule.label.trim(),
        payment_method: schedule.payment_method,
        payment_percentage: Number(schedule.payment_percentage),
        due_timing: schedule.due_timing,
        due_event: schedule.due_timing === 'relative' ? schedule.due_event : null,
        due_offset_days: schedule.due_timing === 'relative' ? Number(schedule.due_offset_days || 0) : null,
        due_date: schedule.due_timing === 'fixed_date' ? schedule.due_date : null,
        notes: schedule.notes.trim(),
    })));
}

function itemsSnapshot(): string {
    return JSON.stringify({
        vat_pricing: form.vat_pricing,
        items: items.value.map((item) => ({
            manufacturer_id: String(item.manufacturer_id),
            product_code: item.product_code.trim(),
            product_name: item.product_name.trim(),
            title: item.title.trim(),
            buyer_description: item.buyer_description,
            quantity: Number(item.quantity),
            uom: item.uom.trim(),
            incoterm_id: String(item.incoterm_id),
            unit_price: Number(item.unit_price),
            vat_rate: Number(item.vat_rate),
        })),
        charges: charges.value.map((charge) => ({
            label: charge.label.trim(),
            amount: Number(charge.amount || 0),
        })),
        discounts: discounts.value.map((discount) => ({
            label: discount.label.trim(),
            discount_type: discount.discount_type,
            amount: Number(discount.amount || 0),
        })),
    });
}

function termsSnapshot(): string {
    return JSON.stringify({
        required: requiredTerms.value.map((term) => ({
            key: term.key,
            title: term.title.trim(),
            description: term.description.trim(),
        })),
        custom: customTerms.value.map((term) => ({
            title: term.title.trim(),
            description: term.description.trim(),
        })),
    });
}

const commercialDirty = computed(() => Boolean(currentQuotation.value) && savedCommercialSnapshot.value !== commercialSnapshot());
const paymentScheduleDirty = computed(
    () => Boolean(currentQuotation.value) && (!hasSavedPaymentSchedule.value || savedPaymentScheduleSnapshot.value !== paymentScheduleSnapshot()),
);
const itemsDirty = computed(() => Boolean(currentQuotation.value) && (!hasSavedItems.value || savedItemsSnapshot.value !== itemsSnapshot()));
const termsDirty = computed(() => Boolean(currentQuotation.value) && (!hasSavedTerms.value || savedTermsSnapshot.value !== termsSnapshot()));
const paymentScheduleSaved = computed(() => hasSavedPaymentSchedule.value && !paymentScheduleDirty.value);
const itemsSaved = computed(() => hasSavedItems.value && !itemsDirty.value);
const termsSaved = computed(() => hasSavedTerms.value && !termsDirty.value);
const canReview = computed(
    () => Boolean(currentQuotation.value) && !commercialDirty.value && paymentScheduleSaved.value && itemsSaved.value && termsSaved.value,
);

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

const selectedPaymentCustomerType = computed(() => {
    return options.value.payment_customer_types.find((type) => String(type.id) === form.payment_customer_type) ?? null;
});

const selectedCurrency = computed(() => {
    return options.value.currencies.find((currency) => String(currency.id) === String(form.accepted_invoice_currency)) ?? null;
});

const rfqTitlePreview = computed(() => {
    if (form.rfq_title.trim()) {
        return form.rfq_title.trim();
    }

    const parts = [
        form.rfq_number.trim() ? `RFQ ${form.rfq_number.trim()}` : '',
        form.pr_number.trim() ? `PR ${form.pr_number.trim()}` : '',
    ].filter(Boolean);

    return parts.join(' ') || '-';
});

const acceptedPaymentTermsText = computed(() => {
    const base = `Within ${Number(form.payment_term_days || 0)} days from the date of Invoice.`;
    const extra = form.payment_terms_extra.trim();

    return extra ? `${base} ${extra}` : base;
});

const canSaveStepOne = computed(() => {
    return (
        Boolean(options.value.supplier) &&
        Boolean(form.buyer_company_id) &&
        Boolean(form.buyer_contact_id) &&
        Boolean(form.quotation_validity_value) &&
        Boolean(form.payment_customer_type) &&
        Boolean(form.payment_term_days || form.payment_term_days === 0) &&
        Boolean(form.delivery_period_min || form.delivery_period_min === 0) &&
        Boolean(form.delivery_period_max || form.delivery_period_max === 0) &&
        Boolean(form.accepted_invoice_currency) &&
        Boolean(form.incoterm_id) &&
        !isSavingStepOne.value
    );
});

const paymentScheduleTotal = computed(() => {
    return paymentSchedules.value.reduce((total, schedule) => total + Number(schedule.payment_percentage || 0), 0);
});

const canSavePaymentSchedule = computed(() => {
    return (
        Boolean(currentQuotation.value) &&
        paymentSchedules.value.length > 0 &&
        Math.abs(paymentScheduleTotal.value - 100) <= 0.01 &&
        paymentSchedules.value.every((schedule) => {
            const hasDue = schedule.due_timing === 'fixed_date'
                ? Boolean(schedule.due_date)
                : Boolean(schedule.due_event) && Number(schedule.due_offset_days || 0) >= 0;

            return (
                schedule.label.trim().length > 0 &&
                schedule.payment_method.length > 0 &&
                Number(schedule.payment_percentage) > 0 &&
                Number(schedule.payment_percentage) <= 100 &&
                hasDue
            );
        }) &&
        !isSavingPaymentSchedule.value
    );
});

const canSaveItems = computed(() => {
    return (
        Boolean(currentQuotation.value) &&
        items.value.length > 0 &&
        items.value.every(
            (item) =>
                item.manufacturer_id &&
                item.product_code.trim() &&
                item.product_name.trim() &&
                item.title.trim() &&
                hasRichTextContent(item.buyer_description) &&
                item.quantity > 0 &&
                item.uom.trim() &&
                item.incoterm_id &&
                item.unit_price >= 0 &&
                item.vat_rate >= 0 &&
                item.vat_rate <= 100,
        ) &&
        charges.value.every((charge) => charge.label.trim() && Number(charge.amount) >= 0) &&
        discounts.value.every((discount) => discount.label.trim() && Number(discount.amount) >= 0 && (discount.discount_type !== 'percentage' || Number(discount.amount) <= 100)) &&
        !isSavingItems.value
    );
});

const canSaveTerms = computed(() => {
    const defaultsReady = requiredTerms.value
        .filter((term) => term.required)
        .every((term) => hasRichTextContent(term.description));
    const customTermsReady = customTerms.value.every((term) => {
        const hasTitle = Boolean(term.title.trim());
        const hasDescription = hasRichTextContent(term.description);

        return (!hasTitle && !hasDescription) || (hasTitle && hasDescription);
    });

    return Boolean(currentQuotation.value) && defaultsReady && customTermsReady && !isSavingTerms.value;
});

const quotationSubtotal = computed(() => {
    return items.value.reduce((total, item) => total + lineTotal(item), 0);
});

const quotationVatTotal = computed(() => {
    const rawVat = items.value.reduce((total, item) => total + lineVatAmount(item), 0);

    if (rawVat <= 0 || discountBase.value <= 0 || discountsTotal.value <= 0) {
        return Math.max(0, rawVat);
    }

    const discountedBase = Math.max(0, discountBase.value - discountsTotal.value);

    return Math.max(0, rawVat * (discountedBase / discountBase.value));
});

const chargesTotal = computed(() => charges.value.reduce((total, charge) => total + Number(charge.amount || 0), 0));

const discountBase = computed(() => quotationSubtotal.value + chargesTotal.value);

const discountsTotal = computed(() => {
    return discounts.value.reduce((total, discount) => {
        const amount = Number(discount.amount || 0);

        return total + (discount.discount_type === 'percentage' ? discountBase.value * Math.min(Math.max(amount, 0), 100) / 100 : Math.min(amount, discountBase.value));
    }, 0);
});

const quotationGrandTotal = computed(() => Math.max(0, quotationSubtotal.value + chargesTotal.value - discountsTotal.value + quotationVatTotal.value));

const canFinalize = computed(() => canReview.value && !isFinalizing.value);

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

function incotermOptionLabel(id: string): string {
    const incoterm = options.value.incoterms.find((option) => String(option.id) === String(id));

    return incoterm ? `${incoterm.code ?? incoterm.id} - ${incoterm.name}` : id || '-';
}

function currencyOptionLabel(currency: SelectOption): string {
    const code = currency.code ?? String(currency.id);
    const symbol = currency.symbol ? ` (${currency.symbol})` : '';

    return `${code} - ${currency.name}${symbol}`;
}

function responsibilityLabel(value: string): string {
    if (value === 'supplier') {
        return 'Supplier / Manufacturer Responsibility';
    }

    return options.value.delivery_responsibilities.find((item) => String(item.id) === value)?.name ?? value;
}

function paymentCustomerTypeLabel(value: string): string {
    return options.value.payment_customer_types.find((item) => String(item.id) === value)?.name ?? (value === 'paying' ? 'Full Paying Customer' : 'Credit Customer');
}

function paymentMethodLabel(value: string): string {
    return options.value.payment_methods.find((item) => String(item.id) === value)?.name ?? value;
}

function paymentDueEventLabel(value: string): string {
    return options.value.payment_due_events.find((item) => String(item.id) === value)?.name ?? value;
}

function paymentScheduleDueText(schedule: PaymentScheduleForm): string {
    if (schedule.due_timing === 'fixed_date') {
        return schedule.due_date ? `On ${schedule.due_date}` : 'Fixed date not set';
    }

    if (!schedule.due_event) {
        return 'Trigger not set';
    }

    const label = paymentDueEventLabel(schedule.due_event).toLowerCase();
    const days = Number(schedule.due_offset_days || 0);

    return schedule.due_event.startsWith('on_') || days === 0 ? label : `${days} days ${label}`;
}

function lineTotal(item: QuotationItemForm): number {
    const gross = Number(item.quantity || 0) * Number(item.unit_price || 0);
    const vatRate = Number(item.vat_rate || 0);

    if (form.vat_pricing === 'inclusive' && vatRate > 0) {
        return gross / (1 + vatRate / 100);
    }

    return gross;
}

function lineVatAmount(item: QuotationItemForm): number {
    const gross = Number(item.quantity || 0) * Number(item.unit_price || 0);

    if (form.vat_pricing === 'inclusive') {
        return Math.max(0, gross - lineTotal(item));
    }

    return lineTotal(item) * (Number(item.vat_rate || 0) / 100);
}

function lineTotalWithVat(item: QuotationItemForm): number {
    return form.vat_pricing === 'inclusive'
        ? Number(item.quantity || 0) * Number(item.unit_price || 0)
        : lineTotal(item) + lineVatAmount(item);
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
        product_code: '',
        product_name: '',
        title: '',
        buyer_description: '',
        quantity: 1,
        uom: 'EA',
        incoterm_id: form.incoterm_id ? String(form.incoterm_id) : '',
        unit_price: 0,
        vat_rate: 0,
    });
}

function removeItem(index: number): void {
    items.value.splice(index, 1);
}

function addCharge(): void {
    charges.value.push({
        key: Date.now() + charges.value.length,
        label: '',
        amount: 0,
    });
}

function removeCharge(index: number): void {
    charges.value.splice(index, 1);
}

function addDiscount(): void {
    discounts.value.push({
        key: Date.now() + discounts.value.length,
        label: '',
        discount_type: 'fixed',
        amount: 0,
    });
}

function removeDiscount(index: number): void {
    discounts.value.splice(index, 1);
}

function defaultPaymentSchedules(type = form.payment_customer_type): PaymentScheduleForm[] {
    if (type === 'paying') {
        return [
            {
                key: Date.now(),
                label: 'Advance payment',
                payment_method: 'bank_transfer',
                payment_percentage: 50,
                due_timing: 'relative',
                due_event: 'before_delivery',
                due_offset_days: 0,
                due_date: '',
                notes: '',
            },
            {
                key: Date.now() + 1,
                label: 'Balance payment',
                payment_method: 'bank_transfer',
                payment_percentage: 50,
                due_timing: 'relative',
                due_event: 'on_delivery',
                due_offset_days: 0,
                due_date: '',
                notes: '',
            },
        ];
    }

    return [
        {
            key: Date.now(),
            label: 'Check 1',
            payment_method: 'cheque',
            payment_percentage: 100,
            due_timing: 'relative',
            due_event: 'after_invoice',
            due_offset_days: Number(form.payment_term_days || 0),
            due_date: '',
            notes: '',
        },
    ];
}

function initializePaymentSchedules(type = form.payment_customer_type): void {
    paymentSchedules.value = defaultPaymentSchedules(type);
}

function rebalancePaymentPercentages(): void {
    if (paymentSchedules.value.length === 0) {
        return;
    }

    const base = Math.floor((100 / paymentSchedules.value.length) * 1000) / 1000;
    let allocated = 0;

    paymentSchedules.value = paymentSchedules.value.map((schedule, index) => {
        const percentage = index === paymentSchedules.value.length - 1 ? Number((100 - allocated).toFixed(3)) : base;
        allocated += percentage;

        return {
            ...schedule,
            payment_percentage: percentage,
        };
    });
}

function addPaymentSchedule(): void {
    const nextIndex = paymentSchedules.value.length + 1;
    paymentSchedules.value.push({
        key: Date.now() + paymentSchedules.value.length,
        label: form.payment_customer_type === 'credit' ? `Check ${nextIndex}` : `Payment ${nextIndex}`,
        payment_method: form.payment_customer_type === 'credit' ? 'cheque' : 'bank_transfer',
        payment_percentage: 0,
        due_timing: 'relative',
        due_event: form.payment_customer_type === 'credit' ? 'after_arrival' : 'after_delivery',
        due_offset_days: form.payment_customer_type === 'credit' ? 30 : 0,
        due_date: '',
        notes: '',
    });
    rebalancePaymentPercentages();
}

function removePaymentSchedule(index: number): void {
    paymentSchedules.value.splice(index, 1);
    rebalancePaymentPercentages();
}

function preparedPaymentSchedules(): Array<{
    label: string;
    payment_method: string;
    payment_percentage: number;
    due_timing: string;
    due_event: string | null;
    due_offset_days: number | null;
    due_date: string | null;
    notes: string | null;
}> {
    return paymentSchedules.value.map((schedule) => ({
        label: schedule.label.trim(),
        payment_method: schedule.payment_method,
        payment_percentage: Number(schedule.payment_percentage),
        due_timing: schedule.due_timing,
        due_event: schedule.due_timing === 'relative' ? schedule.due_event : null,
        due_offset_days: schedule.due_timing === 'relative' ? Number(schedule.due_offset_days || 0) : null,
        due_date: schedule.due_timing === 'fixed_date' ? schedule.due_date : null,
        notes: schedule.notes.trim() || null,
    }));
}

function initializeRequiredTerms(defaults: TermDefault[]): void {
    requiredTerms.value = defaults.map((term, index) => ({
        key: term.key,
        title: term.title,
        description: '',
        isDefault: true,
        required: term.required ?? true,
        localKey: Date.now() + index,
    }));
}

function addCustomTerm(): void {
    customTerms.value.push({
        key: null,
        title: '',
        description: '',
        isDefault: false,
        required: false,
        localKey: Date.now() + customTerms.value.length,
    });
}

function removeCustomTerm(index: number): void {
    customTerms.value.splice(index, 1);
}

function preparedTerms(): Array<{ key: string | null; title: string; description: string }> {
    return [
        ...requiredTerms.value
            .filter((term) => term.required || hasRichTextContent(term.description))
            .map((term) => ({
                key: term.key,
                title: term.title.trim(),
                description: term.description.trim(),
            })),
        ...customTerms.value
            .filter((term) => term.title.trim() || hasRichTextContent(term.description))
            .map((term) => ({
                key: null,
                title: term.title.trim(),
                description: term.description.trim(),
            })),
    ];
}

function populateExistingQuotation(detail: ExistingQuotationDetail): void {
    currentQuotation.value = detail;
    isHydratingQuotation = true;
    try {
        form.buyer_company_id = String(detail.buyer_company_id);
        form.buyer_contact_id = String(detail.buyer_contact_id);
        form.rfq_number = detail.rfq_number ?? '';
        form.pr_number = detail.pr_number ?? '';
        form.rfq_title = detail.rfq_title ?? '';
        form.closing_at = localDateTime(detail.closing_at);
        form.quotation_validity_value = Number(detail.quotation_validity_value);
        form.quotation_validity_unit = detail.quotation_validity_unit;
        form.payment_term_days = Number(detail.payment_term_days);
        form.payment_terms_extra = detail.payment_terms_extra ?? '';
        form.payment_customer_type = detail.payment_customer_type ?? 'credit';
        form.delivery_period_min = Number(detail.delivery_period_min);
        form.delivery_period_max = Number(detail.delivery_period_max);
        form.delivery_period_unit = 'weeks';
        form.delivery_period_type = detail.delivery_period_type;
        form.accepted_invoice_currency = detail.accepted_invoice_currency;
        form.vat_pricing = detail.vat_pricing ?? 'exclusive';
        form.incoterm_id = String(detail.incoterm_id);
        form.delivery_responsibility = detail.delivery_responsibility;
    } finally {
        isHydratingQuotation = false;
    }

    items.value = detail.items.map((item, index) => ({
        key: Date.now() + index,
        manufacturer_id: String(item.manufacturer_id),
        product_code: item.product_code ?? '',
        product_name: item.product_name,
        title: item.title,
        buyer_description: item.buyer_description ?? '',
        quantity: Number(item.quantity),
        uom: item.uom,
        incoterm_id: item.incoterm_id ? String(item.incoterm_id) : String(detail.incoterm_id),
        unit_price: Number(item.unit_price),
        vat_rate: Number(item.vat_rate ?? 0),
    }));
    charges.value = (detail.charges ?? []).map((charge, index) => ({
        key: Date.now() + 600 + index,
        label: charge.label,
        amount: Number(charge.amount),
    }));
    discounts.value = (detail.discounts ?? []).map((discount, index) => ({
        key: Date.now() + 700 + index,
        label: discount.label,
        discount_type: discount.discount_type,
        amount: Number(discount.amount),
    }));

    paymentSchedules.value = detail.payment_schedules.length > 0
        ? detail.payment_schedules.map((schedule, index) => ({
            key: Date.now() + 300 + index,
            label: schedule.label,
            payment_method: schedule.payment_method,
            payment_percentage: Number(schedule.payment_percentage),
            due_timing: schedule.due_timing,
            due_event: schedule.due_event ?? '',
            due_offset_days: Number(schedule.due_offset_days ?? 0),
            due_date: schedule.due_date ?? '',
            notes: schedule.notes ?? '',
        }))
        : defaultPaymentSchedules(form.payment_customer_type);
    hasSavedPaymentSchedule.value = detail.payment_schedules.length > 0;

    const defaults = options.value.term_defaults.length > 0 ? options.value.term_defaults : fallbackTermDefaults;
    requiredTerms.value = defaults.map((term, index) => {
        const existing = detail.terms.find((candidate) => candidate.key === term.key);

        return {
            key: term.key,
            title: term.title,
            description: existing?.description ?? '',
            isDefault: true,
            required: term.required ?? true,
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
            required: false,
            localKey: Date.now() + 100 + index,
        }));
    hasSavedTerms.value = defaults
        .filter((term) => term.required ?? true)
        .every((term) => detail.terms.some((candidate) => candidate.key === term.key && hasRichTextContent(candidate.description)));
    hasSavedItems.value = detail.items.length > 0;
    savedCommercialSnapshot.value = commercialSnapshot();
    savedPaymentScheduleSnapshot.value = hasSavedPaymentSchedule.value ? paymentScheduleSnapshot() : null;
    savedItemsSnapshot.value = hasSavedItems.value ? itemsSnapshot() : null;
    savedTermsSnapshot.value = hasSavedTerms.value ? termsSnapshot() : null;
    paymentTypeRequiresScheduleReset.value = false;
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

        if (paymentSchedules.value.length === 0) {
            initializePaymentSchedules();
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
        const hadUnsavedPaymentChanges = paymentScheduleDirty.value;
        const payload = await requestJson<{ message: string; data: QuotationRecord }>(isUpdating ? `/api/quotations/${currentQuotation.value?.id}` : '/api/quotations', {
            method: isUpdating ? 'PUT' : 'POST',
            body: JSON.stringify({
                buyer_company_id: Number(form.buyer_company_id),
                buyer_contact_id: Number(form.buyer_contact_id),
                rfq_number: form.rfq_number.trim() || null,
                pr_number: form.pr_number.trim() || null,
                rfq_title: form.rfq_title.trim() || null,
                closing_at: apiDateTime(form.closing_at),
                quotation_validity_value: Number(form.quotation_validity_value),
                quotation_validity_unit: form.quotation_validity_unit,
                payment_term_days: Number(form.payment_term_days),
                payment_terms_extra: form.payment_terms_extra.trim() || null,
                payment_customer_type: form.payment_customer_type,
                delivery_period_min: Number(form.delivery_period_min),
                delivery_period_max: Number(form.delivery_period_max),
                delivery_period_unit: 'weeks',
                delivery_period_type: form.delivery_period_type,
                accepted_invoice_currency: form.accepted_invoice_currency,
                incoterm_id: Number(form.incoterm_id),
                delivery_responsibility: form.delivery_responsibility,
            }),
        });

        currentQuotation.value = payload.data;
        form.payment_terms_extra = payload.data.payment_terms_extra ?? form.payment_terms_extra;
        form.rfq_title = payload.data.rfq_title ?? form.rfq_title;
        savedCommercialSnapshot.value = commercialSnapshot();
        if (!isUpdating) {
            initializePaymentSchedules(form.payment_customer_type);
            hasSavedPaymentSchedule.value = false;
            savedPaymentScheduleSnapshot.value = null;
            paymentTypeRequiresScheduleReset.value = false;
            await router.replace({ name: 'quotations-edit', params: { id: payload.data.id } });
        } else if (paymentTypeRequiresScheduleReset.value) {
            initializePaymentSchedules(form.payment_customer_type);
            hasSavedPaymentSchedule.value = false;
            savedPaymentScheduleSnapshot.value = null;
            paymentTypeRequiresScheduleReset.value = false;
        } else if (!hadUnsavedPaymentChanges && payload.data.payment_schedules.length > 0) {
            paymentSchedules.value = payload.data.payment_schedules.map((schedule, index) => ({
                key: Date.now() + index,
                label: schedule.label,
                payment_method: schedule.payment_method,
                payment_percentage: Number(schedule.payment_percentage),
                due_timing: schedule.due_timing,
                due_event: schedule.due_event ?? '',
                due_offset_days: Number(schedule.due_offset_days ?? 0),
                due_date: schedule.due_date ?? '',
                notes: schedule.notes ?? '',
            }));
            hasSavedPaymentSchedule.value = true;
            savedPaymentScheduleSnapshot.value = paymentScheduleSnapshot();
        } else if (!hadUnsavedPaymentChanges) {
            hasSavedPaymentSchedule.value = false;
            savedPaymentScheduleSnapshot.value = null;
        }
        activeStep.value = 2;
        createdVersion.value = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save quotation.');
    } finally {
        isSavingStepOne.value = false;
    }
}

async function submitPaymentSchedule(): Promise<void> {
    if (!canSavePaymentSchedule.value || !currentQuotation.value) {
        return;
    }

    isSavingPaymentSchedule.value = true;

    try {
        const payload = await requestJson<{ message: string; data: QuotationRecord }>(`/api/quotations/${currentQuotation.value.id}/payment-schedule`, {
            method: 'POST',
            body: JSON.stringify({
                payment_schedules: preparedPaymentSchedules(),
            }),
        });

        currentQuotation.value = payload.data;
        form.payment_term_days = Number(payload.data.payment_term_days ?? form.payment_term_days);
        savedCommercialSnapshot.value = commercialSnapshot();
        paymentSchedules.value = payload.data.payment_schedules.map((schedule, index) => ({
            key: Date.now() + index,
            label: schedule.label,
            payment_method: schedule.payment_method,
            payment_percentage: Number(schedule.payment_percentage),
            due_timing: schedule.due_timing,
            due_event: schedule.due_event ?? '',
            due_offset_days: Number(schedule.due_offset_days ?? 0),
            due_date: schedule.due_date ?? '',
            notes: schedule.notes ?? '',
        }));
        hasSavedPaymentSchedule.value = true;
        savedPaymentScheduleSnapshot.value = paymentScheduleSnapshot();
        paymentTypeRequiresScheduleReset.value = false;
        createdVersion.value = null;
        activeStep.value = 3;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save payment plan.');
    } finally {
        isSavingPaymentSchedule.value = false;
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
                vat_pricing: form.vat_pricing,
                items: items.value.map((item) => ({
                    manufacturer_id: Number(item.manufacturer_id),
                    product_code: item.product_code.trim(),
                    product_name: item.product_name.trim(),
                    title: item.title.trim(),
                    buyer_description: item.buyer_description,
                    quantity: Number(item.quantity),
                    uom: item.uom.trim(),
                    incoterm_id: Number(item.incoterm_id),
                    unit_price: Number(item.unit_price),
                    vat_rate: Number(item.vat_rate),
                })),
                charges: charges.value.map((charge) => ({
                    label: charge.label.trim(),
                    amount: Number(charge.amount || 0),
                })),
                discounts: discounts.value.map((discount) => ({
                    label: discount.label.trim(),
                    discount_type: discount.discount_type,
                    amount: Number(discount.amount || 0),
                })),
            }),
        });

        hasSavedItems.value = true;
        savedItemsSnapshot.value = itemsSnapshot();
        activeStep.value = 4;
        hasSavedTerms.value = false;
        savedTermsSnapshot.value = null;
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

        hasSavedTerms.value = true;
        savedTermsSnapshot.value = termsSnapshot();
        createdVersion.value = null;
        activeStep.value = 5;
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

async function downloadVersion(format: 'docx' | 'pdf', documentType: 'commercial' | 'technical' = 'commercial'): Promise<void> {
    if (!createdVersion.value) {
        return;
    }

    try {
        const filename = `${createdVersion.value.quotation_reference}-${documentType}-rev-${createdVersion.value.version_number}.${format}`;
        const url = createdVersion.value.downloads[documentType]?.[format] ?? createdVersion.value.downloads[format];
        await downloadProtectedFile(
            url,
            format === 'docx' ? filename.toUpperCase() : filename,
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

watch(
    () => form.payment_customer_type,
    (type, previousType) => {
        if (isHydratingQuotation || type === previousType) {
            return;
        }

        initializePaymentSchedules(type);
        hasSavedPaymentSchedule.value = false;
        savedPaymentScheduleSnapshot.value = null;
        createdVersion.value = null;

        if (currentQuotation.value) {
            paymentTypeRequiresScheduleReset.value = true;
            showToast('success', `Payment type changed to ${paymentCustomerTypeLabel(type)}. Save the commercial details and the new payment plan before review.`);
        }
    },
    { flush: 'sync' },
);

watch(
    [commercialDirty, paymentScheduleDirty, itemsDirty, termsDirty],
    (dirtySections) => {
        if (dirtySections.some(Boolean)) {
            createdVersion.value = null;
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
            <button type="button" :class="{ active: activeStep === 1, done: Boolean(currentQuotation) && !commercialDirty }" @click="activeStep = 1">
                <strong>1</strong>
                Buyer & Commercial
            </button>
            <button type="button" :class="{ active: activeStep === 2, disabled: !currentQuotation || commercialDirty }" :disabled="!currentQuotation || commercialDirty" @click="activeStep = 2">
                <strong>2</strong>
                Payment Plan
            </button>
            <button
                type="button"
                :class="{ active: activeStep === 3, disabled: !currentQuotation || commercialDirty || !paymentScheduleSaved }"
                :disabled="!currentQuotation || commercialDirty || !paymentScheduleSaved"
                @click="activeStep = 3"
            >
                <strong>3</strong>
                Products
            </button>
            <button type="button" :class="{ active: activeStep === 4, done: termsSaved, disabled: !itemsSaved }" :disabled="!itemsSaved" @click="activeStep = 4">
                <strong>4</strong>
                Terms & Conditions
            </button>
            <button type="button" :class="{ active: activeStep === 5, disabled: !canReview }" :disabled="!canReview" @click="activeStep = 5">
                <strong>5</strong>
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
                        <span>RFQ Title</span>
                        <input v-model.trim="form.rfq_title" type="text" placeholder="RFQ 6000024422 PR 11729328" maxlength="255" aria-label="RFQ Title" />
                    </label>

                    <label class="quote-field">
                        <span>Closing Date & Time</span>
                        <input v-model="form.closing_at" type="datetime-local" aria-label="Closing Date and Time" />
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
                        <span>Customer Payment Type<b>*</b></span>
                        <select v-model="form.payment_customer_type" required aria-label="Customer Payment Type">
                            <option v-for="type in options.payment_customer_types" :key="String(type.id)" :value="type.id">
                                {{ type.name }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Payment Days<b>*</b></span>
                        <input v-model.number="form.payment_term_days" type="number" min="0" max="3650" required aria-label="Payment days from invoice" />
                    </label>

                    <div class="quote-field payment-terms-preview">
                        <span>Accepted Terms of Payment</span>
                        <strong>{{ acceptedPaymentTermsText }}</strong>
                    </div>

                    <label class="quote-field payment-terms-extra-field">
                        <span>Additional Payment Wording</span>
                        <textarea
                            v-model.trim="form.payment_terms_extra"
                            rows="3"
                            maxlength="2000"
                            placeholder="Optional text to append after the invoice-days sentence"
                            aria-label="Additional payment wording"
                        ></textarea>
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
                            <input value="Weeks" type="text" readonly aria-label="Delivery period unit" />
                        </div>
                    </label>

                    <label class="quote-field">
                        <span>Accepted Invoice Currency<b>*</b></span>
                        <select v-model="form.accepted_invoice_currency" required aria-label="Accepted Invoice Currency">
                            <option v-for="currency in options.currencies" :key="String(currency.id)" :value="currency.id">
                                {{ currencyOptionLabel(currency) }}
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
                            <dt>RFQ Title</dt>
                            <dd>{{ rfqTitlePreview }}</dd>
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
                            <dd>{{ selectedPaymentCustomerType?.name ?? paymentCustomerTypeLabel(form.payment_customer_type) }}</dd>
                        </div>
                        <div>
                            <dt>Accepted Terms</dt>
                            <dd>{{ acceptedPaymentTermsText }}</dd>
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
                    <strong class="currency-preview">{{ selectedCurrency ? currencyOptionLabel(selectedCurrency) : form.accepted_invoice_currency }}</strong>
                </section>
            </aside>
        </div>

        <section v-else-if="activeStep === 2" class="quotation-form-panel quotation-payment-panel">
            <header>
                <span class="panel-mark">
                    <CreditCard :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2>Step 2</h2>
                    <p>{{ currentQuotation?.quotation_reference }} payment agreement for {{ paymentCustomerTypeLabel(form.payment_customer_type) }}</p>
                </div>
                <button class="secondary-action icon-gap" type="button" @click="addPaymentSchedule">
                    <Plus :size="17" aria-hidden="true" />
                    Add Payment
                </button>
            </header>

            <div class="payment-accepted-terms-card">
                <span>Accepted Terms of Payment</span>
                <strong>{{ acceptedPaymentTermsText }}</strong>
                <small>Edit days or extra wording in Step 1. The detailed schedule below is for follow-up notifications and payment tracking.</small>
            </div>

            <div class="payment-schedule-body">
                <article v-for="(schedule, index) in paymentSchedules" :key="schedule.key" class="payment-schedule-row">
                    <div class="line-item-head">
                        <strong>{{ schedule.label || `Payment ${index + 1}` }}</strong>
                        <button v-if="paymentSchedules.length > 1" type="button" aria-label="Remove payment schedule" @click="removePaymentSchedule(index)">
                            <Trash2 :size="16" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="payment-schedule-grid">
                        <label class="quote-field">
                            <span>Label<b>*</b></span>
                            <input v-model.trim="schedule.label" type="text" required :aria-label="`Payment ${index + 1} label`" />
                        </label>

                        <label class="quote-field">
                            <span>Method<b>*</b></span>
                            <select v-model="schedule.payment_method" required :aria-label="`Payment ${index + 1} method`">
                                <option v-for="method in options.payment_methods" :key="String(method.id)" :value="method.id">
                                    {{ method.name }}
                                </option>
                            </select>
                        </label>

                        <label class="quote-field">
                            <span>Percentage<b>*</b></span>
                            <input
                                v-model.number="schedule.payment_percentage"
                                type="number"
                                min="0.001"
                                max="100"
                                step="0.001"
                                required
                                :aria-label="`Payment ${index + 1} percentage`"
                            />
                        </label>

                        <label class="quote-field">
                            <span>Due Basis<b>*</b></span>
                            <select v-model="schedule.due_timing" required :aria-label="`Payment ${index + 1} due basis`">
                                <option value="relative">Based on delivery / arrival</option>
                                <option value="fixed_date">Fixed date</option>
                            </select>
                        </label>

                        <label v-if="schedule.due_timing === 'relative'" class="quote-field compact-pair payment-trigger-field">
                            <span>Trigger<b>*</b></span>
                            <input
                                v-model.number="schedule.due_offset_days"
                                type="number"
                                min="0"
                                max="3650"
                                :disabled="schedule.due_event.startsWith('on_')"
                                :aria-label="`Payment ${index + 1} offset days`"
                            />
                            <select v-model="schedule.due_event" required :aria-label="`Payment ${index + 1} trigger`">
                                <option value="">Select trigger</option>
                                <option v-for="event in options.payment_due_events" :key="String(event.id)" :value="event.id">
                                    {{ event.name }}
                                </option>
                            </select>
                        </label>

                        <label v-else class="quote-field">
                            <span>Payment Date<b>*</b></span>
                            <input v-model="schedule.due_date" type="date" required :aria-label="`Payment ${index + 1} fixed date`" />
                        </label>

                        <label class="quote-field payment-notes-field">
                            <span>Notes</span>
                            <input v-model.trim="schedule.notes" type="text" placeholder="Optional agreement note" />
                        </label>
                    </div>

                    <p class="schedule-footer-note">
                        {{ Number(schedule.payment_percentage || 0).toFixed(3) }}% by {{ paymentMethodLabel(schedule.payment_method) }},
                        {{ paymentScheduleDueText(schedule) }}
                    </p>
                </article>
            </div>

            <footer class="items-footer terms-footer">
                <div>
                    <span>Payment Type</span>
                    <strong>{{ paymentCustomerTypeLabel(form.payment_customer_type) }}</strong>
                    <span>Total</span>
                    <strong :class="{ 'text-danger': Math.abs(paymentScheduleTotal - 100) > 0.01 }">{{ paymentScheduleTotal.toFixed(3) }}%</strong>
                </div>
                <div class="terms-actions">
                    <button class="secondary-action" type="button" @click="activeStep = 1">Edit Commercial</button>
                    <button class="primary-action compact-action" type="button" :disabled="!canSavePaymentSchedule" @click="submitPaymentSchedule">
                        <Loader2 v-if="isSavingPaymentSchedule" class="spin-icon" :size="17" aria-hidden="true" />
                        <Save v-else :size="17" aria-hidden="true" />
                        {{ isSavingPaymentSchedule ? 'Saving...' : 'Save Payment Plan' }}
                    </button>
                </div>
            </footer>

            <div v-if="paymentScheduleSaved" class="terms-saved-banner">
                <CheckCircle2 :size="18" aria-hidden="true" />
                Payment plan has been saved for this quotation.
            </div>
        </section>

        <section v-else-if="activeStep === 3" class="quotation-form-panel quotation-items-panel">
            <header>
                <span class="panel-mark">
                    <FileText :size="20" aria-hidden="true" />
                </span>
                <div class="quotation-total-stack">
                    <h2>Step 3</h2>
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

            <div class="quotation-adjustments-panel">
                <fieldset class="quote-field responsibility-field">
                    <legend>VAT Pricing<b>*</b></legend>
                    <label>
                        <input v-model="form.vat_pricing" type="radio" name="vat_pricing" value="exclusive" />
                        <span>Unit prices are VAT exclusive</span>
                    </label>
                    <label>
                        <input v-model="form.vat_pricing" type="radio" name="vat_pricing" value="inclusive" />
                        <span>Unit prices are VAT inclusive</span>
                    </label>
                </fieldset>
            </div>

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
                            <span>Material / Item Code<b>*</b></span>
                            <input v-model.trim="item.product_code" type="text" maxlength="100" required :aria-label="`Line ${index + 1} Material / Item Code`" />
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
                            <select v-model="item.uom" required :aria-label="`Line ${index + 1} UOM`">
                                <option value="">Select UOM</option>
                                <option v-for="uom in options.uoms" :key="String(uom.id)" :value="String(uom.id)">
                                    {{ uom.code ?? uom.id }} - {{ uom.name }}
                                </option>
                            </select>
                        </label>
                        <label class="quote-field">
                            <span>Incoterm<b>*</b></span>
                            <select v-model="item.incoterm_id" required :aria-label="`Line ${index + 1} Incoterm`">
                                <option value="">Select Incoterm</option>
                                <option v-for="incoterm in options.incoterms" :key="String(incoterm.id)" :value="String(incoterm.id)">
                                    {{ incoterm.code }} - {{ incoterm.name }}
                                </option>
                            </select>
                        </label>
                        <label class="quote-field">
                            <span>Unit Price ({{ form.vat_pricing === 'inclusive' ? 'VAT incl.' : 'VAT excl.' }})<b>*</b></span>
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
                            <span>Customer Description<b>*</b></span>
                            <RichTextEditor v-model="item.buyer_description" placeholder="Description visible on the quotation" />
                        </label>
                    </div>
                </article>
            </div>

            <section class="quotation-adjustments-panel quotation-extra-adjustments">
                <div class="terms-section-title">
                    <h3>Additional Charges</h3>
                    <button class="secondary-action icon-gap" type="button" @click="addCharge">
                        <Plus :size="16" aria-hidden="true" />
                        Add Charge
                    </button>
                </div>
                <div v-if="charges.length === 0" class="terms-empty">No additional charges added.</div>
                <article v-for="(charge, index) in charges" :key="charge.key" class="adjustment-row">
                    <label class="quote-field">
                        <span>Charge Name<b>*</b></span>
                        <input v-model.trim="charge.label" type="text" maxlength="150" placeholder="Freight, certification, handling..." />
                    </label>
                    <label class="quote-field">
                        <span>Amount<b>*</b></span>
                        <input v-model.number="charge.amount" type="number" min="0" step="0.001" />
                    </label>
                    <button type="button" aria-label="Remove charge" @click="removeCharge(index)">
                        <Trash2 :size="16" aria-hidden="true" />
                    </button>
                </article>
            </section>

            <section class="quotation-adjustments-panel quotation-extra-adjustments">
                <div class="terms-section-title">
                    <h3>Discounts</h3>
                    <button class="secondary-action icon-gap" type="button" @click="addDiscount">
                        <Plus :size="16" aria-hidden="true" />
                        Add Discount
                    </button>
                </div>
                <div v-if="discounts.length === 0" class="terms-empty">No discounts added.</div>
                <article v-for="(discount, index) in discounts" :key="discount.key" class="adjustment-row">
                    <label class="quote-field">
                        <span>Discount Name<b>*</b></span>
                        <input v-model.trim="discount.label" type="text" maxlength="150" placeholder="Commercial discount" />
                    </label>
                    <label class="quote-field">
                        <span>Type<b>*</b></span>
                        <select v-model="discount.discount_type">
                            <option value="fixed">Fixed amount</option>
                            <option value="percentage">Percentage</option>
                        </select>
                    </label>
                    <label class="quote-field">
                        <span>{{ discount.discount_type === 'percentage' ? 'Percentage' : 'Amount' }}<b>*</b></span>
                        <input v-model.number="discount.amount" type="number" min="0" :max="discount.discount_type === 'percentage' ? 100 : undefined" step="0.001" />
                    </label>
                    <button type="button" aria-label="Remove discount" @click="removeDiscount(index)">
                        <Trash2 :size="16" aria-hidden="true" />
                    </button>
                </article>
            </section>

            <footer class="items-footer">
                <div class="quotation-total-stack">
                    <span>Subtotal excl. VAT</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(quotationSubtotal) }}</strong>
                    <span>VAT after discounts</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(quotationVatTotal) }}</strong>
                    <span>Charges</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(chargesTotal) }}</strong>
                    <span>Discounts</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(discountsTotal) }}</strong>
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

        <section v-else-if="activeStep === 4" class="quotation-form-panel quotation-terms-panel">
            <header>
                <span class="panel-mark">
                    <FileText :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2>Step 4</h2>
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
                        <span>Warranty optional</span>
                    </div>

                    <div v-for="term in requiredTerms" :key="term.localKey" class="term-row">
                        <strong>
                            {{ term.title }}
                            <small>{{ term.required ? 'Required' : 'Optional' }}</small>
                        </strong>
                        <RichTextEditor v-model="term.description" :placeholder="term.required ? 'Enter required clause text' : 'Optional warranty clause'" />
                    </div>
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

                        <label class="quote-field custom-term-editor">
                            <span>Description<b>*</b></span>
                            <RichTextEditor v-model="term.description" :placeholder="`Additional term ${index + 1} description`" />
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
                    <strong>{{ preparedTerms().length }}</strong>
                </div>
                <div class="terms-actions">
                    <button class="secondary-action" type="button" @click="activeStep = 3">Edit Products</button>
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
                            <dt>RFQ Title</dt>
                            <dd>{{ rfqTitlePreview }}</dd>
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
                            <dd>{{ paymentCustomerTypeLabel(form.payment_customer_type) }}</dd>
                        </div>
                        <div>
                            <dt>VAT Pricing</dt>
                            <dd>{{ form.vat_pricing === 'inclusive' ? 'VAT Inclusive' : 'VAT Exclusive' }}</dd>
                        </div>
                        <div>
                            <dt>Accepted Terms</dt>
                            <dd>{{ acceptedPaymentTermsText }}</dd>
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
                    <h3>Payment Plan</h3>
                    <div class="review-list compact">
                        <article v-for="schedule in paymentSchedules" :key="schedule.key">
                            <strong>{{ schedule.label }} - {{ Number(schedule.payment_percentage || 0).toFixed(3) }}%</strong>
                            <span>{{ paymentMethodLabel(schedule.payment_method) }} - {{ paymentScheduleDueText(schedule) }}</span>
                        </article>
                    </div>
                </section>

                <section class="review-block">
                    <h3>Products</h3>
                    <div class="review-list">
                        <article v-for="(item, index) in items" :key="item.key">
                            <strong>{{ index + 1 }}. {{ item.product_code }} - {{ item.title }}</strong>
                            <span>{{ optionName(options.manufacturers, item.manufacturer_id) }} - {{ item.quantity }} {{ item.uom }} x {{ money(item.unit_price) }} + {{ money(item.vat_rate) }}% VAT</span>
                            <small>Delivery: {{ form.delivery_period_min }} to {{ form.delivery_period_max }} {{ form.delivery_period_type }} weeks | {{ incotermOptionLabel(item.incoterm_id) }}</small>
                            <b>{{ form.accepted_invoice_currency }} {{ money(lineTotalWithVat(item)) }}</b>
                        </article>
                    </div>
                    <div v-if="charges.length || discounts.length" class="review-list compact">
                        <article v-for="charge in charges" :key="charge.key">
                            <strong>{{ charge.label }}</strong>
                            <span>Additional charge</span>
                            <b>{{ form.accepted_invoice_currency }} {{ money(charge.amount) }}</b>
                        </article>
                        <article v-for="discount in discounts" :key="discount.key">
                            <strong>{{ discount.label }}</strong>
                            <span>{{ discount.discount_type === 'percentage' ? `${money(discount.amount)}% discount` : 'Fixed discount' }}</span>
                            <b>-{{ form.accepted_invoice_currency }} {{ money(discount.discount_type === 'percentage' ? discountBase * Math.min(Math.max(Number(discount.amount || 0), 0), 100) / 100 : Math.min(Number(discount.amount || 0), discountBase)) }}</b>
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
                            <span v-html="sanitizeRichTextHtml(term.description)"></span>
                        </article>
                    </div>
                </section>

                <section v-if="createdVersion" class="review-block created-version-card">
                    <h3>{{ createdVersion.revision_action === 'refreshed' ? 'Revision Updated' : 'Revision Created' }}</h3>
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
                        <button class="secondary-action icon-gap" type="button" @click="downloadVersion('docx', 'commercial')">
                            <Download :size="17" aria-hidden="true" />
                            Commercial Word
                        </button>
                        <button class="secondary-action icon-gap" type="button" @click="downloadVersion('pdf', 'commercial')">
                            <Download :size="17" aria-hidden="true" />
                            Commercial PDF
                        </button>
                        <button class="secondary-action icon-gap" type="button" @click="downloadVersion('docx', 'technical')">
                            <Download :size="17" aria-hidden="true" />
                            Technical Word
                        </button>
                        <button class="secondary-action icon-gap" type="button" @click="downloadVersion('pdf', 'technical')">
                            <Download :size="17" aria-hidden="true" />
                            Technical PDF
                        </button>
                    </div>
                </section>
            </div>

            <footer class="items-footer terms-footer">
                <div>
                    <span>Revision Status</span>
                    <strong>{{ createdVersion ? `V${createdVersion.version_number}` : 'Ready' }}</strong>
                </div>
                <div class="terms-actions">
                    <button class="secondary-action" type="button" @click="activeStep = 4">Edit Terms</button>
                    <button class="primary-action compact-action" type="button" :disabled="!canFinalize" @click="finalizeQuotation">
                        <Loader2 v-if="isFinalizing" class="spin-icon" :size="17" aria-hidden="true" />
                        <Save v-else :size="17" aria-hidden="true" />
                        {{ isFinalizing ? 'Finalizing...' : isEditMode ? 'Finalize Changes' : 'Create Quotation' }}
                    </button>
                    <button v-if="createdVersion" class="secondary-action" type="button" @click="router.push(`/quotations/${currentQuotation?.id}`)">
                        View Details
                    </button>
                </div>
            </footer>
        </section>
    </section>
</template>
