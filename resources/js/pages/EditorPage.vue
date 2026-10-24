<template>
    <div v-if="loading" class="grid h-full place-items-center text-muted">Loading…</div>
    <div v-else-if="ed.file" class="flex h-full flex-col">
        <!-- file header -->
        <div class="flex flex-wrap items-center gap-3 border-b border-line bg-panel px-4 py-2">
            <div class="min-w-0">
                <div class="truncate font-semibold">{{ ed.file.name }}</div>
                <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-muted">
                    <span class="chip bg-accent/15 text-accent">{{ ed.file.family || 'Unknown ECU' }}</span>
                    <span v-if="ident.sw" class="font-mono">SW {{ ident.sw }}</span>
                    <span v-if="ident.hw" class="font-mono">· HW {{ ident.hw }}</span>
                    <span v-if="ident.partNumber" class="font-mono">· {{ ident.partNumber }}</span>
                    <span v-if="ident.swVersion" class="font-mono">· {{ ident.swVersion }}</span>
                    <span v-if="ident.engine">· {{ ident.engine }}</span>
                    <span>· {{ (ed.file.size / 1024).toFixed(0) }} KB · {{ ed.maps.length }} maps</span>
                </div>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-1.5">
                <span v-if="ed.changedMapIds.size" class="text-[11px] text-accent">{{ ed.changedMapIds.size }} map(s) modified · {{ diffBytes }} bytes</span>
                <button class="btn" :disabled="!ed.undoStack.length" title="Ctrl+Z" @click="ed.undo()">↶ Undo</button>
                <button class="btn" :disabled="!ed.redoStack.length" title="Ctrl+Y" @click="ed.redo()">↷ Redo</button>
                <span class="mx-1 h-5 w-px bg-line" />
                <button class="btn" @click="$refs.xdfInput.click()">Import XDF</button>
                <input ref="xdfInput" type="file" accept=".xdf,.xml" class="hidden" @change="importXdf">
                <a class="btn" :href="`/api/files/${ed.file.id}/xdf`">Export XDF</a>
                <a class="btn" :href="`/api/files/${ed.file.id}/raw`" :download="ed.file.original_filename">Original</a>
                <button class="btn btn-primary" @click="saveDialog = true">Save / Download…</button>
            </div>
        </div>

        <div class="flex min-h-0 flex-1">
            <!-- map list -->
            <aside class="w-72 shrink-0 border-r border-line bg-panel">
                <MapList />
            </aside>

            <!-- map area -->
            <section class="flex min-w-0 flex-1 flex-col">
                <template v-if="map">
                    <div class="flex items-center gap-3 border-b border-line px-3 py-2">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold">{{ map.name }}</div>
                            <div class="truncate text-[11px] text-muted">{{ map.description }}</div>
                        </div>
                        <div class="ml-auto flex items-center gap-1.5">
                            <span class="font-mono text-[11px] text-muted">{{ hex(map.address) }} · {{ map.rows }}×{{ map.cols }} · {{ map.unit || 'raw' }}</span>
                            <div class="flex overflow-hidden rounded-md border border-line">
                                <button v-for="v in views" :key="v" class="px-2.5 py-1 text-[11px]"
                                    :class="view === v ? 'bg-accent text-black' : 'bg-panel-2 text-muted hover:text-ink'"
                                    @click="view = v">{{ v }}</button>
                            </div>
                            <button class="btn btn-sm" :disabled="!ed.changedMapIds.has(map.id)" @click="ed.resetMap(map)">Reset map</button>
                        </div>
                    </div>
                    <div v-if="shownView === 'Table' || shownView === 'Split'" class="flex min-h-0 flex-col" :class="shownView === 'Split' ? 'h-1/2' : 'flex-1'">
                        <MapTable :map="map" />
                    </div>
                    <div v-if="shownView === '3D' || shownView === 'Split'" class="min-h-0 border-t border-line" :class="shownView === 'Split' ? 'h-1/2' : 'flex-1'">
                        <MapChart :map="map" />
                    </div>
                    <div v-if="shownView === 'Hex'" class="min-h-0 flex-1">
                        <HexView :map="map" />
                    </div>
                </template>
                <div v-else class="grid flex-1 place-items-center text-muted">Select a map</div>
            </section>

            <!-- side panel -->
            <aside class="flex w-80 shrink-0 flex-col border-l border-line bg-panel">
                <div class="flex border-b border-line">
                    <button v-for="t in sideTabs" :key="t" class="flex-1 py-2 text-[11px] font-medium"
                        :class="side === t ? 'text-accent shadow-[inset_0_-2px_0_var(--color-accent)]' : 'text-muted'"
                        @click="side = t">{{ t }}</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-3 scroll-thin">
                    <!-- ECU -->
                    <div v-if="side === 'ECU'" class="space-y-3 text-xs">
                        <dl class="grid grid-cols-[90px_1fr] gap-x-2 gap-y-1">
                            <template v-for="row in identRows" :key="row[0]">
                                <dt class="text-muted">{{ row[0] }}</dt>
                                <dd class="font-mono break-all">{{ row[1] }}</dd>
                            </template>
                        </dl>
                        <div v-for="n in ident.notes ?? []" :key="n" class="rounded border border-warn/30 bg-warn/10 p-2 text-[11px] text-yellow-200">{{ n }}</div>
                        <div>
                            <div class="mb-1 font-semibold">Map sources</div>
                            <div v-for="(n, src) in sourceCounts" :key="src" class="flex justify-between text-muted"><span>{{ sourceLabel[src] ?? src }}</span><span class="font-mono">{{ n }}</span></div>
                        </div>
                        <div>
                            <div class="mb-1 font-semibold">Definitions for this SW</div>
                            <div v-for="d in ed.file.definitions" :key="d.id" class="text-muted">{{ d.name }} ({{ d.map_count }})</div>
                            <div v-if="!ed.file.definitions?.length" class="text-muted">None. Import an XDF to add one.</div>
                        </div>
                        <button class="btn w-full justify-center" @click="reanalyze">Re-run detection</button>
                        <label class="block">Notes<textarea v-model="notes" rows="4" class="input mt-1 w-full" @change="saveNotes" /></label>
                    </div>

                    <!-- checksum -->
                    <div v-else-if="side === 'Checksum'" class="space-y-3 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">{{ checksum?.module || 'No checksum module' }}</span>
                            <ChecksumBadge :ok="checksum?.ok ?? null" />
                        </div>
                        <p class="text-[11px] text-muted">
                            Status of the <b>{{ ed.dirty ? 'modified' : 'original' }}</b> buffer.
                            <span v-if="checksum?.canFix">Correction available when saving.</span>
                            <span v-else-if="checksum?.module">Detection only. Correct with your flashing tool.</span>
                        </p>
                        <div v-if="checksum?.error" class="text-bad">{{ checksum.error }}</div>
                        <table class="w-full font-mono text-[10px]">
                            <tr v-for="(b, i) in checksum?.blocks ?? []" :key="i" class="border-b border-line/50">
                                <td class="py-1 pr-1"><span :class="b.ok ? 'text-ok' : 'text-bad'">●</span></td>
                                <td class="py-1 font-sans text-[11px]">{{ b.name }}<div class="text-muted">{{ hex(b.start) }}–{{ hex(b.end) }}</div></td>
                                <td class="py-1 text-right" :title="`stored ${b.stored} / calc ${b.calculated}`">{{ b.ok ? b.stored : b.stored + '≠' + b.calculated }}</td>
                            </tr>
                        </table>
                    </div>

                    <!-- versions -->
                    <div v-else-if="side === 'Versions'" class="space-y-2 text-xs">
                        <div v-for="v in ed.file.versions" :key="v.id" class="rounded border border-line p-2">
                            <div class="flex items-center justify-between">
                                <span class="font-semibold">{{ v.label }}</span>
                                <ChecksumBadge :ok="v.checksums?.ok ?? null" />
                            </div>
                            <div class="text-[11px] text-muted">{{ new Date(v.created_at).toLocaleString() }} · {{ v.checksum_fixed ? 'checksum corrected' : 'no checksum correction' }}</div>
                            <div v-if="v.changed_maps?.length" class="mt-1 text-[11px] text-muted">{{ v.changed_maps.join(', ') }}</div>
                            <div class="mt-2 flex gap-1">
                                <a class="btn btn-sm" :href="`/api/files/${ed.file.id}/versions/${v.id}/download`">Download</a>
                                <button class="btn btn-sm" @click="openVersion(v)">Load into editor</button>
                                <button class="btn btn-sm ml-auto" @click="removeVersion(v)">Delete</button>
                            </div>
                        </div>
                        <div v-if="!ed.file.versions?.length" class="text-muted">No saved versions yet.</div>
                    </div>

                    <!-- definition -->
                    <div v-else-if="side === 'Definition'">
                        <MapProperties v-if="map" :map="map" />
                        <hr class="my-4 border-line">
                        <div class="text-xs font-semibold">Add map manually</div>
                        <div class="mt-2 flex gap-1">
                            <input v-model="newAddr" class="input w-24 font-mono" placeholder="hex addr">
                            <input v-model.number="newRows" type="number" min="1" class="input w-14" title="rows">
                            <input v-model.number="newCols" type="number" min="1" class="input w-14" title="cols">
                            <button class="btn" @click="addMap">Add</button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <!-- save dialog -->
        <div v-if="saveDialog" class="fixed inset-0 z-40 grid place-items-center bg-black/60" @click.self="saveDialog = false">
            <div class="w-[420px] rounded-xl border border-line bg-panel p-5 text-xs">
                <div class="mb-3 text-sm font-semibold">Save modified file</div>
                <label class="block">Label<input v-model="saveLabel" class="input mt-1 w-full" placeholder="Stage 1"></label>
                <label class="mt-3 flex items-center gap-2" :class="checksum?.canFix ? '' : 'opacity-50'">
                    <input v-model="fixChecksum" type="checkbox" :disabled="!checksum?.canFix">
                    Correct checksums ({{ checksum?.module || 'not available' }})
                </label>
                <p v-if="!checksum?.canFix" class="mt-1 text-[11px] text-muted">No checksum correction for this ECU. The file is saved as-is.</p>
                <div class="mt-3 rounded border border-line bg-bg p-2 text-[11px] text-muted">
                    Modified maps: {{ changedNames.join(', ') || 'none' }}
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button class="btn" @click="saveDialog = false">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving" @click="saveVersion">{{ saving ? 'Saving…' : 'Save & download' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { countDiffBytes, hex } from '../lib/binary';
import { toast, toastError } from '../lib/toast';
import { useEditor } from '../stores/editor';
import ChecksumBadge from '../components/ChecksumBadge.vue';
import HexView from '../components/HexView.vue';
import MapList from '../components/MapList.vue';
import MapProperties from '../components/MapProperties.vue';
import MapTable from '../components/MapTable.vue';

const MapChart = defineAsyncComponent(() => import('../components/MapChart.vue'));

const props = defineProps({ id: { type: String, required: true } });
const ed = useEditor();
const loading = ref(true);
const views = ['Table', 'Split', '3D', 'Hex'];
const view = ref('Split');
const sideTabs = ['ECU', 'Checksum', 'Versions', 'Definition'];
const side = ref('ECU');
const saveDialog = ref(false);
const saveLabel = ref('');
const fixChecksum = ref(true);
const saving = ref(false);
const notes = ref('');
const newAddr = ref('');
const newRows = ref(1);
const newCols = ref(8);
const liveChecksum = ref(null);

const map = computed(() => ed.selected);
const shownView = computed(() => (map.value?.kind === 'value' && view.value !== 'Hex' ? 'Table' : view.value));
const ident = computed(() => ed.file?.ident ?? {});
const checksum = computed(() => liveChecksum.value ?? ed.file?.checksums);
const diffBytes = computed(() => {
    void ed.revision;
    return ed.current ? countDiffBytes(ed.original, ed.current) : 0;
});
const changedNames = computed(() => ed.maps.filter((m) => ed.changedMapIds.has(m.id)).map((m) => m.name));
const identRows = computed(() => [
    ['Family', ident.value.family],
    ['Variant', ident.value.variant],
    ['CPU', ident.value.cpu],
    ['Byte order', ident.value.endian === 'be' ? 'Big-endian' : ident.value.endian === 'le' ? 'Little-endian' : null],
    ['Bosch HW', ident.value.hw],
    ['Software', ident.value.sw],
    ['Part number', ident.value.partNumber],
    ['SW version', ident.value.swVersion],
    ['Engine', ident.value.engine],
    ['Fuel', ident.value.fuel],
    ['Match score', ident.value.score],
    ['SHA-1', ed.file?.sha1?.slice(0, 16)],
].filter((r) => r[1] !== null && r[1] !== undefined && r[1] !== ''));
const sourceLabel = { pattern: 'Code pattern (exact)', scan: 'Structure scan', xdf: 'XDF definition', user: 'User edited' };
const sourceCounts = computed(() => ed.maps.reduce((acc, m) => ({ ...acc, [m.source]: (acc[m.source] ?? 0) + 1 }), {}));

async function load() {
    loading.value = true;
    try {
        await ed.load(props.id);
        notes.value = ed.file.notes ?? '';
    } catch (e) {
        toastError(e);
    } finally {
        loading.value = false;
    }
}

let checksumTimer = null;
watch(() => ed.revision, () => {
    clearTimeout(checksumTimer);
    if (!ed.file?.checksums?.module) return;
    checksumTimer = setTimeout(async () => {
        try {
            liveChecksum.value = ed.dirty ? await api.checksum(ed.file.id, ed.current) : null;
        } catch {
            // ignore transient errors
        }
    }, 1200);
});

async function saveVersion() {
    saving.value = true;
    try {
        const version = await api.saveVersion(ed.file.id, ed.current, {
            label: saveLabel.value,
            fix: fixChecksum.value && checksum.value?.canFix,
            changedMaps: changedNames.value,
        });
        saveDialog.value = false;
        toast(`Saved "${version.label}"${version.checksum_fixed ? ' with corrected checksums' : ''}`);
        window.location.href = `/api/files/${ed.file.id}/versions/${version.id}/download`;
        ed.setFile(await api.file(ed.file.id));
        side.value = 'Versions';
    } catch (e) {
        toastError(e);
    } finally {
        saving.value = false;
    }
}

async function openVersion(v) {
    if (ed.dirty && !confirm('Discard current unsaved changes?')) return;
    try {
        const bytes = await api.versionRaw(ed.file.id, v.id);
        ed.current = bytes;
        ed.undoStack = [];
        ed.redoStack = [];
        ed.revision++;
        toast(`Loaded "${v.label}". Differences to the original are highlighted.`);
    } catch (e) {
        toastError(e);
    }
}

async function removeVersion(v) {
    if (!confirm(`Delete version "${v.label}"?`)) return;
    await api.removeVersion(ed.file.id, v.id).catch(toastError);
    ed.setFile(await api.file(ed.file.id));
}

async function importXdf(e) {
    const f = e.target.files[0];
    e.target.value = '';
    if (!f) return;
    try {
        const res = await api.importXdf(ed.file.id, f);
        ed.setFile(res);
        toast(`Imported ${res.imported} definitions${res.importWarnings?.length ? ` (${res.importWarnings.length} warnings)` : ''}`);
    } catch (err) {
        toastError(err);
    }
}

async function reanalyze() {
    try {
        ed.setFile(await api.reanalyze(ed.file.id));
        toast('Detection re-run');
    } catch (e) {
        toastError(e);
    }
}

async function saveNotes() {
    await api.update(ed.file.id, { notes: notes.value }).catch(toastError);
}

async function addMap() {
    const address = parseInt(newAddr.value, 16);
    if (!Number.isFinite(address)) return toastError('Enter a hex address');
    const m = {
        id: `user_${address.toString(16)}_${Date.now() % 100000}`,
        name: `New map @${hex(address)}`,
        description: '',
        category: 'User',
        source: 'user',
        confidence: 1,
        kind: newRows.value > 1 && newCols.value > 1 ? 'map' : 'curve',
        address,
        rows: newRows.value || 1,
        cols: newCols.value || 1,
        dtype: 'u16',
        be: ident.value.endian === 'be',
        factor: 1,
        offset: 0,
        unit: '',
        decimals: 0,
        colMajor: false,
        x: null,
        y: null,
    };
    await ed.saveMaps([...ed.maps, m]).catch(toastError);
    ed.selectedId = m.id;
}

function onKey(e) {
    const ctrl = e.ctrlKey || e.metaKey;
    if (!ctrl || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
    if (e.key.toLowerCase() === 'z' && !e.shiftKey) {
        ed.undo();
        e.preventDefault();
    } else if (e.key.toLowerCase() === 'y' || (e.key.toLowerCase() === 'z' && e.shiftKey)) {
        ed.redo();
        e.preventDefault();
    }
}

function beforeUnload(e) {
    if (ed.changedMapIds.size) e.preventDefault();
}

onMounted(() => {
    load();
    window.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', beforeUnload);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('beforeunload', beforeUnload);
});
</script>
