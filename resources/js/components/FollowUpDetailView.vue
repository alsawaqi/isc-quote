<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardCheck,
    Download,
    Clock3,
    FileCheck2,
    Loader2,
    MessageSquarePlus,
    PackageCheck,
    ReceiptText,
    Save,
    Send,
    Truck,
    Upload,
    Warehouse,
} from 'lucide-vue-next';
import { currentUser, downloadProtectedFile, requestFormData, requestJson } from '../auth';
import { humanizeStatus } from '../utils/format';

type FollowUpStepKey = 'acknowledgement' | 'shipping' | 'logistics' | 'delivery' | 'invoice' | 'payment';

interface FollowUpComment {
    id: number;
    stage: FollowUpStepKey;
    stage_label: string;
    comment: string;
    communication_type: string | null;
    contacted_person: string | null;
    next_action: string | null;
    created_by_name: string | null;
    created_at: string | null;
}

interface FollowUpTimelineEvent {
    id: string;
    source: string;
    stage: string;
    stage_label: string;
    action: string;
    summary: string;
    user_name: string | null;
    properties: Record<string, unknown> | null;
    occurred_at: string | null;
    elapsed_from_previous_seconds: number | null;
    elapsed_from_previous_label: string | null;
}

interface FollowUpItem {
    id: number;
    supplier_po_reference: string | null;
    quotation_reference: string | null;
    quotation_delivery_responsibility: 'isc' | 'buyer' | 'supplier' | null;
    quotation_payment_term_days: number | null;
    quotation_payment_customer_type: string;
    quotation_payment_customer_type_label: string;
    quotation_payment_schedule_summary: string | null;
    quotation_payment_schedules: PaymentScheduleRecord[];
    payment_plan_follow_ups: PaymentPlanFollowUpRecord[];
    buyer_po_number: string | null;
    buyer_po_date: string | null;
    buyer_po_original_file_name: string | null;
    buyer_po_download_url: string | null;
    buyer_company_name: string | null;
    buyer_contact_name: string | null;
    supplier_company_name: string | null;
    supplier_contact_name: string | null;
    company_location_id: number | null;
    factory_name: string | null;
    factory_location: string | null;
    factory_country_name: string | null;
    factory_manufacturer_name: string | null;
    salesperson_name: string | null;
    assigned_to_name: string | null;
    status: string;
    current_stage: FollowUpStepKey;
    current_stage_label: string;
    product_code: string | null;
    product_name: string | null;
    title: string | null;
    description: string | null;
    quantity: string | null;
    uom: string | null;
    quotation_item_vat_rate: string;
    manufacturer_name: string | null;
    reminder_interval_value: number | null;
    reminder_interval_unit: string | null;
    next_follow_up_at: string | null;
    acknowledgement_received_at: string | null;
    acknowledgement_original_file_name: string | null;
    acknowledgement_download_url: string | null;
    acknowledgement_notes: string | null;
    acknowledged_by_name: string | null;
    closed_at: string | null;
    closed_notes: string | null;
    latest_comment: FollowUpComment | null;
    comments: FollowUpComment[];
    comments_by_stage: Record<FollowUpStepKey, FollowUpComment[]>;
    shipping_documents: ShippingDocument[];
    shipping_documents_complete: boolean;
    packing_list: PackingList | null;
    logistics_case: LogisticsCase | null;
    fulfilment: FulfilmentSummary;
    delivery_order: DeliveryOrder | null;
    delivery_orders: DeliveryOrder[];
    invoice: InvoiceRecord | null;
    invoices: InvoiceRecord[];
    timeline_events?: FollowUpTimelineEvent[];
}

interface FulfilmentSummary {
    ordered_quantity: string;
    supplier_quantity: string;
    received_quantity: string;
    remaining_receive_quantity: string;
    delivered_quantity: string;
    remaining_delivery_quantity: string;
    invoiced_quantity: string;
    remaining_invoice_quantity: string;
    quotation_total_amount: string;
    invoiced_amount: string;
    paid_amount: string;
    balance_amount: string;
    remaining_quotation_amount: string;
    uom: string | null;
    currency: string | null;
}

interface PaymentScheduleRecord {
    id: number;
    line_number: number;
    label: string;
    payment_method: string;
    payment_method_label: string;
    payment_percentage: string;
    due_timing: string;
    due_event: string | null;
    due_event_label: string;
    due_offset_days: number | null;
    due_date: string | null;
    due_text: string;
    notes: string | null;
    summary: string;
}

interface PaymentPlanAttachment {
    id: number;
    original_file_name: string | null;
    download_url: string | null;
    mime_type: string | null;
    file_size: number | null;
    remarks: string | null;
    uploaded_by_name: string | null;
    created_at: string | null;
}

interface PaymentPlanFollowUpRecord {
    id: number;
    quotation_payment_schedule_id: number;
    schedule: PaymentScheduleRecord | null;
    label: string;
    payment_method_label: string;
    payment_percentage: string;
    expected_amount: string | null;
    currency: string | null;
    due_text: string;
    due_date: string | null;
    due_state: 'paid' | 'overdue' | 'due_today' | 'upcoming' | 'unscheduled';
    status: 'pending' | 'paid' | string;
    paid_at: string | null;
    paid_amount: string | null;
    payment_reference: string | null;
    remarks: string | null;
    recorded_by_name: string | null;
    attachments: PaymentPlanAttachment[];
}

interface ShippingDocument {
    id: number;
    document_type: string;
    label: string;
    is_required: boolean;
    status: string;
    document_number: string | null;
    document_date: string | null;
    original_file_name: string | null;
    download_url: string | null;
    uploaded_by_name: string | null;
    uploaded_at: string | null;
}

interface PackingList {
    id: number;
    packing_list_reference: string;
    packing_list_date: string | null;
    package_size: string;
    gross_weight: string;
    net_weight: string;
    remarks: string | null;
    downloads: {
        docx: string;
        pdf: string;
    };
}

interface LogisticsEvent {
    id: number;
    event_type: string;
    title: string;
    event_at: string | null;
    notes: string | null;
    created_by_name: string | null;
}

interface LogisticsCase {
    id: number;
    delivery_responsibility: 'isc' | 'buyer_agent' | 'supplier';
    status: string;
    eta_at: string | null;
    agent_name: string | null;
    agent_contact: string | null;
    documents_sent_at: string | null;
    arrived_at: string | null;
    warehouse_received_at: string | null;
    buyer_received_at: string | null;
    received_quantity: string | null;
    goods_condition: string | null;
    received_location: string | null;
    remarks: string | null;
    created_by_name: string | null;
    events: LogisticsEvent[];
}

interface DeliveryOrder {
    id: number;
    delivery_order_reference: string;
    delivery_order_date: string | null;
    delivery_place: string;
    terms: string | null;
    status: string;
    signed_original_file_name: string | null;
    signed_download_url: string | null;
    signed_at: string | null;
    total_quantity: string;
    items: Array<{
        id: number;
        line_number: number;
        item_description: string;
        quantity: string;
        uom: string;
        buyer_po_number: string | null;
        buyer_item_code: string | null;
    }>;
    downloads: {
        docx: string;
        pdf: string;
    };
}

interface InvoiceRecord {
    id: number;
    invoice_reference: string;
    invoice_date: string | null;
    sent_at: string | null;
    payment_term_days: number;
    due_date: string | null;
    currency: string;
    subtotal: string;
    vat_rate: string;
    vat_amount: string;
    total_amount: string;
    paid_amount: string;
    balance_amount: string;
    payment_status: string;
    vat_exception_reason: string | null;
    bank_details: string | null;
    remarks: string | null;
    status: string;
    closed_at: string | null;
    buyer_po_number: string | null;
    delivery_order_reference: string | null;
    total_quantity: string;
    items: Array<{
        id: number;
        line_number: number;
        item_description: string;
        quantity: string;
        uom: string;
        unit_price: string;
        total_price: string;
        buyer_po_number: string | null;
        buyer_item_code: string | null;
    }>;
    payments: PaymentRecord[];
    downloads: {
        docx: string;
        pdf: string;
    };
}

interface PaymentRecord {
    id: number;
    amount: string;
    currency: string;
    payment_date: string | null;
    payment_reference: string | null;
    remarks: string | null;
    recorded_by_name: string | null;
    created_at: string | null;
}

type Toast = { type: 'error' | 'success'; message: string };

interface FollowUpWorkflowStep {
    key: FollowUpStepKey;
    label: string;
    caption: string;
    available: boolean;
    complete: boolean;
}

const route = useRoute();
const router = useRouter();
const item = ref<FollowUpItem | null>(null);
const activeFollowUpStep = ref<FollowUpStepKey>('acknowledgement');
const hasSelectedFollowUpStep = ref(false);
const isLoading = ref(false);
const isSavingReminder = ref(false);
const isSavingComment = ref(false);
const isSavingAcknowledgement = ref(false);
const isSavingDocumentType = ref<string | null>(null);
const isCompletingShippingDocuments = ref(false);
const isSavingLogistics = ref<string | null>(null);
const isSavingDeliveryOrder = ref(false);
const isUploadingSignedDeliveryOrder = ref(false);
const isSavingInvoice = ref(false);
const isMarkingInvoiceSent = ref(false);
const isSavingPayment = ref(false);
const isSavingPaymentPlanId = ref<number | null>(null);
const isUploadingPaymentPlanAttachmentId = ref<number | null>(null);
const isClosingJob = ref(false);
const toast = ref<Toast | null>(null);
const acknowledgementFile = ref<File | null>(null);
const signedDeliveryOrderFile = ref<File | null>(null);
const shippingDocumentFiles = reactive<Record<string, File | null>>({});
const shippingDocumentForms = reactive<Record<string, { document_number: string; document_date: string; remarks: string }>>({});
const paymentPlanFiles = reactive<Record<number, File | null>>({});
const paymentPlanForms = reactive<Record<number, { paid_amount: string; paid_at: string; payment_reference: string; remarks: string; attachment_remarks: string }>>({});

const reminderForm = reactive({
    reminder_interval_value: 2,
    reminder_interval_unit: 'weeks',
    next_follow_up_at: '',
});
const commentForm = reactive({
    comment: '',
    communication_type: 'email',
    contacted_person: '',
    next_action: '',
});
const acknowledgementForm = reactive({
    acknowledgement_received_at: '',
    acknowledgement_notes: '',
});
const logisticsForm = reactive({
    delivery_responsibility: 'isc' as LogisticsCase['delivery_responsibility'],
    eta_at: '',
    agent_name: '',
    agent_contact: '',
    remarks: '',
});
const logisticsEventForm = reactive({
    documents_sent_at: '',
    arrived_at: '',
    warehouse_received_at: '',
    buyer_received_at: '',
    received_location: '',
    received_quantity: '',
    goods_condition: '',
    remarks: '',
});
const deliveryOrderForm = reactive({
    delivery_place: '',
    quantity: '',
    terms: '',
    signed_at: '',
});
const invoiceForm = reactive({
    quantity: '',
    payment_term_days: 45,
    vat_rate: '0',
    vat_exception_reason: '',
    bank_details: '',
    remarks: '',
});
const paymentForm = reactive({
    invoice_id: null as number | null,
    amount: '',
    payment_date: '',
    payment_reference: '',
    remarks: '',
});
const closeForm = reactive({
    closed_notes: '',
});

const itemId = computed(() => Number(route.params.id));
const canSaveReminder = computed(() => {
    if (isSavingReminder.value) {
        return false;
    }

    if (reminderForm.reminder_interval_unit === 'custom') {
        return reminderForm.next_follow_up_at.length > 0;
    }

    return reminderForm.reminder_interval_value > 0;
});
const canSaveComment = computed(() => commentForm.comment.trim().length > 0 && !isSavingComment.value);
const canSaveAcknowledgement = computed(() => acknowledgementForm.acknowledgement_received_at.length > 0 && !isSavingAcknowledgement.value);
const requiredShippingDocuments = computed(() => item.value?.shipping_documents.filter((document) => document.is_required) ?? []);
const optionalTransportDocuments = computed(() => item.value?.shipping_documents.filter((document) => !document.is_required) ?? []);
const logisticsCase = computed(() => item.value?.logistics_case ?? null);
const canSaveEta = computed(
    () => Boolean(logisticsForm.eta_at.length > 0) && isSavingLogistics.value === null,
);
const canMarkDocumentsSent = computed(
    () => Boolean(logisticsCase.value && logisticsEventForm.documents_sent_at.length > 0) && isSavingLogistics.value === null,
);
const canMarkArrived = computed(() => Boolean(logisticsCase.value && logisticsEventForm.arrived_at.length > 0) && isSavingLogistics.value === null);
const canMarkWarehouseReceived = computed(
    () =>
        Boolean(
            logisticsCase.value &&
                logisticsEventForm.warehouse_received_at.length > 0 &&
                logisticsEventForm.received_location.trim().length > 0 &&
                logisticsEventForm.received_quantity.trim().length > 0 &&
                Number(logisticsEventForm.received_quantity) > 0 &&
                Number(logisticsEventForm.received_quantity) <= remainingReceiveQuantity.value + 0.0005 &&
                logisticsEventForm.goods_condition.trim().length > 0,
        ) && isSavingLogistics.value === null,
);
const canMarkBuyerReceived = computed(
    () =>
        Boolean(
            logisticsCase.value &&
                logisticsEventForm.buyer_received_at.length > 0 &&
                logisticsEventForm.received_quantity.trim().length > 0 &&
                Number(logisticsEventForm.received_quantity) > 0 &&
                Number(logisticsEventForm.received_quantity) <= remainingReceiveQuantity.value + 0.0005 &&
                logisticsEventForm.goods_condition.trim().length > 0,
        ) && isSavingLogistics.value === null,
);
const shouldShowDocumentsSent = computed(() => logisticsCase.value?.delivery_responsibility === 'buyer_agent');
const shouldShowArrival = computed(() => logisticsCase.value?.delivery_responsibility === 'isc' || logisticsCase.value?.delivery_responsibility === 'supplier');
const shouldShowWarehouseReceipt = computed(() => logisticsCase.value?.delivery_responsibility === 'isc');
const shouldShowBuyerReceipt = computed(() => logisticsCase.value?.delivery_responsibility === 'buyer_agent');
const remainingReceiveQuantity = computed(() => Number(item.value?.fulfilment.remaining_receive_quantity ?? 0));
const remainingDeliveryQuantity = computed(() => Number(item.value?.fulfilment.remaining_delivery_quantity ?? 0));
const remainingInvoiceQuantity = computed(() => Number(item.value?.fulfilment.remaining_invoice_quantity ?? 0));
const itemInvoiceBalance = computed(() => Number(item.value?.fulfilment.balance_amount ?? 0));
const openPaymentInvoices = computed(() => item.value?.invoices.filter((invoice) => Number(invoice.balance_amount) > 0 && !['paid', 'closed'].includes(invoice.payment_status)) ?? []);
const selectedPaymentInvoice = computed(() => {
    const invoices = item.value?.invoices ?? [];

    return invoices.find((invoice) => invoice.id === paymentForm.invoice_id) ?? openPaymentInvoices.value[0] ?? item.value?.invoice ?? null;
});
const canSaveDeliveryOrder = computed(
    () =>
        Boolean(
            item.value &&
                remainingDeliveryQuantity.value > 0 &&
                deliveryOrderForm.delivery_place.trim().length > 0 &&
                (deliveryOrderForm.quantity.trim().length === 0 || (Number(deliveryOrderForm.quantity) > 0 && Number(deliveryOrderForm.quantity) <= remainingDeliveryQuantity.value + 0.0005)),
        ) &&
        !isSavingDeliveryOrder.value,
);
const canUploadSignedDeliveryOrder = computed(
    () => Boolean(item.value?.delivery_order && deliveryOrderForm.signed_at.length > 0 && signedDeliveryOrderFile.value) && !isUploadingSignedDeliveryOrder.value,
);
const canSaveInvoice = computed(
    () =>
        Boolean(
            item.value &&
                remainingInvoiceQuantity.value > 0 &&
                invoiceForm.payment_term_days >= 0 &&
                (invoiceForm.quantity.trim().length === 0 || (Number(invoiceForm.quantity) > 0 && Number(invoiceForm.quantity) <= remainingInvoiceQuantity.value + 0.0005)),
        ) && !isSavingInvoice.value,
);
const canMarkInvoiceSent = computed(() => {
    const invoice = selectedPaymentInvoice.value;

    return Boolean(invoice && invoice.status === 'issued') && !isMarkingInvoiceSent.value;
});
const canSavePayment = computed(() => {
    const invoice = selectedPaymentInvoice.value;

    return Boolean(invoice && Number(paymentForm.amount) > 0 && paymentForm.payment_date.length > 0 && !['paid', 'closed'].includes(invoice.payment_status)) && !isSavingPayment.value;
});
const canCloseJob = computed(
    () =>
        Boolean(
                item.value &&
                item.value.invoices.length > 0 &&
                item.value.status !== 'closed' &&
                remainingReceiveQuantity.value <= 0.0005 &&
                remainingDeliveryQuantity.value <= 0.0005 &&
                remainingInvoiceQuantity.value <= 0.0005 &&
                itemInvoiceBalance.value <= 0.0005,
        ) && !isClosingJob.value,
);
const deliveryResponsibility = computed(() => logisticsCase.value?.delivery_responsibility ?? logisticsForm.delivery_responsibility);
const isIscHandledDelivery = computed(() => deliveryResponsibility.value === 'isc');
const isBuyerHandledDelivery = computed(() => deliveryResponsibility.value === 'buyer_agent');
const isSupplierHandledDelivery = computed(() => deliveryResponsibility.value === 'supplier');
const isExternalHandledDelivery = computed(() => deliveryResponsibility.value !== 'isc');
const invoiceReadyStatuses = ['ready_for_invoice', 'invoice_created', 'partially_invoiced', 'invoice_sent', 'payment_pending', 'partially_paid', 'paid', 'closed'];
const acknowledgementComplete = computed(() => Boolean(item.value?.acknowledgement_received_at));
const shippingAvailable = computed(() => acknowledgementComplete.value || Boolean(item.value && item.value.status !== 'awaiting_acknowledgement'));
const shippingComplete = computed(() => Boolean(item.value?.shipping_documents_complete));
const logisticsAvailable = computed(() => true);
const logisticsComplete = computed(() => Boolean(logisticsCase.value));
const deliveryAvailable = computed(() => logisticsComplete.value);
const deliveryComplete = computed(() => {
    if (!item.value) {
        return false;
    }

    if (isSupplierHandledDelivery.value) {
        return Boolean(logisticsCase.value?.arrived_at || logisticsCase.value?.status === 'supplier_received' || item.value.invoices.length > 0 || invoiceReadyStatuses.includes(item.value.status));
    }

    if (isBuyerHandledDelivery.value) {
        return Boolean(logisticsCase.value?.buyer_received_at || logisticsCase.value?.status === 'buyer_received' || item.value.invoices.length > 0 || invoiceReadyStatuses.includes(item.value.status));
    }

    return Boolean(
        item.value.delivery_order?.status === 'signed' ||
            invoiceReadyStatuses.includes(item.value.status),
    );
});
const invoiceAvailable = computed(() => deliveryComplete.value || Boolean(item.value?.invoices.length) || item.value?.status === 'ready_for_invoice' || item.value?.status === 'partially_invoiced');
const invoiceComplete = computed(() => Boolean(item.value?.invoices.length));
const paymentAvailable = computed(() => invoiceComplete.value);
const paymentComplete = computed(() => Boolean(item.value && item.value.invoices.length > 0 && itemInvoiceBalance.value <= 0.0005 && remainingInvoiceQuantity.value <= 0.0005) || item.value?.status === 'closed');
const followUpSteps = computed<FollowUpWorkflowStep[]>(() => [
    {
        key: 'acknowledgement',
        label: 'Order Acknowledgement',
        caption: acknowledgementComplete.value ? 'Supplier confirmation received' : 'Start with supplier confirmation',
        available: true,
        complete: acknowledgementComplete.value,
    },
    {
        key: 'shipping',
        label: 'Shipping Details',
        caption: shippingComplete.value ? 'Documents are complete' : 'Packing list and shipper files',
        available: shippingAvailable.value,
        complete: shippingComplete.value,
    },
    {
        key: 'logistics',
        label: 'ETA / Logistics',
        caption: logisticsComplete.value ? 'ETA has been saved' : 'Set delivery responsibility and ETA',
        available: logisticsAvailable.value,
        complete: logisticsComplete.value,
    },
    {
        key: 'delivery',
        label: isSupplierHandledDelivery.value ? 'Supplier Receipt' : isExternalHandledDelivery.value ? 'Agent / Receipt' : 'Delivery to ISC',
        caption: isSupplierHandledDelivery.value
            ? 'Confirm supplier receipt before invoice'
            : isExternalHandledDelivery.value
              ? 'Send documents and confirm buyer receipt'
              : 'Arrival, warehouse, and delivery note',
        available: deliveryAvailable.value,
        complete: deliveryComplete.value,
    },
    {
        key: 'invoice',
        label: 'Invoice',
        caption: invoiceComplete.value ? 'Invoice created' : 'Generate buyer invoice',
        available: invoiceAvailable.value,
        complete: invoiceComplete.value,
    },
    {
        key: 'payment',
        label: 'Payment / Close',
        caption: paymentComplete.value ? 'Payment completed' : 'Track payment reminder and closing',
        available: paymentAvailable.value,
        complete: paymentComplete.value,
    },
]);
const activeFollowUpStepIndex = computed(() => followUpSteps.value.findIndex((step) => step.key === activeFollowUpStep.value));
const previousFollowUpStep = computed(() => {
    const activeIndex = activeFollowUpStepIndex.value;

    if (activeIndex <= 0) {
        return null;
    }

    return [...followUpSteps.value.slice(0, activeIndex)].reverse().find((step) => step.available) ?? null;
});
const nextFollowUpStep = computed(() => {
    const activeIndex = activeFollowUpStepIndex.value;

    if (activeIndex < 0) {
        return null;
    }

    return followUpSteps.value.slice(activeIndex + 1).find((step) => step.available) ?? null;
});
const activeWorkflowStep = computed(() => followUpSteps.value.find((step) => step.key === activeFollowUpStep.value) ?? null);
const activeStageComments = computed(() => item.value?.comments_by_stage[activeFollowUpStep.value] ?? []);
const isAdminUser = computed(() => currentUser.value?.roles.some((role) => role.slug === 'admin') ?? false);
const timelineEvents = computed(() => item.value?.timeline_events ?? []);

function decimalValue(value: string | null | undefined): number {
    return Number(value ?? 0);
}

function preferredFollowUpStepKey(payload: FollowUpItem): FollowUpStepKey {
    if (!payload.acknowledgement_received_at) {
        return 'acknowledgement';
    }

    if (!payload.shipping_documents_complete) {
        return 'shipping';
    }

    if (!payload.logistics_case) {
        return 'logistics';
    }

    if (
        payload.logistics_case.delivery_responsibility === 'supplier' &&
        !payload.logistics_case.arrived_at &&
        payload.invoices.length === 0 &&
        !invoiceReadyStatuses.includes(payload.status)
    ) {
        return 'delivery';
    }

    if (
        payload.logistics_case.delivery_responsibility === 'buyer_agent' &&
        !payload.logistics_case.buyer_received_at &&
        payload.invoices.length === 0 &&
        !invoiceReadyStatuses.includes(payload.status)
    ) {
        return 'delivery';
    }

    if (
        payload.logistics_case.delivery_responsibility === 'isc' &&
        payload.delivery_order?.status !== 'signed' &&
        !invoiceReadyStatuses.includes(payload.status)
    ) {
        return 'delivery';
    }

    if (decimalValue(payload.fulfilment.remaining_delivery_quantity) > 0 && payload.logistics_case.delivery_responsibility === 'isc') {
        return 'delivery';
    }

    if (decimalValue(payload.fulfilment.remaining_invoice_quantity) > 0 || payload.invoices.length === 0) {
        return 'invoice';
    }

    return 'payment';
}

function selectFollowUpStep(key: FollowUpStepKey): void {
    const step = followUpSteps.value.find((candidate) => candidate.key === key);

    if (!step?.available) {
        return;
    }

    hasSelectedFollowUpStep.value = true;
    activeFollowUpStep.value = key;
}

function defaultDeliveryResponsibility(payload: FollowUpItem): LogisticsCase['delivery_responsibility'] {
    if (payload.logistics_case?.delivery_responsibility) {
        return payload.logistics_case.delivery_responsibility;
    }

    if (payload.quotation_delivery_responsibility === 'buyer') {
        return 'buyer_agent';
    }

    if (payload.quotation_delivery_responsibility === 'supplier') {
        return 'supplier';
    }

    return 'isc';
}

function moveFollowUpStep(direction: 'previous' | 'next'): void {
    const target = direction === 'previous' ? previousFollowUpStep.value : nextFollowUpStep.value;

    if (target) {
        selectFollowUpStep(target.key);
    }
}

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

function statusLabel(status: string): string {
    return humanizeStatus(status);
}

function factoryLabel(payload: FollowUpItem): string {
    return [payload.factory_name, payload.factory_location].filter(Boolean).join(' - ') || '-';
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Date(value.replace(' ', 'T')).toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDate(value: string | null): string {
    if (!value) {
        return '-';
    }

    return new Date(value.replace(' ', 'T')).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function toDateTimeLocal(value: string | null): string {
    if (!value) {
        return '';
    }

    return value.replace(' ', 'T').slice(0, 16);
}

function toDateInput(value: string | null): string {
    if (!value) {
        return '';
    }

    return value.replace(' ', 'T').slice(0, 10);
}

function todayDateInput(): string {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Muscat',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const valueByPart = new Map(parts.map((part) => [part.type, part.value]));

    return `${valueByPart.get('year')}-${valueByPart.get('month')}-${valueByPart.get('day')}`;
}

function paymentPlanForm(plan: PaymentPlanFollowUpRecord): { paid_amount: string; paid_at: string; payment_reference: string; remarks: string; attachment_remarks: string } {
    if (!paymentPlanForms[plan.id]) {
        paymentPlanForms[plan.id] = {
            paid_amount: plan.paid_amount ?? plan.expected_amount ?? '',
            paid_at: toDateInput(plan.paid_at) || todayDateInput(),
            payment_reference: plan.payment_reference ?? '',
            remarks: plan.remarks ?? '',
            attachment_remarks: '',
        };
    }

    return paymentPlanForms[plan.id];
}

function clearReactiveRecord<T>(record: Record<string, T> | Record<number, T>): void {
    for (const key of Object.keys(record)) {
        delete (record as Record<string, T>)[key];
    }
}

function resetFollowUpState(): void {
    item.value = null;
    activeFollowUpStep.value = 'acknowledgement';
    hasSelectedFollowUpStep.value = false;
    acknowledgementFile.value = null;
    signedDeliveryOrderFile.value = null;
    clearReactiveRecord(shippingDocumentFiles);
    clearReactiveRecord(shippingDocumentForms);
    clearReactiveRecord(paymentPlanFiles);
    clearReactiveRecord(paymentPlanForms);
    Object.assign(reminderForm, { reminder_interval_value: 2, reminder_interval_unit: 'weeks', next_follow_up_at: '' });
    Object.assign(commentForm, { comment: '', communication_type: 'email', contacted_person: '', next_action: '' });
    Object.assign(acknowledgementForm, { acknowledgement_received_at: '', acknowledgement_notes: '' });
    Object.assign(logisticsForm, { delivery_responsibility: 'isc', eta_at: '', agent_name: '', agent_contact: '', remarks: '' });
    Object.assign(logisticsEventForm, {
        documents_sent_at: '',
        arrived_at: '',
        warehouse_received_at: '',
        buyer_received_at: '',
        received_location: '',
        received_quantity: '',
        goods_condition: '',
        remarks: '',
    });
    Object.assign(deliveryOrderForm, { delivery_place: '', quantity: '', terms: '', signed_at: '' });
    Object.assign(invoiceForm, { quantity: '', payment_term_days: 45, vat_rate: '0', vat_exception_reason: '', bank_details: '', remarks: '' });
    Object.assign(paymentForm, { invoice_id: null, amount: '', payment_date: '', payment_reference: '', remarks: '' });
    Object.assign(closeForm, { closed_notes: '' });
}

function applyItem(
    payload: FollowUpItem,
    options: { preservePaymentPlanDrafts?: boolean; resetPaymentPlanId?: number } = { preservePaymentPlanDrafts: true },
): void {
    if (payload.id !== itemId.value) {
        return;
    }

    item.value = payload;
    reminderForm.reminder_interval_value = payload.reminder_interval_value ?? 2;
    reminderForm.reminder_interval_unit = payload.reminder_interval_unit ?? 'weeks';
    reminderForm.next_follow_up_at = toDateTimeLocal(payload.next_follow_up_at);
    acknowledgementForm.acknowledgement_received_at = toDateTimeLocal(payload.acknowledgement_received_at);
    acknowledgementForm.acknowledgement_notes = payload.acknowledgement_notes ?? '';

    for (const document of payload.shipping_documents) {
        shippingDocumentForms[document.document_type] = {
            document_number: document.document_number ?? '',
            document_date: document.document_date ?? '',
            remarks: '',
        };
    }

    logisticsForm.delivery_responsibility = defaultDeliveryResponsibility(payload);
    logisticsForm.eta_at = toDateTimeLocal(payload.logistics_case?.eta_at ?? null);
    logisticsForm.agent_name = payload.logistics_case?.agent_name ?? '';
    logisticsForm.agent_contact = payload.logistics_case?.agent_contact ?? '';
    logisticsForm.remarks = payload.logistics_case?.remarks ?? '';
    logisticsEventForm.documents_sent_at = toDateTimeLocal(payload.logistics_case?.documents_sent_at ?? null);
    logisticsEventForm.arrived_at = toDateTimeLocal(payload.logistics_case?.arrived_at ?? null);
    logisticsEventForm.warehouse_received_at = toDateTimeLocal(payload.logistics_case?.warehouse_received_at ?? null);
    logisticsEventForm.buyer_received_at = toDateTimeLocal(payload.logistics_case?.buyer_received_at ?? null);
    logisticsEventForm.received_location = payload.logistics_case?.received_location ?? '';
    logisticsEventForm.received_quantity =
        decimalValue(payload.fulfilment.remaining_receive_quantity) > 0
            ? payload.fulfilment.remaining_receive_quantity
            : payload.logistics_case?.received_quantity ?? payload.quantity ?? '';
    logisticsEventForm.goods_condition = payload.logistics_case?.goods_condition ?? '';

    deliveryOrderForm.delivery_place = payload.delivery_order?.delivery_place ?? deliveryOrderForm.delivery_place;
    deliveryOrderForm.quantity = decimalValue(payload.fulfilment.remaining_delivery_quantity) > 0 ? payload.fulfilment.remaining_delivery_quantity : '';
    deliveryOrderForm.terms = payload.delivery_order?.terms ?? deliveryOrderForm.terms;
    deliveryOrderForm.signed_at = toDateTimeLocal(payload.delivery_order?.signed_at ?? null);

    invoiceForm.quantity = decimalValue(payload.fulfilment.remaining_invoice_quantity) > 0 ? payload.fulfilment.remaining_invoice_quantity : '';
    invoiceForm.payment_term_days = payload.invoice?.payment_term_days ?? payload.quotation_payment_term_days ?? invoiceForm.payment_term_days;
    invoiceForm.vat_rate = payload.invoice?.vat_rate ?? payload.quotation_item_vat_rate ?? '0.000';
    invoiceForm.vat_exception_reason = payload.invoice?.vat_exception_reason ?? '';
    invoiceForm.bank_details = payload.invoice?.bank_details ?? invoiceForm.bank_details;
    invoiceForm.remarks = payload.invoice?.remarks ?? invoiceForm.remarks;
    const paymentInvoice = payload.invoices.find((invoice) => Number(invoice.balance_amount) > 0 && !['paid', 'closed'].includes(invoice.payment_status)) ?? payload.invoice;
    paymentForm.invoice_id = paymentInvoice?.id ?? null;
    paymentForm.amount = paymentInvoice?.balance_amount && !['paid', 'closed'].includes(paymentInvoice.payment_status) ? paymentInvoice.balance_amount : '';
    const paymentPlanIds = new Set(payload.payment_plan_follow_ups.map((plan) => plan.id));
    if (!options.preservePaymentPlanDrafts) {
        for (const planId of Object.keys(paymentPlanForms).map(Number)) {
            if (!paymentPlanIds.has(planId)) {
                delete paymentPlanForms[planId];
                delete paymentPlanFiles[planId];
            }
        }
    }

    for (const plan of payload.payment_plan_follow_ups) {
        const shouldRefreshPlanDraft = !options.preservePaymentPlanDrafts || !paymentPlanForms[plan.id] || options.resetPaymentPlanId === plan.id;
        if (shouldRefreshPlanDraft) {
            paymentPlanForms[plan.id] = {
                paid_amount: plan.paid_amount ?? plan.expected_amount ?? '',
                paid_at: toDateInput(plan.paid_at) || todayDateInput(),
                payment_reference: plan.payment_reference ?? '',
                remarks: plan.remarks ?? '',
                attachment_remarks: '',
            };
        }
        if (!options.preservePaymentPlanDrafts || options.resetPaymentPlanId === plan.id) {
            paymentPlanFiles[plan.id] = null;
        }
    }
    closeForm.closed_notes = payload.closed_notes ?? closeForm.closed_notes;

    const activeStepStillAvailable = followUpSteps.value.some((step) => step.key === activeFollowUpStep.value && step.available);

    if (!hasSelectedFollowUpStep.value || !activeStepStillAvailable) {
        activeFollowUpStep.value = preferredFollowUpStepKey(payload);
    }
}

let loadRequestSequence = 0;

async function loadItem(): Promise<void> {
    const requestedItemId = itemId.value;
    const requestSequence = ++loadRequestSequence;
    isLoading.value = true;

    try {
        const payload = await requestJson<{ data: FollowUpItem }>(`/api/follow-up/${requestedItemId}`);
        if (requestSequence === loadRequestSequence && requestedItemId === itemId.value) {
            applyItem(payload.data, { preservePaymentPlanDrafts: false });
        }
    } catch (error) {
        if (requestSequence === loadRequestSequence) {
            showToast('error', error instanceof Error ? error.message : 'Unable to load follow-up item.');
        }
    } finally {
        if (requestSequence === loadRequestSequence) {
            isLoading.value = false;
        }
    }
}

async function saveReminder(): Promise<void> {
    if (!canSaveReminder.value) {
        return;
    }

    isSavingReminder.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/reminder`, {
            method: 'PUT',
            body: JSON.stringify({
                reminder_interval_value: reminderForm.reminder_interval_value,
                reminder_interval_unit: reminderForm.reminder_interval_unit,
                next_follow_up_at: reminderForm.next_follow_up_at,
            }),
        });

        applyItem(payload.data);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to update reminder.');
    } finally {
        isSavingReminder.value = false;
    }
}

async function saveComment(): Promise<void> {
    if (!canSaveComment.value) {
        return;
    }

    isSavingComment.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/comments`, {
            method: 'POST',
            body: JSON.stringify({
                ...commentForm,
                stage: activeFollowUpStep.value,
            }),
        });

        applyItem(payload.data);
        commentForm.comment = '';
        commentForm.contacted_person = '';
        commentForm.next_action = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to add comment.');
    } finally {
        isSavingComment.value = false;
    }
}

async function saveAcknowledgement(): Promise<void> {
    if (!canSaveAcknowledgement.value) {
        return;
    }

    isSavingAcknowledgement.value = true;

    try {
        const formData = new FormData();
        formData.append('acknowledgement_received_at', acknowledgementForm.acknowledgement_received_at);
        formData.append('acknowledgement_notes', acknowledgementForm.acknowledgement_notes);

        if (acknowledgementFile.value) {
            formData.append('acknowledgement_file', acknowledgementFile.value);
        }

        const payload = await requestFormData<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/acknowledgement`, formData);
        applyItem(payload.data);
        acknowledgementFile.value = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record acknowledgement.');
    } finally {
        isSavingAcknowledgement.value = false;
    }
}

function selectAcknowledgementFile(event: Event): void {
    acknowledgementFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function selectShippingDocumentFile(documentType: string, event: Event): void {
    shippingDocumentFiles[documentType] = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function statusLabelForDocument(status: string): string {
    return statusLabel(status);
}

async function uploadShippingDocument(document: ShippingDocument): Promise<void> {
    const file = shippingDocumentFiles[document.document_type];

    if (!file) {
        showToast('error', `Choose ${document.label} file first.`);
        return;
    }

    isSavingDocumentType.value = document.document_type;

    try {
        const form = shippingDocumentForms[document.document_type] ?? {
            document_number: '',
            document_date: '',
            remarks: '',
        };
        const formData = new FormData();
        formData.append('document_file', file);
        formData.append('document_number', form.document_number);
        formData.append('document_date', form.document_date);
        formData.append('remarks', form.remarks);

        await requestFormData(`/api/follow-up/${itemId.value}/shipping-documents/${document.document_type}`, formData);
        shippingDocumentFiles[document.document_type] = null;
        await loadItem();
        showToast('success', `${document.label} uploaded.`);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : `Unable to upload ${document.label}.`);
    } finally {
        isSavingDocumentType.value = null;
    }
}

async function completeShippingDocuments(): Promise<void> {
    isCompletingShippingDocuments.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/shipping-documents/complete`, {
            method: 'POST',
        });

        applyItem(payload.data);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to complete shipping documents.');
    } finally {
        isCompletingShippingDocuments.value = false;
    }
}

async function saveEta(): Promise<void> {
    if (!canSaveEta.value) {
        return;
    }

    isSavingLogistics.value = 'eta';

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/logistics/eta`, {
            method: 'POST',
            body: JSON.stringify(logisticsForm),
        });

        applyItem(payload.data);
        logisticsEventForm.remarks = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record ETA.');
    } finally {
        isSavingLogistics.value = null;
    }
}

async function markDocumentsSent(): Promise<void> {
    if (!canMarkDocumentsSent.value) {
        return;
    }

    isSavingLogistics.value = 'documents-sent';

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/logistics/documents-sent`, {
            method: 'POST',
            body: JSON.stringify({
                documents_sent_at: logisticsEventForm.documents_sent_at,
                agent_name: logisticsForm.agent_name,
                agent_contact: logisticsForm.agent_contact,
                remarks: logisticsEventForm.remarks,
            }),
        });

        applyItem(payload.data);
        logisticsEventForm.remarks = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record document handoff.');
    } finally {
        isSavingLogistics.value = null;
    }
}

async function markArrived(): Promise<void> {
    if (!canMarkArrived.value) {
        return;
    }

    isSavingLogistics.value = 'arrived';

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/logistics/arrived`, {
            method: 'POST',
            body: JSON.stringify({
                arrived_at: logisticsEventForm.arrived_at,
                remarks: logisticsEventForm.remarks,
            }),
        });

        applyItem(payload.data);
        logisticsEventForm.remarks = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record arrival.');
    } finally {
        isSavingLogistics.value = null;
    }
}

async function markWarehouseReceived(): Promise<void> {
    if (!canMarkWarehouseReceived.value) {
        return;
    }

    isSavingLogistics.value = 'warehouse-received';

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/logistics/warehouse-received`, {
            method: 'POST',
            body: JSON.stringify({
                warehouse_received_at: logisticsEventForm.warehouse_received_at,
                received_location: logisticsEventForm.received_location,
                received_quantity: logisticsEventForm.received_quantity,
                goods_condition: logisticsEventForm.goods_condition,
                remarks: logisticsEventForm.remarks,
            }),
        });

        applyItem(payload.data);
        logisticsEventForm.remarks = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record warehouse receipt.');
    } finally {
        isSavingLogistics.value = null;
    }
}

async function markBuyerReceived(): Promise<void> {
    if (!canMarkBuyerReceived.value) {
        return;
    }

    isSavingLogistics.value = 'buyer-received';

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/logistics/buyer-received`, {
            method: 'POST',
            body: JSON.stringify({
                buyer_received_at: logisticsEventForm.buyer_received_at,
                received_quantity: logisticsEventForm.received_quantity,
                goods_condition: logisticsEventForm.goods_condition,
                remarks: logisticsEventForm.remarks,
            }),
        });

        applyItem(payload.data);
        logisticsEventForm.remarks = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record buyer receipt.');
    } finally {
        isSavingLogistics.value = null;
    }
}

function selectSignedDeliveryOrderFile(event: Event): void {
    signedDeliveryOrderFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

async function saveDeliveryOrder(): Promise<void> {
    if (!canSaveDeliveryOrder.value) {
        return;
    }

    isSavingDeliveryOrder.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/delivery-order`, {
            method: 'POST',
            body: JSON.stringify({
                delivery_place: deliveryOrderForm.delivery_place,
                quantity: deliveryOrderForm.quantity || null,
                terms: deliveryOrderForm.terms,
            }),
        });

        applyItem(payload.data);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to generate delivery order.');
    } finally {
        isSavingDeliveryOrder.value = false;
    }
}

async function uploadSignedDeliveryOrder(): Promise<void> {
    if (!canUploadSignedDeliveryOrder.value) {
        return;
    }

    isUploadingSignedDeliveryOrder.value = true;

    try {
        const formData = new FormData();
        formData.append('signed_at', deliveryOrderForm.signed_at);

        if (signedDeliveryOrderFile.value) {
            formData.append('signed_file', signedDeliveryOrderFile.value);
        }

        const payload = await requestFormData<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/delivery-order/signed`, formData);
        applyItem(payload.data);
        signedDeliveryOrderFile.value = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to upload signed delivery order.');
    } finally {
        isUploadingSignedDeliveryOrder.value = false;
    }
}

async function saveInvoice(): Promise<void> {
    if (!canSaveInvoice.value) {
        return;
    }

    isSavingInvoice.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/invoice`, {
            method: 'POST',
            body: JSON.stringify({
                quantity: invoiceForm.quantity || null,
                payment_term_days: Number(invoiceForm.payment_term_days),
                vat_exception_reason: invoiceForm.vat_exception_reason,
                bank_details: invoiceForm.bank_details,
                remarks: invoiceForm.remarks,
            }),
        });

        applyItem(payload.data);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to generate invoice.');
    } finally {
        isSavingInvoice.value = false;
    }
}

async function downloadDeliveryOrder(format: 'docx' | 'pdf', deliveryOrder: DeliveryOrder | null = item.value?.delivery_order ?? null): Promise<void> {
    if (!deliveryOrder) {
        return;
    }

    try {
        const filename = `${deliveryOrder.delivery_order_reference}.${format}`;
        await downloadProtectedFile(deliveryOrder.downloads[format], format === 'docx' ? filename.toUpperCase() : filename);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download delivery order.');
    }
}

async function downloadUploadedEvidence(downloadUrl: string | null, fallbackFilename: string, description: string): Promise<void> {
    if (!downloadUrl) {
        return;
    }

    try {
        await downloadProtectedFile(downloadUrl, fallbackFilename);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : `Unable to download ${description}.`);
    }
}

async function downloadInvoice(format: 'docx' | 'pdf', invoice: InvoiceRecord | null = item.value?.invoice ?? null): Promise<void> {
    if (!invoice) {
        return;
    }

    try {
        const filename = `${invoice.invoice_reference}.${format}`;
        await downloadProtectedFile(invoice.downloads[format], format === 'docx' ? filename.toUpperCase() : filename);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download invoice.');
    }
}

async function markInvoiceSent(): Promise<void> {
    if (!canMarkInvoiceSent.value) {
        return;
    }

    isMarkingInvoiceSent.value = true;

    try {
        const now = new Date();
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/invoice/sent`, {
            method: 'POST',
            body: JSON.stringify({
                invoice_id: selectedPaymentInvoice.value?.id ?? null,
                sent_at: now.toISOString(),
            }),
        });

        applyItem(payload.data);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to mark invoice as sent.');
    } finally {
        isMarkingInvoiceSent.value = false;
    }
}

async function savePayment(): Promise<void> {
    if (!canSavePayment.value) {
        return;
    }

    isSavingPayment.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/payments`, {
            method: 'POST',
            body: JSON.stringify({
                invoice_id: paymentForm.invoice_id,
                amount: paymentForm.amount,
                payment_date: paymentForm.payment_date,
                payment_reference: paymentForm.payment_reference,
                remarks: paymentForm.remarks,
            }),
        });

        applyItem(payload.data);
        paymentForm.payment_reference = '';
        paymentForm.remarks = '';
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to record payment.');
    } finally {
        isSavingPayment.value = false;
    }
}

function selectPaymentPlanFile(planId: number, event: Event): void {
    paymentPlanFiles[planId] = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function paymentPlanDueLabel(plan: PaymentPlanFollowUpRecord): string {
    if (plan.status === 'paid') {
        return 'Paid';
    }

    if (plan.due_state === 'overdue') {
        return `Overdue since ${formatDate(plan.due_date)}`;
    }

    if (plan.due_state === 'due_today') {
        return 'Due today';
    }

    if (plan.due_state === 'unscheduled') {
        return 'Waiting for trigger';
    }

    return `Due ${formatDate(plan.due_date)}`;
}

function paymentPlanPillClass(plan: PaymentPlanFollowUpRecord): string {
    if (plan.status === 'paid') {
        return 'teal';
    }

    if (plan.due_state === 'overdue') {
        return 'amber';
    }

    return 'slate';
}

async function markPaymentPlanPaid(plan: PaymentPlanFollowUpRecord): Promise<void> {
    const form = paymentPlanForm(plan);

    if (Number(form.paid_amount) <= 0) {
        showToast('error', 'Enter the paid amount for this plan line.');
        return;
    }

    isSavingPaymentPlanId.value = plan.id;

    try {
        const formData = new FormData();
        formData.append('paid_amount', form.paid_amount);
        formData.append('paid_at', form.paid_at);
        formData.append('payment_reference', form.payment_reference);
        formData.append('remarks', form.remarks);
        formData.append('attachment_remarks', form.attachment_remarks);

        if (paymentPlanFiles[plan.id]) {
            formData.append('attachment_file', paymentPlanFiles[plan.id] as File);
        }

        const payload = await requestFormData<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/payment-plan/${plan.id}/paid`, formData);
        applyItem(payload.data, { preservePaymentPlanDrafts: true, resetPaymentPlanId: plan.id });
        paymentPlanFiles[plan.id] = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to mark payment plan as paid.');
    } finally {
        isSavingPaymentPlanId.value = null;
    }
}

async function uploadPaymentPlanAttachment(plan: PaymentPlanFollowUpRecord): Promise<void> {
    const file = paymentPlanFiles[plan.id];

    if (!file) {
        showToast('error', 'Choose a payment document first.');
        return;
    }

    isUploadingPaymentPlanAttachmentId.value = plan.id;

    try {
        const form = paymentPlanForm(plan);
        const formData = new FormData();
        formData.append('attachment_file', file);
        formData.append('attachment_remarks', form.attachment_remarks);

        const payload = await requestFormData<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/payment-plan/${plan.id}/attachments`, formData);
        applyItem(payload.data, { preservePaymentPlanDrafts: true, resetPaymentPlanId: plan.id });
        paymentPlanFiles[plan.id] = null;
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to upload payment document.');
    } finally {
        isUploadingPaymentPlanAttachmentId.value = null;
    }
}

async function closeJob(): Promise<void> {
    if (!canCloseJob.value) {
        return;
    }

    isClosingJob.value = true;

    try {
        const payload = await requestJson<{ message: string; data: FollowUpItem }>(`/api/follow-up/${itemId.value}/close`, {
            method: 'POST',
            body: JSON.stringify(closeForm),
        });

        applyItem(payload.data);
        showToast('success', payload.message);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to close job.');
    } finally {
        isClosingJob.value = false;
    }
}

watch(
    itemId,
    (id) => {
        resetFollowUpState();
        if (!Number.isInteger(id) || id <= 0) {
            loadRequestSequence += 1;
            isLoading.value = false;
            return;
        }
        void loadItem();
    },
    { immediate: true },
);

watch(
    () => paymentForm.invoice_id,
    (invoiceId) => {
        const invoice = item.value?.invoices.find((candidate) => candidate.id === invoiceId) ?? null;

        if (invoice && !['paid', 'closed'].includes(invoice.payment_status)) {
            paymentForm.amount = invoice.balance_amount;
        }
    },
);
</script>

<template>
    <section class="page-scaffold follow-up-detail-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>Follow-Up Item</p>
                <h1>{{ item?.supplier_po_reference ?? 'Loading...' }}</h1>
            </div>

            <button class="secondary-action icon-gap" type="button" @click="router.push('/follow-up')">
                <ArrowLeft :size="17" aria-hidden="true" />
                Back
            </button>
        </div>

        <div v-if="isLoading" class="crud-empty">
            <Loader2 :size="20" aria-hidden="true" />
            Loading follow-up details...
        </div>

        <template v-else-if="item">
            <section class="follow-up-detail-grid">
                <article class="follow-up-panel trace-panel">
                    <header>
                        <div>
                            <p>Traceability</p>
                            <h2>{{ item.product_code ? `${item.product_code} - ` : '' }}{{ item.title ?? item.product_name }}</h2>
                        </div>
                        <span class="stage-pill" :class="item.status === 'acknowledged' ? 'teal' : 'amber'">
                            {{ statusLabel(item.status) }}
                        </span>
                    </header>

                    <div class="trace-grid">
                        <span>Quotation</span>
                        <strong>{{ item.quotation_reference ?? '-' }}</strong>
                        <span>Buyer PO</span>
                        <strong>
                            <button
                                v-if="item.buyer_po_download_url"
                                class="table-link-button"
                                type="button"
                                @click="downloadUploadedEvidence(item.buyer_po_download_url, item.buyer_po_original_file_name ?? `buyer-po-${item.buyer_po_number ?? item.id}`, 'the buyer PO')"
                            >
                                <Download :size="14" aria-hidden="true" />
                                {{ item.buyer_po_number ?? item.buyer_po_original_file_name ?? 'Buyer PO' }}
                            </button>
                            <template v-else>{{ item.buyer_po_number ?? '-' }}</template>
                        </strong>
                        <span>Supplier PO</span>
                        <strong>{{ item.supplier_po_reference ?? '-' }}</strong>
                        <span>Buyer</span>
                        <strong>{{ item.buyer_company_name ?? '-' }}</strong>
                        <span>Supplier</span>
                        <strong>{{ item.supplier_company_name ?? '-' }}</strong>
                        <span>Factory</span>
                        <strong>{{ factoryLabel(item) }}</strong>
                        <span>Manufacturer</span>
                        <strong>{{ item.manufacturer_name ?? '-' }}</strong>
                        <span>Material / Item Code</span>
                        <strong>{{ item.product_code ?? '-' }}</strong>
                        <span>Quantity</span>
                        <strong>{{ item.quantity }} {{ item.uom }}</strong>
                    </div>
                </article>

                <article class="follow-up-panel fulfilment-panel">
                    <header>
                        <div>
                            <p>Item Fulfilment</p>
                            <h2>{{ item.fulfilment.received_quantity }} / {{ item.fulfilment.supplier_quantity }} {{ item.fulfilment.uom ?? item.uom }}</h2>
                        </div>
                        <PackageCheck :size="22" aria-hidden="true" />
                    </header>

                    <div class="trace-grid">
                        <span>Remaining Receipt</span>
                        <strong>{{ item.fulfilment.remaining_receive_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Delivered</span>
                        <strong>{{ item.fulfilment.delivered_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Remaining Delivery</span>
                        <strong>{{ item.fulfilment.remaining_delivery_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Invoiced Qty</span>
                        <strong>{{ item.fulfilment.invoiced_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Remaining Invoice</span>
                        <strong>{{ item.fulfilment.remaining_invoice_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Quotation Total</span>
                        <strong>{{ item.fulfilment.currency ?? '' }} {{ item.fulfilment.quotation_total_amount }}</strong>
                        <span>Paid</span>
                        <strong>{{ item.fulfilment.currency ?? '' }} {{ item.fulfilment.paid_amount }}</strong>
                        <span>Pending From Quotation</span>
                        <strong>{{ item.fulfilment.currency ?? '' }} {{ item.fulfilment.remaining_quotation_amount }}</strong>
                    </div>
                </article>

                <article class="follow-up-panel reminder-panel">
                    <header>
                        <div>
                            <p>Reminder</p>
                            <h2>Next Follow-Up</h2>
                        </div>
                        <Clock3 :size="22" aria-hidden="true" />
                    </header>

                    <p class="next-follow-up">{{ formatDateTime(item.next_follow_up_at) }}</p>

                    <form class="follow-up-form inline-form" @submit.prevent="saveReminder">
                        <label>
                            <span>Every</span>
                            <input v-model.number="reminderForm.reminder_interval_value" type="number" min="1" :disabled="reminderForm.reminder_interval_unit === 'custom'" />
                        </label>
                        <label>
                            <span>Interval</span>
                            <select v-model="reminderForm.reminder_interval_unit">
                                <option value="weeks">Weeks</option>
                                <option value="days">Days</option>
                                <option value="months">Months</option>
                                <option value="custom">Custom Date</option>
                            </select>
                        </label>
                        <label v-if="reminderForm.reminder_interval_unit === 'custom'">
                            <span>Custom Date</span>
                            <input v-model="reminderForm.next_follow_up_at" type="datetime-local" />
                        </label>
                        <button class="primary-action compact-action" type="submit" :disabled="!canSaveReminder">
                            <Loader2 v-if="isSavingReminder" class="spin-icon" :size="17" aria-hidden="true" />
                            <Save v-else :size="17" aria-hidden="true" />
                            Save Reminder
                        </button>
                    </form>
                </article>

                <article class="follow-up-panel payment-agreement-panel">
                    <header>
                        <div>
                            <p>Payment Agreement</p>
                            <h2>{{ item.quotation_payment_customer_type_label }}</h2>
                        </div>
                        <ReceiptText :size="22" aria-hidden="true" />
                    </header>

                    <div v-if="item.payment_plan_follow_ups.length > 0" class="payment-plan-follow-up-list">
                        <article v-for="plan in item.payment_plan_follow_ups" :key="plan.id" class="payment-plan-card" :class="plan.due_state">
                            <div class="payment-plan-card-top">
                                <div>
                                    <strong>{{ plan.label }} - {{ plan.payment_percentage }}%</strong>
                                    <span>{{ plan.payment_method_label }} | {{ plan.due_text }}</span>
                                    <small>{{ paymentPlanDueLabel(plan) }}</small>
                                </div>
                                <span class="stage-pill" :class="paymentPlanPillClass(plan)">
                                    {{ plan.status === 'paid' ? 'Paid' : 'Pending' }}
                                </span>
                            </div>

                            <div class="payment-plan-meta-grid">
                                <span>Expected</span>
                                <strong>{{ plan.currency ?? '' }} {{ plan.expected_amount ?? '-' }}</strong>
                                <span>Paid</span>
                                <strong>{{ plan.paid_amount ? `${plan.currency ?? ''} ${plan.paid_amount}` : '-' }}</strong>
                                <span>Reference</span>
                                <strong>{{ plan.payment_reference ?? '-' }}</strong>
                                <span>Recorded By</span>
                                <strong>{{ plan.recorded_by_name ?? '-' }}</strong>
                            </div>

                            <form v-if="plan.status !== 'paid'" class="payment-plan-action-form" @submit.prevent="markPaymentPlanPaid(plan)">
                                <label>
                                    <span>Paid Amount</span>
                                    <input v-model="paymentPlanForms[plan.id].paid_amount" type="number" min="0.001" step="0.001" required />
                                </label>
                                <label>
                                    <span>Paid Date</span>
                                    <input v-model="paymentPlanForms[plan.id].paid_at" type="date" required />
                                </label>
                                <label>
                                    <span>Reference</span>
                                    <input v-model="paymentPlanForms[plan.id].payment_reference" type="text" placeholder="Cheque / transfer no." />
                                </label>
                                <label>
                                    <span>Receipt / Document</span>
                                    <input type="file" @change="selectPaymentPlanFile(plan.id, $event)" />
                                </label>
                                <label class="payment-plan-wide">
                                    <span>Remarks</span>
                                    <input v-model="paymentPlanForms[plan.id].remarks" type="text" placeholder="Payment notes" />
                                </label>
                                <label class="payment-plan-wide">
                                    <span>Document Remarks</span>
                                    <input v-model="paymentPlanForms[plan.id].attachment_remarks" type="text" placeholder="Optional attachment note" />
                                </label>
                                <button class="primary-action compact-action payment-plan-wide" type="submit" :disabled="isSavingPaymentPlanId === plan.id">
                                    <Loader2 v-if="isSavingPaymentPlanId === plan.id" class="spin-icon" :size="17" aria-hidden="true" />
                                    <CheckCircle2 v-else :size="17" aria-hidden="true" />
                                    Mark Paid
                                </button>
                            </form>

                            <form v-else class="payment-plan-action-form compact-upload-form" @submit.prevent="uploadPaymentPlanAttachment(plan)">
                                <label>
                                    <span>Add Document</span>
                                    <input type="file" @change="selectPaymentPlanFile(plan.id, $event)" />
                                </label>
                                <label>
                                    <span>Remarks</span>
                                    <input v-model="paymentPlanForms[plan.id].attachment_remarks" type="text" />
                                </label>
                                <button class="secondary-action compact-action" type="submit" :disabled="isUploadingPaymentPlanAttachmentId === plan.id">
                                    <Loader2 v-if="isUploadingPaymentPlanAttachmentId === plan.id" class="spin-icon" :size="17" aria-hidden="true" />
                                    <Upload v-else :size="17" aria-hidden="true" />
                                    Upload Document
                                </button>
                            </form>

                            <div v-if="plan.attachments.length > 0" class="payment-plan-attachment-list">
                                <span v-for="attachment in plan.attachments" :key="attachment.id">
                                    <button
                                        v-if="attachment.download_url"
                                        class="table-link-button"
                                        type="button"
                                        @click="downloadUploadedEvidence(attachment.download_url, attachment.original_file_name ?? 'payment-document', 'the payment document')"
                                    >
                                        <Download :size="14" aria-hidden="true" />
                                        {{ attachment.original_file_name ?? 'Attachment' }}
                                    </button>
                                    <template v-else>{{ attachment.original_file_name ?? 'Attachment' }}</template>
                                    <small>uploaded {{ formatDateTime(attachment.created_at) }} by {{ attachment.uploaded_by_name ?? 'User' }}</small>
                                </span>
                            </div>
                        </article>
                    </div>

                    <div v-else class="payment-agreement-list">
                        <article v-for="schedule in item.quotation_payment_schedules" :key="schedule.id">
                            <strong>{{ schedule.label }} - {{ schedule.payment_percentage }}%</strong>
                            <span>{{ schedule.payment_method_label }} | {{ schedule.due_text }}</span>
                            <small v-if="schedule.notes">{{ schedule.notes }}</small>
                        </article>
                        <p v-if="item.quotation_payment_schedules.length === 0" class="empty-note">
                            {{ item.quotation_payment_schedule_summary ?? 'No payment schedule recorded.' }}
                        </p>
                    </div>
                </article>
            </section>

            <section class="workflow-stepper" aria-label="Follow-up workflow">
                <button
                    v-for="(step, index) in followUpSteps"
                    :key="step.key"
                    class="workflow-step"
                    :class="{ active: activeFollowUpStep === step.key, complete: step.complete, locked: !step.available }"
                    type="button"
                    :disabled="!step.available"
                    :aria-current="activeFollowUpStep === step.key ? 'step' : undefined"
                    @click="selectFollowUpStep(step.key)"
                >
                    <span class="workflow-step-number">
                        <CheckCircle2 v-if="step.complete" :size="16" aria-hidden="true" />
                        <template v-else>{{ index + 1 }}</template>
                    </span>
                    <strong>{{ step.label }}</strong>
                    <small>{{ step.caption }}</small>
                </button>
            </section>

            <section v-if="activeFollowUpStep === 'acknowledgement'" class="follow-up-detail-grid stage-primary-grid">
                <article class="follow-up-panel">
                    <header>
                        <div>
                            <p>Order Acknowledgement</p>
                            <h2>Supplier Confirmation</h2>
                        </div>
                        <FileCheck2 :size="22" aria-hidden="true" />
                    </header>

                    <div class="acknowledgement-summary">
                        <span>Received</span>
                        <strong>{{ formatDateTime(item.acknowledgement_received_at) }}</strong>
                        <span>By</span>
                        <strong>{{ item.acknowledged_by_name ?? '-' }}</strong>
                        <span>File</span>
                        <strong>
                            <button
                                v-if="item.acknowledgement_download_url"
                                class="table-link-button"
                                type="button"
                                @click="downloadUploadedEvidence(item.acknowledgement_download_url, item.acknowledgement_original_file_name ?? 'acknowledgement', 'the acknowledgement')"
                            >
                                <Download :size="14" aria-hidden="true" />
                                {{ item.acknowledgement_original_file_name ?? 'Download acknowledgement' }}
                            </button>
                            <template v-else>{{ item.acknowledgement_original_file_name ?? '-' }}</template>
                        </strong>
                    </div>

                    <form class="follow-up-form" @submit.prevent="saveAcknowledgement">
                        <label>
                            <span>Received Date</span>
                            <input v-model="acknowledgementForm.acknowledgement_received_at" type="datetime-local" required />
                        </label>
                        <label>
                            <span>Notes</span>
                            <textarea v-model="acknowledgementForm.acknowledgement_notes" rows="3"></textarea>
                        </label>
                        <label>
                            <span>Acknowledgement File</span>
                            <input type="file" @change="selectAcknowledgementFile" />
                        </label>
                        <button class="primary-action compact-action" type="submit" :disabled="!canSaveAcknowledgement">
                            <Loader2 v-if="isSavingAcknowledgement" class="spin-icon" :size="17" aria-hidden="true" />
                            <Upload v-else :size="17" aria-hidden="true" />
                            Record Acknowledgement
                        </button>
                    </form>
                </article>
            </section>

            <section v-if="activeFollowUpStep === 'shipping'" class="follow-up-detail-grid shipping-grid">
                <article class="follow-up-panel shipping-documents-panel">
                    <header>
                        <div>
                            <p>Shipping Documents</p>
                            <h2>Required Uploads</h2>
                        </div>
                        <span class="stage-pill" :class="item.shipping_documents_complete ? 'teal' : 'amber'">
                            {{ item.shipping_documents_complete ? 'Complete' : 'Pending' }}
                        </span>
                    </header>

                    <div class="shipping-document-list">
                        <article v-for="document in requiredShippingDocuments" :key="document.document_type" class="shipping-document-row">
                            <div>
                                <strong>{{ document.label }}</strong>
                                <span>{{ statusLabelForDocument(document.status) }}</span>
                                <small v-if="document.document_type === 'packing_list'">Upload Packing List from carrier</small>
                                <small v-else>Required before ETA</small>
                                <small v-if="document.original_file_name">{{ document.original_file_name }}</small>
                            </div>

                            <label>
                                <span>Document No.</span>
                                <input v-model="shippingDocumentForms[document.document_type].document_number" type="text" />
                            </label>
                            <label>
                                <span>Date</span>
                                <input v-model="shippingDocumentForms[document.document_type].document_date" type="date" />
                            </label>
                            <label>
                                <span>File</span>
                                <input type="file" @change="selectShippingDocumentFile(document.document_type, $event)" />
                            </label>
                            <div class="shipping-document-actions">
                                <button class="secondary-action compact-action" type="button" :disabled="isSavingDocumentType === document.document_type" @click="uploadShippingDocument(document)">
                                    <Loader2 v-if="isSavingDocumentType === document.document_type" class="spin-icon" :size="17" aria-hidden="true" />
                                    <Upload v-else :size="17" aria-hidden="true" />
                                    Upload
                                </button>
                                <button
                                    v-if="document.download_url"
                                    class="table-link-button"
                                    type="button"
                                    @click="downloadUploadedEvidence(document.download_url, document.original_file_name ?? document.label, document.label)"
                                >
                                    <Download :size="14" aria-hidden="true" />
                                    Download
                                </button>
                            </div>
                        </article>

                        <div class="shipping-document-subtitle">
                            <strong>Optional Transport Documents</strong>
                            <span>Upload any transport file you receive: bill of lading, airway bill, land transport, or carrier document.</span>
                        </div>

                        <article v-for="document in optionalTransportDocuments" :key="document.document_type" class="shipping-document-row optional-document-row">
                            <div>
                                <strong>{{ document.label }}</strong>
                                <span>{{ statusLabelForDocument(document.status) }}</span>
                                <small v-if="document.original_file_name">{{ document.original_file_name }}</small>
                            </div>

                            <label>
                                <span>Document No.</span>
                                <input v-model="shippingDocumentForms[document.document_type].document_number" type="text" />
                            </label>
                            <label>
                                <span>Date</span>
                                <input v-model="shippingDocumentForms[document.document_type].document_date" type="date" />
                            </label>
                            <label>
                                <span>File</span>
                                <input type="file" @change="selectShippingDocumentFile(document.document_type, $event)" />
                            </label>
                            <div class="shipping-document-actions">
                                <button class="secondary-action compact-action" type="button" :disabled="isSavingDocumentType === document.document_type" @click="uploadShippingDocument(document)">
                                    <Loader2 v-if="isSavingDocumentType === document.document_type" class="spin-icon" :size="17" aria-hidden="true" />
                                    <Upload v-else :size="17" aria-hidden="true" />
                                    Upload
                                </button>
                                <button
                                    v-if="document.download_url"
                                    class="table-link-button"
                                    type="button"
                                    @click="downloadUploadedEvidence(document.download_url, document.original_file_name ?? document.label, document.label)"
                                >
                                    <Download :size="14" aria-hidden="true" />
                                    Download
                                </button>
                            </div>
                        </article>
                    </div>

                    <button class="primary-action compact-action" type="button" :disabled="item.shipping_documents_complete || isCompletingShippingDocuments" @click="completeShippingDocuments">
                        <Loader2 v-if="isCompletingShippingDocuments" class="spin-icon" :size="17" aria-hidden="true" />
                        <CheckCircle2 v-else :size="17" aria-hidden="true" />
                        Complete Shipping Documents
                    </button>
                </article>
            </section>

            <section v-if="activeFollowUpStep === 'logistics' || activeFollowUpStep === 'delivery'" class="follow-up-detail-grid logistics-grid">
                <article class="follow-up-panel logistics-panel">
                    <header>
                        <div>
                            <p>ETA / Logistics</p>
                            <h2>{{ logisticsCase ? statusLabel(logisticsCase.status) : 'Ready for ETA' }}</h2>
                        </div>
                        <Truck :size="22" aria-hidden="true" />
                    </header>

                    <p v-if="activeFollowUpStep === 'logistics' && !item.shipping_documents_complete" class="logistics-gate">
                        Shipping documents pending.
                    </p>

                    <form v-if="activeFollowUpStep === 'logistics'" class="follow-up-form logistics-form-grid" @submit.prevent="saveEta">
                        <label>
                            <span>Delivery Responsibility</span>
                            <select v-model="logisticsForm.delivery_responsibility" :disabled="isSavingLogistics !== null">
                                <option value="isc">ISC / Internal Delivery</option>
                                <option value="buyer_agent">Buyer Agent</option>
                                <option value="supplier">Supplier / Manufacturer</option>
                            </select>
                        </label>
                        <label>
                            <span>ETA</span>
                            <input v-model="logisticsForm.eta_at" type="datetime-local" :disabled="isSavingLogistics !== null" />
                        </label>
                        <label>
                            <span>Agent Name</span>
                            <input v-model="logisticsForm.agent_name" type="text" :disabled="isSavingLogistics !== null" />
                        </label>
                        <label>
                            <span>Agent Contact</span>
                            <input v-model="logisticsForm.agent_contact" type="text" :disabled="isSavingLogistics !== null" />
                        </label>
                        <label class="logistics-wide">
                            <span>ETA Remarks</span>
                            <textarea v-model="logisticsForm.remarks" rows="3" :disabled="isSavingLogistics !== null"></textarea>
                        </label>
                        <button class="primary-action compact-action logistics-wide" type="submit" :disabled="!canSaveEta">
                            <Loader2 v-if="isSavingLogistics === 'eta'" class="spin-icon" :size="17" aria-hidden="true" />
                            <Clock3 v-else :size="17" aria-hidden="true" />
                            Save ETA
                        </button>
                    </form>

                    <p v-if="activeFollowUpStep === 'delivery' && !logisticsCase" class="logistics-gate">
                        Save ETA / logistics first, then record the delivery handoff or receipt here.
                    </p>

                    <div v-if="logisticsCase && activeFollowUpStep === 'delivery'" class="logistics-action-stack">
                        <label>
                            <span>Step Remarks</span>
                            <textarea v-model="logisticsEventForm.remarks" rows="3" :disabled="isSavingLogistics !== null"></textarea>
                        </label>

                        <div class="logistics-action-grid">
                            <article v-if="shouldShowDocumentsSent" class="logistics-step">
                                <div>
                                    <Send :size="18" aria-hidden="true" />
                                    <strong>Documents Sent</strong>
                                </div>
                                <input v-model="logisticsEventForm.documents_sent_at" type="datetime-local" />
                                <button class="secondary-action compact-action" type="button" :disabled="!canMarkDocumentsSent" @click="markDocumentsSent">
                                    <Loader2 v-if="isSavingLogistics === 'documents-sent'" class="spin-icon" :size="16" aria-hidden="true" />
                                    <Send v-else :size="16" aria-hidden="true" />
                                    Record
                                </button>
                            </article>

                            <article v-if="shouldShowArrival" class="logistics-step">
                                <div>
                                    <Truck :size="18" aria-hidden="true" />
                                    <strong>{{ isSupplierHandledDelivery ? 'Supplier Receipt' : 'Arrived' }}</strong>
                                </div>
                                <input v-model="logisticsEventForm.arrived_at" type="datetime-local" />
                                <button class="secondary-action compact-action" type="button" :disabled="!canMarkArrived" @click="markArrived">
                                    <Loader2 v-if="isSavingLogistics === 'arrived'" class="spin-icon" :size="16" aria-hidden="true" />
                                    <Truck v-else :size="16" aria-hidden="true" />
                                    Record
                                </button>
                            </article>

                            <article v-if="shouldShowWarehouseReceipt" class="logistics-step logistics-receipt-step">
                                <div>
                                    <Warehouse :size="18" aria-hidden="true" />
                                    <strong>Warehouse Receipt</strong>
                                </div>
                                <div class="logistics-receipt-grid">
                                    <input v-model="logisticsEventForm.warehouse_received_at" type="datetime-local" />
                                    <input v-model="logisticsEventForm.received_location" type="text" placeholder="Location" />
                                    <input v-model="logisticsEventForm.received_quantity" type="number" min="0.001" step="0.001" placeholder="Qty" />
                                    <input v-model="logisticsEventForm.goods_condition" type="text" placeholder="Condition" />
                                </div>
                                <button class="secondary-action compact-action" type="button" :disabled="!canMarkWarehouseReceived" @click="markWarehouseReceived">
                                    <Loader2 v-if="isSavingLogistics === 'warehouse-received'" class="spin-icon" :size="16" aria-hidden="true" />
                                    <Warehouse v-else :size="16" aria-hidden="true" />
                                    Record
                                </button>
                            </article>

                            <article v-if="shouldShowBuyerReceipt" class="logistics-step logistics-receipt-step">
                                <div>
                                    <PackageCheck :size="18" aria-hidden="true" />
                                    <strong>Buyer Receipt</strong>
                                </div>
                                <div class="logistics-receipt-grid">
                                    <input v-model="logisticsEventForm.buyer_received_at" type="datetime-local" />
                                    <input v-model="logisticsEventForm.received_quantity" type="number" min="0.001" step="0.001" placeholder="Qty" />
                                    <input v-model="logisticsEventForm.goods_condition" type="text" placeholder="Condition" />
                                </div>
                                <button class="secondary-action compact-action" type="button" :disabled="!canMarkBuyerReceived" @click="markBuyerReceived">
                                    <Loader2 v-if="isSavingLogistics === 'buyer-received'" class="spin-icon" :size="16" aria-hidden="true" />
                                    <PackageCheck v-else :size="16" aria-hidden="true" />
                                    Record
                                </button>
                            </article>
                        </div>
                    </div>
                </article>

                <article class="follow-up-panel logistics-timeline-panel">
                    <header>
                        <div>
                            <p>Logistics Timeline</p>
                            <h2>{{ logisticsCase?.events.length ?? 0 }} Events</h2>
                        </div>
                        <Clock3 :size="22" aria-hidden="true" />
                    </header>

                    <div v-if="logisticsCase?.events.length" class="logistics-timeline">
                        <article v-for="event in logisticsCase.events" :key="event.id">
                            <span></span>
                            <div>
                                <strong>{{ event.title }}</strong>
                                <small>{{ formatDateTime(event.event_at) }} by {{ event.created_by_name ?? 'User' }}</small>
                                <p v-if="event.notes">{{ event.notes }}</p>
                            </div>
                        </article>
                    </div>
                    <p v-else class="empty-note">No logistics events yet.</p>
                </article>
            </section>

            <section v-if="(activeFollowUpStep === 'delivery' && isIscHandledDelivery) || activeFollowUpStep === 'invoice'" class="follow-up-detail-grid document-flow-grid">
                <article v-if="activeFollowUpStep === 'delivery' && isIscHandledDelivery" class="follow-up-panel document-flow-panel">
                    <header>
                        <div>
                            <p>Delivery Order</p>
                            <h2>{{ item.delivery_order?.delivery_order_reference ?? 'Create Delivery Order' }}</h2>
                        </div>
                        <ClipboardCheck :size="22" aria-hidden="true" />
                    </header>

                    <p v-if="remainingDeliveryQuantity <= 0" class="logistics-gate">
                        Goods must be received at ISC warehouse before another delivery order can be created.
                    </p>

                    <div v-if="item.delivery_order" class="document-summary">
                        <span>Status</span>
                        <strong>{{ statusLabel(item.delivery_order.status) }}</strong>
                        <span>Date</span>
                        <strong>{{ item.delivery_order.delivery_order_date ?? '-' }}</strong>
                        <span>Quantity</span>
                        <strong>{{ item.delivery_order.total_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Signed Copy</span>
                        <strong>{{ item.delivery_order.signed_original_file_name ?? '-' }}</strong>
                    </div>

                    <form class="follow-up-form" @submit.prevent="saveDeliveryOrder">
                        <label>
                            <span>Delivery Place</span>
                            <input v-model="deliveryOrderForm.delivery_place" type="text" placeholder="OXY Yard, Muscat" required />
                        </label>
                        <label>
                            <span>Delivery Qty</span>
                            <input v-model="deliveryOrderForm.quantity" type="number" min="0.001" step="0.001" :max="item.fulfilment.remaining_delivery_quantity" />
                        </label>
                        <label>
                            <span>Terms</span>
                            <textarea v-model="deliveryOrderForm.terms" rows="3"></textarea>
                        </label>
                        <button class="primary-action compact-action" type="submit" :disabled="!canSaveDeliveryOrder">
                            <Loader2 v-if="isSavingDeliveryOrder" class="spin-icon" :size="17" aria-hidden="true" />
                            <ClipboardCheck v-else :size="17" aria-hidden="true" />
                            Generate Delivery Order
                        </button>
                    </form>

                    <div v-if="item.delivery_order" class="document-downloads">
                        <button class="table-link-button" type="button" @click="downloadDeliveryOrder('docx', item.delivery_order)">
                            <Download :size="15" aria-hidden="true" />
                            Word
                        </button>
                        <button class="table-link-button" type="button" @click="downloadDeliveryOrder('pdf', item.delivery_order)">
                            <Download :size="15" aria-hidden="true" />
                            PDF
                        </button>
                        <button
                            v-if="item.delivery_order.signed_download_url"
                            class="table-link-button"
                            type="button"
                            @click="downloadUploadedEvidence(item.delivery_order.signed_download_url, item.delivery_order.signed_original_file_name ?? 'signed-delivery-order', 'the signed delivery order')"
                        >
                            <Download :size="15" aria-hidden="true" />
                            Signed Copy
                        </button>
                    </div>

                    <div v-if="item.delivery_orders.length > 0" class="payment-list">
                        <article v-for="deliveryOrder in item.delivery_orders" :key="deliveryOrder.id">
                            <strong>{{ deliveryOrder.delivery_order_reference }} | {{ deliveryOrder.total_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                            <span>{{ statusLabel(deliveryOrder.status) }} | {{ deliveryOrder.delivery_order_date ?? '-' }}</span>
                            <small>{{ deliveryOrder.items[0]?.buyer_item_code ? `Buyer Item ${deliveryOrder.items[0].buyer_item_code}` : 'Buyer item code not set' }}</small>
                            <div class="document-downloads">
                                <button class="table-link-button" type="button" @click="downloadDeliveryOrder('docx', deliveryOrder)">
                                    <Download :size="15" aria-hidden="true" />
                                    Word
                                </button>
                                <button class="table-link-button" type="button" @click="downloadDeliveryOrder('pdf', deliveryOrder)">
                                    <Download :size="15" aria-hidden="true" />
                                    PDF
                                </button>
                                <button
                                    v-if="deliveryOrder.signed_download_url"
                                    class="table-link-button"
                                    type="button"
                                    @click="downloadUploadedEvidence(deliveryOrder.signed_download_url, deliveryOrder.signed_original_file_name ?? 'signed-delivery-order', 'the signed delivery order')"
                                >
                                    <Download :size="15" aria-hidden="true" />
                                    Signed
                                </button>
                            </div>
                        </article>
                    </div>

                    <form v-if="item.delivery_order" class="follow-up-form signed-do-form" @submit.prevent="uploadSignedDeliveryOrder">
                        <label>
                            <span>Signed At</span>
                            <input v-model="deliveryOrderForm.signed_at" type="datetime-local" required />
                        </label>
                        <label>
                            <span>Signed Delivery Order</span>
                            <input type="file" required @change="selectSignedDeliveryOrderFile" />
                        </label>
                        <button class="secondary-action compact-action" type="submit" :disabled="!canUploadSignedDeliveryOrder">
                            <Loader2 v-if="isUploadingSignedDeliveryOrder" class="spin-icon" :size="17" aria-hidden="true" />
                            <Upload v-else :size="17" aria-hidden="true" />
                            Upload Signed Copy
                        </button>
                    </form>
                </article>

                <article v-if="activeFollowUpStep === 'invoice'" class="follow-up-panel document-flow-panel">
                    <header>
                        <div>
                            <p>Invoice</p>
                            <h2>{{ item.invoice?.invoice_reference ?? 'Create Invoice' }}</h2>
                        </div>
                        <ReceiptText :size="22" aria-hidden="true" />
                    </header>

                    <p v-if="remainingInvoiceQuantity <= 0 && item.invoices.length === 0" class="logistics-gate">
                        Invoice can be created after signed DO upload, buyer receipt confirmation, or supplier receipt acknowledgement.
                    </p>
                    <p v-else-if="remainingInvoiceQuantity <= 0" class="logistics-gate">
                        All delivered quantity has been invoiced.
                    </p>

                    <div v-if="item.invoice" class="document-summary invoice-summary">
                        <span>Status</span>
                        <strong>{{ statusLabel(item.invoice.status) }}</strong>
                        <span>Due Date</span>
                        <strong>{{ item.invoice.due_date ?? '-' }}</strong>
                        <span>Quantity</span>
                        <strong>{{ item.invoice.total_quantity }} {{ item.fulfilment.uom ?? item.uom }}</strong>
                        <span>Total</span>
                        <strong>{{ item.invoice.currency }} {{ item.invoice.total_amount }}</strong>
                    </div>

                    <form class="follow-up-form invoice-form-grid" @submit.prevent="saveInvoice">
                        <label>
                            <span>Invoice Qty</span>
                            <input v-model="invoiceForm.quantity" type="number" min="0.001" step="0.001" :max="item.fulfilment.remaining_invoice_quantity" />
                        </label>
                        <label>
                            <span>Payment Days</span>
                            <input v-model.number="invoiceForm.payment_term_days" type="number" min="0" max="3650" required />
                        </label>
                        <label>
                            <span>Quotation VAT %</span>
                            <input v-model="invoiceForm.vat_rate" type="number" min="0" max="100" step="0.001" readonly />
                        </label>
                        <label>
                            <span>VAT Exception Reason</span>
                            <input v-model="invoiceForm.vat_exception_reason" type="text" />
                        </label>
                        <label class="logistics-wide">
                            <span>Bank Details</span>
                            <textarea v-model="invoiceForm.bank_details" rows="3"></textarea>
                        </label>
                        <label class="logistics-wide">
                            <span>Remarks</span>
                            <textarea v-model="invoiceForm.remarks" rows="3"></textarea>
                        </label>
                        <button class="primary-action compact-action logistics-wide" type="submit" :disabled="!canSaveInvoice">
                            <Loader2 v-if="isSavingInvoice" class="spin-icon" :size="17" aria-hidden="true" />
                            <ReceiptText v-else :size="17" aria-hidden="true" />
                            Generate Invoice
                        </button>
                    </form>

                    <div v-if="item.invoice" class="invoice-total-grid">
                        <span>Subtotal</span>
                        <strong>{{ item.invoice.currency }} {{ item.invoice.subtotal }}</strong>
                        <span>VAT {{ item.invoice.vat_rate }}%</span>
                        <strong>{{ item.invoice.currency }} {{ item.invoice.vat_amount }}</strong>
                        <span>Total</span>
                        <strong>{{ item.invoice.currency }} {{ item.invoice.total_amount }}</strong>
                    </div>

                    <div v-if="item.invoice" class="document-downloads">
                        <button class="table-link-button" type="button" @click="downloadInvoice('docx', item.invoice)">
                            <Download :size="15" aria-hidden="true" />
                            Word
                        </button>
                        <button class="table-link-button" type="button" @click="downloadInvoice('pdf', item.invoice)">
                            <Download :size="15" aria-hidden="true" />
                            PDF
                        </button>
                    </div>

                    <div v-if="item.invoices.length > 0" class="payment-list">
                        <article v-for="invoice in item.invoices" :key="invoice.id">
                            <strong>{{ invoice.invoice_reference }} | {{ invoice.currency }} {{ invoice.total_amount }}</strong>
                            <span>{{ statusLabel(invoice.status) }} | Qty {{ invoice.total_quantity }} {{ item.fulfilment.uom ?? item.uom }} | Balance {{ invoice.currency }} {{ invoice.balance_amount }}</span>
                            <small>{{ invoice.delivery_order_reference ?? 'No delivery order linked' }}</small>
                            <div class="document-downloads">
                                <button class="table-link-button" type="button" @click="downloadInvoice('docx', invoice)">
                                    <Download :size="15" aria-hidden="true" />
                                    Word
                                </button>
                                <button class="table-link-button" type="button" @click="downloadInvoice('pdf', invoice)">
                                    <Download :size="15" aria-hidden="true" />
                                    PDF
                                </button>
                            </div>
                        </article>
                    </div>
                </article>
            </section>

            <section v-if="activeFollowUpStep === 'payment'" class="follow-up-detail-grid payment-flow-grid">
                <article class="follow-up-panel payment-panel">
                    <header>
                        <div>
                            <p>Payment Tracking</p>
                            <h2>{{ selectedPaymentInvoice ? statusLabel(selectedPaymentInvoice.payment_status) : 'Invoice Required' }}</h2>
                        </div>
                        <ReceiptText :size="22" aria-hidden="true" />
                    </header>

                    <p v-if="item.invoices.length === 0" class="logistics-gate">
                        Generate the invoice before payment can be tracked.
                    </p>

                    <template v-if="selectedPaymentInvoice">
                        <div class="payment-summary-grid">
                            <span>Item Total</span>
                            <strong>{{ item.fulfilment.currency ?? selectedPaymentInvoice.currency }} {{ item.fulfilment.quotation_total_amount }}</strong>
                            <span>Item Paid</span>
                            <strong>{{ item.fulfilment.currency ?? selectedPaymentInvoice.currency }} {{ item.fulfilment.paid_amount }}</strong>
                            <span>Pending From Quotation</span>
                            <strong>{{ item.fulfilment.currency ?? selectedPaymentInvoice.currency }} {{ item.fulfilment.remaining_quotation_amount }}</strong>
                            <span>Selected Invoice</span>
                            <strong>{{ selectedPaymentInvoice.invoice_reference }}</strong>
                            <span>Invoice Balance</span>
                            <strong>{{ selectedPaymentInvoice.currency }} {{ selectedPaymentInvoice.balance_amount }}</strong>
                        </div>

                        <button class="secondary-action compact-action" type="button" :disabled="!canMarkInvoiceSent" @click="markInvoiceSent">
                            <Loader2 v-if="isMarkingInvoiceSent" class="spin-icon" :size="17" aria-hidden="true" />
                            <Send v-else :size="17" aria-hidden="true" />
                            Mark Invoice Sent
                        </button>

                        <form class="follow-up-form payment-form-grid" @submit.prevent="savePayment">
                            <label>
                                <span>Invoice</span>
                                <select v-model.number="paymentForm.invoice_id">
                                    <option v-for="invoice in item.invoices" :key="invoice.id" :value="invoice.id">
                                        {{ invoice.invoice_reference }} | {{ invoice.currency }} {{ invoice.balance_amount }}
                                    </option>
                                </select>
                            </label>
                            <label>
                                <span>Amount</span>
                                <input v-model="paymentForm.amount" type="number" min="0.001" step="0.001" required />
                            </label>
                            <label>
                                <span>Payment Date</span>
                                <input v-model="paymentForm.payment_date" type="date" required />
                            </label>
                            <label>
                                <span>Reference</span>
                                <input v-model="paymentForm.payment_reference" type="text" />
                            </label>
                            <label>
                                <span>Remarks</span>
                                <input v-model="paymentForm.remarks" type="text" />
                            </label>
                            <button class="primary-action compact-action logistics-wide" type="submit" :disabled="!canSavePayment">
                                <Loader2 v-if="isSavingPayment" class="spin-icon" :size="17" aria-hidden="true" />
                                <ReceiptText v-else :size="17" aria-hidden="true" />
                                Record Payment
                            </button>
                        </form>

                        <div class="payment-list">
                            <article v-for="payment in selectedPaymentInvoice.payments" :key="payment.id">
                                <strong>{{ payment.currency }} {{ payment.amount }}</strong>
                                <span>{{ payment.payment_reference ?? 'No reference' }}</span>
                                <small>{{ payment.payment_date ?? '-' }} by {{ payment.recorded_by_name ?? 'User' }}</small>
                                <p v-if="payment.remarks">{{ payment.remarks }}</p>
                            </article>
                            <p v-if="selectedPaymentInvoice.payments.length === 0" class="empty-note">No payments recorded yet.</p>
                        </div>
                    </template>
                </article>

                <article class="follow-up-panel close-panel">
                    <header>
                        <div>
                            <p>Job Closing</p>
                            <h2>{{ item.status === 'closed' ? 'Closed' : 'Close Job' }}</h2>
                        </div>
                        <CheckCircle2 :size="22" aria-hidden="true" />
                    </header>

                    <div class="document-summary">
                        <span>Current Status</span>
                        <strong>{{ statusLabel(item.status) }}</strong>
                        <span>Closed At</span>
                        <strong>{{ formatDateTime(item.closed_at) }}</strong>
                        <span>Payment Status</span>
                        <strong>{{ itemInvoiceBalance <= 0.0005 && item.invoices.length > 0 ? 'Paid' : 'Pending' }}</strong>
                    </div>

                    <form class="follow-up-form" @submit.prevent="closeJob">
                        <label>
                            <span>Closing Notes</span>
                            <textarea v-model="closeForm.closed_notes" rows="4"></textarea>
                        </label>
                        <button class="primary-action compact-action" type="submit" :disabled="!canCloseJob">
                            <Loader2 v-if="isClosingJob" class="spin-icon" :size="17" aria-hidden="true" />
                            <CheckCircle2 v-else :size="17" aria-hidden="true" />
                            Close Job
                        </button>
                    </form>
                </article>
            </section>

            <section class="follow-up-detail-grid stage-comment-grid">
                <article class="follow-up-panel stage-comment-panel">
                    <header>
                        <div>
                            <p>Stage Comments</p>
                            <h2>{{ activeWorkflowStep?.label ?? 'Workflow Stage' }}</h2>
                        </div>
                        <MessageSquarePlus :size="22" aria-hidden="true" />
                    </header>

                    <form class="follow-up-form" @submit.prevent="saveComment">
                        <label>
                            <span>Progress Comment</span>
                            <textarea v-model="commentForm.comment" rows="3" required></textarea>
                        </label>
                        <div class="comment-meta-grid">
                            <label>
                                <span>Type</span>
                                <select v-model="commentForm.communication_type">
                                    <option value="email">Email</option>
                                    <option value="call">Call</option>
                                    <option value="meeting">Meeting</option>
                                    <option value="note">Note</option>
                                </select>
                            </label>
                            <label>
                                <span>Contacted</span>
                                <input v-model="commentForm.contacted_person" type="text" />
                            </label>
                        </div>
                        <label>
                            <span>Next Action</span>
                            <input v-model="commentForm.next_action" type="text" />
                        </label>
                        <button class="primary-action compact-action" type="submit" :disabled="!canSaveComment">
                            <Loader2 v-if="isSavingComment" class="spin-icon" :size="17" aria-hidden="true" />
                            <MessageSquarePlus v-else :size="17" aria-hidden="true" />
                            Add Stage Comment
                        </button>
                    </form>

                    <div class="comment-list">
                        <article v-for="comment in activeStageComments" :key="comment.id">
                            <strong>{{ comment.created_by_name ?? 'User' }}</strong>
                            <span>{{ formatDateTime(comment.created_at) }} - {{ comment.communication_type ?? 'note' }}</span>
                            <p>{{ comment.comment }}</p>
                            <small v-if="comment.contacted_person">Contacted: {{ comment.contacted_person }}</small>
                            <small v-if="comment.next_action">Next: {{ comment.next_action }}</small>
                        </article>
                        <p v-if="activeStageComments.length === 0" class="empty-note">No comments for this stage yet.</p>
                    </div>
                </article>

                <article class="follow-up-panel stage-audit-panel">
                    <header>
                        <div>
                            <p>Audit View</p>
                            <h2>{{ item.comments.length }} Total Comments</h2>
                        </div>
                        <Clock3 :size="22" aria-hidden="true" />
                    </header>

                    <div class="stage-comment-counts">
                        <button
                            v-for="step in followUpSteps"
                            :key="step.key"
                            type="button"
                            :class="{ active: activeFollowUpStep === step.key }"
                            :disabled="!step.available"
                            @click="selectFollowUpStep(step.key)"
                        >
                            <strong>{{ item.comments_by_stage[step.key]?.length ?? 0 }}</strong>
                            <span>{{ step.label }}</span>
                        </button>
                    </div>

                    <div class="document-summary">
                        <span>Current Stage</span>
                        <strong>{{ item.current_stage_label }}</strong>
                        <span>Last Comment</span>
                        <strong>{{ item.latest_comment ? formatDateTime(item.latest_comment.created_at) : '-' }}</strong>
                        <span>Next Reminder</span>
                        <strong>{{ formatDateTime(item.next_follow_up_at) }}</strong>
                    </div>
                </article>
            </section>

            <section v-if="isAdminUser" class="follow-up-detail-grid full-timeline-grid">
                <article class="follow-up-panel full-timeline-panel">
                    <header>
                        <div>
                            <p>Full Timeline</p>
                            <h2>{{ timelineEvents.length }} Events</h2>
                        </div>
                        <Clock3 :size="22" aria-hidden="true" />
                    </header>

                    <div v-if="timelineEvents.length" class="full-timeline-list">
                        <article v-for="event in timelineEvents" :key="event.id">
                            <span class="timeline-dot"></span>
                            <div>
                                <div class="timeline-event-top">
                                    <strong>{{ event.summary }}</strong>
                                    <small>{{ event.stage_label }} - {{ event.user_name ?? 'System' }}</small>
                                </div>
                                <p>{{ formatDateTime(event.occurred_at) }}</p>
                                <small v-if="event.elapsed_from_previous_label" class="timeline-elapsed">
                                    {{ event.elapsed_from_previous_label }}
                                </small>
                            </div>
                        </article>
                    </div>
                    <p v-else class="empty-note">No audit timeline events yet.</p>
                </article>
            </section>

            <div class="workflow-step-actions">
                <button class="secondary-action compact-action" type="button" :disabled="!previousFollowUpStep" @click="moveFollowUpStep('previous')">
                    <ArrowLeft :size="16" aria-hidden="true" />
                    {{ previousFollowUpStep ? previousFollowUpStep.label : 'Previous Step' }}
                </button>
                <button class="primary-action compact-action" type="button" :disabled="!nextFollowUpStep" @click="moveFollowUpStep('next')">
                    {{ nextFollowUpStep ? `Next: ${nextFollowUpStep.label}` : 'Workflow Complete' }}
                </button>
            </div>
        </template>
    </section>
</template>
