<template>
    <svg :width="size" :height="size" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4"
        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" class="shrink-0">
        <path v-for="(d, i) in paths" :key="i" :d="d" />
    </svg>
</template>

<script setup>
import { computed } from 'vue';

// Small stroke icon set drawn on a 16px grid. Decorative: buttons carry their own label or aria-label.
const ICONS = {
    undo: ['M5.5 4 3 6.5 5.5 9', 'M3 6.5h6.5a3.5 3.5 0 0 1 0 7H7'],
    redo: ['M10.5 4 13 6.5 10.5 9', 'M13 6.5H6.5a3.5 3.5 0 0 0 0 7H9'],
    swap: ['M3 5.5h9.5', 'M10 3l2.5 2.5L10 8', 'M13 10.5H3.5', 'M6 8l-2.5 2.5L6 13'],
    chevronDown: ['M4 6l4 4 4-4'],
    chevronRight: ['M6 4l4 4-4 4'],
    chevronLeft: ['M10 4 6 8l4 4'],
    upload: ['M8 10.5V2.5', 'M5 5.5l3-3 3 3', 'M2.5 10.5v2.5a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5v-2.5'],
    download: ['M8 2.5v8', 'M5 7.5l3 3 3-3', 'M2.5 10.5v2.5a.5.5 0 0 0 .5.5h10a.5.5 0 0 0 .5-.5v-2.5'],
    file: ['M4 1.5h5L12.5 5v9a.5.5 0 0 1-.5.5H4a.5.5 0 0 1-.5-.5V2a.5.5 0 0 1 .5-.5Z', 'M9 1.5V5h3.5'],
    close: ['M4 4l8 8', 'M12 4l-8 8'],
    check: ['M3 8.5l3 3 7-7'],
    alert: ['M8 2 14.5 13.5h-13Z', 'M8 6.5v3', 'M8 11.6v.1'],
    info: ['M8 14.5a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13Z', 'M8 7.5v4', 'M8 4.9v.1'],
    sun: ['M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z', 'M8 1v1.5', 'M8 13.5V15', 'M1 8h1.5', 'M13.5 8H15', 'M3 3l1 1', 'M12 12l1 1', 'M3 13l1-1', 'M12 4l1-1'],
    moon: ['M13.5 9.5A5.5 5.5 0 0 1 6.5 2.5a5.5 5.5 0 1 0 7 7Z'],
    open: ['M9 2.5h4.5V7', 'M13.5 2.5 7.5 8.5', 'M11.5 9.5v3.5a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5V5a.5.5 0 0 1 .5-.5h3.5'],
    trash: ['M2.5 4h11', 'M6 4V2.5h4V4', 'M4 4l.6 9.5h6.8L12 4'],
    more: ['M3.5 8h.01', 'M8 8h.01', 'M12.5 8h.01'],
    search: ['M7 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z', 'M10.5 10.5 14 14'],
    plus: ['M8 3v10', 'M3 8h10'],
    reset: ['M2.5 8a5.5 5.5 0 1 0 1.6-3.9', 'M2.5 2.5v3h3'],
    grid: ['M2 2h12v12H2Z', 'M2 6h12', 'M2 10h12', 'M6 2v12', 'M10 2v12'],
};

const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 14 },
});

const paths = computed(() => ICONS[props.name] ?? []);
</script>
