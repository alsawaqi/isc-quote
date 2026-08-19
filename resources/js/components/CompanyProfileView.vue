<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowLeft,
    Building2,
    CheckCircle2,
    CircleDollarSign,
    ClipboardList,
    Factory,
    FileText,
    Globe2,
    Loader2,
    Mail,
    MapPin,
    Pencil,
    Phone,
    ReceiptText,
    RefreshCcw,
    ShieldCheck,
    Truck,
    UserRound,
    UsersRound,
} from 'lucide-vue-next';
import { requestJson } from '../auth';
import {
    emptyHistory,
    roleLabels,
    type CompanyContact,
    type CompanyHistoryRecord,
    type CompanyLocation,
    type CompanyProfile,
} from '../companyManagement';
import { humanizeStatus } from '../utils/format';

type ProfileTab = 'overview' | 'network' | 'contacts' | 'history';
type Toast = { type: 'error' | 'success'; message: string };

const route = useRoute();
const router = useRouter();
const profile = ref<CompanyProfile | null>(null);
const activeTab = ref<ProfileTab>('overview');
const isLoading = ref(true);
const loadError = ref('');
const toast = ref<Toast | null>(null);
let requestSequence = 0;

const companyId = computed(() => Number(route.params.id || 0));
const activeContacts = computed(() => profile.value?.contacts.filter((contact) => contact.status === 'active') ?? []);
const activeLocations = computed(() => profile.value?.locations.filter((location) => location.status === 'active') ?? []);
const buyerPrimary = computed(() => activeContacts.value.find((contact) => contact.is_primary_buyer)
    ?? activeContacts.value.find((contact) => contact.id === profile.value?.buyer_profile?.primary_contact_id)
    ?? null);
const supplierPrimary = computed(() => activeContacts.value.find((contact) => contact.is_primary_supplier)
    ?? activeContacts.value.find((contact) => contact.id === profile.value?.supplier_profile?.primary_contact_id)
    ?? null);

const tabs: Array<{ key: ProfileTab; label: string; icon: typeof Building2 }> = [
    { key: 'overview', label: 'Overview', icon: Building2 },
    { key: 'network', label: 'Manufacturers & factories', icon: Factory },
    { key: 'contacts', label: 'Contacts', icon: UserRound },
    { key: 'history', label: 'Commercial history', icon: ClipboardList },
];

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) toast.value = null;
    }, 3400);
}

function normalizeProfile(data: CompanyProfile): CompanyProfile {
    return {
        ...data,
        manufacturers: data.manufacturers ?? [],
        locations: (data.locations ?? []).map((location) => ({
            ...location,
            client_key: location.client_key || (location.id ? `location_${location.id}` : `location_${location.name}`),
            address: location.address ?? '',
            country_id: location.country_id ?? null,
            manufacturer_id: location.manufacturer_id ?? null,
            status: location.status === 'inactive' ? 'inactive' : 'active',
        })),
        contacts: (data.contacts ?? []).map((contact) => ({
            ...contact,
            designation_id: contact.designation_id ?? null,
            job_title: contact.job_title ?? '',
            mobile: contact.mobile ?? '',
            telephone: contact.telephone ?? '',
            extension: contact.extension ?? '',
            email: contact.email ?? '',
            fax: contact.fax ?? '',
            location_keys: contact.location_keys ?? [],
            status: contact.status === 'inactive' ? 'inactive' : 'active',
        })),
        history: data.history ?? emptyHistory(),
    };
}

async function loadProfile(): Promise<void> {
    const sequence = ++requestSequence;
    isLoading.value = true;
    loadError.value = '';

    try {
        const payload = await requestJson<{ data: CompanyProfile }>(`/api/company-management/${companyId.value}`);
        if (sequence === requestSequence) profile.value = normalizeProfile(payload.data);
    } catch (error) {
        if (sequence === requestSequence) {
            loadError.value = error instanceof Error ? error.message : 'Unable to load the company profile.';
        }
    } finally {
        if (sequence === requestSequence) isLoading.value = false;
    }
}

function contactLocationNames(contact: CompanyContact): string {
    if (contact.all_locations) return 'All factories';
    if (!profile.value || !contact.location_keys.length) return 'No factory assigned';

    const keys = new Set(contact.location_keys);
    const names = profile.value.locations
        .filter((location) => keys.has(location.client_key) || (location.id && contact.location_ids?.includes(location.id)))
        .map((location) => location.name);
    return names.join(', ') || 'Selected factories';
}

function manufacturerFor(location: CompanyLocation): string {
    if (location.manufacturer_name) return location.manufacturer_name;
    return profile.value?.manufacturers.find((manufacturer) => manufacturer.id === location.manufacturer_id)?.name ?? 'Not assigned';
}

function recordReference(record: CompanyHistoryRecord, fallback: string): string {
    return record.reference || record.quotation_reference || record.buyer_po_number || record.supplier_po_number || `${fallback} #${record.id}`;
}

function formatDate(value?: string | null): string {
    if (!value) return 'Date unavailable';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;
    return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(parsed);
}

function recordMeta(record: CompanyHistoryRecord): string {
    const parts = [formatDate(record.date || record.created_at)];
    if (record.role) parts.push(humanizeStatus(record.role));
    if (record.value !== null && record.value !== undefined) {
        parts.push(`${record.currency || ''} ${record.value}`.trim());
    }
    if (record.factory_name) {
        parts.push(`${record.factory_name}${record.factory_location ? ` — ${record.factory_location}` : ''}`);
    }
    return parts.join(' · ');
}

function countryName(countryId: number | null, suppliedName?: string | null): string {
    if (suppliedName) return suppliedName;
    if (countryId === profile.value?.company.country_id) return profile.value.company.country_name || 'Company country';
    return 'Not specified';
}

watch(companyId, loadProfile);

onMounted(async () => {
    if (route.query.saved === 'created') showToast('success', 'Company profile created successfully.');
    if (route.query.saved === 'updated') showToast('success', 'Company profile updated successfully.');
    await loadProfile();
});
</script>

<template>
    <section class="page-scaffold company-profile-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div v-if="isLoading" class="company-loading-panel" role="status">
            <Loader2 :size="24" aria-hidden="true" />
            <div><strong>Loading company profile</strong><span>Gathering company, contact, and commercial information...</span></div>
        </div>

        <div v-else-if="loadError || !profile" class="company-load-error" role="alert">
            <AlertTriangle :size="24" aria-hidden="true" />
            <div><strong>Company profile could not be loaded</strong><p>{{ loadError || 'The company was not found.' }}</p></div>
            <button class="secondary-action" type="button" @click="loadProfile">Try again</button>
            <button class="secondary-action" type="button" @click="router.push('/companies')">Back to companies</button>
        </div>

        <template v-else>
            <div class="company-profile-hero">
                <button class="company-profile-back" type="button" aria-label="Back to companies" @click="router.push('/companies')"><ArrowLeft :size="19" aria-hidden="true" /></button>
                <span class="company-profile-avatar">{{ profile.company.name.slice(0, 2).toUpperCase() }}</span>
                <div class="company-profile-heading">
                    <span>Company profile · {{ profile.company.company_code }}</span>
                    <h1>{{ profile.company.name }}</h1>
                    <div class="company-profile-badges">
                        <span v-for="role in roleLabels(profile.roles)" :key="role" class="company-role-chip">{{ role }}</span>
                        <span class="stage-pill" :class="profile.company.status === 'active' ? 'teal' : 'slate'">{{ profile.company.status }}</span>
                    </div>
                </div>
                <div class="company-profile-actions">
                    <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadProfile"><RefreshCcw :size="16" aria-hidden="true" /> Refresh</button>
                    <button class="primary-action compact-action" type="button" @click="router.push(`/companies/${companyId}/edit`)"><Pencil :size="16" aria-hidden="true" /> Edit profile</button>
                </div>
            </div>

            <nav class="company-profile-tabs" aria-label="Company profile sections">
                <button v-for="tab in tabs" :key="tab.key" type="button" :class="{ active: activeTab === tab.key }" @click="activeTab = tab.key">
                    <component :is="tab.icon" :size="17" aria-hidden="true" /> {{ tab.label }}
                </button>
            </nav>

            <div v-if="activeTab === 'overview'" class="company-profile-content">
                <div class="company-overview-layout">
                    <section class="company-profile-card">
                        <header><div><h2>Company information</h2><p>Registered identity and general communication details.</p></div><Building2 :size="21" aria-hidden="true" /></header>
                        <dl class="company-detail-list">
                            <div><dt><Globe2 :size="15" aria-hidden="true" /> Country</dt><dd>{{ profile.company.country_name || 'Not specified' }}</dd></div>
                            <div><dt><MapPin :size="15" aria-hidden="true" /> Location</dt><dd>{{ profile.company.location || 'Not specified' }}</dd></div>
                            <div class="span-2"><dt><MapPin :size="15" aria-hidden="true" /> Registered address</dt><dd>{{ profile.company.address || 'Not specified' }}</dd></div>
                            <div><dt><Mail :size="15" aria-hidden="true" /> Email</dt><dd><a v-if="profile.company.email" :href="`mailto:${profile.company.email}`">{{ profile.company.email }}</a><template v-else>Not specified</template></dd></div>
                            <div><dt><Phone :size="15" aria-hidden="true" /> Phone</dt><dd><a v-if="profile.company.phone" :href="`tel:${profile.company.phone}`">{{ profile.company.phone }}</a><template v-else>Not specified</template></dd></div>
                            <div><dt>VAT / TIN</dt><dd>{{ profile.company.vat_tin || 'Not specified' }}</dd></div>
                            <div><dt>Vendor code</dt><dd>{{ profile.company.vendor_code || 'Not specified' }}</dd></div>
                            <div><dt>Postal code</dt><dd>{{ profile.company.postal_code || 'Not specified' }}</dd></div>
                            <div><dt>Code slug</dt><dd>{{ profile.company.code_slug || 'Not specified' }}</dd></div>
                        </dl>
                    </section>

                    <aside class="company-overview-side">
                        <section class="company-profile-card company-capability-card">
                            <header><div><h2>Operational roles</h2><p>Where this company is available.</p></div><ShieldCheck :size="21" aria-hidden="true" /></header>
                            <div class="company-capability-list">
                                <div :class="{ enabled: profile.roles.is_internal }"><ShieldCheck :size="18" aria-hidden="true" /><span><strong>Internal company</strong><small>{{ profile.roles.is_internal ? 'Enabled' : 'Not enabled' }}</small></span></div>
                                <div :class="{ enabled: profile.roles.is_buyer }"><UsersRound :size="18" aria-hidden="true" /><span><strong>Buyer</strong><small>{{ profile.roles.is_buyer ? 'Available in quotations' : 'Not enabled' }}</small></span></div>
                                <div :class="{ enabled: profile.roles.is_supplier }"><Factory :size="18" aria-hidden="true" /><span><strong>Supplier</strong><small>{{ profile.roles.is_supplier ? 'Available in supplier POs' : 'Not enabled' }}</small></span></div>
                            </div>
                        </section>

                        <section class="company-profile-card company-primary-card">
                            <header><div><h2>Primary contacts</h2><p>Default people for each role.</p></div><UserRound :size="21" aria-hidden="true" /></header>
                            <div v-if="buyerPrimary || supplierPrimary" class="company-primary-list">
                                <div v-if="buyerPrimary"><span class="company-avatar">{{ buyerPrimary.name.slice(0, 2).toUpperCase() }}</span><span><small>Buyer contact</small><strong>{{ buyerPrimary.name }}</strong><em>{{ buyerPrimary.email || buyerPrimary.mobile || 'No details' }}</em></span></div>
                                <div v-if="supplierPrimary"><span class="company-avatar">{{ supplierPrimary.name.slice(0, 2).toUpperCase() }}</span><span><small>Supplier contact</small><strong>{{ supplierPrimary.name }}</strong><em>{{ supplierPrimary.email || supplierPrimary.mobile || 'No details' }}</em></span></div>
                            </div>
                            <p v-else class="company-profile-empty-copy">No primary contacts have been selected.</p>
                        </section>
                    </aside>
                </div>

                <div class="company-profile-summary-row">
                    <button type="button" @click="activeTab = 'network'"><span class="company-stat-icon amber"><Factory :size="20" aria-hidden="true" /></span><span><strong>{{ profile.manufacturers.length }}</strong><small>Manufacturers</small></span></button>
                    <button type="button" @click="activeTab = 'network'"><span class="company-stat-icon teal"><MapPin :size="20" aria-hidden="true" /></span><span><strong>{{ activeLocations.length }}</strong><small>Active factories</small></span></button>
                    <button type="button" @click="activeTab = 'contacts'"><span class="company-stat-icon blue"><UserRound :size="20" aria-hidden="true" /></span><span><strong>{{ activeContacts.length }}</strong><small>Active contacts</small></span></button>
                    <button type="button" @click="activeTab = 'history'"><span class="company-stat-icon green"><FileText :size="20" aria-hidden="true" /></span><span><strong>{{ profile.history.counts.quotations }}</strong><small>Quotations</small></span></button>
                </div>
            </div>

            <div v-else-if="activeTab === 'network'" class="company-profile-content company-network-content">
                <section class="company-profile-card">
                    <header><div><h2>Represented manufacturers</h2><p>Manufacturers available when this company is selected as a supplier.</p></div><Factory :size="21" aria-hidden="true" /></header>
                    <div v-if="profile.manufacturers.length" class="company-manufacturer-cards">
                        <article v-for="manufacturer in profile.manufacturers" :key="manufacturer.id"><span><Factory :size="20" aria-hidden="true" /></span><div><strong>{{ manufacturer.name }}</strong><small>{{ manufacturer.country_name || 'Manufacturer' }}</small></div></article>
                    </div>
                    <div v-else class="company-profile-empty"><Factory :size="24" aria-hidden="true" /><strong>No manufacturers linked</strong><p>This is expected for buyer-only and some internal companies.</p></div>
                </section>

                <section class="company-profile-card">
                    <header><div><h2>Factories</h2><p>Production, dispatch, and collection locations.</p></div><MapPin :size="21" aria-hidden="true" /></header>
                    <div v-if="profile.locations.length" class="company-factory-table">
                        <div class="head"><span>Factory</span><span>Location</span><span>Manufacturer</span><span>Status</span></div>
                        <div v-for="factoryLocation in profile.locations" :key="factoryLocation.client_key">
                            <span><strong>{{ factoryLocation.name }}</strong><small>{{ factoryLocation.address || 'No address' }}</small></span>
                            <span>{{ factoryLocation.location }}<small>{{ countryName(factoryLocation.country_id, factoryLocation.country_name) }}</small></span>
                            <span>{{ manufacturerFor(factoryLocation) }}</span>
                            <span class="stage-pill" :class="factoryLocation.status === 'active' ? 'teal' : 'slate'">{{ factoryLocation.status }}</span>
                        </div>
                    </div>
                    <div v-else class="company-profile-empty"><MapPin :size="24" aria-hidden="true" /><strong>No factories recorded</strong><p>Add a factory when this supplier needs location-level purchasing and follow-up.</p></div>
                </section>
            </div>

            <div v-else-if="activeTab === 'contacts'" class="company-profile-content">
                <section class="company-profile-card">
                    <header><div><h2>Company contacts</h2><p>Buyer and supplier responsibilities, primary status, and factory coverage.</p></div><UserRound :size="21" aria-hidden="true" /></header>
                    <div v-if="profile.contacts.length" class="company-contact-directory">
                        <article v-for="contact in profile.contacts" :key="contact.id ?? contact.name" :class="{ inactive: contact.status === 'inactive' }">
                            <header><span class="company-profile-contact-avatar">{{ contact.name.slice(0, 2).toUpperCase() }}</span><div><strong>{{ contact.name }}</strong><small>{{ contact.job_title || contact.designation_name || 'Contact' }}</small></div><span class="stage-pill" :class="contact.status === 'active' ? 'teal' : 'slate'">{{ contact.status }}</span></header>
                            <div class="company-contact-methods">
                                <a v-if="contact.email" :href="`mailto:${contact.email}`"><Mail :size="15" aria-hidden="true" /> {{ contact.email }}</a><span v-else><Mail :size="15" aria-hidden="true" /> No email</span>
                                <a v-if="contact.mobile || contact.telephone" :href="`tel:${contact.mobile || contact.telephone}`"><Phone :size="15" aria-hidden="true" /> {{ contact.mobile || contact.telephone }}</a><span v-else><Phone :size="15" aria-hidden="true" /> No phone</span>
                            </div>
                            <div class="company-contact-tags">
                                <span v-if="contact.serves_buyer" class="company-role-chip">Buyer</span>
                                <span v-if="contact.serves_supplier" class="company-role-chip">Supplier</span>
                                <span v-if="contact.is_primary_buyer" class="company-primary-chip">Primary buyer</span>
                                <span v-if="contact.is_primary_supplier" class="company-primary-chip">Primary supplier</span>
                            </div>
                            <p v-if="contact.serves_supplier"><Factory :size="15" aria-hidden="true" /> {{ contactLocationNames(contact) }}</p>
                        </article>
                    </div>
                    <div v-else class="company-profile-empty"><UserRound :size="24" aria-hidden="true" /><strong>No contacts recorded</strong><p>Edit this profile to add the people used in quotations and supplier POs.</p></div>
                </section>
            </div>

            <div v-else class="company-profile-content company-history-content">
                <div class="company-history-stats">
                    <article><span class="company-stat-icon teal"><FileText :size="19" aria-hidden="true" /></span><div><strong>{{ profile.history.counts.quotations }}</strong><small>Quotations</small></div></article>
                    <article><span class="company-stat-icon blue"><ReceiptText :size="19" aria-hidden="true" /></span><div><strong>{{ profile.history.counts.buyer_pos }}</strong><small>Buyer POs</small></div></article>
                    <article><span class="company-stat-icon amber"><Truck :size="19" aria-hidden="true" /></span><div><strong>{{ profile.history.counts.supplier_pos }}</strong><small>Supplier POs</small></div></article>
                    <article><span class="company-stat-icon green"><ClipboardList :size="19" aria-hidden="true" /></span><div><strong>{{ profile.history.counts.follow_up_items }}</strong><small>Follow-up items</small></div></article>
                    <article><span class="company-stat-icon rose"><CircleDollarSign :size="19" aria-hidden="true" /></span><div><strong>{{ profile.history.counts.invoices }}</strong><small>Invoices</small></div></article>
                </div>

                <div class="company-history-grid">
                    <section class="company-profile-card company-history-list">
                        <header><div><h2>Recent quotations</h2><p>Commercial requests where this company acted as buyer or supplier.</p></div><FileText :size="21" aria-hidden="true" /></header>
                        <div v-if="profile.history.quotations.length">
                            <button v-for="record in profile.history.quotations" :key="record.id" type="button" @click="router.push(`/quotations/${record.id}`)"><span><strong>{{ recordReference(record, 'Quotation') }}</strong><small>{{ recordMeta(record) }}</small></span><span class="stage-pill teal">{{ humanizeStatus(record.status || 'recorded') }}</span></button>
                        </div>
                        <p v-else class="company-profile-empty-copy">No quotations are linked to this company yet.</p>
                    </section>

                    <section class="company-profile-card company-history-list">
                        <header><div><h2>Recent buyer POs</h2><p>Purchase orders received from this company.</p></div><ReceiptText :size="21" aria-hidden="true" /></header>
                        <div v-if="profile.history.buyer_pos.length"><div v-for="record in profile.history.buyer_pos" :key="record.id"><span><strong>{{ recordReference(record, 'Buyer PO') }}</strong><small>{{ recordMeta(record) }}</small></span><span class="stage-pill teal">{{ humanizeStatus(record.status || 'recorded') }}</span></div></div>
                        <p v-else class="company-profile-empty-copy">No buyer POs are linked to this company yet.</p>
                    </section>

                    <section class="company-profile-card company-history-list">
                        <header><div><h2>Recent supplier POs</h2><p>Orders issued when this company acted as supplier.</p></div><Truck :size="21" aria-hidden="true" /></header>
                        <div v-if="profile.history.supplier_pos.length"><div v-for="record in profile.history.supplier_pos" :key="record.id"><span><strong>{{ recordReference(record, 'Supplier PO') }}</strong><small>{{ recordMeta(record) }}</small></span><span class="stage-pill teal">{{ humanizeStatus(record.status || 'recorded') }}</span></div></div>
                        <p v-else class="company-profile-empty-copy">No supplier POs are linked to this company yet.</p>
                    </section>
                </div>
            </div>
        </template>
    </section>
</template>
