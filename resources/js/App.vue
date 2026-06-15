<script setup lang="ts">
import { useRouter } from 'vue-router';
import {
    authError,
    changePassword,
    currentUser,
    homePathForUser,
    isAuthenticating,
    isAuthReady,
    login,
    logout,
    welcomeName,
} from './auth';
import AppShell from './components/AppShell.vue';
import LoginView from './components/LoginView.vue';

const router = useRouter();
const logoSrc = '/images/isc-logo.jpg';

async function submitLogin(credentials: { email: string; password: string; remember: boolean }): Promise<void> {
    await login(credentials);

    if (currentUser.value) {
        await router.replace(homePathForUser(currentUser.value));
    }
}

async function submitLogout(): Promise<void> {
    await logout();
    await router.replace('/login');
}
</script>

<template>
    <div v-if="!isAuthReady" class="app-loader" aria-label="Loading application">
        <div class="preloader-card" role="status" aria-live="polite">
            <span class="preloader-orbit" aria-hidden="true"></span>
            <img class="preloader-logo" :src="logoSrc" alt="ISC" />
            <p>Preparing quotation system</p>
            <span class="preloader-progress" aria-hidden="true"></span>
        </div>
    </div>
    <LoginView
        v-else-if="!currentUser"
        :error="authError"
        :is-submitting="isAuthenticating"
        :welcome-name="welcomeName"
        @login="submitLogin"
    />
    <AppShell v-else :user="currentUser" :change-password="changePassword" @logout="submitLogout" />
</template>
