<template>
    <div class="flex h-full flex-col">
        <div class="space-y-1.5 border-b border-line p-2">
            <label class="relative block">
                <span class="sr-only">Search maps</span>
                <Icon name="search" :size="13" class="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-muted" />
                <input v-model="query" class="input w-full pl-7" placeholder="Name or address">
            </label>
            <div class="seg w-full" role="group" aria-label="Filter maps">
                <button v-for="f in filters" :key="f.id" class="flex-1" :aria-pressed="filter === f.id" @click="filter = f.id">{{ f.label }}</button>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto scroll-thin">
            <div v-for="group in groups" :key="group.name">
                <button class="sticky top-0 z-10 flex w-full cursor-pointer items-center gap-1 border-b border-line bg-panel-2 px-2 py-1 text-left"
                    :aria-expanded="!collapsed[group.name]" @click="toggle(group.name)">
                    <Icon :name="collapsed[group.name] ? 'chevronRight' : 'chevronDown'" :size="12" class="text-muted" />
                    <span class="label flex-1">{{ group.name }}</span>
                    <span class="font-mono text-[10px] text-muted">{{ group.maps.length }}</span>
                </button>
                <template v-if="!collapsed[group.name]">
                    <button v-for="m in group.maps" :key="m.id"
                        class="relative flex w-full cursor-pointer items-center gap-2 border-b border-line/50 py-1 pr-2 pl-3 text-left hover:bg-raised"
                        :class="m.id === ed.selectedId ? 'bg-raised' : ''"
                        :aria-current="m.id === ed.selectedId ? 'true' : undefined"
                        @click="ed.selectedId = m.id">
                        <span v-if="m.id === ed.selectedId" class="absolute inset-y-0 left-0 w-[3px] bg-accent-fill" />
                        <span v-else-if="ed.changedMapIds.has(m.id)" class="absolute inset-y-0 left-0 w-[3px] bg-mod" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[12px]" :class="ed.changedMapIds.has(m.id) ? 'font-medium text-mod' : ''">{{ m.name }}</span>
                            <span class="block font-mono text-[10px] text-muted">{{ hex(m.address) }} · {{ m.rows }}×{{ m.cols }} {{ m.dtype }}</span>
                        </span>
                        <span v-if="ed.refChangedMapIds.has(m.id)" class="font-mono text-[12px] text-ref" :title="`Differs from ${ed.refLabel}`" aria-label="differs from reference">≠</span>
                        <span class="flex shrink-0 items-end gap-px" :title="trust(m)" :aria-label="trust(m)">
                            <span v-for="i in 3" :key="i" class="w-[3px]" :class="[i <= level(m) ? 'bg-ink/70' : 'bg-line-strong', ['h-1.5', 'h-2', 'h-2.5'][i - 1]]" />
                        </span>
                    </button>
                </template>
            </div>
            <div v-if="!groups.length" class="p-4 text-[12px] text-muted">No maps match.</div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { hex } from '../lib/binary';
import Icon from './Icon.vue';
import { useEditor } from '../stores/editor';

const ed = useEditor();
const query = ref('');
const filter = ref('main');
const collapsed = reactive({});
const filters = computed(() => [
    { id: 'main', label: 'Key' },
    { id: 'all', label: 'All' },
    { id: 'changed', label: 'Edited' },
    ...(ed.reference ? [{ id: 'ref', label: '≠ ref' }] : []),
]);

const categoryOrder = ['Torque / IQ', 'Torque', 'Limiters', 'Boost', 'Injection', 'Fuel', 'Ignition', 'Airflow', 'EGR / Air', 'Rail', 'Corrections'];

const groups = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = ed.maps.filter((m) => {
        if (filter.value === 'changed' && !ed.changedMapIds.has(m.id)) return false;
        if (filter.value === 'ref' && ed.reference && !ed.refChangedMapIds.has(m.id)) return false;
        if (filter.value === 'main' && !['xdf', 'user'].includes(m.source) && (m.confidence < 0.5 || m.category === 'Unidentified')) return false;
        if (!q) return true;
        return m.name.toLowerCase().includes(q) || hex(m.address).toLowerCase().includes(q.replace(/^0x/, '0x')) || m.address.toString(16).includes(q.replace(/^0x/, ''));
    });
    const by = {};
    for (const m of list) (by[m.category] ??= []).push(m);
    return Object.entries(by)
        .map(([name, maps]) => ({ name, maps }))
        .sort((a, b) => {
            const ia = categoryOrder.indexOf(a.name);
            const ib = categoryOrder.indexOf(b.name);
            return (ia < 0 ? 99 : ia) - (ib < 0 ? 99 : ib) || a.name.localeCompare(b.name);
        });
});

const toggle = (name) => (collapsed[name] = !collapsed[name]);

/** 1-3 bars: how sure we are that this table is what its name says. */
function level(m) {
    if (m.source === 'xdf' || m.source === 'user') return 3;
    if (m.confidence >= 0.7) return 3;
    if (m.confidence >= 0.45) return 2;
    return 1;
}

function trust(m) {
    const from = { xdf: 'from imported XDF', user: 'edited by you', pattern: 'found in ECU code', scan: 'found by table scan' }[m.source] ?? m.source;
    return `${from}, confidence ${Math.round(m.confidence * 100)} %`;
}
</script>
