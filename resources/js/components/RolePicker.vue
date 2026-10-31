<template>
    <div class="text-[11px]">
        <div class="mb-1.5 text-muted">
            <span class="font-semibold text-ink">Maps used</span>
            · picked automatically unless you choose one. ×N: Stage 1 changes all N maps of that role (hover to list them).
        </div>
        <div class="grid gap-1.5">
            <div v-for="row in rows" :key="row.role" class="flex items-center gap-1.5">
                <span class="w-28 shrink-0 text-muted" :title="row.group.join('\n')">{{ row.label }}<span v-if="row.group.length > 1" class="text-accent"> ×{{ row.group.length }}</span></span>
                <select class="input w-0 min-w-0 flex-1 py-1" :value="selectValue(row)" @change="pick(row, $event.target.value)">
                    <option value="">Auto: {{ row.auto ?? 'not found' }}</option>
                    <option v-for="c in row.options" :key="c.id" :value="c.id">{{ c.name }}</option>
                    <option value="none">None (ignore this role)</option>
                    <option value="__other">Other map…</option>
                </select>
                <button class="btn btn-sm btn-icon" :disabled="!row.id" aria-label="Open this map" title="Open this map" @click="ed.selectedId = row.id"><Icon name="open" :size="12" /></button>
            </div>
        </div>
        <div v-if="otherRole" class="mt-1.5 flex items-center gap-1.5">
            <span class="text-muted">Any map for {{ labels[otherRole] }}:</span>
            <input v-model="otherName" list="role-picker-maps" class="input min-w-0 flex-1 py-1" placeholder="Type to search map names" @change="pickOther">
            <datalist id="role-picker-maps">
                <option v-for="m in pickableMaps" :key="m.id" :value="m.name" />
            </datalist>
            <button class="btn btn-sm" @click="otherRole = null">Cancel</button>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { toastError } from '../lib/toast';
import { useEditor } from '../stores/editor';
import Icon from './Icon.vue';

// roles: result.roles from the API; choices: reactive role => map id ('none' switches it off).
const props = defineProps({
    roles: { type: Object, required: true },
    choices: { type: Object, required: true },
});
const ed = useEditor();
const otherRole = ref(null);
const otherName = ref('');

const labels = {
    wish: "Driver's wish",
    torqueLimit: 'Torque limiter',
    fullLoadLimit: 'Full-load limit',
    smoke: 'Smoke limiter',
    boost: 'Boost target',
    boostLimit: 'Boost limiter',
    ldrxn: 'Max load (LDRXN)',
    kfmirl: 'Torque → load',
};

const pickableMaps = computed(() => ed.maps.filter((m) => m.kind !== 'value'));
const rows = computed(() => Object.entries(props.roles)
    .filter(([role]) => labels[role])
    .map(([role, r]) => {
        const chosen = props.choices[role];
        const options = [...r.candidates];
        if (chosen && chosen !== 'none' && !options.some((c) => c.id === chosen)) {
            const map = ed.maps.find((m) => m.id === chosen);
            if (map) options.push({ id: map.id, name: map.name });
        }
        return { role, label: labels[role], id: r.id, group: r.group, auto: r.candidates[0]?.name ?? null, options };
    }));

function selectValue(row) {
    return props.choices[row.role] ?? '';
}

function pick(row, value) {
    if (value === '__other') {
        otherRole.value = row.role;
        otherName.value = '';
        return;
    }
    if (value) {
        props.choices[row.role] = value;
    } else {
        delete props.choices[row.role];
    }
}

function pickOther() {
    const map = pickableMaps.value.find((m) => m.name === otherName.value);
    if (!map) return toastError('No map with that name');
    props.choices[otherRole.value] = map.id;
    otherRole.value = null;
}
</script>
