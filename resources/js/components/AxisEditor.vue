<template>
    <div class="fixed inset-0 z-40 grid place-items-center bg-black/50" @click.self="$emit('close')" @keydown.esc="$emit('close')">
        <div class="flex max-h-[85vh] w-[420px] flex-col border border-line bg-panel text-[12px] shadow-[var(--bb-shadow)]" role="dialog" aria-modal="true" aria-labelledby="axis-title">
            <div class="flex items-center border-b border-line px-4 py-2.5">
                <div>
                    <h2 id="axis-title" class="text-[13px] font-semibold">{{ which === 'x' ? 'X' : 'Y' }} axis of {{ map.name }}</h2>
                    <div class="font-mono text-[11px] text-muted">{{ axis.name || 'breakpoints' }} · {{ axis.unit || 'raw' }} · {{ hex(axis.address) }} · {{ count }} × {{ axis.dtype }}</div>
                </div>
                <button class="btn btn-quiet btn-icon ml-auto" aria-label="Close" @click="$emit('close')"><Icon name="close" /></button>
            </div>

            <p v-if="users.length" class="note mx-4 mt-3">
                This axis is shared with {{ users.length }} other map{{ users.length === 1 ? '' : 's' }}
                ({{ users.slice(0, 3).map((m) => m.name).join(', ') }}{{ users.length > 3 ? ', …' : '' }}). Changing it moves their breakpoints too.
            </p>

            <div class="flex flex-wrap items-center gap-1.5 border-b border-line px-4 py-2">
                <button class="btn btn-sm" title="Keep the first and last value, space the rest evenly" @click="spread">Spread evenly</button>
                <input v-model="operand" class="input h-6 w-20 font-mono" placeholder="value" aria-label="Value for scale or shift">
                <button class="btn btn-sm" title="Multiply every breakpoint" @click="apply('mul')">× Scale</button>
                <button class="btn btn-sm" title="Add to every breakpoint" @click="apply('add')">+ Shift</button>
                <button class="btn btn-quiet btn-sm ml-auto" title="Back to the values in the original file" @click="values = original.slice()">Original</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-4 py-2 scroll-thin">
                <table class="w-full font-mono text-[12px]">
                    <thead>
                        <tr class="text-left">
                            <th class="label w-8 py-1 font-medium">#</th>
                            <th class="label py-1 text-right font-medium">Original</th>
                            <th class="label py-1 pl-4 font-medium">New</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(v, i) in values" :key="i" class="border-b border-line/60">
                            <td class="py-0.5 text-muted">{{ i }}</td>
                            <td class="py-0.5 text-right text-muted">{{ fmt(original[i], decimals) }}</td>
                            <td class="py-0.5 pl-4">
                                <input v-model.number="values[i]" type="number" step="any" class="input h-6 w-28 text-right font-mono"
                                    :class="[badIndex.has(i) ? 'border-bad' : '', v !== original[i] ? 'text-mod' : '']" :aria-label="`Breakpoint ${i}`">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="problem" class="note mx-4 mb-2 border-bad text-bad" role="alert">{{ problem }}</p>
            <div class="flex justify-end gap-2 border-t border-line px-4 py-2.5">
                <button class="btn" @click="$emit('close')">Cancel</button>
                <button class="btn btn-primary" :disabled="!!problem || !dirty" @click="save">Apply</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { fmt, hex, readAxis, writeAxisValue } from '../lib/binary';
import { toast, toastError } from '../lib/toast';
import { useEditor } from '../stores/editor';
import Icon from './Icon.vue';

// Edit every breakpoint of a stored axis at once; written as a single undo step.
const props = defineProps({
    map: { type: Object, required: true },
    which: { type: String, required: true },
});
const emit = defineEmits(['close']);
const ed = useEditor();

const axis = computed(() => props.map[props.which]);
const count = computed(() => (props.which === 'x' ? props.map.cols : props.map.rows));
const decimals = computed(() => axis.value.decimals ?? 2);
const original = readAxis(ed.original, axis.value, count.value);
const values = ref(readAxis(ed.current, axis.value, count.value));
const operand = ref('');
const users = computed(() => ed.axisUsers(axis.value.address, props.map));

const dirty = computed(() => values.value.some((v, i) => v !== readAxis(ed.current, axis.value, count.value)[i]));

// breakpoints must keep the original direction and stay strictly ordered, or the ECU's lookup breaks
const increasing = original[original.length - 1] >= original[0];
const badIndex = computed(() => {
    const bad = new Set();
    values.value.forEach((v, i) => {
        if (!Number.isFinite(v)) bad.add(i);
        if (i > 0 && (increasing ? v <= values.value[i - 1] : v >= values.value[i - 1])) bad.add(i);
    });
    return bad;
});
const problem = computed(() => {
    if (!badIndex.value.size) return null;
    const i = [...badIndex.value][0];
    return Number.isFinite(values.value[i])
        ? `Breakpoint ${i} must be ${increasing ? 'greater' : 'smaller'} than breakpoint ${i - 1}: axes have to stay in order.`
        : `Breakpoint ${i} is not a number.`;
});

// drop floating-point noise (935.0000000000001) from computed values
const clean = (v) => Math.round(v * 1e6) / 1e6;

function spread() {
    const n = values.value.length;
    const [a, b] = [values.value[0], values.value[n - 1]];
    values.value = values.value.map((_, i) => clean(a + ((b - a) * i) / (n - 1)));
}

function apply(op) {
    const n = parseFloat(String(operand.value).replace(',', '.'));
    if (!Number.isFinite(n)) return;
    values.value = values.value.map((v) => clean(op === 'mul' ? v * n : v + n));
}

function save() {
    if (problem.value) return;
    ed.transact(props.map, (buf) => {
        values.value.forEach((v, i) => writeAxisValue(buf, axis.value, i, v));
    });
    const stored = readAxis(ed.current, axis.value, count.value);
    // rounding to the stored resolution can make two close breakpoints equal: refuse that
    if (stored.some((v, i) => i > 0 && (increasing ? v <= stored[i - 1] : v >= stored[i - 1]))) {
        ed.undo();
        ed.redoStack.pop();
        toastError('Two breakpoints become equal at the resolution the ECU stores. Space them further apart.');
        return;
    }
    const rounded = stored.some((v, i) => Math.abs(v - values.value[i]) > 1e-9);
    toast(`${props.which.toUpperCase()} axis updated${rounded ? ' (values rounded to what the ECU can store)' : ''}${users.value.length ? `, also used by ${users.value.length} other map(s)` : ''}`);
    emit('close');
}
</script>
