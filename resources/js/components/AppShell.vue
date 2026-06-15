<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import {
    BadgeCheck,
    Bell,
    Building2,
    ChevronDown,
    CheckCircle2,
    Clock3,
    ContactRound,
    Factory,
    FileText,
    Globe2,
    KeyRound,
    LayoutDashboard,
    LogOut,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    Ship,
    Truck,
    UsersRound,
    Warehouse,
    X,
} from 'lucide-vue-next';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { canAccess, requestJson } from '../auth';
import { navigationItems } from '../data/dashboard';
import type { RoleSlug, User } from '../types';

interface NotificationItem {
    id: number;
    type: 'overdue' | 'due_today';
    title: string;
    body: string;
    stage_label: string;
    status_label: string;
    supplier_po_reference: string | null;
    buyer_po_number: string | null;
    buyer_company_name: string | null;
    supplier_company_name: string | null;
    due_at: string | null;
    action_url: string;
    severity: 'danger' | 'warning';
}

const props = defineProps<{
    user: User;
    changePassword: (payload: {
        current_password: string;
        password: string;
        password_confirmation: string;
    }) => Promise<void>;
}>();

const emit = defineEmits<{
    logout: [];
}>();

const isMobileSidebarOpen = ref(false);
const isSidebarCollapsed = ref(false);
const isProfileMenuOpen = ref(false);
const isNotificationMenuOpen = ref(false);
const isPasswordModalOpen = ref(false);
const isChangingPassword = ref(false);
const isLoadingNotifications = ref(false);
const passwordError = ref('');
const passwordSuccess = ref('');
const notificationError = ref('');
const notifications = ref<NotificationItem[]>([]);
const route = useRoute();
const router = useRouter();
const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const logoSrc = '/images/isc-logo.jpg';

const iconMap = {
    BadgeCheck,
    Building2,
    Clock3,
    ContactRound,
    Factory,
    FileText,
    Globe2,
    LayoutDashboard,
    Search,
    Ship,
    Truck,
    UsersRound,
    Warehouse,
};

const primaryRole = computed<RoleSlug>(() => {
    const slug = props.user.roles[0]?.slug;

    if (slug === 'salesperson' || slug === 'follow-up') {
        return slug;
    }

    return 'admin';
});

const displayRole = computed(() => {
    if (primaryRole.value === 'salesperson') {
        return 'Sales Executive';
    }

    if (primaryRole.value === 'follow-up') {
        return 'Follow-Up Specialist';
    }

    return 'Operations Manager';
});

const profileInitials = computed(() => {
    return props.user.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
});

const visibleNav = computed(() => {
    return navigationItems.filter((item) => canAccess(item.roles, item.permissions));
});

const notificationCount = computed(() => notifications.value.length);

const canChangePassword = computed(() => {
    return (
        passwordForm.current_password.length > 0 &&
        passwordForm.password.length >= 8 &&
        passwordForm.password === passwordForm.password_confirmation &&
        !isChangingPassword.value
    );
});

const desktopSidebarToggleLabel = computed(() => {
    return isSidebarCollapsed.value ? 'Expand sidebar' : 'Collapse sidebar';
});

function navIcon(icon: string) {
    return iconMap[icon as keyof typeof iconMap] ?? LayoutDashboard;
}

function chooseNav(): void {
    isMobileSidebarOpen.value = false;
    isProfileMenuOpen.value = false;
    isNotificationMenuOpen.value = false;
}

function isMobileViewport(): boolean {
    return window.matchMedia('(max-width: 900px)').matches;
}

function toggleNavigation(): void {
    isProfileMenuOpen.value = false;
    isNotificationMenuOpen.value = false;

    if (isMobileViewport()) {
        isMobileSidebarOpen.value = true;
        return;
    }

    isSidebarCollapsed.value = !isSidebarCollapsed.value;
}

function toggleProfileMenu(): void {
    isNotificationMenuOpen.value = false;
    isProfileMenuOpen.value = !isProfileMenuOpen.value;
}

async function loadNotifications(): Promise<void> {
    isLoadingNotifications.value = true;
    notificationError.value = '';

    try {
        const payload = await requestJson<{ unread_count: number; data: NotificationItem[] }>('/api/notifications');
        notifications.value = payload.data;
    } catch (error) {
        notificationError.value = error instanceof Error ? error.message : 'Unable to load notifications.';
    } finally {
        isLoadingNotifications.value = false;
    }
}

async function toggleNotifications(): Promise<void> {
    isProfileMenuOpen.value = false;
    isNotificationMenuOpen.value = !isNotificationMenuOpen.value;

    if (isNotificationMenuOpen.value) {
        await loadNotifications();
    }
}

function formatNotificationDate(date: string | null): string {
    if (!date) {
        return 'No reminder date';
    }

    return new Date(date.replace(' ', 'T')).toLocaleDateString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

async function openNotification(notification: NotificationItem): Promise<void> {
    isNotificationMenuOpen.value = false;
    await router.push(notification.action_url);
}

function openPasswordModal(): void {
    isProfileMenuOpen.value = false;
    isNotificationMenuOpen.value = false;
    passwordError.value = '';
    passwordSuccess.value = '';
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';
    isPasswordModalOpen.value = true;
}

function closePasswordModal(): void {
    if (isChangingPassword.value) {
        return;
    }

    isPasswordModalOpen.value = false;
}

async function submitPasswordChange(): Promise<void> {
    if (!canChangePassword.value) {
        return;
    }

    passwordError.value = '';
    passwordSuccess.value = '';
    isChangingPassword.value = true;

    try {
        await props.changePassword({ ...passwordForm });
        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        passwordSuccess.value = 'Password updated.';
    } catch (error) {
        passwordError.value = error instanceof Error ? error.message : 'Unable to update password.';
    } finally {
        isChangingPassword.value = false;
    }
}

onMounted(loadNotifications);
</script>

<template>
    <div class="app-shell" :class="{ 'sidebar-collapsed': isSidebarCollapsed }">
        <aside class="sidebar" :class="{ open: isMobileSidebarOpen }" aria-label="Primary navigation">
            <div class="sidebar-head">
                <img class="brand-logo small" :src="logoSrc" alt="ISC" />
                <div>
                    <strong>ISC</strong>
                    <span>Quotation<br />System</span>
                </div>
                <button class="icon-button mobile-only" type="button" aria-label="Close menu" @click="isMobileSidebarOpen = false">
                    <X :size="18" aria-hidden="true" />
                </button>
            </div>

            <nav class="nav-list">
                <RouterLink
                    v-for="item in visibleNav"
                    :key="item.label"
                    :to="item.path"
                    class="nav-item"
                    :class="{ active: route.path === item.path, sales: item.accent === 'sales', follow: item.accent === 'follow' }"
                    @click="chooseNav"
                >
                    <component :is="navIcon(item.icon)" :size="18" aria-hidden="true" />
                    <span>{{ item.label }}</span>
                </RouterLink>
            </nav>

            <div class="sidebar-foot">
                <span>(c) 2025 ISC Quotation System</span>
                <span>v1.0.0</span>
                <button class="collapse-button" type="button" :aria-label="desktopSidebarToggleLabel" @click="toggleNavigation">
                    <PanelLeftOpen v-if="isSidebarCollapsed" :size="17" aria-hidden="true" />
                    <PanelLeftClose v-else :size="17" aria-hidden="true" />
                </button>
            </div>
        </aside>

        <div v-if="isMobileSidebarOpen" class="sidebar-backdrop" @click="isMobileSidebarOpen = false"></div>

        <main class="workspace">
            <header class="topbar">
                <button class="top-icon-button" type="button" aria-label="Toggle sidebar" @click="toggleNavigation">
                    <Menu :size="20" aria-hidden="true" />
                </button>

                <div class="search-box">
                    <Search :size="18" aria-hidden="true" />
                    <input type="search" placeholder="Search jobs, companies, suppliers..." />
                    <kbd>Ctrl + K</kbd>
                </div>

                <div class="topbar-user">
                    <div class="notification-area">
                        <button
                            class="notification-button"
                            type="button"
                            aria-haspopup="menu"
                            :aria-expanded="isNotificationMenuOpen"
                            aria-label="Notifications"
                            @click="toggleNotifications"
                        >
                            <Bell :size="19" aria-hidden="true" />
                            <span v-if="notificationCount > 0">{{ notificationCount > 9 ? '9+' : notificationCount }}</span>
                        </button>

                        <div v-if="isNotificationMenuOpen" class="notification-dropdown" role="menu">
                            <header>
                                <div>
                                    <strong>Notifications</strong>
                                    <span>{{ notificationCount }} active reminder{{ notificationCount === 1 ? '' : 's' }}</span>
                                </div>
                                <button class="table-link-button" type="button" :disabled="isLoadingNotifications" @click="loadNotifications">
                                    Refresh
                                </button>
                            </header>

                            <div v-if="notificationError" class="notification-empty error">{{ notificationError }}</div>
                            <div v-else-if="isLoadingNotifications" class="notification-empty">Loading notifications...</div>
                            <div v-else-if="notifications.length === 0" class="notification-empty">No due follow-ups right now.</div>
                            <div v-else class="notification-list">
                                <button
                                    v-for="notification in notifications"
                                    :key="notification.id"
                                    class="notification-item"
                                    :class="notification.severity"
                                    type="button"
                                    role="menuitem"
                                    @click="openNotification(notification)"
                                >
                                    <span class="notification-dot"></span>
                                    <span>
                                        <strong>{{ notification.title }}</strong>
                                        <small>{{ notification.stage_label }} - {{ formatNotificationDate(notification.due_at) }}</small>
                                        <em>{{ notification.body }}</em>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="profile-area">
                        <button
                            class="profile-menu"
                            type="button"
                            aria-haspopup="menu"
                            :aria-expanded="isProfileMenuOpen"
                            @click="toggleProfileMenu"
                        >
                            <span class="profile-avatar">{{ profileInitials }}</span>
                            <div>
                                <strong>{{ user.name }}</strong>
                                <span>{{ displayRole }}</span>
                            </div>
                            <ChevronDown :size="16" aria-hidden="true" />
                        </button>

                        <div v-if="isProfileMenuOpen" class="profile-dropdown" role="menu">
                            <button type="button" role="menuitem" @click="openPasswordModal">
                                <KeyRound :size="17" aria-hidden="true" />
                                Change Password
                            </button>
                            <button type="button" role="menuitem" @click="emit('logout')">
                                <LogOut :size="17" aria-hidden="true" />
                                Log Out
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <RouterView v-slot="{ Component, route: activeRoute }">
                <component
                    :is="Component"
                    :role="primaryRole"
                    :active-section="String(activeRoute.meta.title ?? 'Dashboard')"
                    :user-name="user.name"
                />
            </RouterView>
        </main>

        <div v-if="isPasswordModalOpen" class="modal-backdrop" role="presentation" @click.self="closePasswordModal">
            <section class="password-modal" role="dialog" aria-modal="true" aria-labelledby="password-modal-title">
                <header>
                    <div>
                        <span class="modal-icon">
                            <KeyRound :size="20" aria-hidden="true" />
                        </span>
                        <div>
                            <h2 id="password-modal-title">Change Password</h2>
                            <p>{{ user.name }} - {{ displayRole }}</p>
                        </div>
                    </div>
                    <button class="top-icon-button" type="button" aria-label="Close password dialog" @click="closePasswordModal">
                        <X :size="18" aria-hidden="true" />
                    </button>
                </header>

                <form class="password-form" @submit.prevent="submitPasswordChange">
                    <label>
                        <span>Current Password</span>
                        <input v-model="passwordForm.current_password" type="password" autocomplete="current-password" required />
                    </label>
                    <label>
                        <span>New Password</span>
                        <input v-model="passwordForm.password" type="password" autocomplete="new-password" minlength="8" required />
                    </label>
                    <label>
                        <span>Confirm New Password</span>
                        <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" minlength="8" required />
                    </label>

                    <p v-if="passwordError" class="form-error">{{ passwordError }}</p>
                    <p v-if="passwordSuccess" class="form-success">
                        <CheckCircle2 :size="16" aria-hidden="true" />
                        {{ passwordSuccess }}
                    </p>

                    <footer>
                        <button class="secondary-action" type="button" :disabled="isChangingPassword" @click="closePasswordModal">
                            Cancel
                        </button>
                        <button class="primary-action compact-action" type="submit" :disabled="!canChangePassword">
                            <span v-if="isChangingPassword" class="button-spinner" aria-hidden="true"></span>
                            <KeyRound v-else :size="17" aria-hidden="true" />
                            {{ isChangingPassword ? 'Updating...' : 'Update Password' }}
                        </button>
                    </footer>
                </form>
            </section>
        </div>
    </div>
</template>
