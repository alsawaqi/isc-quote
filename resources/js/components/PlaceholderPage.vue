<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    AlertTriangle,
    CheckCircle2,
    Loader2,
    Pencil,
    Plus,
    Save,
    Search,
    SlidersHorizontal,
    Trash2,
    X,
} from 'lucide-vue-next';
import { useRoute } from 'vue-router';
import { hasPermission, requestJson } from '../auth';
import type { RoleSlug } from '../types';

defineProps<{
    role?: RoleSlug;
    activeSection?: string;
    userName?: string;
}>();

type FieldType =
    | 'checkbox'
    | 'checkbox-group'
    | 'email'
    | 'multiselect'
    | 'number'
    | 'password'
    | 'select'
    | 'textarea'
    | 'text';

interface FieldConfig {
    key: string;
    label: string;
    type?: FieldType;
    optionsKey?: string;
    required?: boolean;
    showOnEdit?: boolean;
    step?: string;
    visibleForRole?: RoleSlug;
}

interface ColumnConfig {
    key: string;
    label: string;
}

interface ResourceConfig {
    eyebrow: string;
    title: string;
    singular: string;
    endpoint: string;
    action: string;
    columns: ColumnConfig[];
    fields: FieldConfig[];
    tabs?: Array<{ label: string; endpoint: string }>;
}

interface StaticPage {
    eyebrow: string;
    title: string;
    action: string;
    columns: string[];
    rows: string[][];
    stats: Array<{ label: string; value: string }>;
}

type RecordValue = boolean | number | string | null | Array<number | string>;
type RecordItem = Record<string, RecordValue>;
type OptionItem = Record<string, string | number | null>;
type OptionsPayload = Record<string, OptionItem[]>;

const statuses = [
    { id: 'active', name: 'Active' },
    { id: 'inactive', name: 'Inactive' },
];

const companyTypes = [
    { id: 'internal', name: 'Internal Company' },
    { id: 'buyer', name: 'Buyer' },
    { id: 'supplier', name: 'Supplier' },
    { id: 'manufacturer', name: 'Manufacturer' },
    { id: 'shipping_agent', name: 'Shipping Agent' },
    { id: 'mixed', name: 'Mixed Company' },
];

const resourcePages: Record<string, ResourceConfig> = {
    countries: {
        eyebrow: 'Master Data',
        title: 'Countries',
        singular: 'Country',
        endpoint: 'countries',
        action: 'New Country',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Country' },
            { key: 'country_code', label: 'Code' },
            { key: 'phone_code', label: 'Phone Code' },
            { key: 'status', label: 'Status' },
            { key: 'created_at', label: 'Created At' },
            { key: 'updated_at', label: 'Updated At' },
        ],
        fields: [
            { key: 'name', label: 'Country Name', required: true },
            { key: 'country_code', label: 'Country Code', required: true },
            { key: 'phone_code', label: 'Phone Code' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    designations: {
        eyebrow: 'Master Data',
        title: 'Designations',
        singular: 'Designation',
        endpoint: 'designations',
        action: 'New Designation',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Designation' },
            { key: 'code', label: 'Code' },
            { key: 'status', label: 'Status' },
            { key: 'created_at', label: 'Created At' },
            { key: 'updated_at', label: 'Updated At' },
        ],
        fields: [
            { key: 'name', label: 'Designation Name', required: true },
            { key: 'code', label: 'Code' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    companies: {
        eyebrow: 'Master Data',
        title: 'Companies',
        singular: 'Company',
        endpoint: 'companies',
        action: 'New Company',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Company' },
            { key: 'company_type', label: 'Type' },
            { key: 'country_name', label: 'Country' },
            { key: 'company_code', label: 'Company Code' },
            { key: 'code_slug', label: 'Code Slug' },
            { key: 'postal_code', label: 'Postal Code' },
            { key: 'vendor_code', label: 'Vendor Code' },
            { key: 'location', label: 'Location' },
            { key: 'email', label: 'Email' },
            { key: 'phone', label: 'Phone' },
            { key: 'vat_tin', label: 'VAT/TIN' },
            { key: 'status', label: 'Status' },
        ],
        fields: [
            { key: 'name', label: 'Company Name', required: true },
            { key: 'company_type', label: 'Company Type', type: 'select', optionsKey: 'companyTypes', required: true },
            { key: 'country_id', label: 'Country', type: 'select', optionsKey: 'countries' },
            { key: 'company_code', label: 'Company Code', required: true },
            { key: 'code_slug', label: 'Code Slug' },
            { key: 'postal_code', label: 'Postal Code' },
            { key: 'vendor_code', label: 'Vendor Code' },
            { key: 'location', label: 'Location' },
            { key: 'address', label: 'Address', type: 'textarea' },
            { key: 'email', label: 'Email', type: 'email' },
            { key: 'phone', label: 'Phone' },
            { key: 'vat_tin', label: 'VAT/TIN' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    contacts: {
        eyebrow: 'Master Data',
        title: 'Contacts',
        singular: 'Contact',
        endpoint: 'contacts',
        action: 'New Contact',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Name' },
            { key: 'company_name', label: 'Company' },
            { key: 'designation_name', label: 'Designation' },
            { key: 'job_title', label: 'Job Title' },
            { key: 'mobile', label: 'Mobile' },
            { key: 'telephone', label: 'Telephone' },
            { key: 'email', label: 'Email' },
            { key: 'is_primary', label: 'Primary' },
            { key: 'status', label: 'Status' },
        ],
        fields: [
            { key: 'company_id', label: 'Company', type: 'select', optionsKey: 'companies', required: true },
            { key: 'designation_id', label: 'Designation', type: 'select', optionsKey: 'designations' },
            { key: 'name', label: 'Name', required: true },
            { key: 'job_title', label: 'Job Title' },
            { key: 'mobile', label: 'Mobile Number' },
            { key: 'telephone', label: 'Telephone Number' },
            { key: 'extension', label: 'Extension' },
            { key: 'email', label: 'Email', type: 'email' },
            { key: 'fax', label: 'Fax' },
            { key: 'is_primary', label: 'Primary Contact', type: 'checkbox' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    incoterms: {
        eyebrow: 'Master Data',
        title: 'Incoterms',
        singular: 'Incoterm',
        endpoint: 'incoterms',
        action: 'New Incoterm',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'description', label: 'Description' },
            { key: 'reminder_days_before_delivery', label: 'Reminder Days Before Delivery' },
            { key: 'status', label: 'Status' },
            { key: 'created_at', label: 'Created At' },
            { key: 'updated_at', label: 'Updated At' },
        ],
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'description', label: 'Description', type: 'textarea' },
            { key: 'reminder_days_before_delivery', label: 'Reminder Days Before Delivery', type: 'number', required: true },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    uoms: {
        eyebrow: 'Master Data',
        title: 'UOMs',
        singular: 'UOM',
        endpoint: 'uoms',
        action: 'New UOM',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'status', label: 'Status' },
            { key: 'created_at', label: 'Created At' },
            { key: 'updated_at', label: 'Updated At' },
        ],
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    currencies: {
        eyebrow: 'Master Data',
        title: 'Currencies',
        singular: 'Currency',
        endpoint: 'currencies',
        action: 'New Currency',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'code', label: 'Code' },
            { key: 'name', label: 'Name' },
            { key: 'exchange_rate', label: 'Exchange Rate' },
            { key: 'status', label: 'Status' },
            { key: 'created_at', label: 'Created At' },
            { key: 'updated_at', label: 'Updated At' },
        ],
        fields: [
            { key: 'code', label: 'Code', required: true },
            { key: 'name', label: 'Name', required: true },
            { key: 'exchange_rate', label: 'Exchange Rate', type: 'number', required: true, step: '0.000001' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    manufacturers: {
        eyebrow: 'Master Data',
        title: 'Manufacturers',
        singular: 'Manufacturer',
        endpoint: 'manufacturers',
        action: 'New Manufacturer',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Manufacturer' },
            { key: 'country_name', label: 'Country' },
            { key: 'status', label: 'Status' },
        ],
        fields: [
            { key: 'name', label: 'Manufacturer Name', required: true },
            { key: 'country_id', label: 'Country', type: 'select', optionsKey: 'countries', required: true },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    suppliers: {
        eyebrow: 'Master Data',
        title: 'Suppliers',
        singular: 'Supplier',
        endpoint: 'suppliers',
        action: 'New Supplier',
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'company_name', label: 'Company' },
            { key: 'company_code', label: 'Code' },
            { key: 'country_name', label: 'Country' },
            { key: 'primary_contact_name', label: 'Primary Contact' },
            { key: 'manufacturer_name', label: 'Linked Manufacturer' },
            { key: 'status', label: 'Status' },
        ],
        fields: [
            { key: 'company_id', label: 'Company', type: 'select', optionsKey: 'companies', required: true },
            { key: 'primary_contact_id', label: 'Primary Contact', type: 'select', optionsKey: 'contacts' },
            { key: 'manufacturer_id', label: 'Linked Manufacturer', type: 'select', optionsKey: 'manufacturers' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
    'users-roles': {
        eyebrow: 'Access Control',
        title: 'Users & Roles',
        singular: 'User',
        endpoint: 'users',
        action: 'New User',
        tabs: [
            { label: 'Users', endpoint: 'users' },
            { label: 'Roles', endpoint: 'roles' },
        ],
        columns: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'User' },
            { key: 'email', label: 'Email' },
            { key: 'role_names', label: 'Roles' },
            { key: 'direct_permission_names', label: 'Extra Permissions' },
            { key: 'status', label: 'Status' },
            { key: 'created_at', label: 'Created At' },
        ],
        fields: [
            { key: 'name', label: 'Full Name', required: true },
            { key: 'email', label: 'Email', type: 'email', required: true },
            { key: 'password', label: 'Password', type: 'password', required: true, showOnEdit: true },
            { key: 'role_ids', label: 'Roles', type: 'multiselect', optionsKey: 'roles', required: true },
            { key: 'salesperson_contact_name', label: 'Supplier Contact Name', required: true, visibleForRole: 'salesperson' },
            { key: 'salesperson_designation_id', label: 'Designation', type: 'select', optionsKey: 'designations', visibleForRole: 'salesperson' },
            { key: 'salesperson_job_title', label: 'Job Title', visibleForRole: 'salesperson' },
            { key: 'salesperson_mobile', label: 'Mobile Number', visibleForRole: 'salesperson' },
            { key: 'salesperson_telephone', label: 'Telephone Number', visibleForRole: 'salesperson' },
            { key: 'salesperson_extension', label: 'Extension', visibleForRole: 'salesperson' },
            { key: 'salesperson_contact_email', label: 'Contact Email', type: 'email', visibleForRole: 'salesperson' },
            { key: 'salesperson_fax', label: 'Fax', visibleForRole: 'salesperson' },
            { key: 'direct_permission_ids', label: 'Extra User Permissions', type: 'checkbox-group', optionsKey: 'permissions' },
            { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
        ],
    },
};

const usersResource: ResourceConfig = resourcePages['users-roles'];
const rolesResource: ResourceConfig = {
    eyebrow: 'Access Control',
    title: 'Roles',
    singular: 'Role',
    endpoint: 'roles',
    action: 'Fixed Roles',
    tabs: usersResource.tabs,
    columns: [
        { key: 'id', label: 'ID' },
        { key: 'name', label: 'Role' },
        { key: 'slug', label: 'Slug' },
        { key: 'description', label: 'Description' },
        { key: 'permission_names', label: 'Permissions' },
        { key: 'status', label: 'Status' },
        { key: 'created_at', label: 'Created At' },
    ],
    fields: [
        { key: 'name', label: 'Role Name', required: true },
        { key: 'slug', label: 'Slug' },
        { key: 'description', label: 'Description', type: 'textarea' },
        { key: 'permission_ids', label: 'Permissions', type: 'multiselect', optionsKey: 'permissions' },
        { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', required: true },
    ],
};

const staticPages: Record<string, StaticPage> = {
    quotations: {
        eyebrow: 'Sales',
        title: 'Quotations',
        action: 'New Quotation',
        columns: ['Quotation Ref', 'Buyer', 'RFQ', 'Version', 'Status'],
        rows: [
            ['ISC-COR-QT-394-OXY-25', 'Occidental of Oman, Inc', '6000024422', 'V1', 'Accepted'],
            ['ISC-COR-QT-412-OXY-25', 'Oceanic Oil & Gas', 'RFQ-2025-101', 'V2', 'Sent'],
            ['ISC-COR-QT-418-ABB-25', 'Global Petrochem Ltd.', 'RFQ-2025-118', 'V1', 'Draft'],
        ],
        stats: [
            { label: 'Pending Quotations', value: '28' },
            { label: 'Accepted This Month', value: '11' },
        ],
    },
    'follow-up': {
        eyebrow: 'Operations',
        title: 'Follow-Up',
        action: 'New Comment',
        columns: ['Job Ref', 'Supplier PO', 'Supplier', 'Next Follow-Up', 'Status'],
        rows: [
            ['ISC-JOB-2025-0487', 'ISC-COR-PO-087-ABB-25', 'ABB LLC', 'Today', 'Awaiting Ack'],
            ['ISC-JOB-2025-0486', 'ISC-COR-PO-091-FLS-25', 'Flowline Systems', 'Tomorrow', 'In Production'],
            ['ISC-JOB-2025-0485', 'ISC-COR-PO-092-PCC-25', 'Precision Castings Co.', '3 days', 'Docs Pending'],
        ],
        stats: [
            { label: 'Due Today', value: '23' },
            { label: 'Overdue', value: '7' },
        ],
    },
};

const route = useRoute();
const records = ref<RecordItem[]>([]);
const options = ref<OptionsPayload>({
    statuses,
    companyTypes,
});
const search = ref('');
const isLoading = ref(false);
const isSaving = ref(false);
const isModalOpen = ref(false);
const isDeleteOpen = ref(false);
const editingRecord = ref<RecordItem | null>(null);
const deleteRecord = ref<RecordItem | null>(null);
const form = reactive<Record<string, RecordValue>>({});
const toast = ref<{ type: 'error' | 'success'; message: string } | null>(null);
const activeUsersTab = ref('users');

const routeName = computed(() => String(route.name ?? 'companies'));
const staticPage = computed(() => staticPages[routeName.value]);
const isCrudPage = computed(() => !staticPage.value);
const page = computed<ResourceConfig>(() => {
    if (routeName.value === 'users-roles') {
        return activeUsersTab.value === 'roles' ? rolesResource : usersResource;
    }

    return resourcePages[routeName.value] ?? resourcePages.companies;
});

const selectedRoleSlugs = computed<RoleSlug[]>(() => {
    const selectedIds = arrayFieldValue('role_ids').map((value) => String(value));

    return (options.value.roles ?? [])
        .filter((role) => selectedIds.includes(String(role.id)) && typeof role.slug === 'string')
        .map((role) => role.slug as RoleSlug);
});

const visibleFields = computed(() => {
    return page.value.fields.filter((field) => {
        if (editingRecord.value && field.showOnEdit === false) {
            return false;
        }

        if (field.visibleForRole && !selectedRoleSlugs.value.includes(field.visibleForRole)) {
            return false;
        }

        return true;
    });
});

const activeCount = computed(() => records.value.filter((record) => record.status === 'active').length);
const canCreateRecord = computed(() => {
    if (page.value.endpoint === 'roles') {
        return false;
    }

    if (page.value.endpoint === 'users') {
        return hasPermission('manage-users');
    }

    return hasPermission(`create-${page.value.endpoint}`);
});
const canUpdateRecord = computed(() => {
    if (page.value.endpoint === 'roles') {
        return false;
    }

    if (page.value.endpoint === 'users') {
        return hasPermission('manage-users');
    }

    return hasPermission(`update-${page.value.endpoint}`);
});
const canDeleteRecord = computed(() => {
    if (page.value.endpoint === 'roles') {
        return false;
    }

    if (page.value.endpoint === 'users') {
        return hasPermission('manage-users');
    }

    return hasPermission(`delete-${page.value.endpoint}`);
});
const hasRowActions = computed(() => canUpdateRecord.value || canDeleteRecord.value);

function blankValue(field: FieldConfig): RecordValue {
    if (field.type === 'checkbox') {
        return false;
    }

    if (field.type === 'checkbox-group' || field.type === 'multiselect') {
        return [];
    }

    if (field.type === 'number') {
        return 0;
    }

    if (field.key === 'status') {
        return 'active';
    }

    return '';
}

function fieldOptions(field: FieldConfig): OptionItem[] {
    if (!field.optionsKey) {
        return [];
    }

    return options.value[field.optionsKey] ?? [];
}

function optionLabel(option: OptionItem): string {
    const parts = [option.name, option.country_code, option.company_code, option.slug, option.group].filter(Boolean);

    return parts.join(' - ');
}

function checkboxOptionLabel(option: OptionItem): string {
    return String(option.name ?? option.slug ?? option.id ?? '');
}

function checkboxEventChecked(event: Event): boolean {
    return (event.target as HTMLInputElement).checked;
}

function arrayFieldValue(key: string): Array<number | string> {
    const value = form[key];

    return Array.isArray(value) ? value : [];
}

function isOptionSelected(key: string, optionId: number | string | null): boolean {
    if (optionId === null) {
        return false;
    }

    return arrayFieldValue(key).some((value) => String(value) === String(optionId));
}

function toggleOption(key: string, optionId: number | string | null, checked: boolean): void {
    if (optionId === null) {
        return;
    }

    const remainingOptions = arrayFieldValue(key).filter((value) => String(value) !== String(optionId));
    form[key] = checked ? [...remainingOptions, optionId] : remainingOptions;
}

function groupedOptions(field: FieldConfig): Array<{ group: string; options: OptionItem[] }> {
    const groups = new Map<string, OptionItem[]>();

    for (const option of fieldOptions(field)) {
        const group = typeof option.group === 'string' && option.group ? option.group : 'General';
        const groupOptions = groups.get(group) ?? [];

        groupOptions.push(option);
        groups.set(group, groupOptions);
    }

    return Array.from(groups, ([group, groupOptions]) => ({ group, options: groupOptions }));
}

function displayValue(record: RecordItem, key: string): string {
    const value = record[key];

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (key === 'status' && typeof value === 'string') {
        return value === 'active' ? 'Active' : 'Inactive';
    }

    if (key === 'company_type' && typeof value === 'string') {
        return companyTypes.find((type) => type.id === value)?.name ?? value;
    }

    return value === null || value === '' || typeof value === 'undefined' ? '-' : String(value);
}

function textFieldValue(key: string): string {
    const value = form[key];

    return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

function updateTextField(key: string, event: Event): void {
    form[key] = (event.target as HTMLInputElement | HTMLTextAreaElement).value;
}

function showToast(type: 'error' | 'success', message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) {
            toast.value = null;
        }
    }, 3200);
}

async function loadRecords(): Promise<void> {
    if (!isCrudPage.value) {
        return;
    }

    isLoading.value = true;

    try {
        const params = new URLSearchParams();

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        const suffix = params.toString() ? `?${params.toString()}` : '';
        const response = await requestJson<{
            data: RecordItem[];
            options: OptionsPayload;
        }>(`/api/admin/${page.value.endpoint}${suffix}`);

        records.value = response.data;
        options.value = {
            ...options.value,
            ...response.options,
            statuses,
            companyTypes,
        };
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to load records.');
    } finally {
        isLoading.value = false;
    }
}

function resetForm(record?: RecordItem): void {
    for (const key of Object.keys(form)) {
        delete form[key];
    }

    for (const field of page.value.fields) {
        if (record && Object.prototype.hasOwnProperty.call(record, field.key) && field.type !== 'password') {
            form[field.key] = record[field.key];
        } else {
            form[field.key] = blankValue(field);
        }
    }
}

function openCreate(): void {
    if (!canCreateRecord.value) {
        return;
    }

    editingRecord.value = null;
    resetForm();
    isModalOpen.value = true;
}

function openEdit(record: RecordItem): void {
    if (!canUpdateRecord.value) {
        return;
    }

    editingRecord.value = record;
    resetForm(record);
    isModalOpen.value = true;
}

function closeModal(): void {
    if (!isSaving.value) {
        isModalOpen.value = false;
    }
}

function buildPayload(): Record<string, RecordValue> {
    const payload: Record<string, RecordValue> = {};

    for (const field of visibleFields.value) {
        const value = form[field.key];

        if (editingRecord.value && field.type === 'password' && !value) {
            continue;
        }

        payload[field.key] = value;
    }

    return payload;
}

async function submitForm(): Promise<void> {
    isSaving.value = true;

    try {
        const endpoint = `/api/admin/${page.value.endpoint}`;
        const id = editingRecord.value?.id;
        const response = await requestJson<{ message: string; data: RecordItem }>(id ? `${endpoint}/${id}` : endpoint, {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(buildPayload()),
        });

        showToast('success', response.message);
        isModalOpen.value = false;
        await loadRecords();
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to save record.');
    } finally {
        isSaving.value = false;
    }
}

function confirmDelete(record: RecordItem): void {
    if (!canDeleteRecord.value) {
        return;
    }

    deleteRecord.value = record;
    isDeleteOpen.value = true;
}

async function deleteSelected(): Promise<void> {
    if (!deleteRecord.value?.id) {
        return;
    }

    isSaving.value = true;

    try {
        const response = await requestJson<{ message: string }>(`/api/admin/${page.value.endpoint}/${deleteRecord.value.id}`, {
            method: 'DELETE',
        });

        showToast('success', response.message);
        isDeleteOpen.value = false;
        deleteRecord.value = null;
        await loadRecords();
    } catch (error) {
        showToast('error', error instanceof Error ? error.message : 'Unable to delete record.');
    } finally {
        isSaving.value = false;
    }
}

function switchTab(endpoint: string): void {
    activeUsersTab.value = endpoint;
    search.value = '';
}

onMounted(loadRecords);

watch(
    () => [route.name, activeUsersTab.value],
    () => {
        search.value = '';
        loadRecords();
    }
);
</script>

<template>
    <section v-if="staticPage" class="page-scaffold">
        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>{{ staticPage.eyebrow }}</p>
                <h1>{{ staticPage.title }}</h1>
            </div>

            <button class="primary-action compact-action" type="button">
                <Plus :size="17" aria-hidden="true" />
                {{ staticPage.action }}
            </button>
        </div>

        <div class="module-stats">
            <article v-for="stat in staticPage.stats" :key="stat.label" class="module-stat">
                <span>{{ stat.label }}</span>
                <strong>{{ stat.value }}</strong>
            </article>
        </div>

        <section class="table-panel module-table" :aria-labelledby="`${String(route.name)}-title`">
            <div class="panel-title">
                <h2 :id="`${String(route.name)}-title`">{{ staticPage.title }}</h2>
                <div class="module-tools">
                    <label class="mini-search">
                        <Search :size="16" aria-hidden="true" />
                        <input type="search" placeholder="Search" />
                    </label>
                    <button class="top-icon-button" type="button" aria-label="Filter">
                        <SlidersHorizontal :size="17" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <div class="module-records">
                <div class="module-record head" :style="{ gridTemplateColumns: `repeat(${staticPage.columns.length}, minmax(130px, 1fr))` }">
                    <span v-for="column in staticPage.columns" :key="column">{{ column }}</span>
                </div>

                <div
                    v-for="row in staticPage.rows"
                    :key="row.join('-')"
                    class="module-record"
                    :style="{ gridTemplateColumns: `repeat(${staticPage.columns.length}, minmax(130px, 1fr))` }"
                >
                    <span v-for="cell in row" :key="cell">{{ cell }}</span>
                </div>
            </div>
        </section>
    </section>

    <section v-else class="page-scaffold">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>{{ page.eyebrow }}</p>
                <h1>{{ page.title }}</h1>
            </div>

            <button v-if="canCreateRecord" class="primary-action compact-action" type="button" @click="openCreate">
                <Plus :size="17" aria-hidden="true" />
                {{ page.action }}
            </button>
        </div>

        <div v-if="page.tabs" class="resource-tabs" role="tablist" aria-label="Users and roles">
            <button
                v-for="tab in page.tabs"
                :key="tab.endpoint"
                type="button"
                :class="{ active: activeUsersTab === tab.endpoint }"
                @click="switchTab(tab.endpoint)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="module-stats">
            <article class="module-stat">
                <span>Total {{ page.title }}</span>
                <strong>{{ records.length }}</strong>
            </article>
            <article class="module-stat">
                <span>Active Records</span>
                <strong>{{ activeCount }}</strong>
            </article>
        </div>

        <section class="table-panel module-table" :aria-labelledby="`${page.endpoint}-title`">
            <div class="panel-title">
                <h2 :id="`${page.endpoint}-title`">{{ page.title }}</h2>
                <div class="module-tools">
                    <label class="mini-search">
                        <Search :size="16" aria-hidden="true" />
                        <input v-model="search" type="search" placeholder="Search" @keyup.enter="loadRecords" />
                    </label>
                    <button class="top-icon-button" type="button" aria-label="Search records" @click="loadRecords">
                        <SlidersHorizontal :size="17" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <div class="module-records">
                <div class="module-record head crud-row" :style="{ gridTemplateColumns: `repeat(${page.columns.length}, minmax(130px, 1fr)) ${hasRowActions ? '110px' : ''}` }">
                    <span v-for="column in page.columns" :key="column.key">{{ column.label }}</span>
                    <span v-if="hasRowActions">Actions</span>
                </div>

                <div v-if="isLoading" class="crud-empty">
                    <Loader2 :size="20" aria-hidden="true" />
                    Loading {{ page.title.toLowerCase() }}...
                </div>

                <div v-else-if="records.length === 0" class="crud-empty">
                    No {{ page.title.toLowerCase() }} found.
                </div>

                <div
                    v-for="record in records"
                    v-else
                    :key="String(record.id)"
                    class="module-record crud-row"
                    :style="{ gridTemplateColumns: `repeat(${page.columns.length}, minmax(130px, 1fr)) ${hasRowActions ? '110px' : ''}` }"
                >
                    <span v-for="column in page.columns" :key="column.key" :class="{ 'status-cell': column.key === 'status' }">
                        {{ displayValue(record, column.key) }}
                    </span>
                    <span v-if="hasRowActions" class="row-actions">
                        <button v-if="canUpdateRecord" type="button" aria-label="Edit record" @click="openEdit(record)">
                            <Pencil :size="16" aria-hidden="true" />
                        </button>
                        <button v-if="canDeleteRecord" type="button" aria-label="Delete record" @click="confirmDelete(record)">
                            <Trash2 :size="16" aria-hidden="true" />
                        </button>
                    </span>
                </div>
            </div>
        </section>

        <div v-if="isModalOpen" class="modal-backdrop" role="presentation" @click.self="closeModal">
            <section class="crud-modal" role="dialog" aria-modal="true" :aria-labelledby="`${page.endpoint}-modal-title`">
                <header>
                    <div>
                        <h2 :id="`${page.endpoint}-modal-title`">
                            {{ editingRecord ? `Edit ${page.singular}` : page.action }}
                        </h2>
                        <p>Fill the required fields and save the record.</p>
                    </div>
                    <button class="top-icon-button" type="button" aria-label="Close form" @click="closeModal">
                        <X :size="18" aria-hidden="true" />
                    </button>
                </header>

                <form class="crud-form" @submit.prevent="submitForm">
                    <component
                        :is="field.type === 'checkbox-group' ? 'div' : 'label'"
                        v-for="field in visibleFields"
                        :key="field.key"
                        class="form-field"
                        :class="{
                            'checkbox-field': field.type === 'checkbox',
                            'permission-field': field.type === 'checkbox-group',
                        }"
                    >
                        <span>{{ field.label }}<b v-if="field.required">*</b></span>

                        <select
                            v-if="field.type === 'select'"
                            v-model="form[field.key]"
                            :required="field.required"
                        >
                            <option value="">Select {{ field.label }}</option>
                            <option v-for="option in fieldOptions(field)" :key="String(option.id)" :value="option.id">
                                {{ optionLabel(option) }}
                            </option>
                        </select>

                        <select
                            v-else-if="field.type === 'multiselect'"
                            v-model="form[field.key]"
                            :required="field.required"
                            multiple
                        >
                            <option v-for="option in fieldOptions(field)" :key="String(option.id)" :value="option.id">
                                {{ optionLabel(option) }}
                            </option>
                        </select>

                        <div v-else-if="field.type === 'checkbox-group'" class="permission-checkbox-panel">
                            <section v-for="group in groupedOptions(field)" :key="group.group" class="permission-check-group">
                                <strong>{{ group.group }}</strong>
                                <div class="permission-check-grid">
                                    <label v-for="option in group.options" :key="String(option.id)" class="permission-check">
                                        <input
                                            type="checkbox"
                                            :checked="isOptionSelected(field.key, option.id)"
                                            @change="toggleOption(field.key, option.id, checkboxEventChecked($event))"
                                        />
                                        <span>{{ checkboxOptionLabel(option) }}</span>
                                    </label>
                                </div>
                            </section>
                        </div>

                        <textarea
                            v-else-if="field.type === 'textarea'"
                            :value="textFieldValue(field.key)"
                            :required="field.required"
                            rows="3"
                            @input="updateTextField(field.key, $event)"
                        ></textarea>

                        <input
                            v-else-if="field.type === 'checkbox'"
                            v-model="form[field.key]"
                            type="checkbox"
                        />

                        <input
                            v-else
                            v-model="form[field.key]"
                            :type="field.type ?? 'text'"
                            :required="field.required && !(editingRecord && field.type === 'password')"
                            :step="field.step"
                            :placeholder="editingRecord && field.type === 'password' ? 'Leave blank to keep current password' : ''"
                        />
                    </component>

                    <footer>
                        <button class="secondary-action" type="button" :disabled="isSaving" @click="closeModal">Cancel</button>
                        <button class="primary-action compact-action" type="submit" :disabled="isSaving">
                            <Loader2 v-if="isSaving" class="spin-icon" :size="17" aria-hidden="true" />
                            <Save v-else :size="17" aria-hidden="true" />
                            {{ isSaving ? 'Saving...' : 'Save' }}
                        </button>
                    </footer>
                </form>
            </section>
        </div>

        <div v-if="isDeleteOpen" class="modal-backdrop" role="presentation" @click.self="isDeleteOpen = false">
            <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="delete-title">
                <span class="confirm-icon">
                    <AlertTriangle :size="22" aria-hidden="true" />
                </span>
                <h2 id="delete-title">Delete {{ page.singular }}?</h2>
                <p>This action cannot be undone. Linked records may prevent deletion.</p>
                <footer>
                    <button class="secondary-action" type="button" :disabled="isSaving" @click="isDeleteOpen = false">Cancel</button>
                    <button class="danger-action" type="button" :disabled="isSaving" @click="deleteSelected">
                        <Loader2 v-if="isSaving" class="spin-icon" :size="17" aria-hidden="true" />
                        <Trash2 v-else :size="17" aria-hidden="true" />
                        Delete
                    </button>
                </footer>
            </section>
        </div>
    </section>
</template>
