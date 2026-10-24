<template>
    <div class="flex h-full flex-col">
        <header class="flex h-11 shrink-0 items-center gap-4 border-b border-line bg-panel px-4">
            <router-link to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="grid h-6 w-6 place-items-center rounded bg-accent text-[11px] font-bold text-black">BB</span>
                <span>BinBench</span>
            </router-link>
            <span class="text-xs text-muted">ECU identification · map finder · editor · checksums</span>
        </header>
        <main class="min-h-0 flex-1">
            <router-view :key="$route.fullPath" />
        </main>
        <Toasts />
    </div>
</template>

<script setup>
import Toasts from './components/Toasts.vue';
</script>
