<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Building2,
    Check,
    CheckCircle2,
    Factory,
    Globe2,
    Loader2,
    MapPin,
    Plus,
    Save,
    ShieldCheck,
    Trash2,
    UserRound,
    UsersRound,
    Warehouse,
} from 'lucide-vue-next';
import { requestJson } from '../auth';
import {
    emptyCompany,
    kindForRoles,
    roleLabels,
    rolesForKind,
    type CompanyContact,
    type CompanyCore,
    type CompanyKind,
    type CompanyLocation,
    type CompanyOptions,
    type CompanyProfile,
    type CompanyRoles,
} from '../companyManagement';

type StepKey = 'company' | 'supplier' | 'contacts' | 'review';
type Toast = { type: 'error' | 'success'; message: string };

interface WizardStep {
    key: StepKey;
    label: string;
    description: string;
}

const route = useRoute();
const router = useRouter();
const options = ref<CompanyOptions>({ countries: [], designations: [], manufacturers: [] });
const company = reactive<CompanyCore>(emptyCompany());
const companyKind = ref<CompanyKind | ''>('');
const manufacturerIds = ref<number[]>([]);
const locations = ref<CompanyLocation[]>([]);
const contacts = ref<CompanyContact[]>([]);
const activeStep = ref(0);
const visitedSteps = ref<StepKey[]>(['company']);
const isLoading = ref(true);
const isSaving = ref(false);
const loadError = ref('');
const validationError = ref('');
const toast = ref<Toast | null>(null);
const slugWasEdited = ref(false);
let factoryCounter = 0;

const companyId = computed(() => Number(route.params.id || 0));
const isEditing = computed(() => companyId.value > 0);
const roles = computed<CompanyRoles>(() => companyKind.value ? rolesForKind(companyKind.value) : {
    is_internal: false,
    is_buyer: false,
    is_supplier: false,
});
const isSupplier = computed(() => roles.value.is_supplier);
const isBuyer = computed(() => roles.value.is_buyer);
const activeLocations = computed(() => locations.value.filter((location) => location.status === 'active'));
const activeContacts = computed(() => contacts.value.filter((contact) => contact.status === 'active'));
const selectedManufacturers = computed(() => options.value.manufacturers.filter((manufacturer) => manufacturerIds.value.includes(manufacturer.id)));
const steps = computed<WizardStep[]>(() => [
    { key: 'company', label: 'Company', description: 'Identity and capabilities' },
    ...(isSupplier.value ? [{ key: 'supplier' as const, label: 'Supplier setup', description: 'Manufacturers and factories' }] : []),
    { key: 'contacts', label: 'Contacts', description: 'People and responsibilities' },
    { key: 'review', label: 'Review', description: 'Check and save' },
]);
const currentStep = computed(() => steps.value[activeStep.value] ?? steps.value[0]);

const roleChoices: Array<{
    value: CompanyKind;
    label: string;
    description: string;
    icon: typeof Building2;
}> = [
    { value: 'internal', label: 'Internal company', description: 'ISC or another group company that can buy and supply.', icon: ShieldCheck },
    { value: 'buyer', label: 'Buyer', description: 'A customer that receives quotations and places buyer POs.', icon: UsersRound },
    { value: 'supplier', label: 'Supplier', description: 'A vendor linked to manufacturers, factories, and supplier contacts.', icon: Warehouse },
    { value: 'mixed', label: 'Buyer & supplier', description: 'A company that can act on either side of a transaction.', icon: Building2 },
];

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) toast.value = null;
    }, 3400);
}

function nullableNumber(value: number | string | null | undefined): number | null {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
}

function nextFactoryKey(id?: number): string {
    factoryCounter += 1;
    return id ? `location_${id}` : `factory_new_${factoryCounter}`;
}

function normalizeLocation(location: CompanyLocation): CompanyLocation {
    return {
        id: location.id,
        client_key: location.client_key || nextFactoryKey(location.id),
        location_type: 'factory',
        name: location.name ?? '',
        location: location.location ?? '',
        address: location.address ?? '',
        country_id: nullableNumber(location.country_id),
        country_name: location.country_name,
        manufacturer_id: nullableNumber(location.manufacturer_id),
        manufacturer_name: location.manufacturer_name,
        status: location.status === 'inactive' ? 'inactive' : 'active',
    };
}

function normalizeContact(contact: CompanyContact, normalizedLocations: CompanyLocation[]): CompanyContact {
    const keyById = new Map(normalizedLocations.filter((location) => location.id).map((location) => [location.id as number, location.client_key]));
    const locationKeys = contact.location_keys?.length
        ? contact.location_keys
        : (contact.location_ids ?? []).map((id) => keyById.get(id)).filter((key): key is string => Boolean(key));

    return {
        id: contact.id,
        name: contact.name ?? '',
        designation_id: nullableNumber(contact.designation_id),
        designation_name: contact.designation_name,
        job_title: contact.job_title ?? '',
        mobile: contact.mobile ?? '',
        telephone: contact.telephone ?? '',
        extension: contact.extension ?? '',
        email: contact.email ?? '',
        fax: contact.fax ?? '',
        status: contact.status === 'inactive' ? 'inactive' : 'active',
        serves_buyer: Boolean(contact.serves_buyer),
        serves_supplier: Boolean(contact.serves_supplier),
        all_locations: Boolean(contact.all_locations),
        is_primary_buyer: Boolean(contact.is_primary_buyer),
        is_primary_supplier: Boolean(contact.is_primary_supplier),
        location_keys: locationKeys,
        location_ids: contact.location_ids,
    };
}

function applyProfile(profile: CompanyProfile): void {
    Object.assign(company, emptyCompany(), profile.company, {
        country_id: nullableNumber(profile.company.country_id),
        name: profile.company.name ?? '',
        company_code: profile.company.company_code ?? '',
        code_slug: profile.company.code_slug ?? '',
        postal_code: profile.company.postal_code ?? '',
        vendor_code: profile.company.vendor_code ?? '',
        location: profile.company.location ?? '',
        address: profile.company.address ?? '',
        email: profile.company.email ?? '',
        phone: profile.company.phone ?? '',
        vat_tin: profile.company.vat_tin ?? '',
        status: profile.company.status === 'inactive' ? 'inactive' : 'active',
    });
    companyKind.value = kindForRoles(profile.roles);
    profile.manufacturers.forEach((manufacturer) => {
        if (!options.value.manufacturers.some((option) => option.id === manufacturer.id)) {
            options.value.manufacturers.push({ ...manufacturer, status: 'inactive' });
        }
    });
    manufacturerIds.value = profile.manufacturers.map((manufacturer) => manufacturer.id);
    const normalizedLocations = profile.locations.map(normalizeLocation);
    locations.value = normalizedLocations;
    contacts.value = profile.contacts.map((contact) => normalizeContact(contact, normalizedLocations));
    if (profile.buyer_profile?.primary_contact_id && !contacts.value.some((contact) => contact.is_primary_buyer)) {
        const buyerPrimary = contacts.value.find((contact) => contact.id === profile.buyer_profile?.primary_contact_id);
        if (buyerPrimary) buyerPrimary.is_primary_buyer = true;
    }
    if (profile.supplier_profile?.primary_contact_id && !contacts.value.some((contact) => contact.is_primary_supplier)) {
        const supplierPrimary = contacts.value.find((contact) => contact.id === profile.supplier_profile?.primary_contact_id);
        if (supplierPrimary) supplierPrimary.is_primary_supplier = true;
    }
    slugWasEdited.value = Boolean(company.code_slug);
}

async function loadWizard(): Promise<void> {
    isLoading.value = true;
    loadError.value = '';

    try {
        const optionPromise = requestJson<{ data: CompanyOptions }>('/api/company-management/options');
        const profilePromise = isEditing.value
            ? requestJson<{ data: CompanyProfile }>(`/api/company-management/${companyId.value}`)
            : Promise.resolve(null);
        const [optionPayload, profilePayload] = await Promise.all([optionPromise, profilePromise]);
        options.value = optionPayload.data;
        if (profilePayload) applyProfile(profilePayload.data);
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'Unable to load the company form.';
    } finally {
        isLoading.value = false;
    }
}

function selectCompanyKind(kind: CompanyKind): void {
    companyKind.value = kind;
    validationError.value = '';

    contacts.value.forEach((contact) => {
        if (!roles.value.is_buyer) {
            contact.serves_buyer = false;
            contact.is_primary_buyer = false;
        }
        if (!roles.value.is_supplier) {
            contact.serves_supplier = false;
            contact.is_primary_supplier = false;
            contact.all_locations = false;
            contact.location_keys = [];
        }
    });
}

function handleCompanyCodeInput(): void {
    if (!slugWasEdited.value || !company.code_slug) {
        company.code_slug = company.company_code
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
}

function addLocation(): void {
    locations.value.push({
        client_key: nextFactoryKey(),
        location_type: 'factory',
        name: '',
        location: '',
        address: '',
        country_id: company.country_id,
        manufacturer_id: manufacturerIds.value.length === 1 ? manufacturerIds.value[0] : null,
        status: 'active',
    });
}

function removeLocation(index: number): void {
    const location = locations.value[index];
    if (!location) return;

    contacts.value.forEach((contact) => {
        contact.location_keys = contact.location_keys.filter((key) => key !== location.client_key);
    });

    if (location.id) {
        location.status = 'inactive';
        showToast('success', 'Factory marked inactive and removed from contact scopes. It will remain available in historical records.');
        return;
    }

    locations.value.splice(index, 1);
}

function restoreLocation(location: CompanyLocation): void {
    location.status = 'active';
}

function addContact(): void {
    const existingBuyerPrimary = contacts.value.some((contact) => contact.status === 'active' && contact.is_primary_buyer);
    const existingSupplierPrimary = contacts.value.some((contact) => contact.status === 'active' && contact.is_primary_supplier);

    contacts.value.push({
        name: '',
        designation_id: null,
        job_title: '',
        mobile: '',
        telephone: '',
        extension: '',
        email: '',
        fax: '',
        status: 'active',
        serves_buyer: isBuyer.value,
        serves_supplier: isSupplier.value,
        all_locations: isSupplier.value,
        is_primary_buyer: isBuyer.value && !existingBuyerPrimary,
        is_primary_supplier: isSupplier.value && !existingSupplierPrimary,
        location_keys: [],
    });
}

function removeContact(index: number): void {
    const contact = contacts.value[index];
    if (!contact) return;

    if (contact.id) {
        contact.status = 'inactive';
        contact.is_primary_buyer = false;
        contact.is_primary_supplier = false;
        showToast('success', 'Contact marked inactive to preserve its commercial history.');
        return;
    }

    contacts.value.splice(index, 1);
}

function restoreContact(contact: CompanyContact): void {
    contact.status = 'active';
}

function setContactRole(contact: CompanyContact, role: 'buyer' | 'supplier', checked: boolean): void {
    if (role === 'buyer') {
        contact.serves_buyer = checked;
        if (!checked) contact.is_primary_buyer = false;
        return;
    }

    contact.serves_supplier = checked;
    if (!checked) {
        contact.is_primary_supplier = false;
        contact.all_locations = false;
        contact.location_keys = [];
    } else if (activeLocations.value.length > 0) {
        contact.all_locations = true;
    }
}

function setPrimary(contact: CompanyContact, role: 'buyer' | 'supplier', checked: boolean): void {
    if (role === 'buyer') {
        if (checked) {
            contacts.value.forEach((candidate) => { candidate.is_primary_buyer = false; });
            contact.serves_buyer = true;
        }
        contact.is_primary_buyer = checked;
        return;
    }

    if (checked) {
        contacts.value.forEach((candidate) => { candidate.is_primary_supplier = false; });
        contact.serves_supplier = true;
    }
    contact.is_primary_supplier = checked;
}

function setAllLocations(contact: CompanyContact, checked: boolean): void {
    contact.all_locations = checked;
    if (checked) contact.location_keys = [];
}

function validateStep(key: StepKey): string {
    if (key === 'company') {
        if (!companyKind.value) return 'Choose how this company will operate in the system.';
        if (!company.name.trim()) return 'Enter the legal or trading name of the company.';
        if (!company.company_code.trim()) return 'Enter a unique company code.';
        if (company.email && !/^\S+@\S+\.\S+$/.test(company.email)) return 'Enter a valid general company email address.';
    }

    if (key === 'supplier') {
        if (isSupplier.value && !roles.value.is_internal && manufacturerIds.value.length === 0) {
            return 'Select at least one manufacturer represented by this supplier.';
        }

        const incompleteFactory = activeLocations.value.find((location) => !location.name.trim() || !location.location.trim());
        if (incompleteFactory) return 'Each active factory needs a name and location.';
    }

    if (key === 'contacts') {
        if (activeContacts.value.length === 0) return 'Add at least one active contact for this company.';
        const unnamedContact = activeContacts.value.find((contact) => !contact.name.trim());
        if (unnamedContact) return 'Enter a name for every active contact.';
        const invalidEmail = activeContacts.value.find((contact) => contact.email && !/^\S+@\S+\.\S+$/.test(contact.email));
        if (invalidEmail) return `Enter a valid email address for ${invalidEmail.name || 'the contact'}.`;
        if (isBuyer.value && !activeContacts.value.some((contact) => contact.serves_buyer)) {
            return 'Assign at least one active contact to the buyer role.';
        }
        if (isSupplier.value && !activeContacts.value.some((contact) => contact.serves_supplier)) {
            return 'Assign at least one active contact to the supplier role.';
        }

        const unscoped = activeContacts.value.find((contact) =>
            isSupplier.value &&
            contact.serves_supplier &&
            activeLocations.value.length > 0 &&
            !contact.all_locations &&
            contact.location_keys.length === 0,
        );
        if (unscoped) return `Choose one or more factories for ${unscoped.name}, or select all factories.`;
    }

    return '';
}

function goNext(): void {
    const error = validateStep(currentStep.value.key);
    if (error) {
        validationError.value = error;
        return;
    }

    validationError.value = '';
    const nextStep = steps.value[activeStep.value + 1];
    if (!nextStep) return;
    if (nextStep.key === 'contacts' && contacts.value.length === 0) addContact();
    if (!visitedSteps.value.includes(nextStep.key)) visitedSteps.value.push(nextStep.key);
    activeStep.value += 1;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goBack(): void {
    validationError.value = '';
    if (activeStep.value > 0) activeStep.value -= 1;
    else router.push(isEditing.value ? `/companies/${companyId.value}` : '/companies');
}

function openStep(index: number): void {
    const step = steps.value[index];
    if (!step || !visitedSteps.value.includes(step.key)) return;
    validationError.value = '';
    activeStep.value = index;
}

function payload() {
    return {
        company: {
            country_id: company.country_id,
            name: company.name.trim(),
            company_code: company.company_code.trim(),
            code_slug: company.code_slug.trim(),
            postal_code: company.postal_code.trim(),
            vendor_code: company.vendor_code.trim(),
            location: company.location.trim(),
            address: company.address.trim(),
            email: company.email.trim(),
            phone: company.phone.trim(),
            vat_tin: company.vat_tin.trim(),
            status: company.status,
        },
        roles: roles.value,
        manufacturer_ids: isSupplier.value ? manufacturerIds.value : [],
        locations: isSupplier.value
            ? locations.value.map((location) => ({
                id: location.id,
                client_key: location.client_key,
                location_type: 'factory',
                name: location.name.trim(),
                location: location.location.trim(),
                address: location.address.trim(),
                country_id: location.country_id,
                manufacturer_id: location.manufacturer_id,
                status: location.status,
            }))
            : [],
        contacts: contacts.value.map((contact) => ({
            id: contact.id,
            name: contact.name.trim(),
            designation_id: contact.designation_id,
            job_title: contact.job_title.trim(),
            mobile: contact.mobile.trim(),
            telephone: contact.telephone.trim(),
            extension: contact.extension.trim(),
            email: contact.email.trim(),
            fax: contact.fax.trim(),
            status: contact.status,
            serves_buyer: isBuyer.value && contact.serves_buyer,
            serves_supplier: isSupplier.value && contact.serves_supplier,
            all_locations: isSupplier.value && contact.serves_supplier && contact.all_locations,
            is_primary_buyer: isBuyer.value && contact.is_primary_buyer,
            is_primary_supplier: isSupplier.value && contact.is_primary_supplier,
            location_keys: isSupplier.value && contact.serves_supplier && !contact.all_locations ? contact.location_keys : [],
        })),
    };
}

async function saveCompany(): Promise<void> {
    for (const step of steps.value) {
        const error = validateStep(step.key);
        if (error) {
            validationError.value = error;
            const index = steps.value.findIndex((candidate) => candidate.key === step.key);
            if (!visitedSteps.value.includes(step.key)) visitedSteps.value.push(step.key);
            activeStep.value = Math.max(0, index);
            return;
        }
    }

    isSaving.value = true;
    validationError.value = '';

    try {
        const endpoint = isEditing.value ? `/api/company-management/${companyId.value}` : '/api/company-management';
        const response = await requestJson<{ data: CompanyProfile; message?: string }>(endpoint, {
            method: isEditing.value ? 'PUT' : 'POST',
            body: JSON.stringify(payload()),
        });
        await router.push({
            path: `/companies/${response.data.company.id}`,
            query: { saved: isEditing.value ? 'updated' : 'created' },
        });
    } catch (error) {
        validationError.value = error instanceof Error ? error.message : 'Unable to save the company.';
    } finally {
        isSaving.value = false;
    }
}

watch(steps, (newSteps) => {
    if (activeStep.value >= newSteps.length) activeStep.value = newSteps.length - 1;
});

onMounted(loadWizard);
</script>

<template>
    <section class="page-scaffold company-wizard-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>Company onboarding</p>
                <h1>{{ isEditing ? 'Update Company Profile' : 'Create New Company' }}</h1>
                <span class="company-page-intro">Build the complete company structure in one guided flow.</span>
            </div>
            <button class="secondary-action icon-gap" type="button" @click="router.push(isEditing ? `/companies/${companyId}` : '/companies')">
                <ArrowLeft :size="17" aria-hidden="true" /> Cancel
            </button>
        </div>

        <div v-if="isLoading" class="company-loading-panel" role="status">
            <Loader2 :size="24" aria-hidden="true" />
            <div><strong>Preparing company setup</strong><span>Loading master data and company information...</span></div>
        </div>

        <div v-else-if="loadError" class="company-load-error" role="alert">
            <AlertTriangle :size="24" aria-hidden="true" />
            <div><strong>Company setup could not be loaded</strong><p>{{ loadError }}</p></div>
            <button class="secondary-action" type="button" @click="loadWizard">Try again</button>
        </div>

        <template v-else>
            <nav class="company-wizard-steps" aria-label="Company setup progress">
                <button
                    v-for="(step, index) in steps"
                    :key="step.key"
                    type="button"
                    :class="{ active: index === activeStep, done: visitedSteps.includes(step.key) && index !== activeStep, disabled: !visitedSteps.includes(step.key) }"
                    :disabled="!visitedSteps.includes(step.key)"
                    :aria-current="index === activeStep ? 'step' : undefined"
                    @click="openStep(index)"
                >
                    <strong><Check v-if="visitedSteps.includes(step.key) && index < activeStep" :size="16" aria-hidden="true" /><template v-else>{{ index + 1 }}</template></strong>
                    <span><b>{{ step.label }}</b><small>{{ step.description }}</small></span>
                </button>
            </nav>

            <p v-if="validationError" class="company-validation-error" role="alert">
                <AlertTriangle :size="18" aria-hidden="true" /> {{ validationError }}
            </p>

            <form class="company-wizard-form" novalidate @submit.prevent="saveCompany">
                <section v-if="currentStep.key === 'company'" class="company-step-panel" aria-labelledby="company-identity-title">
                    <header>
                        <span class="panel-mark"><Building2 :size="21" aria-hidden="true" /></span>
                        <div><h2 id="company-identity-title">Company identity and role</h2><p>Start with how the company operates, then add its core information.</p></div>
                    </header>

                    <div class="company-step-content">
                        <fieldset class="company-role-picker">
                            <legend>How will this company operate? <b>*</b></legend>
                            <div>
                                <label v-for="choice in roleChoices" :key="choice.value" :class="{ selected: companyKind === choice.value }">
                                    <input :checked="companyKind === choice.value" type="radio" name="company-kind" :value="choice.value" @change="selectCompanyKind(choice.value)" />
                                    <span class="company-role-icon"><component :is="choice.icon" :size="21" aria-hidden="true" /></span>
                                    <span><strong>{{ choice.label }}</strong><small>{{ choice.description }}</small></span>
                                    <CheckCircle2 v-if="companyKind === choice.value" class="company-role-check" :size="20" aria-hidden="true" />
                                </label>
                            </div>
                        </fieldset>

                        <div class="company-form-section">
                            <div class="company-section-heading"><div><h3>Core information</h3><p>Use the legal or recognized trading information.</p></div></div>
                            <div class="company-form-grid">
                                <label class="quote-field span-2"><span>Company name <b>*</b></span><input v-model="company.name" type="text" autocomplete="organization" placeholder="Legal or trading name" /></label>
                                <label class="quote-field"><span>Company code <b>*</b></span><input v-model="company.company_code" type="text" placeholder="e.g. ACME-OM" @input="handleCompanyCodeInput" /></label>
                                <label class="quote-field"><span>Code slug</span><input v-model="company.code_slug" type="text" placeholder="acme-om" @input="slugWasEdited = true" /></label>
                                <label class="quote-field"><span>Country</span><select v-model="company.country_id"><option :value="null">Select country</option><option v-for="country in options.countries" :key="country.id" :value="country.id">{{ country.name }}</option></select></label>
                                <label class="quote-field"><span>City / location</span><input v-model="company.location" type="text" autocomplete="address-level2" placeholder="Muscat" /></label>
                                <label class="quote-field span-2"><span>Registered address</span><textarea v-model="company.address" autocomplete="street-address" placeholder="Street, building, area, and any delivery notes"></textarea></label>
                                <label class="quote-field"><span>Postal code</span><input v-model="company.postal_code" type="text" autocomplete="postal-code" /></label>
                                <label class="quote-field"><span>Vendor code</span><input v-model="company.vendor_code" type="text" placeholder="Optional internal vendor code" /></label>
                                <label class="quote-field"><span>General email</span><input v-model="company.email" type="email" autocomplete="email" placeholder="info@company.com" /></label>
                                <label class="quote-field"><span>General phone</span><input v-model="company.phone" type="tel" autocomplete="tel" /></label>
                                <label class="quote-field"><span>VAT / TIN</span><input v-model="company.vat_tin" type="text" /></label>
                                <label class="quote-field"><span>Status</span><select v-model="company.status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-else-if="currentStep.key === 'supplier'" class="company-step-panel" aria-labelledby="supplier-setup-title">
                    <header>
                        <span class="panel-mark"><Factory :size="21" aria-hidden="true" /></span>
                        <div><h2 id="supplier-setup-title">Manufacturers and factories</h2><p>Define who this supplier represents and where goods are produced or dispatched.</p></div>
                    </header>

                    <div class="company-step-content supplier-setup-content">
                        <div class="company-form-section">
                            <div class="company-section-heading">
                                <div><h3>Represented manufacturers</h3><p>Select every manufacturer this supplier can supply.</p></div>
                                <span>{{ manufacturerIds.length }} selected</span>
                            </div>
                            <div v-if="options.manufacturers.length" class="manufacturer-picker">
                                <label v-for="manufacturer in options.manufacturers" :key="manufacturer.id" :class="{ selected: manufacturerIds.includes(manufacturer.id) }">
                                    <input v-model="manufacturerIds" type="checkbox" :value="manufacturer.id" />
                                    <span class="manufacturer-mark"><Factory :size="18" aria-hidden="true" /></span>
                                    <span><strong>{{ manufacturer.name }}</strong><small>{{ manufacturer.status === 'inactive' ? 'Inactive — retained for history' : 'Available manufacturer' }}</small></span>
                                    <Check v-if="manufacturerIds.includes(manufacturer.id)" :size="17" aria-hidden="true" />
                                </label>
                            </div>
                            <p v-else class="company-inline-empty"><Factory :size="18" aria-hidden="true" /> No active manufacturers are available. Add one from Manufacturers first.</p>
                            <p v-if="roles.is_internal" class="company-help-note"><ShieldCheck :size="17" aria-hidden="true" /> Manufacturer selection is optional for an internal company.</p>
                        </div>

                        <div class="company-form-section">
                            <div class="company-section-heading">
                                <div><h3>Factories</h3><p>Add production, dispatch, or collection locations. Factories are optional for distributors.</p></div>
                                <button class="secondary-action icon-gap" type="button" @click="addLocation"><Plus :size="16" aria-hidden="true" /> Add factory</button>
                            </div>

                            <div v-if="locations.length === 0" class="company-builder-empty">
                                <span><MapPin :size="23" aria-hidden="true" /></span>
                                <div><strong>No factories added</strong><p>You can continue without one, or add factory information now.</p></div>
                                <button class="secondary-action" type="button" @click="addLocation">Add first factory</button>
                            </div>

                            <div v-else class="company-builder-list">
                                <article v-for="(factoryLocation, index) in locations" :key="factoryLocation.client_key" class="company-builder-card" :class="{ inactive: factoryLocation.status === 'inactive' }">
                                    <header>
                                        <span><Factory :size="18" aria-hidden="true" /></span>
                                        <div><strong>Factory {{ index + 1 }}</strong><small>{{ factoryLocation.id ? 'Saved location' : 'New location' }}</small></div>
                                        <button v-if="factoryLocation.status === 'active'" class="company-card-remove" type="button" :aria-label="factoryLocation.id ? 'Deactivate factory' : 'Remove factory'" @click="removeLocation(index)"><Trash2 :size="16" aria-hidden="true" /></button>
                                        <button v-else class="company-card-restore" type="button" @click="restoreLocation(factoryLocation)">Reactivate</button>
                                    </header>
                                    <div class="company-form-grid">
                                        <label class="quote-field"><span>Factory name <b>*</b></span><input v-model="factoryLocation.name" type="text" placeholder="Main production facility" :disabled="factoryLocation.status === 'inactive'" /></label>
                                        <label class="quote-field"><span>Location <b>*</b></span><input v-model="factoryLocation.location" type="text" placeholder="City / industrial area" :disabled="factoryLocation.status === 'inactive'" /></label>
                                        <label class="quote-field"><span>Country</span><select v-model="factoryLocation.country_id" :disabled="factoryLocation.status === 'inactive'"><option :value="null">Use company country</option><option v-for="country in options.countries" :key="country.id" :value="country.id">{{ country.name }}</option></select></label>
                                        <label class="quote-field"><span>Manufacturer</span><select v-model="factoryLocation.manufacturer_id" :disabled="factoryLocation.status === 'inactive'"><option :value="null">Not specified</option><option v-for="manufacturer in selectedManufacturers" :key="manufacturer.id" :value="manufacturer.id">{{ manufacturer.name }}</option></select></label>
                                        <label class="quote-field span-2"><span>Factory address</span><textarea v-model="factoryLocation.address" placeholder="Full factory address" :disabled="factoryLocation.status === 'inactive'"></textarea></label>
                                    </div>
                                </article>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-else-if="currentStep.key === 'contacts'" class="company-step-panel" aria-labelledby="contacts-setup-title">
                    <header>
                        <span class="panel-mark"><UserRound :size="21" aria-hidden="true" /></span>
                        <div><h2 id="contacts-setup-title">Company contacts</h2><p>Add people once and define whether they serve the buyer, supplier, or both roles.</p></div>
                        <button class="secondary-action icon-gap" type="button" @click="addContact"><Plus :size="16" aria-hidden="true" /> Add contact</button>
                    </header>

                    <div class="company-step-content">
                        <div v-if="contacts.length === 0" class="company-builder-empty">
                            <span><UserRound :size="23" aria-hidden="true" /></span>
                            <div><strong>No contacts added</strong><p>Add at least one person to complete this company profile.</p></div>
                            <button class="secondary-action" type="button" @click="addContact">Add first contact</button>
                        </div>

                        <div v-else class="company-builder-list contact-builder-list">
                            <article v-for="(contact, index) in contacts" :key="contact.id ?? `new_${index}`" class="company-builder-card contact-builder-card" :class="{ inactive: contact.status === 'inactive' }">
                                <header>
                                    <span><UserRound :size="18" aria-hidden="true" /></span>
                                    <div><strong>{{ contact.name || `Contact ${index + 1}` }}</strong><small>{{ contact.id ? 'Saved contact' : 'New contact' }}</small></div>
                                    <span v-if="contact.status === 'inactive'" class="stage-pill slate">Inactive</span>
                                    <button v-if="contact.status === 'active'" class="company-card-remove" type="button" :aria-label="contact.id ? 'Deactivate contact' : 'Remove contact'" @click="removeContact(index)"><Trash2 :size="16" aria-hidden="true" /></button>
                                    <button v-else class="company-card-restore" type="button" @click="restoreContact(contact)">Reactivate</button>
                                </header>

                                <div class="company-form-grid">
                                    <label class="quote-field"><span>Full name <b>*</b></span><input v-model="contact.name" type="text" autocomplete="name" :disabled="contact.status === 'inactive'" /></label>
                                    <label class="quote-field"><span>Designation</span><select v-model="contact.designation_id" :disabled="contact.status === 'inactive'"><option :value="null">Select designation</option><option v-for="designation in options.designations" :key="designation.id" :value="designation.id">{{ designation.name }}</option></select></label>
                                    <label class="quote-field"><span>Job title</span><input v-model="contact.job_title" type="text" autocomplete="organization-title" :disabled="contact.status === 'inactive'" /></label>
                                    <label class="quote-field"><span>Email</span><input v-model="contact.email" type="email" autocomplete="email" :disabled="contact.status === 'inactive'" /></label>
                                    <label class="quote-field"><span>Mobile</span><input v-model="contact.mobile" type="tel" autocomplete="tel" :disabled="contact.status === 'inactive'" /></label>
                                    <label class="quote-field"><span>Telephone</span><input v-model="contact.telephone" type="tel" :disabled="contact.status === 'inactive'" /></label>
                                    <label class="quote-field"><span>Extension</span><input v-model="contact.extension" type="text" :disabled="contact.status === 'inactive'" /></label>
                                    <label class="quote-field"><span>Fax</span><input v-model="contact.fax" type="text" :disabled="contact.status === 'inactive'" /></label>
                                </div>

                                <fieldset v-if="contact.status === 'active'" class="contact-responsibility-panel">
                                    <legend>Responsibilities</legend>
                                    <div class="contact-role-options">
                                        <label v-if="isBuyer" :class="{ selected: contact.serves_buyer }"><input :checked="contact.serves_buyer" type="checkbox" @change="setContactRole(contact, 'buyer', ($event.target as HTMLInputElement).checked)" /><span><strong>Buyer contact</strong><small>Available on quotations and buyer records</small></span></label>
                                        <label v-if="isSupplier" :class="{ selected: contact.serves_supplier }"><input :checked="contact.serves_supplier" type="checkbox" @change="setContactRole(contact, 'supplier', ($event.target as HTMLInputElement).checked)" /><span><strong>Supplier contact</strong><small>Available on supplier POs and follow-up</small></span></label>
                                    </div>

                                    <div class="contact-primary-options">
                                        <label v-if="isBuyer && contact.serves_buyer"><input :checked="contact.is_primary_buyer" type="checkbox" @change="setPrimary(contact, 'buyer', ($event.target as HTMLInputElement).checked)" /> Primary buyer contact</label>
                                        <label v-if="isSupplier && contact.serves_supplier"><input :checked="contact.is_primary_supplier" type="checkbox" @change="setPrimary(contact, 'supplier', ($event.target as HTMLInputElement).checked)" /> Primary supplier contact</label>
                                    </div>

                                    <div v-if="isSupplier && contact.serves_supplier && locations.length" class="contact-factory-scope">
                                        <div><strong>Factory access</strong><small>Choose where this contact can be used.</small></div>
                                        <label class="all-factories-toggle"><input :checked="contact.all_locations" type="checkbox" @change="setAllLocations(contact, ($event.target as HTMLInputElement).checked)" /> All current and future factories</label>
                                        <div v-if="!contact.all_locations" class="factory-scope-grid">
                                            <label v-for="factoryLocation in locations" :key="factoryLocation.client_key" :class="{ inactive: factoryLocation.status === 'inactive' }">
                                                <input v-model="contact.location_keys" type="checkbox" :value="factoryLocation.client_key" :disabled="factoryLocation.status === 'inactive'" />
                                                <span><strong>{{ factoryLocation.name || 'Unnamed factory' }}</strong><small>{{ factoryLocation.location || 'Location pending' }}<template v-if="factoryLocation.status === 'inactive'"> · inactive</template></small></span>
                                            </label>
                                        </div>
                                    </div>
                                </fieldset>
                            </article>
                        </div>
                    </div>
                </section>

                <section v-else class="company-step-panel" aria-labelledby="company-review-title">
                    <header>
                        <span class="panel-mark"><CheckCircle2 :size="21" aria-hidden="true" /></span>
                        <div><h2 id="company-review-title">Review company profile</h2><p>Confirm the complete structure before making it available to the operational workflow.</p></div>
                    </header>

                    <div class="company-review-grid">
                        <article class="company-review-card">
                            <header><Building2 :size="19" aria-hidden="true" /><div><strong>Company</strong><button type="button" @click="openStep(0)">Change</button></div></header>
                            <dl>
                                <div><dt>Name</dt><dd>{{ company.name || '—' }}</dd></div>
                                <div><dt>Code</dt><dd>{{ company.company_code || '—' }}</dd></div>
                                <div><dt>Roles</dt><dd><span v-for="label in roleLabels(roles)" :key="label" class="company-role-chip">{{ label }}</span></dd></div>
                                <div><dt>Location</dt><dd>{{ company.location || '—' }}</dd></div>
                                <div><dt>Email</dt><dd>{{ company.email || '—' }}</dd></div>
                                <div><dt>Status</dt><dd><span class="stage-pill" :class="company.status === 'active' ? 'teal' : 'slate'">{{ company.status }}</span></dd></div>
                            </dl>
                        </article>

                        <article v-if="isSupplier" class="company-review-card">
                            <header><Factory :size="19" aria-hidden="true" /><div><strong>Supplier network</strong><button type="button" @click="openStep(steps.findIndex((step) => step.key === 'supplier'))">Change</button></div></header>
                            <dl>
                                <div><dt>Manufacturers</dt><dd>{{ selectedManufacturers.map((manufacturer) => manufacturer.name).join(', ') || 'None selected' }}</dd></div>
                                <div><dt>Active factories</dt><dd>{{ activeLocations.length }}</dd></div>
                                <div v-for="factoryLocation in activeLocations" :key="factoryLocation.client_key"><dt>{{ factoryLocation.name }}</dt><dd>{{ factoryLocation.location }}</dd></div>
                            </dl>
                        </article>

                        <article class="company-review-card company-review-contacts">
                            <header><UserRound :size="19" aria-hidden="true" /><div><strong>Contacts</strong><button type="button" @click="openStep(steps.findIndex((step) => step.key === 'contacts'))">Change</button></div></header>
                            <div class="review-contact-list">
                                <div v-for="contact in activeContacts" :key="contact.id ?? contact.name">
                                    <span class="company-avatar">{{ contact.name.slice(0, 2).toUpperCase() }}</span>
                                    <span><strong>{{ contact.name }}</strong><small>{{ contact.email || contact.mobile || 'No contact details' }}</small></span>
                                    <span class="company-role-list"><span v-if="contact.serves_buyer" class="company-role-chip">Buyer</span><span v-if="contact.serves_supplier" class="company-role-chip">Supplier</span></span>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="company-activation-note">
                        <ShieldCheck :size="22" aria-hidden="true" />
                        <div><strong>Ready for the quotation workflow</strong><p>Active buyer contacts will be available on quotations. Active supplier contacts, factories, and manufacturers will be available to supplier purchasing and follow-up.</p></div>
                    </div>
                </section>

                <footer class="company-wizard-footer">
                    <button class="secondary-action icon-gap" type="button" :disabled="isSaving" @click="goBack"><ArrowLeft :size="17" aria-hidden="true" /> {{ activeStep === 0 ? 'Cancel' : 'Back' }}</button>
                    <span>Step {{ activeStep + 1 }} of {{ steps.length }}</span>
                    <button v-if="currentStep.key !== 'review'" class="primary-action compact-action" type="button" @click="goNext">Continue <ArrowRight :size="17" aria-hidden="true" /></button>
                    <button v-else class="primary-action compact-action" type="submit" :disabled="isSaving">
                        <Loader2 v-if="isSaving" class="spin-icon" :size="17" aria-hidden="true" /><Save v-else :size="17" aria-hidden="true" />
                        {{ isSaving ? 'Saving company...' : (isEditing ? 'Save Changes' : 'Create Company') }}
                    </button>
                </footer>
            </form>
        </template>
    </section>
</template>
