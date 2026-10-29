<template>
    <div class="grid h-full place-items-center overflow-y-auto px-4 py-8">
        <form class="w-full max-w-sm rounded-xl border border-line bg-panel p-6 text-xs" @submit.prevent="submit">
            <div class="mb-1 text-base font-semibold">{{ mode === 'login' ? 'Sign in' : 'Create an account' }}</div>
            <p class="mb-4 text-[11px] text-muted">Your files, versions and imported definitions are only visible to you.</p>

            <label v-if="mode === 'register'" class="mb-3 block">Name
                <input v-model="form.name" required maxlength="100" autocomplete="name" class="input mt-1 w-full">
            </label>
            <label class="mb-3 block">Email
                <input v-model="form.email" type="email" required autocomplete="email" class="input mt-1 w-full">
            </label>
            <label class="mb-3 block">Password
                <input v-model="form.password" type="password" required :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" class="input mt-1 w-full">
            </label>
            <template v-if="mode === 'register'">
                <label class="mb-3 block">Repeat password
                    <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="input mt-1 w-full">
                </label>
                <label v-if="auth.inviteRequired" class="mb-3 block">Invite code
                    <input v-model="form.invite_code" required autocomplete="off" class="input mt-1 w-full">
                </label>
            </template>
            <label v-else class="mb-3 flex items-center gap-2">
                <input v-model="form.remember" type="checkbox"> Keep me signed in
            </label>

            <div v-if="error" class="mb-3 rounded border border-bad/40 bg-bad/10 p-2 text-bad">{{ error }}</div>

            <button type="submit" class="btn btn-primary w-full justify-center" :disabled="busy">
                {{ busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account' }}
            </button>

            <p v-if="auth.registration" class="mt-4 text-center text-[11px] text-muted">
                <template v-if="mode === 'login'">No account? <button type="button" class="text-accent hover:underline" @click="switchMode('register')">Create one</button></template>
                <template v-else>Already registered? <button type="button" class="text-accent hover:underline" @click="switchMode('login')">Sign in</button></template>
            </p>
        </form>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const mode = ref('login');
const busy = ref(false);
const error = ref(null);
const form = reactive({ name: '', email: '', password: '', password_confirmation: '', invite_code: '', remember: true });

function switchMode(next) {
    mode.value = next;
    error.value = null;
}

async function submit() {
    busy.value = true;
    error.value = null;
    try {
        if (mode.value === 'login') {
            await auth.login({ email: form.email, password: form.password, remember: form.remember });
        } else {
            await auth.register({ ...form });
        }
        const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';
        router.replace(target);
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
</script>
