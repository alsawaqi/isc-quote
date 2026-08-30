<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    CheckCircle2,
    Download,
    FileText,
    Loader2,
    Plus,
    RefreshCcw,
    Save,
    Search,
    Truck,
    UserRound,
} from 'lucide-vue-next';
import { downloadProtectedFile, requestJson } from '../auth';
import RichTextEditor from './RichTextEditor.vue';

interface SupplierOption {
    id: number;
    company_id: number;
    company_name: string;
    company_code: string | null;
    primary_contact_id: number | null;
    primary_contact_name: string | null;
    manufacturer_id: number | null;
    manufacturer_name: string | null;
    manufacturer_ids: number[];
    manufacturers: Array<{ id: number; name: string }>;
    requires_factory: boolean;
    locations: FactoryOption[];
}

interface FactoryOption {
    id: number;
    company_id: number;
    name: string;
    location: string;
    address?: string | null;
    country_name?: string | null;
    manufacturer_id?: number | null;
    manufacturer_name?: string | null;
    status?: 'active' | 'inactive';
}

interface ContactOption {
    id: number;
    company_id: number;
    name: string;
    email?: string | null;
    mobile?: string | null;
    telephone?: string | null;
    all_locations: boolean;
    is_primary_supplier: boolean;
    location_ids: number[];
    status?: 'active' | 'inactive';
}

interface SelectOption {
    id: string | number;
    code?: string;
    name: string;
}

interface CountryOption {
    id: number;
    name: string;
    country_code?: string | null;
}

interface BuyerDefault {
    company_id: number;
    company_name: string;
    contact_id: number;
    contact_name: string;
}

interface PendingItemFilterOption {
    value: string;
    label: string;
}

interface PendingItemFilters {
    buyers: PendingItemFilterOption[];
    manufacturers: PendingItemFilterOption[];
    quotations: PendingItemFilterOption[];
}

interface PendingItem {
    quotation_item_id: number;
    quotation_id: number;
    quotation_reference: string;
    quotation_status: string | null;
    quotation_closing_at: string | null;
    buyer_company_name: string;
    buyer_po_id: number;
    buyer_po_item_id: number | null;
    buyer_po_number: string;
    buyer_po_date: string | null;
    buyer_item_code: string | null;
    buyer_po_item_amount: string | null;
    delivery_date?: string | null;
    incoterm_id: number | null;
    incoterm_code: string | null;
    manufacturer_id: number | null;
    manufacturer_name: string | null;
    product_code: string | null;
    product_name: string;
    title: string;
    description: string | null;
    quantity: string;
    uom: string;
    quotation_unit_price: string;
    quotation_total_price: string;
}

interface TermDefault {
    key: string;
    title: string;
}

interface SupplierPoOptions {
    buyer: BuyerDefault | null;
    suppliers: SupplierOption[];
    supplier_contacts: ContactOption[];
    incoterms: SelectOption[];
    countries: CountryOption[];
    currencies: SelectOption[];
    period_units: SelectOption[];
    delivery_types: SelectOption[];
    pending_items: PendingItem[];
    pending_item_filters: PendingItemFilters;
    term_defaults: TermDefault[];
}

interface SelectedLine {
    quotation_item_id: number;
    unit_cost: number | null;
    item_description: string;
    company_location_id: string;
    delivery_date: string;
    incoterm_id: string;
    coo_entries: CooEntryForm[];
}

interface CooEntryForm {
    country_id: string;
    country_name: string;
    amount: number | null;
    location: string;
    localKey: number;
}

interface SupplierPoTermForm {
    key: string | null;
    title: string;
    description: string;
    localKey: number;
}

interface SupplierPoRecord {
    id: number;
    po_reference: string;
    revision_number: number;
    supplier_id: number;
    supplier_company_id: number;
    supplier_contact_id: number;
    company_location_id: number | null;
    factory?: FactoryOption | null;
    factory_name?: string | null;
    factory_location?: string | null;
    incoterm_id: number | null;
    supplier_company_name: string;
    supplier_contact_name: string;
    supplier_quote_reference: string | null;
    payment_term_days: number;
    delivery_period_min: number;
    delivery_period_max: number;
    delivery_period_unit: string;
    delivery_period_type: string;
    accepted_invoice_currency: string;
    additional_charges_label: string | null;
    additional_charges: string;
    total_amount: string;
    docx_path: string;
    pdf_path: string;
    lines: SupplierPoLineRecord[];
    terms: SupplierPoTermRecord[];
    revisions: SupplierPoRevisionRecord[];
    downloads: {
        docx: string;
        pdf: string;
    };
}

interface SupplierPoLineRecord {
    quotation_item_id: number;
    quotation_id: number;
    quotation_reference: string;
    buyer_company_name: string | null;
    buyer_po_id: number;
    buyer_po_item_id: number | null;
    buyer_po_number: string;
    buyer_item_code: string | null;
    manufacturer_id: number | null;
    manufacturer_name: string | null;
    company_location_id: number | null;
    factory?: FactoryOption | null;
    factory_name?: string | null;
    factory_location?: string | null;
    factory_country_name?: string | null;
    factory_manufacturer_name?: string | null;
    product_code: string | null;
    product_name: string;
    title: string;
    description: string | null;
    quantity: string;
    uom: string;
    delivery_date: string | null;
    incoterm_id: number | null;
    incoterm_code: string | null;
    unit_cost: string;
    total_cost: string;
    coo_entries: CooEntryRecord[];
}

interface CooEntryRecord {
    id: number;
    country_id: number | null;
    country_name: string | null;
    country_code: string | null;
    amount: string | null;
    location: string | null;
    line_number: number;
}

interface SupplierPoTermRecord {
    line_number: number;
    key: string | null;
    title: string;
    description: string;
}

interface SupplierPoRevisionRecord {
    id: number;
    revision_number: number;
    po_reference: string;
    finalized_at: string | null;
    created_by_name: string | null;
    downloads: {
        docx: string;
        pdf: string;
    };
}

type Toast = { type: 'error' | 'success'; message: string };

const fallbackTermDefaults: TermDefault[] = [
    { key: 'acknowledgment', title: 'Acknowledgment' },
    { key: 'delivery_terms', title: 'Delivery Terms' },
    { key: 'documents', title: 'Documents' },
    { key: 'warranty', title: 'Warranty' },
    { key: 'bank_details', title: 'Bank details' },
];

const router = useRouter();
const route = useRoute();
const options = ref<SupplierPoOptions>({
    buyer: null,
    suppliers: [],
    supplier_contacts: [],
    incoterms: [],
    countries: [],
    currencies: [],
    period_units: [],
    delivery_types: [],
    pending_items: [],
    pending_item_filters: {
        buyers: [],
        manufacturers: [],
        quotations: [],
    },
    term_defaults: [],
});
const activeStep = ref<1 | 2 | 3 | 4>(1);
const selectedLines = ref<SelectedLine[]>([]);
const selectedItemCache = ref<Record<number, PendingItem>>({});
const terms = ref<SupplierPoTermForm[]>([]);
const createdSupplierPo = ref<SupplierPoRecord | null>(null);
const isLoading = ref(false);
const isLoadingItems = ref(false);
const isCreating = ref(false);
const isHydrating = ref(false);
const toast = ref<Toast | null>(null);
const lastAutomaticDeliveryTerm = ref('');
let itemSearchTimer: number | undefined;

const form = reactive({
    supplier_id: '',
    supplier_contact_id: '',
    company_location_id: '',
    supplier_quote_reference: '',
    payment_term_days: 30,
    delivery_period_min: 22,
    delivery_period_max: 24,
    delivery_period_unit: 'weeks',
    delivery_period_type: 'working',
    accepted_invoice_currency: 'USD',
    incoterm_id: '',
    additional_charges_label: 'COO Charges USD',
    additional_charges: 0,
});
const itemFilters = reactive({
    current_only: true,
    search: '',
    quotation_reference: '',
    buyer_id: 'all',
    manufacturer_id: 'all',
    buyer_po_date_from: '',
    buyer_po_date_to: '',
});

const editId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isEditing = computed(() => Boolean(editId.value));
const selectedSupplier = computed(() => options.value.suppliers.find((supplier) => String(supplier.id) === String(form.supplier_id)) ?? null);
const supplierFactories = computed(() => selectedSupplier.value?.locations ?? []);
const selectedFactory = computed(() => supplierFactories.value.find((factory) => String(factory.id) === String(form.company_location_id)) ?? null);
const selectedIncoterm = computed(() => options.value.incoterms.find((incoterm) => String(incoterm.id) === String(form.incoterm_id)) ?? null);
const filteredSupplierContacts = computed(() => {
    return options.value.supplier_contacts.filter((contact) => {
        if (String(contact.company_id) !== String(selectedSupplier.value?.company_id ?? '')) return false;
        if (createdSupplierPo.value?.supplier_contact_id === contact.id) return true;
        if (!form.company_location_id) return true;

        return contact.all_locations || contact.location_ids.includes(Number(form.company_location_id));
    });
});
const itemCandidates = computed(() => {
    return options.value.pending_items;
});
const selectedItems = computed(() => {
    return selectedLines.value
        .map((line) => ({
            line,
            item: selectedItemCache.value[line.quotation_item_id] ?? options.value.pending_items.find((candidate) => candidate.quotation_item_id === line.quotation_item_id) ?? null,
        }))
        .filter((entry): entry is { line: SelectedLine; item: PendingItem } => Boolean(entry.item));
});
const subtotal = computed(() => {
    return selectedItems.value.reduce((total, entry) => total + Number(entry.item.quantity) * Number(entry.line.unit_cost ?? 0), 0);
});
const grandTotal = computed(() => subtotal.value + Number(form.additional_charges || 0));
const canSaveHeader = computed(() => {
    const factorySelected = !selectedSupplier.value?.requires_factory || Boolean(form.company_location_id);

    return Boolean(options.value.buyer)
        && Boolean(selectedSupplier.value)
        && factorySelected
        && Boolean(form.supplier_contact_id)
        && Boolean(form.accepted_invoice_currency);
});
const canSaveItems = computed(() => selectedLines.value.length > 0 && selectedLines.value.every(
    (line) => line.unit_cost !== null
        && Number.isFinite(line.unit_cost)
        && line.unit_cost >= 0
        && (!lineFactoryRequired() || Boolean(line.company_location_id))
        && Boolean(line.delivery_date)
        && Boolean(line.incoterm_id)
        && hasRichTextContent(line.item_description),
));
const canSaveTerms = computed(() => terms.value.length > 0 && terms.value.every((term) => term.title.trim() && term.description.trim()));
const canCreate = computed(() => canSaveHeader.value && canSaveItems.value && canSaveTerms.value && !isCreating.value);
const pageTitle = computed(() => (isEditing.value ? 'Edit Supplier PO' : 'Create Supplier PO'));
const reviewTitle = computed(() => (isEditing.value ? 'Review & Update' : 'Review & Create'));
const submitLabel = computed(() => (isEditing.value ? 'Update Supplier PO' : 'Create Supplier PO'));
const busyLabel = computed(() => (isEditing.value ? 'Updating...' : 'Creating...'));

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

function hasRichTextContent(value: string): boolean {
    const text = value
        .replace(/<[^>]*>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .trim();

    return text.length > 0;
}

function optionName(optionsList: SelectOption[], id: string | number): string {
    return optionsList.find((option) => String(option.id) === String(id))?.name ?? String(id);
}

function incotermOptionLabel(id: string | number | null): string {
    if (!id) {
        return '-';
    }

    const incoterm = options.value.incoterms.find((option) => String(option.id) === String(id));

    if (!incoterm) {
        return String(id);
    }

    const code = incoterm.code?.trim() ?? '';
    const name = incoterm.name.trim();

    return code && code.toLowerCase() !== name.toLowerCase() ? `${code} - ${name}` : (code || name);
}

function factoryOptionLabel(factory: FactoryOption): string {
    const parts = [factory.name, factory.location].filter(Boolean);
    const manufacturer = factory.manufacturer_name ? ` (${factory.manufacturer_name})` : '';
    const status = factory.status === 'inactive' ? ' (inactive - retained)' : '';

    return `${parts.join(' - ')}${manufacturer}${status}`;
}

function lineFactoryRequired(): boolean {
    return Boolean(selectedSupplier.value?.requires_factory || supplierFactories.value.length > 0);
}

function factoryMatchesItem(factory: FactoryOption, item: PendingItem): boolean {
    return !factory.manufacturer_id
        || !item.manufacturer_id
        || Number(factory.manufacturer_id) === Number(item.manufacturer_id);
}

function lineFactoryOptions(item: PendingItem, line?: SelectedLine): FactoryOption[] {
    const matching = supplierFactories.value.filter((factory) => factoryMatchesItem(factory, item));

    if (line?.company_location_id && !matching.some((factory) => String(factory.id) === String(line.company_location_id))) {
        const retainedFactory = supplierFactories.value.find((factory) => String(factory.id) === String(line.company_location_id));

        if (retainedFactory) {
            return [...matching, retainedFactory];
        }
    }

    return matching;
}

function defaultLineFactoryId(item: PendingItem): string {
    if (selectedFactory.value && factoryMatchesItem(selectedFactory.value, item)) {
        return String(selectedFactory.value.id);
    }

    const matchingFactories = supplierFactories.value.filter((factory) => factory.status !== 'inactive' && factoryMatchesItem(factory, item));

    return matchingFactories.length === 1 ? String(matchingFactories[0].id) : '';
}

function lineFactoryLabel(line: SelectedLine, item?: PendingItem): string {
    const factory = supplierFactories.value.find((option) => String(option.id) === String(line.company_location_id))
        ?? (item ? lineFactoryOptions(item, line).find((option) => String(option.id) === String(line.company_location_id)) : null);

    return factory ? factoryOptionLabel(factory) : '-';
}

function defaultLineIncotermId(item: PendingItem): string {
    return item.incoterm_id ? String(item.incoterm_id) : (form.incoterm_id ? String(form.incoterm_id) : '');
}

function newCooEntry(): CooEntryForm {
    return {
        country_id: '',
        country_name: '',
        amount: null,
        location: '',
        localKey: Date.now() + Math.random(),
    };
}

function cooEntriesFromRecord(entries: CooEntryRecord[] = []): CooEntryForm[] {
    const mapped = entries.map((entry, index) => ({
        country_id: entry.country_id ? String(entry.country_id) : '',
        country_name: entry.country_name ?? '',
        amount: entry.amount !== null ? Number(entry.amount) : null,
        location: entry.location ?? '',
        localKey: Date.now() + index + Math.random(),
    }));

    return mapped.length > 0 ? mapped : [newCooEntry()];
}

function hasCooEntryContent(entry: CooEntryForm): boolean {
    return Boolean(
        entry.country_id
            || entry.country_name.trim()
            || String(entry.amount ?? '').trim()
            || entry.location.trim(),
    );
}

function visibleCooEntries(line: SelectedLine): CooEntryForm[] {
    return line.coo_entries.filter((entry) => hasCooEntryContent(entry));
}

function cooEntrySummary(entry: CooEntryForm): string {
    const selectedCountry = entry.country_id
        ? options.value.countries.find((country) => String(country.id) === String(entry.country_id))?.name
        : null;
    const parts = [
        selectedCountry ?? entry.country_name.trim(),
        String(entry.amount ?? '').trim() ? `Amount: ${entry.amount}` : '',
        entry.location.trim() ? `Location: ${entry.location.trim()}` : '',
    ].filter(Boolean);

    return parts.join(' | ');
}

function cooPayloadEntries(line: SelectedLine): Array<{ country_id: number | null; country_name: string | null; amount: number | null; location: string | null }> {
    return visibleCooEntries(line).map((entry) => ({
        country_id: entry.country_id ? Number(entry.country_id) : null,
        country_name: entry.country_name.trim() || null,
        amount: String(entry.amount ?? '').trim() ? Number(entry.amount) : null,
        location: entry.location.trim() || null,
    }));
}

function addCooEntry(line: SelectedLine): void {
    line.coo_entries.push(newCooEntry());
}

function removeCooEntry(line: SelectedLine, localKey: number): void {
    line.coo_entries = line.coo_entries.filter((entry) => entry.localKey !== localKey);
}

function selectedLineFor(itemId: number): SelectedLine | null {
    return selectedLines.value.find((line) => line.quotation_item_id === itemId) ?? null;
}

function cacheSelectedItem(item: PendingItem): void {
    selectedItemCache.value = {
        ...selectedItemCache.value,
        [item.quotation_item_id]: item,
    };
}

function removeSelectedItem(itemId: number): void {
    const existingIndex = selectedLines.value.findIndex((line) => line.quotation_item_id === itemId);

    if (existingIndex >= 0) {
        selectedLines.value.splice(existingIndex, 1);
    }

    const nextCache = { ...selectedItemCache.value };
    delete nextCache[itemId];
    selectedItemCache.value = nextCache;
}

function toggleItem(item: PendingItem): void {
    const existingIndex = selectedLines.value.findIndex((line) => line.quotation_item_id === item.quotation_item_id);

    if (existingIndex >= 0) {
        removeSelectedItem(item.quotation_item_id);
        return;
    }

    selectedLines.value.push({
        quotation_item_id: item.quotation_item_id,
        unit_cost: null,
        item_description: item.description ?? '',
        company_location_id: defaultLineFactoryId(item),
        // The exact delivery date is confirmed here, after the buyer PO is received.
        delivery_date: '',
        incoterm_id: defaultLineIncotermId(item),
        coo_entries: [newCooEntry()],
    });
    cacheSelectedItem(item);
}

function updateLineCost(itemId: number, value: string): void {
    const line = selectedLineFor(itemId);

    if (line) {
        const parsedValue = Number(value);
        line.unit_cost = value.trim() && Number.isFinite(parsedValue) ? parsedValue : null;
    }
}

function updateLineCostFromEvent(itemId: number, event: Event): void {
    updateLineCost(itemId, (event.target as HTMLInputElement).value);
}

function initializeTerms(defaults: TermDefault[]): void {
    terms.value = defaults.map((term, index) => ({
        key: term.key,
        title: term.title,
        description: defaultDescription(term.key),
        localKey: Date.now() + index,
    }));
    lastAutomaticDeliveryTerm.value = automaticDeliveryTermDescription();
}

function addTerm(): void {
    terms.value.push({
        key: null,
        title: '',
        description: '',
        localKey: Date.now() + terms.value.length,
    });
}

function moveTerm(index: number, direction: -1 | 1): void {
    const nextIndex = index + direction;

    if (nextIndex < 0 || nextIndex >= terms.value.length) {
        return;
    }

    const reordered = [...terms.value];
    const [term] = reordered.splice(index, 1);
    reordered.splice(nextIndex, 0, term);
    terms.value = reordered;
}

function defaultDescription(key: string): string {
    const defaults: Record<string, string> = {
        acknowledgment: 'Suppliers shall acknowledge receipt of this PO by email within TWO days.',
        delivery_terms: automaticDeliveryTermDescription(),
        documents: 'Shipping Documents: Invoice, Packing list, COO, Bill of Lading',
        warranty: 'Warranty shall be 12 months from commissioning or 18 months from supply.',
        bank_details: 'Payment will be transferred to supplier bank details.',
    };

    return defaults[key] ?? '';
}

function automaticDeliveryTermDescription(): string {
    const incoterm = selectedIncoterm.value;

    if (!incoterm) {
        return 'Incoterm to be confirmed before supplier PO acceptance.';
    }

    const code = incoterm.code?.trim() ?? '';
    const name = incoterm.name.trim();

    if (code && name && code.toLowerCase() !== name.toLowerCase()) {
        return `${code} - ${name}`;
    }

    return code || name;
}

function refreshAutomaticDeliveryTerm(): void {
    const deliveryTerm = terms.value.find((term) => term.key === 'delivery_terms');
    const nextAutomaticDescription = automaticDeliveryTermDescription();

    if (deliveryTerm && (!deliveryTerm.description.trim() || deliveryTerm.description.trim() === lastAutomaticDeliveryTerm.value.trim())) {
        deliveryTerm.description = nextAutomaticDescription;
    }

    lastAutomaticDeliveryTerm.value = nextAutomaticDescription;
}

function supplierPoLineToPendingItem(line: SupplierPoLineRecord): PendingItem {
    return {
        quotation_item_id: line.quotation_item_id,
        quotation_id: line.quotation_id,
        quotation_reference: line.quotation_reference,
        quotation_status: null,
        quotation_closing_at: null,
        buyer_company_name: line.buyer_company_name ?? '-',
        buyer_po_id: line.buyer_po_id,
        buyer_po_item_id: line.buyer_po_item_id,
        buyer_po_number: line.buyer_po_number,
        buyer_po_date: null,
        buyer_item_code: line.buyer_item_code,
        buyer_po_item_amount: null,
        delivery_date: line.delivery_date,
        incoterm_id: line.incoterm_id,
        incoterm_code: line.incoterm_code,
        manufacturer_id: line.manufacturer_id,
        manufacturer_name: line.manufacturer_name,
        product_code: line.product_code,
        product_name: line.product_name,
        title: line.title,
        description: line.description,
        quantity: line.quantity,
        uom: line.uom,
        quotation_unit_price: line.unit_cost,
        quotation_total_price: line.total_cost,
    };
}

function mergePendingItems(items: PendingItem[], lines: SupplierPoLineRecord[]): PendingItem[] {
    const merged = new Map<number, PendingItem>();

    for (const item of items) {
        merged.set(item.quotation_item_id, item);
    }

    for (const line of lines) {
        merged.set(line.quotation_item_id, supplierPoLineToPendingItem(line));
    }

    return Array.from(merged.values());
}

function applySupplierPo(supplierPo: SupplierPoRecord): void {
    isHydrating.value = true;
    options.value.pending_items = mergePendingItems(options.value.pending_items, supplierPo.lines);
    selectedItemCache.value = supplierPo.lines.reduce<Record<number, PendingItem>>((cache, line) => {
        const pendingItem = supplierPoLineToPendingItem(line);
        cache[pendingItem.quotation_item_id] = pendingItem;

        return cache;
    }, {});
    form.supplier_id = String(supplierPo.supplier_id);
    form.supplier_contact_id = '';
    form.company_location_id = supplierPo.company_location_id ? String(supplierPo.company_location_id) : '';
    form.supplier_quote_reference = supplierPo.supplier_quote_reference ?? '';
    form.payment_term_days = Number(supplierPo.payment_term_days);
    form.delivery_period_min = Number(supplierPo.delivery_period_min);
    form.delivery_period_max = Number(supplierPo.delivery_period_max);
    form.delivery_period_unit = supplierPo.delivery_period_unit;
    form.delivery_period_type = supplierPo.delivery_period_type;
    form.accepted_invoice_currency = supplierPo.accepted_invoice_currency;
    form.incoterm_id = supplierPo.incoterm_id ? String(supplierPo.incoterm_id) : '';
    form.additional_charges_label = supplierPo.additional_charges_label ?? '';
    form.additional_charges = Number(supplierPo.additional_charges);
    selectedLines.value = supplierPo.lines.map((line) => ({
        quotation_item_id: line.quotation_item_id,
        unit_cost: Number(line.unit_cost),
        item_description: line.description ?? '',
        company_location_id: line.company_location_id ? String(line.company_location_id) : (supplierPo.company_location_id ? String(supplierPo.company_location_id) : ''),
        delivery_date: line.delivery_date ?? '',
        incoterm_id: line.incoterm_id ? String(line.incoterm_id) : (supplierPo.incoterm_id ? String(supplierPo.incoterm_id) : ''),
        coo_entries: cooEntriesFromRecord(line.coo_entries),
    }));
    terms.value = supplierPo.terms.map((term, index) => ({
        key: term.key,
        title: term.title,
        description: term.description,
        localKey: Date.now() + index,
    }));
    lastAutomaticDeliveryTerm.value = automaticDeliveryTermDescription();
    createdSupplierPo.value = supplierPo;
    window.setTimeout(() => {
        form.supplier_contact_id = String(supplierPo.supplier_contact_id);
        isHydrating.value = false;
    });
}

function pendingItemParams(): URLSearchParams {
    const params = new URLSearchParams();

    if (form.supplier_id) {
        params.set('supplier_id', form.supplier_id);
    }

    if (form.company_location_id) {
        params.set('company_location_id', form.company_location_id);
    }

    if (editId.value) {
        params.set('supplier_po_id', String(editId.value));
    }

    if (itemFilters.current_only) {
        params.set('current_only', '1');
    }

    if (itemFilters.search.trim()) {
        params.set('search', itemFilters.search.trim());
    }

    if (itemFilters.quotation_reference.trim()) {
        params.set('quotation_reference', itemFilters.quotation_reference.trim());
    }

    if (itemFilters.buyer_id !== 'all') {
        params.set('buyer_id', itemFilters.buyer_id);
    }

    if (itemFilters.manufacturer_id !== 'all') {
        params.set('manufacturer_id', itemFilters.manufacturer_id);
    }

    if (itemFilters.buyer_po_date_from) {
        params.set('buyer_po_date_from', itemFilters.buyer_po_date_from);
    }

    if (itemFilters.buyer_po_date_to) {
        params.set('buyer_po_date_to', itemFilters.buyer_po_date_to);
    }

    return params;
}

async function loadOptions(): Promise<void> {
    isLoading.value = true;

    try {
        const params = pendingItemParams();
        options.value = await requestJson<SupplierPoOptions>(`/api/supplier-pos/create-options${params.toString() ? `?${params.toString()}` : ''}`);
        if (terms.value.length === 0) {
            initializeTerms(options.value.term_defaults.length > 0 ? options.value.term_defaults : fallbackTermDefaults);
        }

        if (isEditing.value && editId.value) {
            const payload = await requestJson<{ data: SupplierPoRecord }>(`/api/supplier-pos/${editId.value}`);
            applySupplierPo(payload.data);
        }
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load supplier PO setup.');
    } finally {
        isLoading.value = false;
    }
}

async function loadPendingItems(): Promise<void> {
    isLoadingItems.value = true;

    try {
        const params = pendingItemParams();
        const payload = await requestJson<SupplierPoOptions>(`/api/supplier-pos/create-options${params.toString() ? `?${params.toString()}` : ''}`);
        options.value = {
            ...options.value,
            buyer: payload.buyer,
            suppliers: payload.suppliers,
            supplier_contacts: payload.supplier_contacts,
            incoterms: payload.incoterms,
            countries: payload.countries,
            currencies: payload.currencies,
            period_units: payload.period_units,
            delivery_types: payload.delivery_types,
            pending_items: payload.pending_items,
            pending_item_filters: payload.pending_item_filters,
            term_defaults: payload.term_defaults,
        };
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load filtered items.');
    } finally {
        isLoadingItems.value = false;
    }
}

function resetItemFilters(): void {
    itemFilters.current_only = true;
    itemFilters.search = '';
    itemFilters.quotation_reference = '';
    itemFilters.buyer_id = 'all';
    itemFilters.manufacturer_id = 'all';
    itemFilters.buyer_po_date_from = '';
    itemFilters.buyer_po_date_to = '';
    void loadPendingItems();
}

async function saveSupplierPo(): Promise<void> {
    if (!canCreate.value) {
        return;
    }

    isCreating.value = true;

    try {
        const endpoint = isEditing.value && editId.value ? `/api/supplier-pos/${editId.value}` : '/api/supplier-pos';
        const payload = await requestJson<{ message: string; data: SupplierPoRecord }>(endpoint, {
            method: isEditing.value ? 'PUT' : 'POST',
            body: JSON.stringify({
                supplier_id: Number(form.supplier_id),
                supplier_contact_id: Number(form.supplier_contact_id),
                company_location_id: form.company_location_id ? Number(form.company_location_id) : null,
                supplier_quote_reference: form.supplier_quote_reference.trim() || null,
                payment_term_days: Number(form.payment_term_days),
                delivery_period_min: Number(form.delivery_period_min),
                delivery_period_max: Number(form.delivery_period_max),
                delivery_period_unit: form.delivery_period_unit,
                delivery_period_type: form.delivery_period_type,
                accepted_invoice_currency: form.accepted_invoice_currency,
                incoterm_id: form.incoterm_id ? Number(form.incoterm_id) : null,
                additional_charges_label: form.additional_charges_label.trim() || null,
                additional_charges: Number(form.additional_charges || 0),
                items: selectedLines.value.map((line) => ({
                    quotation_item_id: line.quotation_item_id,
                    unit_cost: Number(line.unit_cost),
                    item_description: line.item_description,
                    company_location_id: line.company_location_id ? Number(line.company_location_id) : null,
                    delivery_date: line.delivery_date || null,
                    incoterm_id: line.incoterm_id ? Number(line.incoterm_id) : null,
                    coo_entries: cooPayloadEntries(line),
                })),
                terms: terms.value.map((term, index) => ({
                    line_number: index + 1,
                    key: term.key,
                    title: term.title.trim(),
                    description: term.description.trim(),
                })),
            }),
        });

        createdSupplierPo.value = payload.data;
        showToast('success', payload.message);
        if (!isEditing.value) {
            await loadOptions();
        }
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save supplier PO.');
    } finally {
        isCreating.value = false;
    }
}

async function downloadDocument(format: 'docx' | 'pdf'): Promise<void> {
    if (!createdSupplierPo.value) {
        return;
    }

    try {
        const filename = `${createdSupplierPo.value.po_reference}-REV-${createdSupplierPo.value.revision_number}.${format}`.toUpperCase();
        await downloadProtectedFile(createdSupplierPo.value.downloads[format], filename);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download supplier PO.');
    }
}

async function downloadRevisionDocument(revision: SupplierPoRevisionRecord, format: 'docx' | 'pdf'): Promise<void> {
    try {
        const filename = `${revision.po_reference}-REV-${revision.revision_number}.${format}`.toUpperCase();
        await downloadProtectedFile(revision.downloads[format], filename);
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to download supplier PO.');
    }
}

watch(
    () => form.supplier_id,
    () => {
        if (isHydrating.value) {
            return;
        }

        const supplier = selectedSupplier.value;
        const previousFactoryId = form.company_location_id;
        form.company_location_id = supplier?.locations.length === 1 ? String(supplier.locations[0].id) : '';
        const primaryContact = filteredSupplierContacts.value.find((contact) => contact.id === supplier?.primary_contact_id)
            ?? filteredSupplierContacts.value.find((contact) => contact.is_primary_supplier)
            ?? filteredSupplierContacts.value[0];
        form.supplier_contact_id = primaryContact ? String(primaryContact.id) : '';
        selectedLines.value = [];
        selectedItemCache.value = {};

        if (previousFactoryId === form.company_location_id) {
            void loadPendingItems();
        }
    },
);

watch(
    () => form.company_location_id,
    () => {
        if (isHydrating.value) return;

        const selectedContactStillApplies = filteredSupplierContacts.value.some(
            (contact) => String(contact.id) === String(form.supplier_contact_id),
        );

        if (!selectedContactStillApplies) {
            const preferredContact = filteredSupplierContacts.value.find((contact) => contact.id === selectedSupplier.value?.primary_contact_id)
                ?? filteredSupplierContacts.value.find((contact) => contact.is_primary_supplier)
                ?? filteredSupplierContacts.value[0];
            form.supplier_contact_id = preferredContact ? String(preferredContact.id) : '';
        }

        selectedLines.value = [];
        selectedItemCache.value = {};
        void loadPendingItems();
    },
);

watch(
    () => form.incoterm_id,
    () => {
        selectedLines.value.forEach((line) => {
            if (!line.incoterm_id && form.incoterm_id) {
                line.incoterm_id = String(form.incoterm_id);
            }
        });

        if (!isHydrating.value) {
            refreshAutomaticDeliveryTerm();
        }
    },
);

watch(
    () => [
        itemFilters.current_only,
        itemFilters.quotation_reference,
        itemFilters.buyer_id,
        itemFilters.manufacturer_id,
        itemFilters.buyer_po_date_from,
        itemFilters.buyer_po_date_to,
    ],
    () => {
        if (!isHydrating.value) {
            void loadPendingItems();
        }
    },
);

watch(
    () => itemFilters.search,
    () => {
        if (isHydrating.value) {
            return;
        }

        window.clearTimeout(itemSearchTimer);
        itemSearchTimer = window.setTimeout(() => {
            void loadPendingItems();
        }, 320);
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
                <p>Procurement</p>
                <h1>{{ pageTitle }}</h1>
            </div>

            <button class="secondary-action icon-gap" type="button" @click="router.push('/supplier-pos')">
                <ArrowLeft :size="17" aria-hidden="true" />
                Supplier POs
            </button>
        </div>

        <nav class="quotation-steps" aria-label="Supplier PO steps">
            <button type="button" :class="{ active: activeStep === 1, done: canSaveHeader }" @click="activeStep = 1">
                <strong>1</strong>
                Supplier & Terms
            </button>
            <button type="button" :class="{ active: activeStep === 2, disabled: !canSaveHeader }" :disabled="!canSaveHeader" @click="activeStep = 2">
                <strong>2</strong>
                Select Items
            </button>
            <button type="button" :class="{ active: activeStep === 3, disabled: !canSaveItems }" :disabled="!canSaveItems" @click="activeStep = 3">
                <strong>3</strong>
                Terms
            </button>
            <button type="button" :class="{ active: activeStep === 4, disabled: !canSaveTerms }" :disabled="!canSaveTerms" @click="activeStep = 4">
                <strong>4</strong>
                {{ reviewTitle }}
            </button>
        </nav>

        <div v-if="isLoading" class="crud-empty">
            <Loader2 class="spin-icon" :size="20" aria-hidden="true" />
            Loading supplier PO setup...
        </div>

        <div v-else-if="activeStep === 1" class="quotation-grid">
            <form class="quotation-form-panel" @submit.prevent="activeStep = 2">
                <header>
                    <span class="panel-mark">
                        <Truck :size="20" aria-hidden="true" />
                    </span>
                    <div>
                        <h2>Step 1</h2>
                        <p>Supplier, supplier contact, payment, delivery, currency, and buyer defaults</p>
                    </div>
                </header>

                <div class="readonly-strip">
                    <div>
                        <FileText :size="18" aria-hidden="true" />
                        <span>Buyer</span>
                        <strong>{{ options.buyer?.company_name ?? 'Not configured' }}</strong>
                    </div>
                    <div>
                        <UserRound :size="18" aria-hidden="true" />
                        <span>Buyer Contact</span>
                        <strong>{{ options.buyer?.contact_name ?? 'Not configured' }}</strong>
                    </div>
                </div>

                <div class="quotation-form-grid">
                    <label class="quote-field">
                        <span>Supplier<b>*</b></span>
                        <select v-model="form.supplier_id" required>
                            <option value="">Select supplier</option>
                            <option v-for="supplier in options.suppliers" :key="supplier.id" :value="supplier.id">
                                {{ supplier.company_name }} - {{ supplier.company_code ?? 'SUP' }} - {{ supplier.manufacturers.map((manufacturer) => manufacturer.name).join(', ') || 'No manufacturer link' }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Supplier Factory<b v-if="selectedSupplier?.requires_factory">*</b></span>
                        <select
                            v-model="form.company_location_id"
                            :required="selectedSupplier?.requires_factory"
                            :disabled="!selectedSupplier || supplierFactories.length === 0"
                        >
                            <option value="">{{ supplierFactories.length ? 'Select factory' : 'No factory recorded' }}</option>
                            <option v-for="factory in supplierFactories" :key="factory.id" :value="factory.id">
                                {{ factory.name }} - {{ factory.location }}{{ factory.status === 'inactive' ? ' (inactive — retained)' : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Supplier Contact<b>*</b></span>
                        <select v-model="form.supplier_contact_id" required :disabled="!form.supplier_id">
                            <option value="">Select contact</option>
                            <option v-for="contact in filteredSupplierContacts" :key="contact.id" :value="contact.id">
                                {{ contact.name }}{{ contact.status === 'inactive' ? ' (inactive — retained)' : '' }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Supplier Quotation Ref</span>
                        <input v-model.trim="form.supplier_quote_reference" type="text" placeholder="E-mail / Quote ref" maxlength="150" />
                    </label>

                    <label class="quote-field">
                        <span>Payment Terms<b>*</b></span>
                        <div class="inline-sentence">
                            <span>Within</span>
                            <input v-model.number="form.payment_term_days" type="number" min="0" max="3650" required />
                            <span>days from supplier invoice</span>
                        </div>
                    </label>

                    <label class="quote-field delivery-field">
                        <span>Delivery<b>*</b></span>
                        <div class="delivery-controls">
                            <input v-model.number="form.delivery_period_min" type="number" min="0" max="3650" required />
                            <span>to</span>
                            <input v-model.number="form.delivery_period_max" type="number" min="0" max="3650" required />
                            <select v-model="form.delivery_period_type" required>
                                <option v-for="type in options.delivery_types" :key="String(type.id)" :value="type.id">{{ type.name }}</option>
                            </select>
                            <select v-model="form.delivery_period_unit" required>
                                <option v-for="unit in options.period_units" :key="String(unit.id)" :value="unit.id">{{ unit.name }}</option>
                            </select>
                        </div>
                    </label>

                    <label class="quote-field">
                        <span>Accepted Invoice Currency<b>*</b></span>
                        <select v-model="form.accepted_invoice_currency" required>
                            <option v-for="currency in options.currencies" :key="String(currency.id)" :value="currency.id">{{ currency.name }}</option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Delivery Term</span>
                        <select v-model="form.incoterm_id">
                            <option value="">Select Incoterm</option>
                            <option v-for="incoterm in options.incoterms" :key="String(incoterm.id)" :value="String(incoterm.id)">
                                {{ incoterm.code }} - {{ incoterm.name }}
                            </option>
                        </select>
                    </label>

                    <label class="quote-field">
                        <span>Additional Charges Label</span>
                        <input v-model.trim="form.additional_charges_label" type="text" maxlength="150" />
                    </label>

                    <label class="quote-field">
                        <span>Additional Charges</span>
                        <input v-model.number="form.additional_charges" type="number" min="0" step="0.001" />
                    </label>
                </div>

                <footer>
                    <button class="primary-action compact-action" type="submit" :disabled="!canSaveHeader">
                        <Save :size="17" aria-hidden="true" />
                        Continue
                    </button>
                </footer>
            </form>

            <aside class="quotation-preview-panel">
                <section class="preview-card">
                    <header>
                        <Truck :size="19" aria-hidden="true" />
                        <h2>Supplier</h2>
                    </header>
                    <dl>
                        <div>
                            <dt>Company</dt>
                            <dd>{{ selectedSupplier?.company_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Linked Manufacturers</dt>
                            <dd>{{ selectedSupplier?.manufacturers.map((manufacturer) => manufacturer.name).join(', ') || 'No manufacturer link' }}</dd>
                        </div>
                        <div>
                            <dt>Factory</dt>
                            <dd>{{ selectedFactory ? `${selectedFactory.name} — ${selectedFactory.location}` : (selectedSupplier?.requires_factory ? 'Select a factory' : 'Not required') }}</dd>
                        </div>
                        <div>
                            <dt>Contact</dt>
                            <dd>{{ filteredSupplierContacts.find((contact) => String(contact.id) === String(form.supplier_contact_id))?.name ?? '-' }}</dd>
                        </div>
                    </dl>
                </section>
                <section class="preview-card">
                    <header>
                        <FileText :size="19" aria-hidden="true" />
                        <h2>Pending Items</h2>
                    </header>
                    <strong class="currency-preview">{{ itemCandidates.length }}</strong>
                    <span class="preview-subtle">{{ selectedItems.length }} selected</span>
                </section>
            </aside>
        </div>

        <section v-else-if="activeStep === 2" class="quotation-form-panel quotation-items-panel">
            <header>
                <span class="panel-mark">
                    <Truck :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2>Step 2</h2>
                    <p>Select accepted buyer-PO items to consolidate into this supplier PO</p>
                </div>
            </header>

            <datalist id="supplier-po-quotation-filter-options">
                <option v-for="option in options.pending_item_filters.quotations" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </datalist>

            <section class="supplier-po-filter-panel" aria-label="Supplier PO item filters">
                <label class="supplier-po-current-toggle">
                    <input v-model="itemFilters.current_only" type="checkbox" />
                    <span>Current quotations</span>
                </label>
                <label class="follow-up-search-control supplier-po-filter-search">
                    <span>Search</span>
                    <span class="mini-search">
                        <Search :size="16" aria-hidden="true" />
                        <input v-model="itemFilters.search" type="search" placeholder="Item, quotation, customer, PO..." />
                    </span>
                </label>
                <label class="quote-field">
                    <span>Quotation Number</span>
                    <input v-model.trim="itemFilters.quotation_reference" list="supplier-po-quotation-filter-options" type="search" placeholder="Quotation number" />
                </label>
                <label class="quote-field">
                    <span>Customer</span>
                    <select v-model="itemFilters.buyer_id">
                        <option value="all">All customers</option>
                        <option v-for="option in options.pending_item_filters.buyers" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="quote-field">
                    <span>Manufacturer</span>
                    <select v-model="itemFilters.manufacturer_id">
                        <option value="all">All manufacturers</option>
                        <option v-for="option in options.pending_item_filters.manufacturers" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <label class="quote-field">
                    <span>Date From</span>
                    <input v-model="itemFilters.buyer_po_date_from" type="date" />
                </label>
                <label class="quote-field">
                    <span>Date To</span>
                    <input v-model="itemFilters.buyer_po_date_to" type="date" />
                </label>
                <button class="secondary-action compact-action" type="button" :disabled="isLoadingItems" @click="loadPendingItems">
                    <RefreshCcw :class="{ 'spin-icon': isLoadingItems }" :size="16" aria-hidden="true" />
                    Refresh
                </button>
                <button class="secondary-action compact-action" type="button" :disabled="isLoadingItems" @click="resetItemFilters">
                    Reset
                </button>
            </section>

            <div v-if="selectedItems.length" class="supplier-po-selection-strip">
                <article v-for="entry in selectedItems" :key="entry.item.quotation_item_id">
                    <header class="supplier-po-selected-head">
                        <div>
                            <strong>{{ entry.item.product_code ?? '-' }} - {{ entry.item.title }}</strong>
                            <span>{{ entry.item.buyer_company_name }} | Buyer PO {{ entry.item.buyer_po_number }} | {{ entry.item.quotation_reference }}</span>
                        </div>
                        <button class="table-link-button" type="button" @click="removeSelectedItem(entry.item.quotation_item_id)">Remove</button>
                    </header>
                    <div class="supplier-po-line-controls">
                        <label class="quote-field">
                            <span>Supplier Unit Cost<b>*</b></span>
                            <input
                                v-model.number="entry.line.unit_cost"
                                type="number"
                                min="0"
                                step="0.001"
                                required
                                placeholder="Enter supplier cost"
                            />
                        </label>
                        <label class="quote-field">
                            <span>Factory<b v-if="lineFactoryRequired()">*</b></span>
                            <select
                                v-model="entry.line.company_location_id"
                                :required="lineFactoryRequired()"
                                :disabled="!lineFactoryOptions(entry.item, entry.line).length"
                            >
                                <option value="">{{ lineFactoryOptions(entry.item, entry.line).length ? 'Select factory' : 'No matching factory' }}</option>
                                <option v-for="factory in lineFactoryOptions(entry.item, entry.line)" :key="factory.id" :value="String(factory.id)">
                                    {{ factoryOptionLabel(factory) }}
                                </option>
                            </select>
                        </label>
                        <label class="quote-field">
                            <span>Delivery Date<b>*</b></span>
                            <input v-model="entry.line.delivery_date" type="date" required />
                        </label>
                        <label class="quote-field">
                            <span>Incoterm<b>*</b></span>
                            <select v-model="entry.line.incoterm_id" required>
                                <option value="">Select Incoterm</option>
                                <option v-for="incoterm in options.incoterms" :key="String(incoterm.id)" :value="String(incoterm.id)">
                                    {{ incoterm.code }} - {{ incoterm.name }}
                                </option>
                            </select>
                        </label>
                    </div>
                    <label class="quote-field supplier-po-description-editor">
                        <span>Manufacturer / Supplier Description<b>*</b></span>
                        <RichTextEditor v-model="entry.line.item_description" placeholder="Description and technical notes for the supplier PO" />
                    </label>
                    <section class="supplier-po-coo-panel">
                        <header class="supplier-po-coo-heading">
                            <span>Country of Origin</span>
                            <button class="table-link-button" type="button" @click="addCooEntry(entry.line)">Add COO</button>
                        </header>
                        <div v-if="entry.line.coo_entries.length" class="supplier-po-coo-rows">
                            <div v-for="origin in entry.line.coo_entries" :key="origin.localKey" class="supplier-po-coo-row">
                                <label class="quote-field">
                                    <span>Country</span>
                                    <select v-model="origin.country_id">
                                        <option value="">Optional country</option>
                                        <option v-for="country in options.countries" :key="country.id" :value="String(country.id)">
                                            {{ country.name }}
                                        </option>
                                    </select>
                                </label>
                                <label class="quote-field">
                                    <span>Origin Name</span>
                                    <input v-model.trim="origin.country_name" type="text" maxlength="120" placeholder="Manual COO name" />
                                </label>
                                <label class="quote-field">
                                    <span>Amount</span>
                                    <input v-model.number="origin.amount" type="number" min="0" step="0.001" placeholder="0.000" />
                                </label>
                                <label class="quote-field">
                                    <span>Factory / Location</span>
                                    <input v-model.trim="origin.location" type="text" maxlength="255" placeholder="Factory or city" />
                                </label>
                                <button class="table-link-button supplier-po-coo-remove" type="button" @click="removeCooEntry(entry.line, origin.localKey)">Remove</button>
                            </div>
                        </div>
                    </section>
                </article>
            </div>

            <div class="supplier-po-items">
                <div v-if="isLoadingItems" class="crud-empty">
                    <Loader2 class="spin-icon" :size="20" aria-hidden="true" />
                    Loading filtered items...
                </div>
                <div v-else-if="itemCandidates.length === 0" class="crud-empty">No pending items match these filters.</div>
                <template v-else>
                    <article v-for="item in itemCandidates" :key="item.quotation_item_id" class="supplier-po-item-card">
                        <label>
                            <input type="checkbox" :checked="Boolean(selectedLineFor(item.quotation_item_id))" @change="toggleItem(item)" />
                            <span>
                                <strong>{{ item.product_code ?? '-' }} - {{ item.title }}</strong>
                                <small>{{ item.buyer_company_name }} | Buyer PO {{ item.buyer_po_number }} | Buyer Item {{ item.buyer_item_code ?? '-' }} | {{ item.quotation_reference }} | {{ item.buyer_po_date ?? '-' }}</small>
                            </span>
                        </label>

                        <div>
                            <span>{{ item.manufacturer_name ?? '-' }}</span>
                            <b>{{ item.quantity }} {{ item.uom }}</b>
                            <small>Delivery date set in this Supplier PO | {{ item.incoterm_code ?? 'No Incoterm' }}</small>
                        </div>

                        <div>
                            <span>Buyer Amount</span>
                            <b>{{ item.buyer_po_item_amount ?? item.quotation_total_price }}</b>
                            <small>Quote unit {{ item.quotation_unit_price }}</small>
                        </div>
                    </article>
                </template>
            </div>

            <footer class="items-footer">
                <div>
                    <span>Selected Total</span>
                    <strong>{{ form.accepted_invoice_currency }} {{ money(subtotal) }}</strong>
                </div>
                <button class="primary-action compact-action" type="button" :disabled="!canSaveItems" @click="activeStep = 3">
                    <Save :size="17" aria-hidden="true" />
                    Continue
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
                    <p>Supplier PO terms and document requirements</p>
                </div>
                <button class="secondary-action icon-gap" type="button" @click="addTerm">
                    <Plus :size="17" aria-hidden="true" />
                    Add Term
                </button>
            </header>

            <div class="terms-body">
                <article v-for="(term, index) in terms" :key="term.localKey" class="custom-term-row supplier-po-term-row">
                    <div class="term-sequence-tools">
                        <span>{{ index + 1 }}</span>
                        <button type="button" :disabled="index === 0" aria-label="Move term up" @click="moveTerm(index, -1)">
                            <ArrowUp :size="14" aria-hidden="true" />
                        </button>
                        <button type="button" :disabled="index === terms.length - 1" aria-label="Move term down" @click="moveTerm(index, 1)">
                            <ArrowDown :size="14" aria-hidden="true" />
                        </button>
                    </div>
                    <label class="quote-field">
                        <span>Title<b>*</b></span>
                        <input v-model.trim="term.title" type="text" required placeholder="Term title" />
                    </label>
                    <label class="quote-field">
                        <span>Description<b>*</b></span>
                        <textarea v-model.trim="term.description" rows="4" placeholder="Enter term details" required></textarea>
                    </label>
                </article>
            </div>

            <footer class="items-footer terms-footer">
                <div>
                    <span>Terms</span>
                    <strong>{{ terms.length }}</strong>
                </div>
                <button class="primary-action compact-action" type="button" :disabled="!canSaveTerms" @click="activeStep = 4">
                    <Save :size="17" aria-hidden="true" />
                    Review
                </button>
            </footer>
        </section>

        <section v-else class="quotation-form-panel quotation-review-panel">
            <header>
                <span class="panel-mark">
                    <CheckCircle2 :size="20" aria-hidden="true" />
                </span>
                <div>
                    <h2>{{ reviewTitle }}</h2>
                    <p>{{ isEditing ? 'Update this supplier PO while preserving its reference and traceability.' : 'Create one supplier PO linked back to every selected quotation item and buyer PO.' }}</p>
                </div>
            </header>

            <div class="review-layout">
                <section class="review-block">
                    <h3>Supplier PO</h3>
                    <dl>
                        <div>
                            <dt>Supplier</dt>
                            <dd>{{ selectedSupplier?.company_name ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt>Factory</dt>
                            <dd>{{ selectedFactory ? `${selectedFactory.name} — ${selectedFactory.location}` : 'Not required' }}</dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>{{ form.payment_term_days }} days from supplier invoice</dd>
                        </div>
                        <div>
                            <dt>Delivery</dt>
                            <dd>
                                {{ form.delivery_period_min }} to {{ form.delivery_period_max }}
                                {{ form.delivery_period_type }} {{ optionName(options.period_units, form.delivery_period_unit) }}
                            </dd>
                        </div>
                        <div>
                            <dt>Currency</dt>
                            <dd>{{ form.accepted_invoice_currency }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="review-block">
                    <h3>Selected Items</h3>
                    <div class="review-list">
                        <article v-for="entry in selectedItems" :key="entry.item.quotation_item_id">
                            <strong>{{ entry.item.product_code ?? '-' }} - {{ entry.item.title }}</strong>
                            <span>{{ entry.item.buyer_company_name }} | {{ entry.item.buyer_po_number }} | Buyer Item {{ entry.item.buyer_item_code ?? '-' }}</span>
                            <span>Factory {{ lineFactoryLabel(entry.line, entry.item) }}</span>
                            <span>Delivery {{ entry.line.delivery_date || '-' }} | {{ incotermOptionLabel(entry.line.incoterm_id) }}</span>
                            <span v-if="visibleCooEntries(entry.line).length">COO: {{ visibleCooEntries(entry.line).map(cooEntrySummary).join('; ') }}</span>
                            <b>{{ form.accepted_invoice_currency }} {{ money(Number(entry.item.quantity) * Number(entry.line.unit_cost)) }}</b>
                        </article>
                    </div>
                    <div class="review-total">
                        <span>Total</span>
                        <strong>{{ form.accepted_invoice_currency }} {{ money(grandTotal) }}</strong>
                    </div>
                </section>

                <section v-if="createdSupplierPo" class="review-block created-version-card">
                    <h3>Supplier PO Created</h3>
                    <dl>
                        <div>
                            <dt>Reference</dt>
                            <dd>{{ createdSupplierPo.po_reference }}</dd>
                        </div>
                        <div>
                            <dt>Revision</dt>
                            <dd>Rev {{ createdSupplierPo.revision_number }}</dd>
                        </div>
                        <div>
                            <dt>Supplier</dt>
                            <dd>{{ createdSupplierPo.supplier_company_name }}</dd>
                        </div>
                        <div v-if="createdSupplierPo.factory_name">
                            <dt>Factory</dt>
                            <dd>{{ createdSupplierPo.factory_name }}<template v-if="createdSupplierPo.factory_location"> — {{ createdSupplierPo.factory_location }}</template></dd>
                        </div>
                        <div>
                            <dt>Total</dt>
                            <dd>{{ form.accepted_invoice_currency }} {{ createdSupplierPo.total_amount }}</dd>
                        </div>
                    </dl>
                    <div class="download-actions">
                        <button class="secondary-action icon-gap" type="button" @click="downloadDocument('docx')">
                            <Download :size="17" aria-hidden="true" />
                            Word
                        </button>
                        <button class="secondary-action icon-gap" type="button" @click="downloadDocument('pdf')">
                            <Download :size="17" aria-hidden="true" />
                            PDF
                        </button>
                    </div>
                    <div v-if="createdSupplierPo.revisions.length" class="revision-history-list">
                        <article v-for="revision in createdSupplierPo.revisions" :key="revision.id">
                            <span>
                                <strong>Rev {{ revision.revision_number }}</strong>
                                <small>{{ revision.finalized_at ?? '-' }}</small>
                            </span>
                            <button class="table-link-button" type="button" @click="downloadRevisionDocument(revision, 'docx')">
                                <Download :size="15" aria-hidden="true" />
                                Word
                            </button>
                            <button class="table-link-button" type="button" @click="downloadRevisionDocument(revision, 'pdf')">
                                <Download :size="15" aria-hidden="true" />
                                PDF
                            </button>
                        </article>
                    </div>
                </section>
            </div>

            <footer class="items-footer terms-footer">
                <div>
                    <span>Ready</span>
                    <strong>{{ selectedItems.length }} Item(s)</strong>
                </div>
                <button class="primary-action compact-action" type="button" :disabled="!canCreate" @click="saveSupplierPo">
                    <Loader2 v-if="isCreating" class="spin-icon" :size="17" aria-hidden="true" />
                    <Save v-else :size="17" aria-hidden="true" />
                    {{ isCreating ? busyLabel : submitLabel }}
                </button>
            </footer>
        </section>
    </section>
</template>
