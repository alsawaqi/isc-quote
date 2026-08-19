<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    Factory,
    Loader2,
    MapPin,
    Plus,
    RefreshCcw,
    Search,
    UsersRound,
} from 'lucide-vue-next';
import { requestJson } from '../auth';
import type { CompanyRoles, CompanySummary } from '../companyManagement';
import { roleLabels } from '../companyManagement';

type Toast = { type: 'error' | 'success'; message: string };
type RoleFilter = 'all' | 'internal' | 'buyer' | 'supplier' | 'mixed';

interface SummaryPayload {
    id: number;
    name?: string;
    company_code?: string;
    country_name?: string | null;
    location?: string | null;
    email?: string | null;
    phone?: string | null;
    status?: string;
    roles?: Partial<CompanyRoles>;
    is_internal?: boolean;
    is_buyer?: boolean;
    is_supplier?: boolean;
    contacts_count?: number;
    locations_count?: number;
    manufacturers_count?: number;
    manufacturer_names?: string[] | string | null;
}

const router = useRouter();
const companies = ref<CompanySummary[]>([]);
const search = ref('');
const selectedRole = ref<RoleFilter>('all');
const isLoading = ref(false);
const toast = ref<Toast | null>(null);
const page = ref(1);
const meta = ref({ total: 0, current_page: 1, last_page: 1, per_page: 50 });
let searchTimer: number | undefined;
let requestSequence = 0;

const roleFilters: Array<{ value: RoleFilter; label: string }> = [
    { value: 'all', label: 'All companies' },
    { value: 'buyer', label: 'Buyers' },
    { value: 'supplier', label: 'Suppliers' },
    { value: 'mixed', label: 'Buyer & supplier' },
    { value: 'internal', label: 'Internal' },
];

const counts = computed(() => ({
    total: meta.value.total,
    buyers: companies.value.filter((company) => company.roles.is_buyer).length,
    suppliers: companies.value.filter((company) => company.roles.is_supplier).length,
    mixed: companies.value.filter((company) => company.roles.is_buyer && company.roles.is_supplier && !company.roles.is_internal).length,
}));

function normalizeCompany(record: SummaryPayload): CompanySummary {
    const manufacturerNames = Array.isArray(record.manufacturer_names)
        ? record.manufacturer_names
        : record.manufacturer_names
            ? record.manufacturer_names.split(',').map((name) => name.trim()).filter(Boolean)
            : [];

    return {
        id: record.id,
        name: record.name ?? 'Unnamed company',
        company_code: record.company_code ?? '—',
        country_name: record.country_name ?? null,
        location: record.location ?? null,
        email: record.email ?? null,
        phone: record.phone ?? null,
        status: record.status ?? 'active',
        roles: {
            is_internal: record.roles?.is_internal ?? record.is_internal ?? false,
            is_buyer: record.roles?.is_buyer ?? record.is_buyer ?? false,
            is_supplier: record.roles?.is_supplier ?? record.is_supplier ?? false,
            label: record.roles?.label,
        },
        contacts_count: record.contacts_count ?? 0,
        locations_count: record.locations_count ?? 0,
        manufacturers_count: record.manufacturers_count ?? manufacturerNames.length,
        manufacturer_names: manufacturerNames,
    };
}

function showToast(type: Toast['type'], message: string): void {
    toast.value = { type, message };
    window.setTimeout(() => {
        if (toast.value?.message === message) toast.value = null;
    }, 3400);
}

async function loadCompanies(): Promise<void> {
    const sequence = ++requestSequence;
    isLoading.value = true;

    const params = new URLSearchParams();
    if (search.value.trim()) params.set('search', search.value.trim());
    if (selectedRole.value !== 'all') params.set('role', selectedRole.value);
    params.set('page', String(page.value));
    params.set('per_page', String(meta.value.per_page));

    try {
        const suffix = params.toString() ? `?${params.toString()}` : '';
        const payload = await requestJson<{
            data: SummaryPayload[];
            meta: { total: number; current_page: number; last_page: number; per_page: number };
        }>(`/api/company-management${suffix}`);

        if (sequence === requestSequence) {
            companies.value = payload.data.map(normalizeCompany);
            meta.value = { ...meta.value, ...payload.meta };
        }
    } catch (error) {
        if (sequence === requestSequence) {
            showToast('error', error instanceof Error ? error.message : 'Unable to load companies.');
        }
    } finally {
        if (sequence === requestSequence) isLoading.value = false;
    }
}

watch(selectedRole, () => {
    page.value = 1;
    void loadCompanies();
});
watch(search, () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        page.value = 1;
        void loadCompanies();
    }, 280);
});

function changePage(nextPage: number): void {
    if (nextPage < 1 || nextPage > meta.value.last_page || nextPage === page.value) return;
    page.value = nextPage;
    void loadCompanies();
}

onMounted(loadCompanies);
onBeforeUnmount(() => window.clearTimeout(searchTimer));
</script>

<template>
    <section class="page-scaffold company-directory-page">
        <transition name="toast-slide">
            <div v-if="toast" class="toast-message" :class="toast.type" role="status">
                <CheckCircle2 v-if="toast.type === 'success'" :size="18" aria-hidden="true" />
                <AlertTriangle v-else :size="18" aria-hidden="true" />
                <span>{{ toast.message }}</span>
            </div>
        </transition>

        <div class="dashboard-titlebar module-titlebar">
            <div class="page-title">
                <p>Master data</p>
                <h1>Companies</h1>
                <span class="company-page-intro">One place for buyer, supplier, factory, and contact information.</span>
            </div>

            <div class="quotation-title-actions">
                <button class="secondary-action icon-gap" type="button" :disabled="isLoading" @click="loadCompanies">
                    <RefreshCcw :class="{ 'spin-icon': isLoading }" :size="17" aria-hidden="true" />
                    Refresh
                </button>
                <button class="primary-action compact-action" type="button" @click="router.push('/companies/create')">
                    <Plus :size="17" aria-hidden="true" />
                    New Company
                </button>
            </div>
        </div>

        <div class="company-directory-stats" aria-label="Company summary">
            <article>
                <span class="company-stat-icon teal"><Building2 :size="20" aria-hidden="true" /></span>
                <div><span>Companies found</span><strong>{{ counts.total }}</strong></div>
            </article>
            <article>
                <span class="company-stat-icon blue"><UsersRound :size="20" aria-hidden="true" /></span>
                <div><span>Buyers on page</span><strong>{{ counts.buyers }}</strong></div>
            </article>
            <article>
                <span class="company-stat-icon amber"><Factory :size="20" aria-hidden="true" /></span>
                <div><span>Suppliers on page</span><strong>{{ counts.suppliers }}</strong></div>
            </article>
            <article>
                <span class="company-stat-icon green"><Building2 :size="20" aria-hidden="true" /></span>
                <div><span>Mixed on page</span><strong>{{ counts.mixed }}</strong></div>
            </article>
        </div>

        <section class="table-panel module-table company-directory-panel" aria-labelledby="company-list-title">
            <div class="company-directory-toolbar">
                <div>
                    <h2 id="company-list-title">Company directory</h2>
                    <p>Open a company to manage its complete commercial profile.</p>
                </div>

                <div class="company-directory-controls">
                    <label class="mini-search company-search">
                        <Search :size="16" aria-hidden="true" />
                        <span class="sr-only">Search companies</span>
                        <input v-model="search" type="search" placeholder="Name, code, email or location" />
                    </label>
                    <label class="company-role-filter">
                        <span class="sr-only">Filter by company role</span>
                        <select v-model="selectedRole">
                            <option v-for="filter in roleFilters" :key="filter.value" :value="filter.value">{{ filter.label }}</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="module-records">
                <div class="module-record head company-directory-row" aria-hidden="true">
                    <span>Company</span>
                    <span>Capabilities</span>
                    <span>Location</span>
                    <span>Network</span>
                    <span>Contact</span>
                    <span>Status</span>
                    <span></span>
                </div>

                <div v-if="isLoading" class="crud-empty">
                    <Loader2 :size="20" aria-hidden="true" />
                    Loading companies...
                </div>

                <div v-else-if="companies.length === 0" class="company-empty-state">
                    <span><Building2 :size="26" aria-hidden="true" /></span>
                    <div>
                        <strong>No companies found</strong>
                        <p>{{ search || selectedRole !== 'all' ? 'Try changing the search or role filter.' : 'Create the first company profile to get started.' }}</p>
                    </div>
                    <button v-if="!search && selectedRole === 'all'" class="primary-action compact-action" type="button" @click="router.push('/companies/create')">
                        <Plus :size="16" aria-hidden="true" /> New Company
                    </button>
                </div>

                <button
                    v-for="company in companies"
                    v-else
                    :key="company.id"
                    class="module-record company-directory-row company-directory-entry"
                    type="button"
                    :aria-label="`View ${company.name}`"
                    @click="router.push(`/companies/${company.id}`)"
                >
                    <span class="company-list-identity">
                        <span class="company-avatar">{{ company.name.slice(0, 2).toUpperCase() }}</span>
                        <span><strong>{{ company.name }}</strong><small>{{ company.company_code }}</small></span>
                    </span>
                    <span class="company-role-list">
                        <span v-for="role in roleLabels(company.roles)" :key="role" class="company-role-chip">{{ role }}</span>
                        <small v-if="roleLabels(company.roles).length === 0">No role assigned</small>
                    </span>
                    <span class="company-location-cell">
                        <MapPin :size="15" aria-hidden="true" />
                        <span>{{ company.location || company.country_name || 'Not specified' }}</span>
                    </span>
                    <span class="company-network-cell">
                        <small :title="company.manufacturer_names.join(', ')">{{ company.manufacturer_names.length ? company.manufacturer_names.join(', ') : `${company.manufacturers_count} manufacturers` }}</small>
                        <small>{{ company.locations_count }} factories</small>
                    </span>
                    <span class="company-contact-cell">
                        <strong>{{ company.contacts_count }} contacts</strong>
                        <small>{{ company.email || company.phone || 'No general contact' }}</small>
                    </span>
                    <span class="stage-pill" :class="company.status === 'active' ? 'teal' : 'slate'">{{ company.status }}</span>
                    <span class="company-row-arrow"><ArrowRight :size="18" aria-hidden="true" /></span>
                </button>
            </div>

            <div v-if="meta.last_page > 1" class="pager" aria-label="Company directory pages">
                <button type="button" :disabled="meta.current_page <= 1 || isLoading" @click="changePage(meta.current_page - 1)">Previous</button>
                <span>Page {{ meta.current_page }} of {{ meta.last_page }}</span>
                <button type="button" :disabled="meta.current_page >= meta.last_page || isLoading" @click="changePage(meta.current_page + 1)">Next</button>
            </div>
        </section>
    </section>
</template>
