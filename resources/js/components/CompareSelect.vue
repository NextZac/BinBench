<template>
    <select :value="modelValue" class="input max-w-48 py-1" @change="$emit('update:modelValue', $event.target.value)">
        <option value="original">Original</option>
        <option v-if="includeCurrent" value="current">Current edits</option>
        <optgroup v-if="ed.file.versions?.length" label="Saved versions">
            <option v-for="v in ed.file.versions" :key="v.id" :value="`version:${v.id}`">{{ v.label }}</option>
        </optgroup>
        <optgroup v-if="ed.compareFiles.length" label="Other files (same size)">
            <option v-for="f in ed.compareFiles" :key="f.id" :value="`file:${f.id}`">
                {{ f.name }}{{ f.sw && f.sw !== ed.file.sw ? ` · SW ${f.sw} ≠` : '' }}
            </option>
        </optgroup>
    </select>
</template>

<script setup>
import { onMounted } from 'vue';
import { toastError } from '../lib/toast';
import { useEditor } from '../stores/editor';

// Picks a compare source: 'original', 'current', 'version:{id}' or 'file:{id}'.
defineProps({
    modelValue: { type: String, required: true },
    includeCurrent: { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);

const ed = useEditor();

onMounted(() => {
    if (!ed.compareFiles.length) ed.loadCompareFiles().catch(toastError);
});
</script>
