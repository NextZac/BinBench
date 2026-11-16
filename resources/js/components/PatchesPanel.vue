<template>
    <div class="flex h-full min-h-0 flex-col overflow-y-auto scroll-thin">
        <div class="border-b border-line px-3 py-2">
            <h2 class="text-[14px] font-semibold">Code patches</h2>
            <p class="text-[11px] text-muted">Firmware changes for specific software numbers. They are written to your working copy like any edit: undo, diff and checksum apply, and nothing is stored until you save.</p>
        </div>

        <div v-if="error" class="p-6 text-[12px] text-bad">{{ error }}</div>
        <div v-else-if="!patches" class="grid flex-1 place-items-center font-mono text-[12px] text-muted">Checking patches…</div>
        <div v-else-if="!patches.length" class="max-w-xl p-6 text-[12px] text-muted">
            <p class="text-ink">No code patches for this ECU yet.</p>
            <p class="mt-1">Supported so far: multimap for BMW DDE4 / EDC15C4 (software 1037351632 and 1037351761) and BMW DDE5 / EDC16C (software 1037361878), full reads only.</p>
        </div>

        <div v-else class="max-w-3xl space-y-3 p-3">
            <section v-for="p in patches" :key="p.id" class="border border-line bg-panel p-3 text-[12px]">
                <div class="flex flex-wrap items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <h3 class="text-[13px] font-semibold">{{ p.name }}</h3>
                        <p class="mt-0.5 text-muted">{{ p.summary }}</p>
                    </div>
                    <span class="status" :class="statusClass[p.status]">{{ statusLabel[p.status] }}</span>
                </div>

                <ul class="mt-2 list-disc space-y-0.5 pl-4 text-[11px] text-muted">
                    <li v-for="d in p.details" :key="d">{{ d }}</li>
                </ul>
                <p class="mt-2 text-[11px]"><span class="text-muted">Software:</span> <span class="font-mono">{{ p.software.join(', ') }}</span></p>
                <p class="mt-0.5 text-[11px]"><span class="text-muted">On the car:</span> {{ p.tested }}</p>
                <p class="note mt-2"><Icon name="alert" :size="12" class="mr-1 inline" />{{ p.flash }}</p>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <button v-if="p.status !== 'installed'" class="btn btn-primary btn-sm" :disabled="p.status !== 'available' || busy" @click="install(p)">Install</button>
                    <button v-else class="btn btn-sm" :disabled="busy" @click="uninstall(p)"><Icon name="reset" :size="12" />Remove</button>
                    <span v-if="p.reason" class="text-[11px] text-muted">{{ p.reason }}</span>
                    <span v-else-if="p.status === 'installed'" class="text-[11px] text-muted">Edit the tables in the Multimap category of the map list.</span>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../lib/api';
import { toast, toastError } from '../lib/toast';
import { useEditor } from '../stores/editor';
import Icon from './Icon.vue';

const ed = useEditor();
const patches = ref(null);
const error = ref(null);
const busy = ref(false);

const statusLabel = { available: 'available', installed: 'installed', unavailable: 'not available' };
const statusClass = { available: 'text-ok', installed: 'text-mod', unavailable: 'text-muted' };

const fromHex = (hex) => Uint8Array.from(hex.match(/../g) ?? [], (b) => parseInt(b, 16));

async function refresh() {
    try {
        patches.value = (await api.patches(ed.file.id, ed.current)).patches;
        error.value = null;
    } catch (e) {
        error.value = e.message;
    }
}

/** Apply the server's writes as one undo step. */
function applyWrites(writes) {
    const chunks = writes.map((w) => ({ at: w.address, bytes: fromHex(w.hex) }));
    ed.transactRanges(chunks.map((c) => [c.at, c.at + c.bytes.length]), (buf) => chunks.forEach((c) => buf.set(c.bytes, c.at)));
}

async function install(patch) {
    busy.value = true;
    try {
        const { writes, maps } = await api.installPatch(ed.file.id, patch.id, ed.current);
        applyWrites(writes);
        // the patch's own names and axes replace whatever detection found at the same addresses
        const addresses = new Set(maps.map((m) => m.address));
        await ed.saveMaps([...ed.maps.filter((m) => !addresses.has(m.address)), ...maps]);
        toast(`${patch.name} installed. Save with checksum correction to keep it.`);
    } catch (e) {
        toastError(e);
    } finally {
        busy.value = false;
        await refresh();
    }
}

async function uninstall(patch) {
    busy.value = true;
    try {
        const { writes, maps } = await api.uninstallPatch(ed.file.id, patch.id, ed.current);
        applyWrites(writes);
        const ids = new Set(maps.map((m) => m.id));
        await ed.saveMaps(ed.maps.filter((m) => !ids.has(m.id)));
        toast(`${patch.name} removed.`);
    } catch (e) {
        toastError(e);
    } finally {
        busy.value = false;
        await refresh();
    }
}

// undo/redo can take a patch in or out again
let timer = null;
watch(
    () => ed.revision,
    () => {
        if (busy.value) return;
        clearTimeout(timer);
        timer = setTimeout(refresh, 400);
    },
);
onBeforeUnmount(() => clearTimeout(timer));

onMounted(refresh);
</script>
