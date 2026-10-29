<template>
    <div class="flex h-full flex-col">
        <div class="space-y-2 border-b border-line p-2">
            <input v-model="query" class="input w-full" placeholder="Search name or address…">
            <div class="flex gap-1">
                <button v-for="f in filters" :key="f.id" class="btn btn-sm flex-1 justify-center"
                    :class="filter === f.id ? 'border-accent text-accent' : ''" @click="filter = f.id">{{ f.label }}</button>
            </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto scroll-thin">
            <div v-for="group in groups" :key="group.name">
                <button class="sticky top-0 z-10 flex w-full items-center justify-between border-b border-line bg-panel-2 px-3 py-1.5 text-left text-[11px] font-semibold tracking-wide text-muted uppercase"
                    @click="toggle(group.name)">
                    <span>{{ collapsed[group.name] ? '▸' : '▾' }} {{ group.name }}</span>
                    <span class="font-mono">{{ group.maps.length }}</span>
                </button>
                <template v-if="!collapsed[group.name]">
                    <button v-for="m in group.maps" :key="m.id"
                        class="flex w-full items-center gap-2 border-b border-line/50 px-3 py-1.5 text-left hover:bg-panel-2"
                        :class="m.id === ed.selectedId ? 'bg-accent/10 shadow-[inset_2px_0_0_var(--color-accent)]' : ''"
                        @click="ed.selectedId = m.id">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="dotClass(m)" :title="`${m.source} · confidence ${Math.round(m.confidence * 100)}%`" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[12px]" :class="ed.changedMapIds.has(m.id) ? 'font-semibold text-accent' : ''">{{ m.name }}</span>
                            <span class="block font-mono text-[10px] text-muted">{{ hex(m.address) }} · {{ m.rows }}×{{ m.cols }} · {{ m.dtype }}{{ m.be ? ' BE' : '' }}</span>
                        </span>
                        <span v-if="ed.refChangedMapIds.has(m.id)" class="chip bg-sky-500/20 text-sky-300" :title="`Differs from ${ed.refLabel}`">≠ref</span>
                        <span v-if="ed.changedMapIds.has(m.id)" class="chip bg-accent/20 text-accent">mod</span>
                    </button>
                </template>
            </div>
            <div v-if="!groups.length" class="p-4 text-center text-xs text-muted">No maps match.</div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { hex } from '../lib/binary';
import { useEditor } from '../stores/editor';

const ed = useEditor();
const query = ref('');
const filter = ref('main');
const collapsed = reactive({});
const filters = computed(() => [
    { id: 'main', label: 'Key maps' },
    { id: 'all', label: 'All' },
    { id: 'changed', label: 'Changed' },
    ...(ed.reference ? [{ id: 'ref', label: '≠ Ref' }] : []),
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

function dotClass(m) {
    if (m.source === 'xdf' || m.source === 'user') return 'bg-sky-400';
    if (m.source === 'pattern') return 'bg-ok';
    if (m.confidence >= 0.7) return 'bg-ok';
    if (m.confidence >= 0.5) return 'bg-warn';
    return 'bg-muted';
}
</script>
