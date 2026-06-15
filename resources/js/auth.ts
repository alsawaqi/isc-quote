import { ref } from 'vue';
import type { RoleSlug, User } from './types';

const tokenKey = 'isc_auth_token';
const refreshTokenKey = 'isc_refresh_token';

type AuthResponse = {
    user: User;
    token: string;
    refresh_token: string;
    token_type: 'Bearer';
    expires_in: number;
    refresh_expires_in: number;
};

export const currentUser = ref<User | null>(null);
export const isAuthReady = ref(false);
export const isAuthenticating = ref(false);
export const authError = ref('');
export const welcomeName = ref('');

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function storedToken(): string | null {
    return window.localStorage.getItem(tokenKey) ?? window.sessionStorage.getItem(tokenKey);
}

function storedRefreshToken(): string | null {
    return window.localStorage.getItem(refreshTokenKey) ?? window.sessionStorage.getItem(refreshTokenKey);
}

function tokenIsRemembered(): boolean {
    return window.localStorage.getItem(refreshTokenKey) !== null || window.localStorage.getItem(tokenKey) !== null;
}

function storeTokens(token: string, refreshToken: string, remember: boolean): void {
    if (remember) {
        window.localStorage.setItem(tokenKey, token);
        window.localStorage.setItem(refreshTokenKey, refreshToken);
        window.sessionStorage.removeItem(tokenKey);
        window.sessionStorage.removeItem(refreshTokenKey);
        return;
    }

    window.sessionStorage.setItem(tokenKey, token);
    window.sessionStorage.setItem(refreshTokenKey, refreshToken);
    window.localStorage.removeItem(tokenKey);
    window.localStorage.removeItem(refreshTokenKey);
}

function clearStoredToken(): void {
    window.localStorage.removeItem(tokenKey);
    window.localStorage.removeItem(refreshTokenKey);
    window.sessionStorage.removeItem(tokenKey);
    window.sessionStorage.removeItem(refreshTokenKey);
}

export async function requestJson<T>(url: string, options: RequestInit = {}, retryOnUnauthorized = true): Promise<T> {
    const token = storedToken();
    const baseHeaders: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
    };

    if (token) {
        baseHeaders.Authorization = `Bearer ${token}`;
    }

    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            ...baseHeaders,
            ...(options.headers ?? {}),
        },
        ...options,
    });

    const payload = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (!response.ok) {
        if (
            response.status === 401 &&
            retryOnUnauthorized &&
            url !== '/api/token/refresh' &&
            url !== '/api/logout' &&
            (await refreshSession())
        ) {
            return requestJson<T>(url, options, false);
        }

        const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
        const message = firstFieldError ?? payload?.message ?? 'Unable to complete the request.';
        throw new Error(message);
    }

    return payload as T;
}

export async function requestFormData<T>(url: string, formData: FormData, retryOnUnauthorized = true): Promise<T> {
    const token = storedToken();
    const response = await fetch(url, {
        body: formData,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        method: 'POST',
    });

    const payload = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[]>;
    } | null;

    if (!response.ok) {
        if (response.status === 401 && retryOnUnauthorized && (await refreshSession())) {
            return requestFormData<T>(url, formData, false);
        }

        const firstFieldError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
        const message = firstFieldError ?? payload?.message ?? 'Unable to complete the request.';
        throw new Error(message);
    }

    return payload as T;
}

export async function downloadProtectedFile(url: string, fallbackFilename: string, retryOnUnauthorized = true): Promise<void> {
    const token = storedToken();
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/octet-stream',
            'X-CSRF-TOKEN': csrfToken(),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });

    if (!response.ok) {
        if (response.status === 401 && retryOnUnauthorized && (await refreshSession())) {
            return downloadProtectedFile(url, fallbackFilename, false);
        }

        const payload = (await response.json().catch(() => null)) as { message?: string } | null;
        throw new Error(payload?.message ?? 'Unable to download the file.');
    }

    const blob = await response.blob();
    const contentDisposition = response.headers.get('content-disposition') ?? '';
    const match = contentDisposition.match(/filename="?([^"]+)"?/i);
    const filename = match?.[1] ?? fallbackFilename;
    const objectUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(objectUrl);
}

export async function loadSession(): Promise<void> {
    const token = storedToken();
    const refreshToken = storedRefreshToken();

    if (!token && !refreshToken) {
        currentUser.value = null;
        isAuthReady.value = true;
        return;
    }

    if (!token && refreshToken) {
        await refreshSession();
        isAuthReady.value = true;
        return;
    }

    try {
        const payload = await requestJson<{ user: User | null }>('/api/me');
        currentUser.value = payload.user;
    } catch {
        if (!(await refreshSession())) {
            currentUser.value = null;
            clearStoredToken();
        }
    } finally {
        isAuthReady.value = true;
    }
}

export async function login(credentials: { email: string; password: string; remember: boolean }): Promise<void> {
    authError.value = '';
    welcomeName.value = '';
    isAuthenticating.value = true;

    try {
        const payload = await requestJson<AuthResponse>('/api/login', {
            method: 'POST',
            body: JSON.stringify(credentials),
        });

        storeTokens(payload.token, payload.refresh_token, credentials.remember);
        welcomeName.value = payload.user.name;
        await new Promise((resolve) => window.setTimeout(resolve, 900));
        currentUser.value = payload.user;
        isAuthReady.value = true;
    } catch (error) {
        authError.value = error instanceof Error ? error.message : 'Login failed.';
    } finally {
        isAuthenticating.value = false;
        welcomeName.value = '';
    }
}

export async function refreshSession(): Promise<boolean> {
    const refreshToken = storedRefreshToken();

    if (!refreshToken) {
        return false;
    }

    try {
        const payload = await requestJson<AuthResponse>(
            '/api/token/refresh',
            {
                method: 'POST',
                body: JSON.stringify({ refresh_token: refreshToken }),
            },
            false,
        );

        storeTokens(payload.token, payload.refresh_token, tokenIsRemembered());
        currentUser.value = payload.user;

        return true;
    } catch {
        currentUser.value = null;
        clearStoredToken();

        return false;
    }
}

export async function logout(): Promise<void> {
    const refreshToken = storedRefreshToken();

    try {
        if (storedToken() || refreshToken) {
            await requestJson<{ message: string }>(
                '/api/logout',
                {
                    method: 'POST',
                    body: JSON.stringify({ refresh_token: refreshToken }),
                },
                false,
            );
        }
    } finally {
        currentUser.value = null;
        clearStoredToken();
        isAuthReady.value = true;
    }
}

export async function changePassword(payload: {
    current_password: string;
    password: string;
    password_confirmation: string;
}): Promise<void> {
    await requestJson<{ message: string }>('/api/password', {
        method: 'PUT',
        body: JSON.stringify(payload),
    });
}

export function hasRequiredRole(requiredRoles: RoleSlug[] | undefined): boolean {
    if (!requiredRoles?.length) {
        return true;
    }

    return requiredRoles.some((role) => currentUser.value?.roles.some((userRole) => userRole.slug === role));
}

export function hasPermission(permissionSlug: string, user: User | null = currentUser.value): boolean {
    if (!user) {
        return false;
    }

    if (user.roles.some((role) => role.slug === 'admin')) {
        return true;
    }

    return user.permissions.some((permission) => permission.slug === permissionSlug);
}

export function hasAnyPermission(permissionSlugs: string[] | undefined, user: User | null = currentUser.value): boolean {
    if (!permissionSlugs?.length) {
        return false;
    }

    return permissionSlugs.some((permission) => hasPermission(permission, user));
}

export function canAccess(requiredRoles: RoleSlug[] | undefined, permissionSlugs?: string[]): boolean {
    return hasRequiredRole(requiredRoles) || hasAnyPermission(permissionSlugs);
}

export function homePathForUser(user: User | null = currentUser.value): string {
    const slugs = user?.roles.map((role) => role.slug) ?? [];

    if (slugs.includes('admin')) {
        return '/dashboard';
    }

    if (slugs.includes('salesperson')) {
        return '/quotations';
    }

    if (slugs.includes('follow-up')) {
        return '/follow-up';
    }

    if (hasAnyPermission(['view-countries', 'create-countries', 'update-countries', 'delete-countries'], user)) {
        return '/countries';
    }

    return '/login';
}
