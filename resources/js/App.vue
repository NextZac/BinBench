<template>
    <div class="flex h-full flex-col">
        <header class="flex h-10 shrink-0 items-center gap-5 border-b border-line bg-panel px-3">
            <router-link to="/" class="flex items-center gap-2 text-[14px] font-semibold tracking-[-0.01em] text-ink">
                <BrandMark />
                BinBench
            </router-link>
            <nav v-if="auth.user" class="flex h-full items-stretch">
                <router-link to="/" class="tab" :aria-selected="$route.name === 'files' || $route.name === 'editor'">Files</router-link>
            </nav>
            <div class="ml-auto flex items-center gap-1">
                <button class="btn btn-quiet btn-icon" :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
                    :title="theme === 'dark' ? 'Light theme' : 'Dark theme'" @click="toggleTheme">
                    <Icon :name="theme === 'dark' ? 'sun' : 'moon'" />
                </button>
                <template v-if="auth.user">
                    <span class="mx-2 h-4 w-px bg-line" />
                    <span class="text-[12px] text-muted" :title="auth.user.email">{{ auth.user.name }}</span>
                    <button class="btn btn-quiet btn-sm" @click="logout">Sign out</button>
                </template>
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
import BrandMark from './components/BrandMark.vue';
import Icon from './components/Icon.vue';
import Toasts from './components/Toasts.vue';
import { theme, toggleTheme } from './lib/theme';
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
