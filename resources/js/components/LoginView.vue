<script setup lang="ts">
import { computed, reactive } from 'vue';
import { LockKeyhole, Mail, ShieldCheck } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
    error?: string;
    isSubmitting?: boolean;
    welcomeName?: string;
}>(), {
    error: '',
    isSubmitting: false,
    welcomeName: '',
});

const emit = defineEmits<{
    login: [{ email: string; password: string; remember: boolean }];
}>();

const form = reactive({
    email: '',
    password: '',
    remember: false,
});
const logoSrc = '/images/isc-logo.jpg';

const canSubmit = computed(() => form.email.trim().length > 0 && form.password.length > 0 && !props.isSubmitting);

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    emit('login', { ...form });
}
</script>

<template>
    <main class="login-page">
        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-brand">
                <img class="brand-logo" :src="logoSrc" alt="ISC" />
                <div>
                    <p>Quotation System</p>
                    <h1 id="login-title">Sign in</h1>
                </div>
            </div>

            <form class="login-form" autocomplete="off" @submit.prevent="submit">
                <label>
                    <span>Email</span>
                    <div class="input-wrap">
                        <Mail :size="18" aria-hidden="true" />
                        <input v-model="form.email" type="email" autocomplete="off" required />
                    </div>
                </label>

                <label>
                    <span>Password</span>
                    <div class="input-wrap">
                        <LockKeyhole :size="18" aria-hidden="true" />
                        <input v-model="form.password" type="password" autocomplete="off" required />
                    </div>
                </label>

                <div class="login-options">
                    <label class="check-row">
                        <input v-model="form.remember" type="checkbox" />
                        <span>Keep me signed in</span>
                    </label>
                </div>

                <p v-if="error" class="form-error">{{ error }}</p>

                <button class="primary-action" type="submit" :disabled="!canSubmit || isSubmitting">
                    <span v-if="isSubmitting" class="button-spinner" aria-hidden="true"></span>
                    <ShieldCheck v-else :size="18" aria-hidden="true" />
                    {{ isSubmitting ? 'Signing in...' : 'Sign In' }}
                </button>
            </form>

            <p class="login-security-note">Authorized ISC users only. Access is controlled by role permissions.</p>

            <transition name="welcome-pop">
                <div v-if="welcomeName" class="welcome-overlay" role="status" aria-live="polite">
                    <span class="welcome-mark">
                        <ShieldCheck :size="30" aria-hidden="true" />
                    </span>
                    <p>Welcome back</p>
                    <strong>{{ welcomeName }}</strong>
                </div>
            </transition>
        </section>
    </main>
</template>
