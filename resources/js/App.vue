<template>
    <div class="flex h-full flex-col">
        <header class="flex h-11 shrink-0 items-center gap-4 border-b border-line bg-panel px-4">
            <router-link to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="grid h-6 w-6 place-items-center rounded bg-accent text-[11px] font-bold text-black">BB</span>
                <span>BinBench</span>
            </router-link>
            <span class="hidden text-xs text-muted sm:inline">ECU identification · map finder · editor · checksums</span>
            <div v-if="auth.user" class="ml-auto flex items-center gap-2 text-xs">
                <span class="text-muted" :title="auth.user.email">{{ auth.user.name }}</span>
                <button class="btn btn-sm" @click="logout">Sign out</button>
            </div>
        </header>
        <main class="min-h-0 flex-1">
            <router-view :key="$route.fullPath" />
        </main>
        <Toasts />
    </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import Toasts from './components/Toasts.vue';
import { toastError } from './lib/toast';
import { useAuth } from './stores/auth';

const auth = useAuth();
const router = useRouter();

async function logout() {
    try {
        await auth.logout();
        router.replace({ name: 'login' });
    } catch (e) {
        toastError(e);
    }
}
</script>
