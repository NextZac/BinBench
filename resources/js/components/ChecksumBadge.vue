<template>
    <span v-if="ok === true" class="status text-ok"><Icon name="check" :size="12" />OK</span>
    <span v-else-if="ok === false" class="status text-bad"><Icon name="alert" :size="12" />Bad</span>
    <span v-else class="status text-muted" title="No checksum module for this ECU">—</span>
</template>

<script setup>
import Icon from './Icon.vue';

defineProps({ ok: { type: [Boolean, null], default: null } });
</script>
