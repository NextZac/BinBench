<template>
    <!-- a map surface seen from the side: the thing BinBench spends its day editing -->
    <svg :width="size" :height="size" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
        <path d="M2 15.5 6 13l4 1.5 4-5 4 1.5" stroke="var(--bb-accent-fill)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
        <path d="M2 11 6 8.5 10 10l4-6.5 4 2" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" opacity=".55" />
        <path d="M2 18.5h16" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" opacity=".35" />
    </svg>
</template>

<script setup>
defineProps({ size: { type: Number, default: 20 } });
</script>
