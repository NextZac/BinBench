<template>
    <div v-if="loading" class="grid h-full place-items-center font-mono text-[12px] text-muted">Loading read…</div>
    <div v-else-if="ed.file" class="flex h-full flex-col">
        <!-- file bar -->
        <div class="flex h-10 shrink-0 items-stretch gap-3 border-b border-line bg-panel px-2">
            <div class="flex min-w-0 items-center gap-1.5">
                <router-link to="/" class="btn btn-quiet btn-icon" aria-label="Back to files"><Icon name="chevronLeft" /></router-link>
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-[13px] font-medium" :title="ed.file.original_filename">{{ ed.file.name }}</div>
                    <div class="truncate text-[11px] text-muted">{{ ed.file.family || 'ECU not recognised' }}<span v-if="ident.sw" class="font-mono"> · {{ ident.sw }}</span></div>
                </div>
            </div>
            <nav class="ml-4 flex items-stretch" role="tablist" aria-label="Editor mode">
                <button v-for="v in mainViews" :key="v.id" class="tab" role="tab" :aria-selected="mainView === v.id" @click="mainView = v.id">{{ v.label }}</button>
            </nav>
            <div class="ml-auto flex items-center gap-1">
                <button class="btn btn-quiet btn-icon" :disabled="!ed.undoStack.length" aria-label="Undo" title="Undo (Ctrl+Z)" @click="ed.undo()"><Icon name="undo" /></button>
                <button class="btn btn-quiet btn-icon" :disabled="!ed.redoStack.length" aria-label="Redo" title="Redo (Ctrl+Y)" @click="ed.redo()"><Icon name="redo" /></button>
                <span class="mx-1 h-4 w-px bg-line" />
                <div ref="fileMenuEl" class="relative">
                    <button class="btn" :aria-expanded="fileMenu" aria-haspopup="menu" @click="fileMenu = !fileMenu">File <Icon name="chevronDown" :size="12" /></button>
                    <div v-if="fileMenu" class="absolute right-0 z-40 mt-1 w-52 border border-line bg-panel py-1 text-[12px] shadow-[var(--bb-shadow)]" role="menu" @click="fileMenu = false">
                        <button class="menu-item" role="menuitem" @click="$refs.xdfInput.click()"><Icon name="upload" :size="13" />Import XDF…</button>
                        <a class="menu-item" role="menuitem" :href="`/api/files/${ed.file.id}/xdf`"><Icon name="download" :size="13" />Export XDF</a>
                        <a class="menu-item" role="menuitem" :href="`/api/files/${ed.file.id}/raw`" :download="ed.file.original_filename"><Icon name="file" :size="13" />Download original</a>
                        <div class="my-1 border-t border-line" />
                        <button class="menu-item" role="menuitem" @click="reanalyze"><Icon name="reset" :size="13" />Re-run detection</button>
                    </div>
                </div>
                <input ref="xdfInput" type="file" accept=".xdf,.xml" class="hidden" @change="importXdf">
                <button class="btn btn-primary" @click="saveDialog = true"><Icon name="download" :size="13" />Save…</button>
            </div>
        </div>

        <div class="flex min-h-0 flex-1">
            <!-- map list -->
            <aside class="w-64 shrink-0 border-r border-line bg-panel" aria-label="Maps">
                <MapList />
            </aside>

            <!-- main area -->
            <section class="flex min-w-0 flex-1 flex-col">
                <DynoPanel v-if="mainView === 'dyno'" />
                <Stage1Panel v-else-if="mainView === 'stage1'" />
                <DiffPanel v-else-if="mainView === 'diff'" :version-id="diffVersion" />
                <FaultsPanel v-else-if="mainView === 'faults'" />
                <template v-else-if="map">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line px-3 py-1.5">
                        <div class="min-w-[14rem] flex-1">
                            <div class="flex items-baseline gap-2">
                                <h2 class="truncate text-[14px] font-semibold">{{ map.name }}</h2>
                                <span class="font-mono text-[11px] whitespace-nowrap text-muted">{{ hex(map.address) }} · {{ map.rows }}×{{ map.cols }} {{ map.dtype }} · {{ map.unit || 'raw' }}</span>
                            </div>
                            <div v-if="map.description" class="truncate text-[11px] text-muted" :title="map.description">{{ map.description }}</div>
                        </div>
                        <div class="seg" role="group" aria-label="View">
                            <button v-for="v in views" :key="v" :aria-pressed="view === v" @click="view = v">{{ v }}</button>
                        </div>
                        <label class="flex items-center gap-1.5 text-[11px] text-muted" title="What the Original / Δ / Δ% views and the 3D overlay compare against">
                            vs
                            <CompareSelect v-model="referenceKey" />
                        </label>
                        <span v-if="ed.reference?.sw && ed.reference.sw !== ed.file.sw" class="status text-warn" title="Different software: map addresses may not line up"><Icon name="alert" :size="12" />other SW</span>
                        <button class="btn btn-quiet btn-sm" :disabled="!ed.changedMapIds.has(map.id)" title="Put this map back to the original" @click="ed.resetMap(map)"><Icon name="reset" :size="12" />Reset map</button>
                    </div>
                    <div class="grid min-h-0 flex-1" :class="shownView === 'Split' ? 'grid-rows-[minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[minmax(0,1fr)_minmax(320px,40%)] xl:grid-rows-1' : ''">
                        <div v-if="shownView === 'Table' || shownView === 'Split'" class="flex min-h-0 min-w-0 flex-col">
                            <MapTable :map="map" />
                        </div>
                        <div v-if="shownView === '3D' || shownView === 'Split'" class="min-h-0 min-w-0 border-line" :class="shownView === 'Split' ? 'border-t xl:border-t-0 xl:border-l' : ''">
                            <MapChart :map="map" />
                        </div>
                        <div v-if="shownView === 'Hex'" class="min-h-0">
                            <HexView :map="map" />
                        </div>
                    </div>
                </template>
                <div v-else class="grid flex-1 place-items-center text-[12px] text-muted">Pick a map on the left.</div>
            </section>

            <!-- side panel -->
            <aside class="flex w-72 shrink-0 flex-col border-l border-line bg-panel" aria-label="Details">
                <div class="flex h-9 shrink-0 items-stretch border-b border-line px-1" role="tablist">
                    <button v-for="t in sideTabs" :key="t" class="tab px-2" role="tab" :aria-selected="side === t" @click="side = t">{{ t }}</button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-3 scroll-thin">
                    <!-- ECU -->
                    <div v-if="side === 'ECU'" class="space-y-4 text-[12px]">
                        <dl class="grid grid-cols-[88px_1fr] gap-x-2 gap-y-1">
                            <template v-for="row in identRows" :key="row[0]">
                                <dt class="text-muted">{{ row[0] }}</dt>
                                <dd class="font-mono break-all">{{ row[1] }}</dd>
                            </template>
                        </dl>
                        <div v-if="ident.notes?.length" class="space-y-1.5">
                            <p v-for="n in ident.notes" :key="n" class="note break-words">{{ n }}</p>
                        </div>
                        <div>
                            <div class="label mb-1">Where the maps came from</div>
                            <div v-for="(n, src) in sourceCounts" :key="src" class="flex justify-between border-b border-line/60 py-0.5"><span class="text-muted">{{ sourceLabel[src] ?? src }}</span><span class="font-mono">{{ n }}</span></div>
                        </div>
                        <div>
                            <div class="label mb-1">Definitions for this software</div>
                            <div v-for="d in ed.file.definitions" :key="d.id" class="flex justify-between gap-2"><span class="truncate">{{ d.name }}</span><span class="font-mono text-muted">{{ d.map_count }}</span></div>
                            <div v-if="!ed.file.definitions?.length" class="text-muted">None. File → Import XDF adds one.</div>
                        </div>
                        <label class="block"><span class="label">Notes</span><textarea v-model="notes" rows="4" class="input mt-0.5 w-full" placeholder="Car, customer, what was changed…" @change="saveNotes" /></label>
                    </div>

                    <!-- checksum -->
                    <div v-else-if="side === 'Checksum'" class="space-y-3 text-[12px]">
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-medium">{{ checksum?.module || 'No checksum module for this ECU' }}</span>
                            <ChecksumBadge :ok="checksum?.ok ?? null" />
                        </div>
                        <p class="text-[11px] text-muted">
                            Showing the {{ ed.dirty ? 'edited' : 'original' }} data.
                            <span v-if="checksum?.canFix">Corrected automatically when you save.</span>
                            <span v-else-if="checksum?.module">Check only: correct it in your flashing tool.</span>
                        </p>
                        <p v-if="checksum?.error" class="note border-bad text-bad">{{ checksum.error }}</p>
                        <table class="w-full text-[11px]">
                            <tr v-for="(b, i) in checksum?.blocks ?? []" :key="i" class="border-b border-line/60 align-top">
                                <td class="py-1 pr-1.5"><Icon :name="b.ok ? 'check' : 'alert'" :size="12" :class="b.ok ? 'mt-0.5 text-ok' : 'mt-0.5 text-bad'" /></td>
                                <td class="py-1">{{ b.name }}<div class="font-mono text-[10px] text-muted">{{ hex(b.start) }}–{{ hex(b.end) }}</div></td>
                                <td class="py-1 pl-1 text-right font-mono text-[10px]" :class="b.ok ? 'text-muted' : 'text-bad'" :title="`stored ${b.stored} / calculated ${b.calculated}`">{{ b.ok ? b.stored : `${b.stored}≠${b.calculated}` }}</td>
                            </tr>
                        </table>
                    </div>

                    <!-- versions -->
                    <div v-else-if="side === 'Versions'" class="text-[12px]">
                        <div v-for="v in ed.file.versions" :key="v.id" class="border-b border-line py-2 first:pt-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate font-medium">{{ v.label }}</span>
                                <ChecksumBadge :ok="v.checksums?.ok ?? null" />
                            </div>
                            <div class="text-[11px] text-muted">{{ new Date(v.created_at).toLocaleString() }}<template v-if="!v.checksum_fixed"> · checksums untouched</template></div>
                            <div v-if="v.changed_maps?.length" class="mt-0.5 truncate text-[11px] text-muted" :title="v.changed_maps.join('\n')">{{ v.changed_maps.join(', ') }}</div>
                            <div class="mt-1.5 flex gap-1">
                                <a class="btn btn-sm" :href="`/api/files/${ed.file.id}/versions/${v.id}/download`"><Icon name="download" :size="12" />Download</a>
                                <button class="btn btn-sm" title="Load this version into the editor" @click="openVersion(v)">Load</button>
                                <button class="btn btn-sm" title="Diff and review against the original" @click="reviewVersion(v)">Review</button>
                                <button class="btn btn-quiet btn-sm btn-icon btn-danger ml-auto" :aria-label="`Delete version ${v.label}`" @click="removeVersion(v)"><Icon name="trash" :size="12" /></button>
                            </div>
                        </div>
                        <div v-if="!ed.file.versions?.length" class="text-muted">No saved versions. Save… stores one and downloads it.</div>
                    </div>

                    <!-- definition -->
                    <div v-else-if="side === 'Definition'">
                        <MapProperties v-if="map" :map="map" />
                        <div class="label mt-5 mb-1 border-t border-line pt-3">Add a map by hand</div>
                        <div class="flex gap-1">
                            <input v-model="newAddr" class="input w-24 font-mono" placeholder="address" aria-label="Hex address">
                            <input v-model.number="newRows" type="number" min="1" class="input w-14" aria-label="Rows" title="Rows">
                            <input v-model.number="newCols" type="number" min="1" class="input w-14" aria-label="Columns" title="Columns">
                            <button class="btn" @click="addMap"><Icon name="plus" :size="12" />Add</button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <!-- status bar -->
        <footer class="flex h-6 shrink-0 items-center gap-4 overflow-hidden border-t border-line bg-panel px-3 font-mono text-[11px] whitespace-nowrap text-muted">
            <span>{{ ident.variant || ed.file.family || 'unknown ECU' }}<template v-if="ident.hw"> · HW {{ ident.hw }}</template><template v-if="ident.sw"> · SW {{ ident.sw }}</template></span>
            <span :class="checksum?.ok === false ? 'text-bad' : checksum?.ok ? 'text-ok' : ''">
                checksum
                <template v-if="checksum?.ok === true">ok</template>
                <template v-else-if="checksum?.ok === false">bad{{ checksum.canFix ? ' (fixed on save)' : '' }}</template>
                <template v-else>n/a</template>
            </span>
            <span v-if="ed.changedMapIds.size" class="text-mod">{{ ed.changedMapIds.size }} map{{ ed.changedMapIds.size === 1 ? '' : 's' }} edited · {{ diffBytes }} B</span>
            <span v-else-if="!ed.changedFaultCodes.length">no edits</span>
            <span v-if="ed.changedFaultCodes.length" class="text-mod">{{ ed.changedFaultCodes.length }} fault path{{ ed.changedFaultCodes.length === 1 ? '' : 's' }} changed</span>
            <span v-if="ed.reference" class="text-ref">vs {{ ed.refLabel }}</span>
            <span class="ml-auto">{{ ed.maps.length }} maps · {{ (ed.file.size / 1024).toFixed(0) }} KB · {{ ident.endian === 'be' ? 'big' : 'little' }}-endian</span>
        </footer>

        <!-- save dialog -->
        <div v-if="saveDialog" class="fixed inset-0 z-40 grid place-items-center bg-black/50" @click.self="saveDialog = false" @keydown.esc="saveDialog = false">
            <div class="w-[440px] border border-line bg-panel text-[12px] shadow-[var(--bb-shadow)]" role="dialog" aria-modal="true" aria-labelledby="save-title">
                <div class="flex items-center border-b border-line px-4 py-2.5">
                    <h2 id="save-title" class="text-[13px] font-semibold">Save edited read</h2>
                    <button class="btn btn-quiet btn-icon ml-auto" aria-label="Close" @click="saveDialog = false"><Icon name="close" /></button>
                </div>
                <div class="space-y-3 px-4 py-3">
                    <label class="block"><span class="label">Version name</span><input v-model="saveLabel" class="input mt-0.5 w-full" placeholder="Stage 1, pops off, …" autofocus></label>
                    <label class="flex cursor-pointer items-center gap-2" :class="checksum?.canFix ? '' : 'cursor-not-allowed opacity-50'">
                        <input v-model="fixChecksum" type="checkbox" :disabled="!checksum?.canFix" class="accent-[var(--bb-accent-fill)]">
                        Correct checksums <span class="text-muted">({{ checksum?.module || 'not available' }})</span>
                    </label>
                    <p v-if="!checksum?.canFix" class="note">BinBench can't correct checksums for this ECU. The file is saved exactly as edited.</p>
                    <div>
                        <div class="label">Edited maps</div>
                        <p class="mt-0.5 max-h-24 overflow-y-auto text-muted scroll-thin">{{ changedNames.join(', ') || 'none' }}</p>
                    </div>
                    <div v-if="ed.changedFaultCodes.length">
                        <div class="label">Changed fault paths</div>
                        <p class="mt-0.5 max-h-24 overflow-y-auto font-mono text-muted scroll-thin">{{ ed.changedFaultCodes.join(' ') }}</p>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-4 py-2.5">
                    <button class="btn" @click="saveDialog = false">Cancel</button>
                    <button class="btn btn-primary" :disabled="saving" @click="saveVersion">{{ saving ? 'Saving…' : 'Save and download' }}</button>
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
import CompareSelect from '../components/CompareSelect.vue';
import HexView from '../components/HexView.vue';
import Icon from '../components/Icon.vue';
import MapList from '../components/MapList.vue';
import MapProperties from '../components/MapProperties.vue';
import MapTable from '../components/MapTable.vue';

const MapChart = defineAsyncComponent(() => import('../components/MapChart.vue'));
const DynoPanel = defineAsyncComponent(() => import('../components/DynoPanel.vue'));
const Stage1Panel = defineAsyncComponent(() => import('../components/Stage1Panel.vue'));
const DiffPanel = defineAsyncComponent(() => import('../components/DiffPanel.vue'));
const FaultsPanel = defineAsyncComponent(() => import('../components/FaultsPanel.vue'));

const props = defineProps({ id: { type: String, required: true } });
const ed = useEditor();
const loading = ref(true);
const views = ['Table', 'Split', '3D', 'Hex'];
const view = ref('Split');
const mainViews = [{ id: 'maps', label: 'Maps' }, { id: 'dyno', label: 'Dyno' }, { id: 'stage1', label: 'Stage 1' }, { id: 'diff', label: 'Diff' }, { id: 'faults', label: 'Faults' }];
const mainView = ref('maps');
const diffVersion = ref(null);
const sideTabs = ['ECU', 'Checksum', 'Versions', 'Definition'];
const side = ref('ECU');
const saveDialog = ref(false);
const fileMenu = ref(false);
const fileMenuEl = ref(null);
const saveLabel = ref('');
const fixChecksum = ref(true);
const saving = ref(false);
const notes = ref('');
const newAddr = ref('');
const newRows = ref(1);
const newCols = ref(8);
const liveChecksum = ref(null);

const map = computed(() => ed.selected);
const referenceKey = computed({
    get: () => ed.reference?.key ?? 'original',
    set: (key) => ed.setReference(key).catch(toastError),
});
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
const sourceLabel = { pattern: 'Found in ECU code', scan: 'Table structure scan', xdf: 'Imported XDF', user: 'Edited by you' };
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

// picking a map (in the list or from the dyno) goes back to the map view
watch(() => ed.selectedId, () => {
    mainView.value = 'maps';
});

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
            changedMaps: [...changedNames.value, ...ed.changedFaultCodes.map((c) => `DTC ${c}`)],
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

function reviewVersion(v) {
    diffVersion.value = v.id;
    mainView.value = 'diff';
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
    if (e.key === 'Escape') {
        fileMenu.value = false;
    }
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

function closeFileMenu(e) {
    if (fileMenu.value && !fileMenuEl.value?.contains(e.target)) fileMenu.value = false;
}

function beforeUnload(e) {
    if (ed.changedMapIds.size) e.preventDefault();
}

onMounted(() => {
    load();
    window.addEventListener('keydown', onKey);
    window.addEventListener('beforeunload', beforeUnload);
    window.addEventListener('mousedown', closeFileMenu);
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('beforeunload', beforeUnload);
    window.removeEventListener('mousedown', closeFileMenu);
});
</script>
