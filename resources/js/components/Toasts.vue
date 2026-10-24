<template>
    <div class="pointer-events-none fixed right-4 bottom-4 z-50 flex flex-col gap-2">
        <div v-for="t in toasts" :key="t.id"
            class="pointer-events-auto max-w-sm rounded-md border px-3 py-2 text-xs shadow-lg"
            :class="t.type === 'error' ? 'border-bad/60 bg-[#2a1416] text-red-200' : 'border-ok/50 bg-[#10241a] text-green-200'">
            {{ t.message }}
        </div>
    </div>
</template>

<script setup>
import { toasts } from '../lib/toast';
</script>
