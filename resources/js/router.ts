import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { h } from 'vue';
import DashboardView from './components/DashboardView.vue';
import PlaceholderPage from './components/PlaceholderPage.vue';
import QuotationListView from './components/QuotationListView.vue';
import SupplierPoListView from './components/SupplierPoListView.vue';
import { canAccess, currentUser, homePathForUser, isAuthReady, loadSession } from './auth';
import type { RoleSlug } from './types';

const QuotationCreateView = () => import('./components/QuotationCreateView.vue');
const QuotationDetailView = () => import('./components/QuotationDetailView.vue');
const SupplierPoCreateView = () => import('./components/SupplierPoCreateView.vue');
const AdminQuotationTraceView = () => import('./components/AdminQuotationTraceView.vue');
const AdminItemTraceView = () => import('./components/AdminItemTraceView.vue');
const FollowUpDashboardView = () => import('./components/FollowUpDashboardView.vue');
const FollowUpGroupView = () => import('./components/FollowUpGroupView.vue');
const FollowUpQuotationView = () => import('./components/FollowUpQuotationView.vue');
const FollowUpVisualizationView = () => import('./components/FollowUpVisualizationView.vue');
const FollowUpDetailView = () => import('./components/FollowUpDetailView.vue');

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        requiresAuth?: boolean;
        permissions?: string[];
        roles?: RoleSlug[];
        title?: string;
    }
}

const adminRoles: RoleSlug[] = ['admin'];
const GuestOnlyView = { render: () => h('span') };

const resourcePermissions = (resource: string): string[] => [
    `view-${resource}`,
    `create-${resource}`,
    `update-${resource}`,
    `delete-${resource}`,
];

const moduleRoute = (path: string, name: string, title: string, roles: RoleSlug[], permissions?: string[]): RouteRecordRaw => ({
    path,
    name,
    component: PlaceholderPage,
    meta: {
        requiresAuth: true,
        permissions,
        roles,
        title,
    },
});

const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/login' },
    {
        path: '/login',
        name: 'login',
        component: GuestOnlyView,
        meta: { guest: true, title: 'Sign In' },
    },
    {
        path: '/dashboard',
        name: 'dashboard',
        component: DashboardView,
        meta: {
            requiresAuth: true,
            roles: adminRoles,
            title: 'Dashboard',
        },
    },
    moduleRoute('/countries', 'countries', 'Countries', adminRoles, resourcePermissions('countries')),
    moduleRoute('/designations', 'designations', 'Designations', adminRoles, resourcePermissions('designations')),
    moduleRoute('/companies', 'companies', 'Companies', adminRoles, resourcePermissions('companies')),
    moduleRoute('/contacts', 'contacts', 'Contacts', adminRoles, resourcePermissions('contacts')),
    moduleRoute('/incoterms', 'incoterms', 'Incoterms', adminRoles, resourcePermissions('incoterms')),
    moduleRoute('/uoms', 'uoms', 'UOMs', adminRoles, resourcePermissions('uoms')),
    moduleRoute('/currencies', 'currencies', 'Currencies', adminRoles, resourcePermissions('currencies')),
    moduleRoute('/manufacturers', 'manufacturers', 'Manufacturers', adminRoles, resourcePermissions('manufacturers')),
    moduleRoute('/suppliers', 'suppliers', 'Suppliers', adminRoles, resourcePermissions('suppliers')),
    moduleRoute('/users-roles', 'users-roles', 'Users & Roles', adminRoles, ['manage-users', 'manage-roles']),
    {
        path: '/admin/trace/quotations',
        name: 'admin-trace-quotations',
        component: AdminQuotationTraceView,
        meta: {
            requiresAuth: true,
            roles: adminRoles,
            title: 'Quotation Trace',
        },
    },
    {
        path: '/admin/trace/quotations/:id',
        name: 'admin-trace-quotation-detail',
        component: AdminQuotationTraceView,
        meta: {
            requiresAuth: true,
            roles: adminRoles,
            title: 'Quotation Timeline',
        },
    },
    {
        path: '/admin/trace/items',
        name: 'admin-trace-items',
        component: AdminItemTraceView,
        meta: {
            requiresAuth: true,
            roles: adminRoles,
            title: 'Item Trace',
        },
    },
    {
        path: '/quotations',
        name: 'quotations',
        component: QuotationListView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            title: 'Quotations',
        },
    },
    {
        path: '/quotations/create',
        name: 'quotations-create',
        component: QuotationCreateView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            title: 'Create Quotation',
        },
    },
    {
        path: '/quotations/:id',
        name: 'quotations-detail',
        component: QuotationDetailView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            title: 'Quotation Details',
        },
    },
    {
        path: '/quotations/:id/edit',
        name: 'quotations-edit',
        component: QuotationCreateView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            title: 'Edit Quotation',
        },
    },
    {
        path: '/supplier-po',
        redirect: '/supplier-pos',
    },
    {
        path: '/supplier-po/create',
        redirect: '/supplier-pos/create',
    },
    {
        path: '/supplier-pos',
        name: 'supplier-pos',
        component: SupplierPoListView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            permissions: ['create-supplier-pos'],
            title: 'Supplier POs',
        },
    },
    {
        path: '/supplier-pos/create',
        name: 'supplier-pos-create',
        component: SupplierPoCreateView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            permissions: ['create-supplier-pos'],
            title: 'Create Supplier PO',
        },
    },
    {
        path: '/supplier-pos/:id/edit',
        name: 'supplier-pos-edit',
        component: SupplierPoCreateView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'salesperson'],
            permissions: ['create-supplier-pos'],
            title: 'Edit Supplier PO',
        },
    },
    {
        path: '/follow-up',
        name: 'follow-up',
        component: FollowUpDashboardView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'follow-up'],
            title: 'Follow-Up',
        },
    },
    {
        path: '/follow-up/groups',
        name: 'follow-up-groups',
        component: FollowUpGroupView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'follow-up'],
            title: 'Follow-Up Groups',
        },
    },
    {
        path: '/follow-up/quotations',
        name: 'follow-up-quotations',
        component: FollowUpQuotationView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'follow-up'],
            title: 'Follow-Up Quotations',
        },
    },
    {
        path: '/follow-up/quotations/:id',
        name: 'follow-up-quotation-detail',
        component: FollowUpQuotationView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'follow-up'],
            title: 'Quotation Follow-Up',
        },
    },
    {
        path: '/follow-up/visualization',
        name: 'follow-up-visualization',
        component: FollowUpVisualizationView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'follow-up'],
            title: 'Follow-Up Visualization',
        },
    },
    {
        path: '/follow-up/:id',
        name: 'follow-up-detail',
        component: FollowUpDetailView,
        meta: {
            requiresAuth: true,
            roles: ['admin', 'follow-up'],
            title: 'Follow-Up Detail',
        },
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    if (!isAuthReady.value) {
        await loadSession();
    }

    if (to.meta.guest && currentUser.value) {
        return { path: homePathForUser(currentUser.value) };
    }

    if (to.meta.requiresAuth && !currentUser.value) {
        return { path: '/login' };
    }

    if (currentUser.value && !canAccess(to.meta.roles, to.meta.permissions)) {
        return { path: homePathForUser(currentUser.value) };
    }

    return true;
});
