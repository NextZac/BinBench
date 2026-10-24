<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- toolbar -->
        <div class="flex flex-wrap items-center gap-1.5 border-b border-line bg-panel px-3 py-2">
            <div class="flex overflow-hidden rounded-md border border-line">
                <button v-for="m in viewModes" :key="m.id" class="px-2 py-1 text-[11px]"
                    :class="viewMode === m.id ? 'bg-accent text-black' : 'bg-panel-2 text-muted hover:text-ink'"
                    @click="viewMode = m.id">{{ m.label }}</button>
            </div>
            <span class="mx-1 h-5 w-px bg-line" />
            <input v-model="operand" class="input w-20 font-mono" placeholder="value" @keydown.enter.prevent="apply('set')">
            <button class="btn btn-sm" title="Set selection to value (Enter)" @click="apply('set')">=</button>
            <button class="btn btn-sm" title="Add value" @click="apply('add')">+</button>
            <button class="btn btn-sm" title="Subtract value" @click="apply('sub')">−</button>
            <button class="btn btn-sm" title="Increase by %" @click="apply('pct')">+%</button>
            <button class="btn btn-sm" title="Decrease by %" @click="apply('-pct')">−%</button>
            <button class="btn btn-sm" title="Multiply" @click="apply('mul')">×</button>
            <span class="mx-1 h-5 w-px bg-line" />
            <button class="btn btn-sm" title="Interpolate horizontally between selection edges" @click="interpolate('h')">Interp ↔</button>
            <button class="btn btn-sm" title="Interpolate vertically" @click="interpolate('v')">Interp ↕</button>
            <button class="btn btn-sm" title="Bilinear fill from selection corners" @click="interpolate('b')">Interp ⊞</button>
            <button class="btn btn-sm" title="Smooth selection (3×3 average)" @click="smooth">Smooth</button>
            <button class="btn btn-sm" title="Restore selection from original" @click="restoreSelection">Restore</button>
            <span class="ml-auto text-[11px] text-muted">
                {{ selCount }} selected<span v-if="selStats"> · min {{ selStats.min }} · max {{ selStats.max }} · avg {{ selStats.avg }}</span>
            </span>
        </div>

        <!-- grid -->
        <div ref="scroller" class="min-h-0 flex-1 overflow-auto scroll-thin" tabindex="0" @keydown="onKey">
            <table class="border-separate border-spacing-0 font-mono text-[11px] select-none">
                <thead>
                    <tr>
                        <th class="sticky top-0 left-0 z-20 border-r border-b border-line bg-panel px-2 py-1 text-[10px] font-normal text-muted">
                            <div>{{ map.y?.unit || (map.rows > 1 ? 'Y' : '') }}</div>
                            <div>{{ map.x?.unit || 'X' }}</div>
                        </th>
                        <th v-for="(xv, c) in xAxis" :key="c"
                            class="sticky top-0 z-10 cursor-pointer border-b border-line bg-panel px-2 py-1 text-right font-medium text-sky-300"
                            @mousedown="selectCol(c, $event)">{{ fmt(xv, map.x?.decimals ?? 0) }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, r) in grid" :key="r">
                        <th class="sticky left-0 z-10 cursor-pointer border-r border-line bg-panel px-2 py-0.5 text-right font-medium text-sky-300"
                            @mousedown="selectRow(r, $event)">{{ fmt(yAxis[r], map.y?.decimals ?? 0) }}</th>
                        <td v-for="(v, c) in row" :key="c"
                            class="relative min-w-[52px] border-r border-b border-black/30 px-1.5 py-0.5 text-right"
                            :class="[isSelected(r, c) ? 'outline-2 -outline-offset-2 outline-white' : '', changed(r, c) ? 'font-bold' : '']"
                            :style="cellStyle(r, c, v)"
                            :title="cellTitle(r, c)"
                            @mousedown.prevent="startSelect(r, c, $event)"
                            @mouseenter="dragSelect(r, c)"
                            @dblclick="beginEdit(r, c)">
                            <input v-if="editing && editing.r === r && editing.c === c" ref="editInput" v-model="editing.value"
                                class="w-full bg-black/60 text-right text-white outline-none"
                                @keydown.enter.stop.prevent="commitEdit" @keydown.esc.stop="editing = null" @blur="commitEdit">
                            <template v-else>{{ display(r, c, v) }}</template>
                            <span v-if="changed(r, c)" class="absolute top-0 right-0 h-1.5 w-1.5 bg-accent" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { fmt, readAxis, readCell, readGrid, writeCell } from '../lib/binary';
import { useEditor } from '../stores/editor';

const props = defineProps({ map: { type: Object, required: true } });
const ed = useEditor();

const viewModes = [
    { id: 'value', label: 'Value' },
    { id: 'orig', label: 'Original' },
    { id: 'diff', label: 'Δ' },
    { id: 'pct', label: 'Δ%' },
];
const viewMode = ref('value');
const operand = ref('');
const sel = ref({ r0: 0, c0: 0, r1: 0, c1: 0 });
const dragging = ref(false);
const editing = ref(null);
const editInput = ref(null);
const scroller = ref(null);

const grid = computed(() => {
    void ed.revision;
    return readGrid(ed.current, props.map);
});
const origGrid = computed(() => readGrid(ed.original, props.map));
const xAxis = computed(() => {
    void ed.revision;
    return readAxis(ed.current, props.map.x, props.map.cols);
});
const yAxis = computed(() => {
    void ed.revision;
    return readAxis(ed.current, props.map.y, props.map.rows);
});

const range = computed(() => {
    let min = Infinity;
    let max = -Infinity;
    for (const row of grid.value) for (const v of row) {
        if (Number.isFinite(v)) {
            min = Math.min(min, v);
            max = Math.max(max, v);
        }
    }
    return { min, max };
});

const box = computed(() => ({
    r0: Math.min(sel.value.r0, sel.value.r1),
    r1: Math.max(sel.value.r0, sel.value.r1),
    c0: Math.min(sel.value.c0, sel.value.c1),
    c1: Math.max(sel.value.c0, sel.value.c1),
}));
const selCount = computed(() => (box.value.r1 - box.value.r0 + 1) * (box.value.c1 - box.value.c0 + 1));
const selStats = computed(() => {
    const vals = [];
    eachSelected((r, c) => vals.push(grid.value[r]?.[c]));
    if (vals.length < 2) return null;
    const d = props.map.decimals ?? 2;
    return {
        min: fmt(Math.min(...vals), d),
        max: fmt(Math.max(...vals), d),
        avg: fmt(vals.reduce((a, b) => a + b, 0) / vals.length, d),
    };
});

watch(() => props.map.id, () => {
    sel.value = { r0: 0, c0: 0, r1: 0, c1: 0 };
    editing.value = null;
});

const isSelected = (r, c) => r >= box.value.r0 && r <= box.value.r1 && c >= box.value.c0 && c <= box.value.c1;
const changed = (r, c) => grid.value[r][c] !== origGrid.value[r][c];

function eachSelected(fn) {
    for (let r = box.value.r0; r <= box.value.r1; r++) {
        for (let c = box.value.c0; c <= box.value.c1; c++) fn(r, c);
    }
}

function heat(t) {
    // blue -> cyan -> green -> yellow -> red
    const stops = [[37, 99, 235], [6, 182, 212], [34, 197, 94], [234, 179, 8], [239, 68, 68]];
    const x = Math.min(0.9999, Math.max(0, t)) * (stops.length - 1);
    const i = Math.floor(x);
    const f = x - i;
    const [a, b] = [stops[i], stops[i + 1]];
    return a.map((v, k) => Math.round(v + (b[k] - v) * f));
}

function cellStyle(r, c, v) {
    if (viewMode.value === 'diff' || viewMode.value === 'pct') {
        const d = v - origGrid.value[r][c];
        if (d === 0) return { background: '#1a212b', color: '#6b7787' };
        return { background: d > 0 ? 'rgba(239,68,68,.55)' : 'rgba(37,99,235,.6)', color: '#fff' };
    }
    const src = viewMode.value === 'orig' ? origGrid.value[r][c] : v;
    const { min, max } = range.value;
    const t = max > min ? (src - min) / (max - min) : 0.5;
    const [R, G, B] = heat(t);
    return { background: `rgba(${R},${G},${B},0.42)`, color: '#f1f5f9' };
}

function display(r, c, v) {
    const d = props.map.decimals ?? 2;
    const o = origGrid.value[r][c];
    switch (viewMode.value) {
        case 'orig': return fmt(o, d);
        case 'diff': return v === o ? '·' : (v - o > 0 ? '+' : '') + fmt(v - o, d);
        case 'pct': return v === o ? '·' : (o === 0 ? '∞' : ((v - o) / Math.abs(o) * 100).toFixed(1) + '%');
        default: return fmt(v, d);
    }
}

function cellTitle(r, c) {
    const d = props.map.decimals ?? 2;
    return `${fmt(yAxis.value[r], 2)} / ${fmt(xAxis.value[c], 2)}\nValue ${fmt(grid.value[r][c], d)}  Original ${fmt(origGrid.value[r][c], d)}`;
}

function startSelect(r, c, e) {
    scroller.value?.focus();
    if (e.shiftKey) {
        sel.value = { ...sel.value, r1: r, c1: c };
    } else {
        sel.value = { r0: r, c0: c, r1: r, c1: c };
    }
    dragging.value = true;
}
function dragSelect(r, c) {
    if (dragging.value) sel.value = { ...sel.value, r1: r, c1: c };
}
const stopDrag = () => (dragging.value = false);
function selectRow(r, e) {
    sel.value = e.shiftKey ? { ...sel.value, r1: r, c1: props.map.cols - 1 } : { r0: r, c0: 0, r1: r, c1: props.map.cols - 1 };
}
function selectCol(c, e) {
    sel.value = e.shiftKey ? { ...sel.value, c1: c, r1: props.map.rows - 1 } : { r0: 0, c0: c, r1: props.map.rows - 1, c1: c };
}

/** Write new physical values for the selection; fn(oldValue, r, c) => newValue. */
function mutate(fn) {
    ed.transact(props.map, (buf) => {
        eachSelected((r, c) => {
            const old = readCell(buf, props.map, r, c);
            const next = fn(old, r, c);
            if (Number.isFinite(next)) writeCell(buf, props.map, r, c, next);
        });
    });
}

function apply(op) {
    const n = parseFloat(String(operand.value).replace(',', '.'));
    if (!Number.isFinite(n)) return;
    mutate((v) => ({
        set: n,
        add: v + n,
        sub: v - n,
        pct: v * (1 + n / 100),
        '-pct': v * (1 - n / 100),
        mul: v * n,
    })[op] ?? v);
}

function nudge(dir) {
    const step = Math.abs(props.map.factor || 1);
    mutate((v) => v + dir * step);
}

function interpolate(mode) {
    const { r0, r1, c0, c1 } = box.value;
    const g = grid.value;
    mutate((v, r, c) => {
        const tr = r1 > r0 ? (r - r0) / (r1 - r0) : 0;
        const tc = c1 > c0 ? (c - c0) / (c1 - c0) : 0;
        if (mode === 'h') return c1 > c0 ? g[r][c0] + (g[r][c1] - g[r][c0]) * tc : v;
        if (mode === 'v') return r1 > r0 ? g[r0][c] + (g[r1][c] - g[r0][c]) * tr : v;
        const top = g[r0][c0] + (g[r0][c1] - g[r0][c0]) * tc;
        const bot = g[r1][c0] + (g[r1][c1] - g[r1][c0]) * tc;
        return top + (bot - top) * tr;
    });
}

function smooth() {
    const g = grid.value.map((row) => row.slice());
    mutate((v, r, c) => {
        let sum = 0;
        let n = 0;
        for (let dr = -1; dr <= 1; dr++) {
            for (let dc = -1; dc <= 1; dc++) {
                const x = g[r + dr]?.[c + dc];
                if (Number.isFinite(x)) {
                    sum += x;
                    n++;
                }
            }
        }
        return sum / n;
    });
}

function restoreSelection() {
    mutate((v, r, c) => origGrid.value[r][c]);
}

async function beginEdit(r, c, initial = null) {
    sel.value = { r0: r, c0: c, r1: r, c1: c };
    editing.value = { r, c, value: initial ?? fmt(grid.value[r][c], props.map.decimals ?? 2) };
    await nextTick();
    const el = Array.isArray(editInput.value) ? editInput.value[0] : editInput.value;
    el?.focus();
    if (initial === null) el?.select();
}

function commitEdit() {
    if (!editing.value) return;
    const n = parseFloat(String(editing.value.value).replace(',', '.'));
    const { r, c } = editing.value;
    editing.value = null;
    if (Number.isFinite(n)) {
        ed.transact(props.map, (buf) => writeCell(buf, props.map, r, c, n));
    }
    scroller.value?.focus();
}

function move(dr, dc, extend) {
    const r = Math.min(props.map.rows - 1, Math.max(0, sel.value.r1 + dr));
    const c = Math.min(props.map.cols - 1, Math.max(0, sel.value.c1 + dc));
    sel.value = extend ? { ...sel.value, r1: r, c1: c } : { r0: r, c0: c, r1: r, c1: c };
}

async function copySelection() {
    const lines = [];
    for (let r = box.value.r0; r <= box.value.r1; r++) {
        const cells = [];
        for (let c = box.value.c0; c <= box.value.c1; c++) cells.push(fmt(grid.value[r][c], props.map.decimals ?? 2));
        lines.push(cells.join('\t'));
    }
    await navigator.clipboard?.writeText(lines.join('\n'));
}

async function pasteAtSelection() {
    const text = await navigator.clipboard?.readText();
    if (!text) return;
    const rows = text.trim().split(/\r?\n/).map((l) => l.split(/\t|;/).map((s) => parseFloat(s.replace(',', '.'))));
    const { r0, c0 } = box.value;
    ed.transact(props.map, (buf) => {
        rows.forEach((row, dr) => row.forEach((v, dc) => {
            const r = r0 + dr;
            const c = c0 + dc;
            if (r < props.map.rows && c < props.map.cols && Number.isFinite(v)) writeCell(buf, props.map, r, c, v);
        }));
    });
}

function onKey(e) {
    if (editing.value) return;
    const ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && e.key.toLowerCase() === 'a') {
        sel.value = { r0: 0, c0: 0, r1: props.map.rows - 1, c1: props.map.cols - 1 };
    } else if (ctrl && e.key.toLowerCase() === 'c') {
        copySelection();
    } else if (ctrl && e.key.toLowerCase() === 'v') {
        pasteAtSelection();
    } else if (ctrl) {
        return;
    } else if (e.key === 'ArrowUp') move(-1, 0, e.shiftKey);
    else if (e.key === 'ArrowDown') move(1, 0, e.shiftKey);
    else if (e.key === 'ArrowLeft') move(0, -1, e.shiftKey);
    else if (e.key === 'ArrowRight') move(0, 1, e.shiftKey);
    else if (e.key === '+' || e.key === 'PageUp') nudge(1);
    else if (e.key === '-' || e.key === 'PageDown') nudge(-1);
    else if (e.key === 'Enter' || e.key === 'F2') beginEdit(sel.value.r1, sel.value.c1);
    else if (/^[0-9.]$/.test(e.key)) beginEdit(sel.value.r1, sel.value.c1, e.key);
    else return;
    e.preventDefault();
}

onMounted(() => window.addEventListener('mouseup', stopDrag));
onBeforeUnmount(() => window.removeEventListener('mouseup', stopDrag));
</script>
