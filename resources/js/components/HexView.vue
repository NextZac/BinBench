<template>
    <div class="flex h-full flex-col">
        <div class="flex items-center gap-2 border-b border-line bg-panel px-3 py-2 text-[11px]">
            <span class="text-muted">Go to</span>
            <input v-model="gotoText" class="input w-28 font-mono" placeholder="0x7A326" @keydown.enter="goto">
            <button class="btn btn-sm" @click="base = Math.max(0, base - LINES * 16)">◀ Page</button>
            <button class="btn btn-sm" @click="base = Math.min((ed.current?.length ?? 16) - 16, base + LINES * 16)">Page ▶</button>
            <span class="text-muted">Map bytes are outlined; changed bytes are orange.</span>
            <label class="ml-auto flex items-center gap-1 text-muted"><input v-model="wordMode" type="checkbox"> 16-bit words ({{ map?.be ? 'BE' : 'LE' }})</label>
        </div>
        <div class="min-h-0 flex-1 overflow-auto p-3 font-mono text-[11px] leading-5 scroll-thin">
            <div v-for="row in rows" :key="row.addr" class="flex gap-3 whitespace-pre">
                <span class="text-muted">{{ row.addr.toString(16).toUpperCase().padStart(6, '0') }}</span>
                <span class="flex gap-[3px]">
                    <span v-for="cell in row.cells" :key="cell.addr" :title="'0x' + cell.addr.toString(16).toUpperCase()"
                        :class="[cell.inMap ? 'bg-sky-500/20' : '', cell.changed ? 'text-accent font-bold' : 'text-ink/85']">{{ cell.text }}</span>
                </span>
                <span class="text-muted">{{ row.ascii }}</span>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { mapRange } from '../lib/binary';
import { useEditor } from '../stores/editor';

const props = defineProps({ map: { type: Object, default: null } });
const ed = useEditor();
const base = ref(0);
const gotoText = ref('');
const wordMode = ref(false);
const LINES = 64;

watch(() => props.map?.id, () => {
    if (props.map) base.value = Math.max(0, (props.map.address & ~0xf) - 0x40);
}, { immediate: true });

function goto() {
    const n = parseInt(gotoText.value, 16);
    if (Number.isFinite(n)) base.value = Math.max(0, n & ~0xf);
}

const rows = computed(() => {
    void ed.revision;
    const buf = ed.current;
    const orig = ed.original;
    if (!buf) return [];
    const [ms, me] = props.map ? mapRange(props.map) : [-1, -1];
    const out = [];
    for (let l = 0; l < LINES; l++) {
        const addr = base.value + l * 16;
        if (addr >= buf.length) break;
        const cells = [];
        let ascii = '';
        const step = wordMode.value ? 2 : 1;
        for (let i = 0; i < 16; i += step) {
            const a = addr + i;
            if (a >= buf.length) break;
            let text;
            let changed = buf[a] !== orig[a];
            if (wordMode.value) {
                const w = props.map?.be ? (buf[a] << 8) | buf[a + 1] : (buf[a + 1] << 8) | buf[a];
                text = w.toString(16).toUpperCase().padStart(4, '0');
                changed = changed || buf[a + 1] !== orig[a + 1];
            } else {
                text = buf[a].toString(16).toUpperCase().padStart(2, '0');
            }
            cells.push({ addr: a, text, inMap: a >= ms && a < me, changed });
        }
        for (let i = 0; i < 16 && addr + i < buf.length; i++) {
            const b = buf[addr + i];
            ascii += b >= 32 && b < 127 ? String.fromCharCode(b) : '.';
        }
        out.push({ addr, cells, ascii });
    }
    return out;
});
</script>
