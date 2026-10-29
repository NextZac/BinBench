<template>
    <div class="flex h-full min-h-0 flex-col overflow-y-auto scroll-thin">
        <div class="flex flex-wrap items-end gap-3 border-b border-line px-3 py-2 text-xs">
            <div class="min-w-0">
                <div class="text-sm font-semibold">Simulated dyno</div>
                <div class="text-[11px] text-muted">Estimated full-load curves from the limiter maps. Not a measurement.</div>
            </div>
            <EngineInputs class="ml-auto" :engine="engine" :derived="result?.engine" />
        </div>

        <div v-if="error" class="grid flex-1 place-items-center p-6 text-center text-xs text-bad">{{ error }}</div>
        <div v-else-if="result && !result.available" class="space-y-3 p-6 text-xs">
            <div class="text-center text-muted">{{ result.reason }}</div>
            <RolePicker :roles="result.roles" :choices="engine.roles" />
        </div>
        <div v-else-if="!result" class="grid flex-1 place-items-center text-xs text-muted">Calculating…</div>

        <template v-else>
            <DynoChart :result="result" />

            <!-- maps used + notes -->
            <div class="border-t border-line px-3 py-2">
                <div class="mb-1.5 flex justify-end">
                    <span v-if="result.calibrated" class="chip bg-ok/15 text-ok">Calibrated to stock power</span>
                    <span v-else class="chip bg-panel-2 text-muted">Uncalibrated</span>
                </div>
                <RolePicker :roles="result.roles" :choices="engine.roles" />
            </div>
            <div v-for="n in result.notes" :key="n" class="mx-3 mb-2 rounded border border-warn/30 bg-warn/10 p-2 text-[11px] text-yellow-200">{{ n }}</div>
        </template>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { useEngineInputs } from '../lib/engine';
import { useEditor } from '../stores/editor';
import DynoChart from './DynoChart.vue';
import EngineInputs from './EngineInputs.vue';
import RolePicker from './RolePicker.vue';

const ed = useEditor();
const result = ref(null);
const error = ref(null);
const { engine, params } = useEngineInputs(ed.file.id);
let timer = null;
let requestId = 0;

async function calculate() {
    const id = ++requestId;
    try {
        const res = await api.dyno(ed.file.id, ed.dirty ? ed.current : null, params());
        if (id !== requestId) return;
        error.value = null;
        result.value = res;
    } catch (e) {
        if (id === requestId) error.value = e.message;
    }
}

function schedule(delay = 500) {
    clearTimeout(timer);
    timer = setTimeout(calculate, delay);
}

watch(() => ed.revision, () => schedule(800));
watch(engine, () => schedule());
onMounted(calculate);
onBeforeUnmount(() => clearTimeout(timer));
</script>
