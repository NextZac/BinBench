import { defineStore } from 'pinia';
import { api } from '../lib/api';
import { bytesDiffer, mapRange } from '../lib/binary';

/**
 * Holds the open file: original + working buffer, map definitions, undo history.
 */
export const useEditor = defineStore('editor', {
    state: () => ({
        file: null,
        original: null,
        current: null,
        selectedId: null,
        undoStack: [],
        redoStack: [],
        revision: 0,
        // what the Original / Δ views compare against: null = the original file, else { key, label, sw, bytes }
        reference: null,
        // other files of the same size the user can compare with
        compareFiles: [],
        liveChecksum: null,
        // fault paths of the original file (null until the Faults view loads them)
        dtc: null,
    }),

    getters: {
        maps: (s) => s.file?.maps ?? [],
        selected(s) {
            return this.maps.find((m) => m.id === s.selectedId) ?? null;
        },
        changedMapIds(s) {
            void s.revision;
            if (!s.current) return new Set();
            return new Set(this.maps.filter((m) => {
                const [a, b] = mapRange(m);
                return bytesDiffer(s.original, s.current, a, b);
            }).map((m) => m.id));
        },
        // maps whose current values differ from the picked reference (empty without one)
        refChangedMapIds(s) {
            void s.revision;
            if (!s.current || !s.reference) return new Set();
            return new Set(this.maps.filter((m) => {
                const [a, b] = mapRange(m);
                return bytesDiffer(s.reference.bytes, s.current, a, b);
            }).map((m) => m.id));
        },
        // P-codes of fault paths whose bytes differ from the original
        changedFaultCodes(s) {
            void s.revision;
            if (!s.dtc?.available || !s.current) return [];
            const size = s.dtc.codeSize;
            const differs = (address, n) => bytesDiffer(s.original, s.current, address, address + n);
            return s.dtc.paths
                .filter((p) => p.codes.some((c) => differs(c.address, size)) || p.fields.some((f) => differs(f.address, f.size)))
                .map((p) => p.codes.find((c) => c.value && c.value !== 0xffff)?.code ?? `path ${p.index}`);
        },
        refBytes: (s) => s.reference?.bytes ?? s.original,
        refLabel: (s) => s.reference?.label ?? 'Original',
        dirty(s) {
            void s.revision;
            return this.changedMapIds.size > 0 || s.undoStack.length > 0;
        },
    },

    actions: {
        async load(id) {
            this.$reset();
            const [file, raw] = await Promise.all([api.file(id), api.raw(id)]);
            this.file = file;
            this.original = raw;
            this.current = raw.slice();
            const firstGood = file.maps.find((m) => m.kind === 'map' && m.confidence >= 0.7) ?? file.maps[0];
            this.selectedId = firstGood?.id ?? null;
        },

        async loadDtcs() {
            this.dtc = await api.dtcs(this.file.id);
            return this.dtc;
        },

        async loadCompareFiles() {
            const files = await api.files();
            this.compareFiles = files.filter((f) => f.id !== this.file.id && f.size === this.file.size);
        },

        /**
         * Raw bytes of a compare source: 'original', 'current', 'version:{id}' or 'file:{id}'.
         */
        async sourceBytes(key) {
            const [kind, id] = key.split(':');
            if (kind === 'current') return this.current;
            if (kind === 'version') return api.versionRaw(this.file.id, id);
            if (kind === 'file') return api.raw(id);
            return this.original;
        },

        sourceLabel(key) {
            const [kind, id] = key.split(':');
            if (kind === 'current') return 'Current edits';
            if (kind === 'version') return this.file.versions.find((v) => v.id === Number(id))?.label ?? 'Version';
            if (kind === 'file') return this.compareFiles.find((f) => f.id === Number(id))?.name ?? 'File';
            return 'Original';
        },

        async setReference(key) {
            if (!key || key === 'original') {
                this.reference = null;
            } else {
                const bytes = await this.sourceBytes(key);
                const sw = key.startsWith('file:') ? this.compareFiles.find((f) => f.id === Number(key.slice(5)))?.sw : this.file.sw;
                this.reference = { key, label: this.sourceLabel(key), sw, bytes };
            }
            this.revision++;
        },

        setFile(file) {
            this.file = file;
            if (!this.maps.some((m) => m.id === this.selectedId)) {
                this.selectedId = this.maps[0]?.id ?? null;
            }
        },

        /**
         * Apply a batch of byte writes as one undo step. `fn(buf)` mutates the buffer.
         */
        transact(map, fn) {
            this.transactRange(...mapRange(map), fn);
        },

        /**
         * Like transact, for any byte range [a, b) (fault paths, raw patches).
         */
        transactRange(a, b, fn) {
            const before = this.current.slice(a, b);
            fn(this.current);
            const after = this.current.slice(a, b);
            if (before.every((v, i) => v === after[i])) return;
            this.undoStack.push({ at: a, before, after });
            if (this.undoStack.length > 200) this.undoStack.shift();
            this.redoStack = [];
            this.revision++;
        },

        undo() {
            const step = this.undoStack.pop();
            if (!step) return;
            this.current.set(step.before, step.at);
            this.redoStack.push(step);
            this.revision++;
        },

        redo() {
            const step = this.redoStack.pop();
            if (!step) return;
            this.current.set(step.after, step.at);
            this.undoStack.push(step);
            this.revision++;
        },

        resetMap(map) {
            this.transact(map, (buf) => {
                const [a, b] = mapRange(map);
                buf.set(this.original.slice(a, b), a);
            });
        },

        async saveMaps(maps) {
            const file = await api.update(this.file.id, { maps });
            this.setFile(file);
            this.revision++;
        },
    },
});
