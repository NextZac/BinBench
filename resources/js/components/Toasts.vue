<template>
    <div class="pointer-events-none fixed bottom-9 left-3 z-50 flex flex-col gap-1.5" role="status" aria-live="polite">
        <div v-for="t in toasts" :key="t.id"
            class="pointer-events-auto flex max-w-md items-start gap-2 border border-line bg-panel py-1.5 pr-3 pl-2 text-[12px] shadow-[var(--bb-shadow)]"
            :class="t.type === 'error' ? 'border-l-2 border-l-bad' : 'border-l-2 border-l-ok'">
            <Icon :name="t.type === 'error' ? 'alert' : 'check'" :class="t.type === 'error' ? 'mt-0.5 text-bad' : 'mt-0.5 text-ok'" />
            <span>{{ t.message }}</span>
        </div>
    </div>
</template>

<script setup>
import { toasts } from '../lib/toast';
import Icon from './Icon.vue';
</script>
