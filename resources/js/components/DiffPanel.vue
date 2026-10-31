<template>
    <div class="flex h-full min-h-0 flex-col overflow-y-auto scroll-thin">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-3 py-2 text-xs">
            <div class="min-w-0">
                <div class="text-sm font-semibold">Diff &amp; review</div>
                <div class="text-[11px] text-muted">What changed between two files, and whether it looks safe and well made.</div>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2">Compare
                    <CompareSelect v-model="target" include-current />
                </label>
                <button class="btn btn-quiet btn-icon" aria-label="Swap the two sides" title="Swap the two sides" @click="swap"><Icon name="swap" /></button>
                <label class="flex items-center gap-2">against
                    <CompareSelect v-model="base" include-current />
                </label>
            </div>
        </div>
        <div v-if="result?.swMismatch" class="note mx-3 mt-3">
            {{ result.target.label }} (SW {{ result.target.sw }}) and {{ result.base.label }} (SW {{ result.base.sw }}) have different software.
            Map definitions come from this file, so changes may land in the wrong maps.
        </div>

        <div v-if="error" class="p-6 text-center text-xs text-bad">{{ error }}</div>
        <div v-else-if="!result" class="p-6 text-center text-xs text-muted">Comparing…</div>
        <div v-else-if="base === target" class="p-6 text-center text-xs text-muted">Pick two different files to compare.</div>
        <div v-else-if="result.review.verdict === 'none'" class="p-6 text-center text-xs text-muted">No differences between {{ result.target.label }} and {{ result.base.label }}.</div>

        <template v-else>
            <!-- verdict -->
            <div class="flex flex-wrap items-center gap-3 px-3 pt-3 text-xs">
                <span class="flex items-center gap-1.5 text-[15px] font-semibold" :class="verdict.class"><Icon :name="verdict.icon" :size="15" />{{ verdict.label }}</span>
                <span class="text-muted">{{ result.changedBytes }} bytes in {{ result.maps.length }} map{{ result.maps.length === 1 ? '' : 's' }}<template v-if="unmappedBytes">, {{ unmappedBytes }} outside known maps</template></span>
                <span v-if="result.dyno" class="ml-auto font-mono text-[11px]">
                    est. {{ Math.round(result.dyno.stock.power.value) }} → <b>{{ Math.round(result.dyno.modified.power.value) }} PS</b>,
                    {{ Math.round(result.dyno.stock.torque.value) }} → <b>{{ Math.round(result.dyno.modified.torque.value) }} Nm</b>
                    <span v-if="!result.dyno.calibrated" class="text-muted">(uncalibrated)</span>
                </span>
            </div>

            <!-- findings -->
            <div class="space-y-1.5 p-3">
                <div v-for="(f, i) in result.review.findings" :key="i" class="flex gap-2 border-l-2 py-1 pl-2 text-xs" :class="severity[f.severity].box">
                    <Icon :name="severity[f.severity].icon" :size="13" class="mt-0.5" :class="severity[f.severity].text" />
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold">{{ f.title }}</div>
                        <div class="text-[11px] text-muted">{{ f.detail }}</div>
                        <div v-if="f.mapIds.length" class="mt-1 flex flex-wrap gap-1">
                            <button v-for="id in f.mapIds.slice(0, 3)" :key="id" class="max-w-56 truncate rounded border border-line bg-panel px-1.5 py-0.5 text-[10px] text-muted hover:text-accent"
                                :title="mapName(id)" @click="ed.selectedId = id">{{ mapName(id) }}</button>
                            <span v-if="f.mapIds.length > 3" class="text-[10px] text-muted" :title="f.mapIds.slice(3).map(mapName).join('\n')">+{{ f.mapIds.length - 3 }} more</span>
                        </div>
                    </div>
                </div>
                <div v-if="!result.review.findings.length" class="text-[11px] text-muted">No issues found by the checks.</div>
                <p class="text-[11px] text-muted">Rule-of-thumb checks for common mistakes and aggressive values. They do not replace a datalog.</p>
            </div>

            <!-- changed maps -->
            <div class="px-3 pb-3">
                <div class="mb-1 text-xs font-semibold">Changed maps</div>
                <table class="w-full text-[11px]">
                    <thead class="text-left text-muted">
                        <tr class="border-b border-line">
                            <th class="py-1 pr-2 font-medium">Map</th>
                            <th class="py-1 pr-2 text-right font-medium">Cells</th>
                            <th class="py-1 pr-2 text-right font-medium">Avg</th>
                            <th class="py-1 font-medium" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in sortedMaps" :key="m.id" class="border-b border-line/50 align-top">
                            <td class="py-1 pr-2">
                                <button class="text-left hover:text-accent" @click="ed.selectedId = m.id">{{ m.name }}</button>
                                <div class="font-mono text-[10px] text-muted">{{ range(m.before) }} → <span class="text-accent">{{ range(m.after) }}</span> {{ m.unit }} · {{ m.category }}</div>
                            </td>
                            <td class="py-1 pr-2 text-right font-mono whitespace-nowrap">{{ m.changedCells }}/{{ m.cells }}</td>
                            <td class="py-1 pr-2 text-right font-mono whitespace-nowrap" :class="m.meanChangePct > 0 ? 'text-ok' : m.meanChangePct < 0 ? 'text-bad' : ''">{{ m.meanChangePct === null ? '—' : (m.meanChangePct > 0 ? '+' : '') + m.meanChangePct + ' %' }}</td>
                            <td class="py-1">
                                <span class="flex flex-wrap justify-end gap-1">
                                    <span v-if="m.clamped" class="status text-bad" title="Values hit the storage limit">limit</span>
                                    <span v-if="m.spike" class="status text-warn" title="A cell jumps away from its neighbours">spike</span>
                                    <span v-if="m.axisChanged" class="status text-warn" title="The axis breakpoints were changed">axis</span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <template v-if="result.unmapped.length">
                    <div class="mt-3 mb-1 text-xs font-semibold">Changes outside known maps</div>
                    <div class="flex flex-wrap gap-1.5 font-mono text-[11px]">
                        <span v-for="u in result.unmapped" :key="u.start" class="border-b border-line-strong">{{ hex(u.start) }}–{{ hex(u.end - 1) }} · {{ u.length }} B</span>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { hex } from '../lib/binary';
import { useEngineInputs } from '../lib/engine';
import { useEditor } from '../stores/editor';
import CompareSelect from './CompareSelect.vue';
import Icon from './Icon.vue';

// versionId: compare this saved version (instead of the current edits) against the original.
const props = defineProps({ versionId: { type: Number, default: null } });
const ed = useEditor();
const { params } = useEngineInputs(ed.file.id);
// each side: 'original', 'current', 'version:{id}' or 'file:{id}'
const target = ref(props.versionId ? `version:${props.versionId}` : 'current');
const base = ref('original');
const result = ref(null);
const error = ref(null);
let timer = null;
let requestId = 0;

const verdicts = {
    risky: { label: 'Risky', class: 'text-bad', icon: 'alert' },
    check: { label: 'Needs a look', class: 'text-warn', icon: 'alert' },
    clean: { label: 'Looks clean', class: 'text-ok', icon: 'check' },
};
const severity = {
    danger: { icon: 'alert', text: 'text-bad', box: 'border-bad' },
    warning: { icon: 'alert', text: 'text-warn', box: 'border-warn' },
    info: { icon: 'info', text: 'text-muted', box: 'border-line-strong' },
};

const verdict = computed(() => verdicts[result.value?.review.verdict] ?? verdicts.clean);
const unmappedBytes = computed(() => (result.value?.unmapped ?? []).reduce((n, u) => n + u.length, 0));
const sortedMaps = computed(() => [...(result.value?.maps ?? [])].sort((a, b) => Math.abs(b.meanChangePct ?? 0) - Math.abs(a.meanChangePct ?? 0)));

function mapName(id) {
    return ed.maps.find((m) => m.id === id)?.name ?? id;
}

function range(r) {
    const f = (v) => (Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 100) / 100);
    return r.min === r.max ? `${f(r.min)}` : `${f(r.min)}–${f(r.max)}`;
}

function swap() {
    [base.value, target.value] = [target.value, base.value];
}

async function compare() {
    const id = ++requestId;
    if (base.value === target.value) {
        result.value = null;
        return;
    }
    try {
        // the working buffer goes in the body; unedited, "current" is just the original
        const side = (key) => (key === 'current' ? (ed.dirty ? null : 'original') : key);
        const bufferSide = target.value === 'current' ? 'target' : base.value === 'current' ? 'base' : null;
        const bytes = bufferSide && ed.dirty ? ed.current : null;
        const res = await api.diff(ed.file.id, bytes, {
            ...params(),
            base: side(base.value),
            target: side(target.value),
            buffer_side: bytes ? bufferSide : null,
        });
        if (id !== requestId) return;
        error.value = null;
        result.value = res;
    } catch (e) {
        if (id === requestId) error.value = e.message;
    }
}

watch([base, target], compare);
watch(() => props.versionId, (v) => {
    if (v !== null) {
        target.value = `version:${v}`;
        base.value = 'original';
    }
});
watch(() => ed.revision, () => {
    if (target.value !== 'current' && base.value !== 'current') return;
    clearTimeout(timer);
    timer = setTimeout(compare, 800);
});
onMounted(compare);
onBeforeUnmount(() => clearTimeout(timer));
</script>
