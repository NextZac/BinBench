<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="flex flex-wrap items-end gap-3 border-b border-line px-3 py-2">
            <div class="min-w-0">
                <h2 class="text-[14px] font-semibold">Fault codes</h2>
                <p class="text-[11px] text-muted">
                    <template v-if="dtc?.available">{{ dtc.paths.length }} fault paths · {{ counts.off + counts.hidden }} switched off or hidden · {{ counts.protected }} locked (emissions)</template>
                    <template v-else>What the ECU reports, stores and lights the warning lamp for.</template>
                </p>
            </div>
            <template v-if="dtc?.available">
                <label class="relative ml-auto">
                    <span class="sr-only">Search fault codes</span>
                    <Icon name="search" :size="13" class="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-muted" />
                    <input v-model="query" class="input w-56 pl-7" placeholder="P0301, knock, boost…">
                </label>
                <div class="seg" role="group" aria-label="Filter">
                    <button v-for="f in filters" :key="f.id" :aria-pressed="filter === f.id" @click="filter = f.id">{{ f.label }}</button>
                </div>
            </template>
        </div>

        <div v-if="error" class="p-6 text-[12px] text-bad">{{ error }}</div>
        <div v-else-if="!dtc" class="grid flex-1 place-items-center font-mono text-[12px] text-muted">Reading fault tables…</div>
        <div v-else-if="!dtc.available" class="max-w-xl p-6 text-[12px] text-muted">
            <p class="text-ink">No fault-code tables found for this ECU yet.</p>
            <p class="mt-1">BinBench only edits fault paths it can locate with confidence. Supported so far: Bosch ME7.x, and ME9.0 / EDC15 DDE4 where the tables were identified.</p>
        </div>

        <template v-else>
            <details v-if="dtc.notes?.length" class="border-b border-line px-3 py-1.5 text-[11px]">
                <summary class="cursor-pointer text-muted">How these tables were found</summary>
                <p v-for="n in dtc.notes" :key="n" class="note mt-1.5">{{ n }}</p>
            </details>
            <div class="min-h-0 flex-1 overflow-auto scroll-thin">
                <table class="w-full min-w-[720px] text-[12px]">
                    <thead class="sticky top-0 z-10 bg-panel-2 text-left">
                        <tr class="border-b border-line">
                            <th class="label w-12 px-3 py-1 font-medium">Path</th>
                            <th class="label w-44 py-1 pr-3 font-medium">Codes</th>
                            <th class="label py-1 pr-3 font-medium">Description</th>
                            <th class="label w-28 py-1 pr-3 font-medium">State</th>
                            <th class="w-64 py-1 pr-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in shown" :key="row.path.index">
                            <tr class="border-b border-line/60 align-top hover:bg-panel" :class="open === row.path.index ? 'bg-panel' : ''">
                                <td class="px-3 py-1.5 font-mono text-muted">{{ row.path.index }}</td>
                                <td class="py-1.5 pr-3 font-mono">
                                    <span v-for="c in row.codes" :key="c" class="mr-1.5 inline-block" :class="row.state === 'active' ? '' : 'text-muted line-through decoration-1'">{{ c }}</span>
                                    <span v-if="!row.codes.length" class="text-muted">—</span>
                                </td>
                                <td class="py-1.5 pr-3">
                                    <button class="cursor-pointer text-left hover:text-accent" :aria-expanded="open === row.path.index" @click="open = open === row.path.index ? null : row.path.index">{{ row.name }}</button>
                                    <div v-if="row.system !== row.name" class="text-[11px] text-muted">{{ row.system }}</div>
                                </td>
                                <td class="py-1.5 pr-3">
                                    <span v-if="row.path.protected" class="status text-muted" :title="`Emissions monitoring (${row.path.protectedReason}): read-only`"><Icon name="alert" :size="12" />locked</span>
                                    <span v-else class="status" :class="stateClass[row.state]">{{ stateLabel[row.state] }}</span>
                                </td>
                                <td class="py-1 pr-3 text-right whitespace-nowrap">
                                    <template v-if="!row.path.protected">
                                        <button class="btn btn-sm" :disabled="row.state === 'hidden' || row.state === 'off' || !row.codes.length" title="Stop this path reporting a P-code to scan tools. The ECU still reacts to the fault." @click="hide(row.path)">Hide code</button>
                                        <button class="btn btn-sm ml-1" :disabled="!row.canSwitchOff || row.state === 'off'" :title="row.canSwitchOff ? 'Hide the code and set the path’s class/enable fields to their off values' : 'No off value known for this path’s fields'" @click="switchOff(row.path)">Switch off</button>
                                        <button class="btn btn-quiet btn-sm ml-1" :disabled="row.state === 'active'" title="Put the original values back" @click="restore(row.path)"><Icon name="reset" :size="12" /></button>
                                    </template>
                                    <span v-else class="text-[11px] text-muted">{{ row.path.protectedReason }}</span>
                                </td>
                            </tr>
                            <tr v-if="open === row.path.index" class="border-b border-line/60 bg-panel">
                                <td />
                                <td colspan="4" class="pb-2 pr-3 text-[11px]">
                                    <table class="font-mono">
                                        <tr v-for="c in row.path.codes" :key="c.address" class="text-muted">
                                            <td class="pr-3">{{ c.slot }}</td>
                                            <td class="pr-3">{{ hex(c.address) }}</td>
                                            <td class="pr-3 text-ink">{{ used(c) ? c.code : '—' }}</td>
                                            <td class="font-sans">{{ used(c) ? c.name : 'unused slot' }}</td>
                                        </tr>
                                        <tr v-for="f in row.path.fields" :key="f.key" class="text-muted">
                                            <td class="pr-3" :title="dtc.fieldHelp?.[f.key]">{{ f.label }}</td>
                                            <td class="pr-3">{{ hex(f.address) }}</td>
                                            <td class="pr-3 text-ink">{{ hexValue(readField(f), f.size) }}</td>
                                            <td class="font-sans">original {{ hexValue(f.value, f.size) }}<template v-if="f.offValue !== f.value">, off {{ hexValue(f.offValue, f.size) }}</template><span v-if="dtc.fieldHelp?.[f.key]"> · {{ dtc.fieldHelp[f.key] }}</span></td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p v-if="!shown.length" class="p-4 text-[12px] text-muted">Nothing matches.</p>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { hex } from '../lib/binary';
import { useEditor } from '../stores/editor';
import Icon from './Icon.vue';

const ed = useEditor();
const dtc = computed(() => ed.dtc);
const error = ref(null);
const query = ref('');
const filter = ref('all');
const open = ref(null);

const filters = [
    { id: 'all', label: 'All' },
    { id: 'active', label: 'Active' },
    { id: 'changed', label: 'Changed' },
    { id: 'locked', label: 'Locked' },
];
const stateLabel = { active: 'active', hidden: 'code hidden', off: 'switched off', modified: 'changed' };
const stateClass = { active: 'text-muted', hidden: 'text-mod', off: 'text-mod', modified: 'text-mod' };

function readInt(buf, address, size) {
    let v = 0;
    for (let i = 0; i < size; i++) {
        const byte = buf[address + (dtc.value.bigEndian ? i : size - 1 - i)];
        v = v * 256 + byte;
    }
    return v;
}

function writeInt(buf, address, size, value) {
    for (let i = 0; i < size; i++) {
        const shift = 8 * (dtc.value.bigEndian ? size - 1 - i : i);
        buf[address + i] = Math.floor(value / 2 ** shift) & 0xff;
    }
}

const readField = (f) => {
    void ed.revision;
    return readInt(ed.current, f.address, f.size);
};

// 0x0000 and 0xFFFF mark an unused code slot
const used = (c) => c.value !== 0 && c.value !== 0xffff;

const hexValue = (v, size) => '0x' + v.toString(16).toUpperCase().padStart(size * 2, '0');

function state(path) {
    const size = dtc.value.codeSize;
    const codesNow = path.codes.map((c) => readInt(ed.current, c.address, size));
    const fieldsNow = path.fields.map((f) => readInt(ed.current, f.address, f.size));
    const codesHidden = path.codes.some(used) && path.codes.every((c, i) => !used(c) || codesNow[i] === 0);
    const fieldsOff = path.fields.some((f) => f.offValue !== f.value) && path.fields.every((f, i) => fieldsNow[i] === f.offValue);
    const changed = path.codes.some((c, i) => codesNow[i] !== c.value) || path.fields.some((f, i) => fieldsNow[i] !== f.value);
    if (codesHidden && fieldsOff) return 'off';
    if (codesHidden) return 'hidden';
    return changed ? 'modified' : 'active';
}

const rows = computed(() => {
    void ed.revision;
    if (!dtc.value?.available) return [];
    return dtc.value.paths.map((path) => {
        const named = path.codes.filter(used);
        return {
            path,
            codes: [...new Set(named.map((c) => c.code))],
            name: path.name || named[0]?.name || 'Unused fault path',
            system: named[0]?.system ?? '',
            state: state(path),
            canSwitchOff: path.fields.some((f) => f.offValue !== f.value),
        };
    });
});

const counts = computed(() => ({
    off: rows.value.filter((r) => r.state === 'off').length,
    hidden: rows.value.filter((r) => r.state === 'hidden').length,
    protected: rows.value.filter((r) => r.path.protected).length,
}));

const shown = computed(() => {
    const q = query.value.trim().toLowerCase();
    return rows.value.filter((r) => {
        if (filter.value === 'active' && r.state !== 'active') return false;
        if (filter.value === 'changed' && r.state === 'active') return false;
        if (filter.value === 'locked' && !r.path.protected) return false;
        if (!q) return true;
        return r.codes.some((c) => c.toLowerCase().includes(q)) || r.name.toLowerCase().includes(q) || r.system.toLowerCase().includes(q);
    });
});

/** Write several (address, size, value) entries as one undo step. */
function write(entries) {
    if (!entries.length) return;
    const from = Math.min(...entries.map((e) => e.address));
    const to = Math.max(...entries.map((e) => e.address + e.size));
    ed.transactRange(from, to, (buf) => entries.forEach((e) => writeInt(buf, e.address, e.size, e.value)));
}

function hide(path) {
    if (path.protected) return;
    write(path.codes.filter(used).map((c) => ({ address: c.address, size: dtc.value.codeSize, value: 0 })));
}

function switchOff(path) {
    if (path.protected) return;
    write([
        ...path.codes.filter(used).map((c) => ({ address: c.address, size: dtc.value.codeSize, value: 0 })),
        ...path.fields.filter((f) => f.offValue !== f.value).map((f) => ({ address: f.address, size: f.size, value: f.offValue })),
    ]);
}

function restore(path) {
    write([
        ...path.codes.map((c) => ({ address: c.address, size: dtc.value.codeSize, value: c.value })),
        ...path.fields.map((f) => ({ address: f.address, size: f.size, value: f.value })),
    ]);
}

onMounted(async () => {
    if (ed.dtc) return;
    try {
        await ed.loadDtcs();
    } catch (e) {
        error.value = e.message;
    }
});
</script>
