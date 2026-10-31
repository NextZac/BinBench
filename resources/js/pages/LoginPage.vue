<template>
    <div class="h-full overflow-y-auto scroll-thin">
        <div class="mx-auto grid max-w-4xl gap-10 px-6 py-12 lg:grid-cols-[minmax(0,22rem)_1fr] lg:py-20">
            <form class="text-[12px]" @submit.prevent="submit">
                <h1 class="text-[20px] font-semibold tracking-[-0.01em]">{{ mode === 'login' ? 'Sign in' : 'Create an account' }}</h1>
                <p class="mt-1 mb-6 text-muted">Files, versions and imported definitions are private to your account.</p>

                <label v-if="mode === 'register'" class="mb-3 block"><span class="label">Name</span>
                    <input v-model="form.name" required maxlength="100" autocomplete="name" class="input mt-0.5 h-8 w-full">
                </label>
                <label class="mb-3 block"><span class="label">Email</span>
                    <input v-model="form.email" type="email" required autocomplete="email" class="input mt-0.5 h-8 w-full">
                </label>
                <label class="mb-3 block"><span class="label">Password</span>
                    <input v-model="form.password" type="password" required :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" class="input mt-0.5 h-8 w-full">
                </label>
                <template v-if="mode === 'register'">
                    <label class="mb-3 block"><span class="label">Repeat password</span>
                        <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="input mt-0.5 h-8 w-full">
                    </label>
                    <label v-if="auth.inviteRequired" class="mb-3 block"><span class="label">Invite code</span>
                        <input v-model="form.invite_code" required autocomplete="off" class="input mt-0.5 h-8 w-full font-mono">
                    </label>
                </template>
                <label v-else class="mb-4 flex cursor-pointer items-center gap-2 text-muted">
                    <input v-model="form.remember" type="checkbox" class="accent-[var(--bb-accent-fill)]"> Keep me signed in
                </label>

                <p v-if="error" class="note mb-3 border-bad text-bad" role="alert">{{ error }}</p>

                <button type="submit" class="btn btn-primary h-8 w-full justify-center" :disabled="busy">
                    {{ busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account' }}
                </button>

                <p v-if="auth.registration" class="mt-4 text-muted">
                    <template v-if="mode === 'login'">No account yet? <button type="button" class="cursor-pointer text-accent underline-offset-2 hover:underline" @click="switchMode('register')">Create one</button></template>
                    <template v-else>Already registered? <button type="button" class="cursor-pointer text-accent underline-offset-2 hover:underline" @click="switchMode('login')">Sign in</button></template>
                </p>
            </form>

            <section class="hidden border-l border-line pl-10 text-[12px] lg:block" aria-label="What BinBench reads">
                <div class="label">Reads</div>
                <table class="mt-2 w-full">
                    <tbody>
                        <tr v-for="row in support" :key="row[0]" class="border-b border-line/70 align-top">
                            <td class="py-1.5 pr-4 font-mono whitespace-nowrap">{{ row[0] }}</td>
                            <td class="py-1.5 text-muted">{{ row[1] }}</td>
                            <td class="py-1.5 pl-3 text-right font-mono whitespace-nowrap" :class="row[2] === 'fix' ? 'text-ok' : 'text-muted'">{{ row[2] === 'fix' ? 'checksum fix' : 'checksum check' }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-4 max-w-sm text-muted">
                    Maps are found from the ECU code where possible, otherwise from the table structure.
                    Imported XDFs are reused for every file with the same software number.
                </p>
            </section>
        </div>
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
const support = [
    ['ME7.x', 'VAG 1.8T, V6 (ME7.1, 7.1.1, 7.5)', 'fix'],
    ['ME9.0', 'Ford Focus RS mk2, Volvo 2.5T', 'fix'],
    ['EDC15', 'VAG TDI, BMW M47/M57 DDE4', 'fix'],
    ['EDC16', 'diesel, PowerPC', 'check'],
    ['EDC17', 'diesel, TriCore', 'check'],
];
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
