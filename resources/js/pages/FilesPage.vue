<template>
    <div class="mx-auto h-full max-w-6xl overflow-y-auto px-6 py-6 scroll-thin">
        <div
            class="grid cursor-pointer place-items-center rounded-xl border-2 border-dashed px-6 py-10 text-center transition"
            :class="dragging ? 'border-accent bg-accent/5' : 'border-line hover:border-accent/50'"
            @click="$refs.input.click()"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop">
            <input ref="input" type="file" multiple class="hidden" @change="onPick">
            <div class="text-base font-semibold">Drop an ECU read here</div>
            <div class="mt-1 text-xs text-muted">
                .bin / .ori dumps · Bosch ME7.x, EDC15, EDC16, EDC17 + generic map scan. Files are analysed on the server.
            </div>
            <div v-if="uploading" class="mt-3 text-xs text-accent">Analysing {{ uploading }}…</div>
        </div>

        <h2 class="mt-8 mb-2 text-sm font-semibold">Files</h2>
        <div class="overflow-hidden rounded-lg border border-line">
            <table class="w-full text-xs">
                <thead class="bg-panel text-left text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">ECU</th>
                        <th class="px-3 py-2 font-medium">Software</th>
                        <th class="px-3 py-2 text-right font-medium">Size</th>
                        <th class="px-3 py-2 text-right font-medium">Maps</th>
                        <th class="px-3 py-2 font-medium">Checksum</th>
                        <th class="px-3 py-2 font-medium">Added</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in files" :key="f.id" class="cursor-pointer border-t border-line hover:bg-panel"
                        @click="$router.push({ name: 'editor', params: { id: f.id } })">
                        <td class="px-3 py-2 font-medium">{{ f.name }}</td>
                        <td class="px-3 py-2">{{ f.family || '—' }}</td>
                        <td class="px-3 py-2 font-mono">{{ f.sw || '—' }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ (f.size / 1024).toFixed(0) }} KB</td>
                        <td class="px-3 py-2 text-right font-mono">{{ f.map_count }}</td>
                        <td class="px-3 py-2"><ChecksumBadge :ok="f.checksumOk" /></td>
                        <td class="px-3 py-2 text-muted">{{ new Date(f.created_at).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right">
                            <button class="btn btn-sm" @click.stop="remove(f)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!files.length">
                        <td colspan="8" class="px-3 py-6 text-center text-muted">No files yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="mt-8 mb-2 text-sm font-semibold">Definition library</h2>
        <p class="mb-2 text-xs text-muted">
            XDFs imported for a file are stored per software number and applied automatically to every file with the same SW.
        </p>
        <div class="overflow-hidden rounded-lg border border-line">
            <table class="w-full text-xs">
                <thead class="bg-panel text-left text-muted">
                    <tr>
                        <th class="px-3 py-2 font-medium">Definition</th>
                        <th class="px-3 py-2 font-medium">Software</th>
                        <th class="px-3 py-2 font-medium">Source</th>
                        <th class="px-3 py-2 text-right font-medium">Maps</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in definitions" :key="d.id" class="border-t border-line">
                        <td class="px-3 py-2">{{ d.name }}</td>
                        <td class="px-3 py-2 font-mono">{{ d.sw || 'any' }}</td>
                        <td class="px-3 py-2 text-muted">{{ d.source_filename }}</td>
                        <td class="px-3 py-2 text-right font-mono">{{ d.map_count }}</td>
                        <td class="px-3 py-2 text-right"><button class="btn btn-sm" @click="removeDefinition(d)">Delete</button></td>
                    </tr>
                    <tr v-if="!definitions.length">
                        <td colspan="5" class="px-3 py-6 text-center text-muted">Import an XDF from the editor to add one.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../lib/api';
import { toast, toastError } from '../lib/toast';
import ChecksumBadge from '../components/ChecksumBadge.vue';

const router = useRouter();
const files = ref([]);
const definitions = ref([]);
const dragging = ref(false);
const uploading = ref(null);

async function refresh() {
    [files.value, definitions.value] = await Promise.all([api.files(), api.definitions()]);
}

async function upload(list) {
    let last = null;
    for (const f of list) {
        uploading.value = f.name;
        try {
            last = await api.upload(f);
            toast(`${f.name}: ${last.family || 'unknown'} · ${last.maps.length} maps`);
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
const onPick = (e) => upload([...e.target.files]);

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
