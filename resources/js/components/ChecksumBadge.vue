<template>
    <span v-if="ok === true" class="chip bg-ok/15 text-ok">OK</span>
    <span v-else-if="ok === false" class="chip bg-bad/15 text-bad">Bad</span>
    <span v-else class="chip bg-panel-2 text-muted">n/a</span>
</template>

<script setup>
defineProps({ ok: { type: [Boolean, null], default: null } });
</script>
