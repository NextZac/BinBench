<template>
    <div class="relative h-full overflow-y-auto scroll-thin" @dragenter.prevent="dragging = true" @dragover.prevent>
        <div class="mx-auto max-w-6xl px-6 py-6">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <h1 class="text-[18px] font-semibold tracking-[-0.01em]">Files</h1>
                    <p class="text-[12px] text-muted">{{ files.length }} {{ files.length === 1 ? 'read' : 'reads' }} · drop .bin / .ori / .fls anywhere on this page</p>
                </div>
                <div class="ml-auto flex items-center gap-2">
                    <label class="relative">
                        <span class="sr-only">Filter files</span>
                        <Icon name="search" :size="13" class="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-muted" />
                        <input v-model="query" class="input w-56 pl-7" placeholder="Filter by name, ECU or SW">
                    </label>
                    <button class="btn btn-primary" :disabled="!!uploading" @click="$refs.input.click()">
                        <Icon name="upload" :size="13" /> Open file…
                    </button>
                    <input ref="input" type="file" multiple class="hidden" @change="onPick">
                </div>
            </div>

            <p v-if="uploading" class="mt-3 font-mono text-[12px] text-accent">Analysing {{ uploading }}…</p>

            <div class="mt-4 overflow-x-auto scroll-thin">
            <table class="w-full min-w-[640px] border-t border-line text-[12px]">
                <thead class="text-left">
                    <tr class="border-b border-line">
                        <th class="label w-[34%] py-1.5 pr-3 font-medium">Name</th>
                        <th class="label w-[22%] py-1.5 pr-3 font-medium">ECU</th>
                        <th class="label py-1.5 pr-3 font-medium">Software</th>
                        <th class="label py-1.5 pr-3 text-right font-medium">Size</th>
                        <th class="label py-1.5 pr-3 text-right font-medium">Maps</th>
                        <th class="label py-1.5 pr-3 font-medium">Checksum</th>
                        <th class="label py-1.5 pr-3 font-medium">Added</th>
                        <th class="w-8"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in shownFiles" :key="f.id" class="group cursor-pointer border-b border-line/70 hover:bg-panel"
                        @click="open(f)">
                        <td class="max-w-0 py-1.5 pr-3">
                            <router-link :to="{ name: 'editor', params: { id: f.id } }" class="block truncate font-medium text-ink" :title="f.name" @click.stop>{{ f.name }}</router-link>
                        </td>
                        <td class="max-w-0 truncate py-1.5 pr-3" :class="f.family ? '' : 'text-muted'" :title="f.family">{{ f.family || 'not recognised' }}</td>
                        <td class="py-1.5 pr-3 font-mono">{{ f.sw || '—' }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono whitespace-nowrap">{{ size(f.size) }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono">{{ f.map_count }}</td>
                        <td class="py-1.5 pr-3"><ChecksumBadge :ok="f.checksumOk" /></td>
                        <td class="py-1.5 pr-3 whitespace-nowrap text-muted" :title="new Date(f.created_at).toLocaleString()">{{ when(f.created_at) }}</td>
                        <td class="py-1 text-right">
                            <button class="btn btn-quiet btn-icon btn-danger opacity-0 group-hover:opacity-100 focus-visible:opacity-100" :aria-label="`Delete ${f.name}`" @click.stop="remove(f)">
                                <Icon name="trash" :size="13" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
            <div v-if="loaded && !files.length" class="mt-6 max-w-lg text-[12px] text-muted">
                <p class="text-ink">No reads yet.</p>
                <p class="mt-1">Open a full flash read or a calibration read. Supported: Bosch ME7.x, ME9.0, EDC15, EDC16 and EDC17; anything else gets a generic table scan.</p>
            </div>
            <p v-else-if="files.length && !shownFiles.length" class="mt-4 text-[12px] text-muted">Nothing matches “{{ query }}”.</p>

            <h2 class="mt-10 text-[14px] font-semibold">Definition library</h2>
            <p class="text-[12px] text-muted">XDFs imported in the editor, reused automatically for every read with the same software number.</p>
            <table class="mt-3 w-full border-t border-line text-[12px]">
                <thead class="text-left">
                    <tr class="border-b border-line">
                        <th class="label py-1.5 pr-3 font-medium">Definition</th>
                        <th class="label py-1.5 pr-3 font-medium">Software</th>
                        <th class="label py-1.5 pr-3 font-medium">Imported from</th>
                        <th class="label py-1.5 pr-3 text-right font-medium">Maps</th>
                        <th class="w-8"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in definitions" :key="d.id" class="group border-b border-line/70 hover:bg-panel">
                        <td class="py-1.5 pr-3">{{ d.name }}</td>
                        <td class="py-1.5 pr-3 font-mono">{{ d.sw || 'any' }}</td>
                        <td class="py-1.5 pr-3 font-mono text-muted">{{ d.source_filename }}</td>
                        <td class="py-1.5 pr-3 text-right font-mono">{{ d.map_count }}</td>
                        <td class="py-1 text-right">
                            <button class="btn btn-quiet btn-icon btn-danger opacity-0 group-hover:opacity-100 focus-visible:opacity-100" :aria-label="`Delete definition ${d.name}`" @click="removeDefinition(d)">
                                <Icon name="trash" :size="13" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="loaded && !definitions.length" class="mt-3 text-[12px] text-muted">None yet. Use Import XDF in the editor's File menu.</p>
        </div>

        <!-- whole-page drop target -->
        <div v-if="dragging" class="absolute inset-0 z-30 grid place-items-center bg-bg/85" @dragleave.self="dragging = false" @dragover.prevent @drop.prevent="onDrop">
            <div class="pointer-events-none border border-accent-fill bg-panel px-6 py-4 text-center shadow-[var(--bb-shadow)]">
                <Icon name="upload" :size="18" class="mx-auto text-accent" />
                <div class="mt-1 text-[13px] font-medium">Release to analyse</div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { toast, toastError } from '../lib/toast';
import ChecksumBadge from '../components/ChecksumBadge.vue';
import Icon from '../components/Icon.vue';

const router = useRouter();
const files = ref([]);
const definitions = ref([]);
const dragging = ref(false);
const uploading = ref(null);
const loaded = ref(false);
const query = ref('');

const shownFiles = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return files.value;
    return files.value.filter((f) => [f.name, f.family, f.sw].some((v) => v?.toLowerCase().includes(q)));
});

const size = (bytes) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(bytes % (1024 * 1024) ? 1 : 0)} MB` : `${Math.round(bytes / 1024)} KB`);

function when(date) {
    const d = new Date(date);
    const days = Math.floor((Date.now() - d) / 86400000);
    if (days === 0) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (days < 7) return d.toLocaleDateString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString();
}

async function refresh() {
    [files.value, definitions.value] = await Promise.all([api.files(), api.definitions()]);
    loaded.value = true;
}

function open(f) {
    router.push({ name: 'editor', params: { id: f.id } });
}

async function upload(list) {
    let last = null;
    for (const f of list) {
        uploading.value = f.name;
        try {
            last = await api.upload(f);
            toast(`${f.name}: ${last.family || 'not recognised'}, ${last.maps.length} maps`);
        } catch (e) {
            toastError(e);
        }
    }
    uploading.value = null;
    if (list.length === 1 && last) {
        router.push({ name: 'editor', params: { id: last.id } });
    } else {
        refresh();
    }
}

const onDrop = (e) => {
    dragging.value = false;
    upload([...e.dataTransfer.files]);
};
const onPick = (e) => {
    upload([...e.target.files]);
    e.target.value = '';
};

async function remove(f) {
    if (!confirm(`Delete ${f.name} and all its versions?`)) return;
    await api.remove(f.id).catch(toastError);
    refresh();
}

async function removeDefinition(d) {
    if (!confirm(`Delete definition ${d.name}?`)) return;
    await api.removeDefinition(d.id).catch(toastError);
    refresh();
}

onMounted(() => refresh().catch(toastError));
</script>
