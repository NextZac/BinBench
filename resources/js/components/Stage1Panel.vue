<template>
    <div class="flex h-full min-h-0 flex-col overflow-y-auto scroll-thin">
        <div class="flex flex-wrap items-end gap-3 border-b border-line px-3 py-2 text-xs">
            <div class="min-w-0">
                <div class="text-sm font-semibold">Stage 1 assistant</div>
                <div class="text-[11px] text-muted">Rule-based proposal within the limits you set. Review every change before applying.</div>
            </div>
            <EngineInputs class="ml-auto" :engine="engine" :derived="result?.dyno?.engine" />
        </div>

        <!-- limits -->
        <div class="flex flex-wrap items-end gap-3 border-b border-line px-3 py-2 text-xs">
            <label v-for="f in fields" :key="f.key" :title="f.hint">{{ f.label }}
                <input v-model.number="options[f.key]" type="number" :step="f.step ?? 1" :min="f.min" :max="f.max" :placeholder="f.placeholder ?? ''" class="input mt-1 block w-24">
            </label>
            <button class="btn btn-primary ml-auto" :disabled="loading" @click="generate">{{ loading ? 'Working…' : 'Generate proposal' }}</button>
        </div>

        <div v-if="result?.roles" class="border-b border-line px-3 py-2">
            <RolePicker :roles="result.roles" :choices="engine.roles" />
        </div>

        <div v-if="error" class="p-6 text-center text-xs text-bad">{{ error }}</div>
        <div v-else-if="result && !result.available" class="space-y-1.5 p-6 text-center text-xs text-muted">
            <div>{{ result.reason }}</div>
            <div v-for="w in result.warnings" :key="w" class="text-yellow-200">{{ w }}</div>
        </div>
        <div v-else-if="!result" class="p-6 text-center text-xs text-muted">Calculating…</div>

        <template v-else>
            <!-- proposals -->
            <div class="space-y-1.5 p-3">
                <div v-for="p in result.proposals" :key="p.mapId" class="flex gap-2 rounded-md border border-line bg-panel-2 p-2 text-xs"
                    :class="selected[p.mapId] ? '' : 'opacity-50'">
                    <input v-model="selected[p.mapId]" type="checkbox" class="mt-0.5" :aria-label="`Include ${p.name}`">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <button class="text-left font-semibold hover:text-accent" @click="ed.selectedId = p.mapId">{{ p.name }}</button>
                            <span class="font-mono text-[11px] text-muted">{{ range(p.before, p.unit) }} → <span class="text-accent">{{ range(p.after, p.unit) }}</span></span>
                            <span class="ml-auto text-[11px] text-muted">{{ p.changedCells }} cells</span>
                        </div>
                        <div class="mt-0.5 text-[11px] text-muted">{{ p.summary }}</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 pt-1">
                    <button class="btn btn-primary" :disabled="!selectedCount || applied" @click="apply">Apply {{ selectedCount }} change{{ selectedCount === 1 ? '' : 's' }}</button>
                    <span v-if="applied" class="text-[11px] text-ok">Applied. Generating again would add the increase on top of these values.</span>
                    <span v-else class="text-[11px] text-muted">One undo step per map. The dyno preview below includes all proposed changes.</span>
                </div>
            </div>

            <div class="space-y-1.5 px-3">
                <div v-for="w in result.warnings" :key="w" class="rounded border border-warn/30 bg-warn/10 p-2 text-[11px] text-yellow-200">{{ w }}</div>
                <div v-for="n in result.notes" :key="n" class="text-[11px] text-muted">• {{ n }}</div>
            </div>

            <DynoChart v-if="result.dyno?.available" class="mt-2 border-t border-line" :result="result.dyno" modified-label="Stage 1" />

            <!-- AI review -->
            <div class="border-t border-line p-3 text-xs">
                <div class="flex items-center gap-2">
                    <span class="font-semibold">AI review</span>
                    <span v-if="aiModel" class="chip bg-panel-2 text-muted">{{ aiModel }}</span>
                    <button class="btn btn-sm ml-auto" :disabled="!result.prompt" title="Copy instructions and a summary of this proposal to paste into Copilot Chat, ChatGPT, DeepSeek or similar" @click="copyPrompt">{{ copied ? 'Copied ✓' : 'Copy prompt' }}</button>
                    <button class="btn btn-sm" @click="showPrompt = !showPrompt">{{ showPrompt ? 'Hide prompt' : 'Show prompt' }}</button>
                    <button v-if="result.aiConfigured" class="btn btn-sm" :disabled="explaining" @click="explain">{{ explaining ? 'Asking…' : explanation ? 'Ask again' : 'Explain with AI' }}</button>
                </div>
                <p v-if="!result.aiConfigured" class="mt-1 text-[11px] text-muted">
                    Copy the prompt and paste it into any chat assistant (Copilot Chat, ChatGPT, DeepSeek…). It holds map names, value ranges, warnings and estimated power, not the file.
                    To get the review here instead, set TUNING_AI_BASE_URL, TUNING_AI_API_KEY and TUNING_AI_MODEL in .env (any OpenAI-compatible API).
                </p>
                <textarea v-if="showPrompt" ref="promptBox" readonly :value="result.prompt" rows="14"
                    class="input mt-2 w-full font-mono text-[11px] scroll-thin" @focus="$event.target.select()" />
                <p v-else-if="!explanation && !explainError" class="mt-1 text-[11px] text-muted">Sends a summary (map names, value ranges, warnings, estimated power) to the configured model. The file itself is not sent.</p>
                <div v-if="explainError" class="mt-2 text-bad">{{ explainError }}</div>
                <div v-if="explanation" class="mt-2 space-y-1 leading-relaxed">
                    <template v-for="(b, i) in explanationBlocks" :key="i">
                        <div v-if="b.type === 'h'" class="pt-1 font-semibold">{{ b.text }}</div>
                        <div v-else-if="b.type === 'li'" class="flex gap-1.5 pl-1"><span class="text-accent">•</span><span>{{ b.text }}</span></div>
                        <p v-else>{{ b.text }}</p>
                    </template>
                    <p class="pt-1 text-[11px] text-muted">AI text can be wrong. The values above come from the rules, not from the model.</p>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { api } from '../lib/api';
import { writeCell } from '../lib/binary';
import { useEngineInputs } from '../lib/engine';
import { toast } from '../lib/toast';
import { useEditor } from '../stores/editor';
import DynoChart from './DynoChart.vue';
import EngineInputs from './EngineInputs.vue';
import RolePicker from './RolePicker.vue';

const ed = useEditor();
const { engine, params } = useEngineInputs(ed.file.id);
const diesel = (ed.file.ident?.fuel ?? '') === 'Diesel' || (ed.file.family_id ?? '').startsWith('edc');
const options = reactive(diesel
    ? { fuel_increase: 15, boost_increase: 8, max_boost: 2600, max_fuel: null, max_torque: null }
    : { load_increase: 10, max_load: 180, max_torque: null });
const fields = diesel
    ? [
        { key: 'fuel_increase', label: 'Fuel +%', min: 0, max: 40, hint: 'Increase of the fuel limits at full load' },
        { key: 'boost_increase', label: 'Boost +%', min: 0, max: 25, hint: 'Increase of the boost target at high fuel quantity' },
        { key: 'max_boost', label: 'Max boost (mbar)', min: 1000, max: 4000, step: 50, hint: 'Absolute boost cap. Check what your turbo is rated for.' },
        { key: 'max_fuel', label: 'Max fuel (mg/st)', min: 5, max: 200, placeholder: 'none', hint: 'Cap for every fuel limit, e.g. what the injectors can deliver' },
        { key: 'max_torque', label: 'Max torque (Nm)', min: 50, max: 2000, placeholder: 'none', hint: 'Clutch or gearbox limit; warns when the estimate exceeds it' },
    ]
    : [
        { key: 'load_increase', label: 'Load +%', min: 0, max: 40, hint: 'Increase of the maximum load (LDRXN)' },
        { key: 'max_load', label: 'Max load (%)', min: 50, max: 250, hint: 'Absolute load cap. Stock MAP sensors read up to about 2.5 bar.' },
        { key: 'max_torque', label: 'Max torque (Nm)', min: 50, max: 2000, placeholder: 'none', hint: 'Clutch or gearbox limit; warns when the estimate exceeds it' },
    ];

const result = ref(null);
const error = ref(null);
const loading = ref(false);
const selected = reactive({});
const explanation = ref('');
const explainError = ref(null);
const explaining = ref(false);
const aiModel = ref(null);
const applied = ref(false);
const showPrompt = ref(false);
const promptBox = ref(null);
const copied = ref(false);

const selectedCount = computed(() => (result.value?.proposals ?? []).filter((p) => selected[p.mapId]).length);
const explanationBlocks = computed(() => explanation.value.split('\n').map((l) => l.trim()).filter(Boolean).map((line) => {
    const text = line.replace(/\*\*(.+?)\*\*/g, '$1').replace(/`([^`]+)`/g, '$1');
    if (/^#{1,6}\s/.test(text)) return { type: 'h', text: text.replace(/^#+\s*/, '') };
    if (/^([-*•]|\d+[.)])\s/.test(text)) return { type: 'li', text: text.replace(/^([-*•]|\d+[.)])\s*/, '') };
    return { type: 'p', text };
}));

const requestParams = () => ({ ...params(), ...options });
const buffer = () => (ed.dirty ? ed.current : null);

function range(r, unit) {
    const f = (v) => (Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10);
    return `${f(r.min)}–${f(r.max)}${unit ? ' ' + unit : ''}`;
}

async function generate() {
    loading.value = true;
    explanation.value = '';
    explainError.value = null;
    applied.value = false;
    try {
        result.value = await api.stage1(ed.file.id, buffer(), requestParams());
        error.value = null;
        for (const p of result.value.proposals ?? []) selected[p.mapId] = true;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function apply() {
    const chosen = result.value.proposals.filter((p) => selected[p.mapId]);
    for (const p of chosen) {
        const map = ed.maps.find((m) => m.id === p.mapId);
        if (!map) continue;
        ed.transact(map, (buf) => {
            p.values.forEach((row, r) => row.forEach((v, c) => writeCell(buf, map, r, c, v)));
        });
    }
    applied.value = true;
    toast(`Applied ${chosen.length} Stage 1 change${chosen.length === 1 ? '' : 's'}. Check them in the map view and on the dyno.`);
}

async function explain() {
    explaining.value = true;
    explainError.value = null;
    try {
        const res = await api.explainStage1(ed.file.id, buffer(), requestParams());
        explanation.value = res.explanation;
        aiModel.value = res.model;
    } catch (e) {
        explainError.value = e.message;
    } finally {
        explaining.value = false;
    }
}

async function copyPrompt() {
    try {
        await navigator.clipboard.writeText(result.value.prompt);
    } catch {
        // clipboard API blocked (e.g. not a secure context): show the text selected for manual copying
        showPrompt.value = true;
        await nextTick();
        promptBox.value?.select();
        if (!document.execCommand('copy')) {
            toast('Press Ctrl+C / Cmd+C to copy the selected prompt.');
            return;
        }
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

// a different map choice means a different proposal
watch(() => JSON.stringify(engine.roles), generate);
onMounted(generate);
</script>
