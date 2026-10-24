<template>
    <div class="space-y-3 text-xs">
        <div class="grid grid-cols-2 gap-2">
            <label class="col-span-2">Name<input v-model="form.name" class="input mt-1 w-full"></label>
            <label class="col-span-2">Category<input v-model="form.category" class="input mt-1 w-full" list="cats"></label>
            <datalist id="cats"><option v-for="c in categories" :key="c" :value="c" /></datalist>
            <label>Address (hex)<input v-model="addressHex" class="input mt-1 w-full font-mono"></label>
            <label>Type
                <select v-model="form.dtype" class="input mt-1 w-full">
                    <option v-for="t in types" :key="t">{{ t }}</option>
                </select>
            </label>
            <label>Rows<input v-model.number="form.rows" type="number" min="1" class="input mt-1 w-full"></label>
            <label>Cols<input v-model.number="form.cols" type="number" min="1" class="input mt-1 w-full"></label>
            <label>Factor<input v-model.number="form.factor" type="number" step="any" class="input mt-1 w-full font-mono"></label>
            <label>Offset<input v-model.number="form.offset" type="number" step="any" class="input mt-1 w-full font-mono"></label>
            <label>Unit<input v-model="form.unit" class="input mt-1 w-full"></label>
            <label>Decimals<input v-model.number="form.decimals" type="number" min="0" max="6" class="input mt-1 w-full"></label>
            <label class="flex items-center gap-1.5"><input v-model="form.be" type="checkbox"> Big-endian</label>
            <label class="flex items-center gap-1.5"><input v-model="form.colMajor" type="checkbox"> Column-major</label>
        </div>

        <details v-for="ax in ['x', 'y']" :key="ax" class="rounded border border-line p-2">
            <summary class="cursor-pointer text-muted">{{ ax.toUpperCase() }} axis {{ form[ax] ? `(${form[ax].count} pts${form[ax].address != null ? ' @ ' + hex(form[ax].address) : ''})` : '(none)' }}</summary>
            <div v-if="form[ax]" class="mt-2 grid grid-cols-2 gap-2">
                <label>Name<input v-model="form[ax].name" class="input mt-1 w-full"></label>
                <label>Unit<input v-model="form[ax].unit" class="input mt-1 w-full"></label>
                <label>Factor<input v-model.number="form[ax].factor" type="number" step="any" class="input mt-1 w-full font-mono"></label>
                <label>Offset<input v-model.number="form[ax].offset" type="number" step="any" class="input mt-1 w-full font-mono"></label>
                <label>Decimals<input v-model.number="form[ax].decimals" type="number" min="0" class="input mt-1 w-full"></label>
            </div>
        </details>

        <label class="block">Description<textarea v-model="form.description" rows="3" class="input mt-1 w-full" /></label>

        <div class="flex gap-2">
            <button class="btn btn-primary" @click="save">Save definition</button>
            <button class="btn" @click="duplicate">Duplicate</button>
            <button class="btn ml-auto" @click="remove">Delete</button>
        </div>
        <p class="text-[11px] text-muted">Source: {{ map.source }} · confidence {{ Math.round(map.confidence * 100) }}%</p>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { hex } from '../lib/binary';
import { useEditor } from '../stores/editor';
import { toast, toastError } from '../lib/toast';

const props = defineProps({ map: { type: Object, required: true } });
const ed = useEditor();
const types = ['u8', 's8', 'u16', 's16', 'u32', 's32', 'f32'];
const form = ref({});
const addressHex = ref('');

watch(() => props.map, (m) => {
    form.value = JSON.parse(JSON.stringify(m));
    addressHex.value = m.address.toString(16).toUpperCase();
}, { immediate: true });

const categories = computed(() => [...new Set(ed.maps.map((m) => m.category))]);

function normalized() {
    const f = { ...form.value, address: parseInt(addressHex.value, 16) };
    f.kind = f.rows > 1 && f.cols > 1 ? 'map' : (f.rows * f.cols > 1 ? 'curve' : 'value');
    f.source = f.source === 'scan' || f.source === 'pattern' ? 'user' : f.source;
    f.confidence = 1;
    return f;
}

async function persist(maps, msg) {
    try {
        await ed.saveMaps(maps);
        toast(msg);
    } catch (e) {
        toastError(e);
    }
}

function save() {
    const f = normalized();
    if (!Number.isFinite(f.address) || f.address < 0 || f.address >= ed.current.length) return toastError('Invalid address');
    persist(ed.maps.map((m) => (m.id === props.map.id ? f : m)), 'Definition saved');
}

function duplicate() {
    const f = normalized();
    f.id = `${f.name.replace(/\W+/g, '_')}_copy@${f.address.toString(16)}_${Date.now() % 10000}`;
    f.name += ' (copy)';
    persist([...ed.maps, f], 'Map duplicated').then(() => (ed.selectedId = f.id));
}

function remove() {
    if (!confirm(`Remove definition "${props.map.name}"? (data in the bin is not touched)`)) return;
    persist(ed.maps.filter((m) => m.id !== props.map.id), 'Definition removed');
}
</script>
