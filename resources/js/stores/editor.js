import { defineStore } from 'pinia';
import { api } from '../lib/api';
import { bytesDiffer, mapRanges } from '../lib/binary';

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
            return new Set(this.maps.filter((m) => mapRanges(m).some(([a, b]) => bytesDiffer(s.original, s.current, a, b))).map((m) => m.id));
        },
        // maps whose current values differ from the picked reference (empty without one)
        refChangedMapIds(s) {
            void s.revision;
            if (!s.current || !s.reference) return new Set();
            return new Set(this.maps.filter((m) => mapRanges(m).some(([a, b]) => bytesDiffer(s.reference.bytes, s.current, a, b))).map((m) => m.id));
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
         * Apply a batch of byte writes to a map (data and its stored axes) as one undo step.
         * `fn(buf)` mutates the buffer.
         */
        transact(map, fn) {
            this.transactRanges(mapRanges(map), fn);
        },

        /**
         * Like transact, for any byte range [a, b) (fault paths, raw patches).
         */
        transactRange(a, b, fn) {
            this.transactRanges([[a, b]], fn);
        },

        /**
         * One undo step over several byte ranges [[a, b], ...] (a map's data and axes can lie far apart).
         */
        transactRanges(ranges, fn) {
            const segments = ranges.map(([a, b]) => ({ at: a, before: this.current.slice(a, b) }));
            fn(this.current);
            const changed = segments
                .map((seg) => ({ ...seg, after: this.current.slice(seg.at, seg.at + seg.before.length) }))
                .filter((seg) => seg.before.some((v, i) => v !== seg.after[i]));
            if (!changed.length) return;
            this.undoStack.push({ segments: changed });
            if (this.undoStack.length > 200) this.undoStack.shift();
            this.redoStack = [];
            this.revision++;
        },

        undo() {
            const step = this.undoStack.pop();
            if (!step) return;
            step.segments.forEach((seg) => this.current.set(seg.before, seg.at));
            this.redoStack.push(step);
            this.revision++;
        },

        redo() {
            const step = this.redoStack.pop();
            if (!step) return;
            step.segments.forEach((seg) => this.current.set(seg.after, seg.at));
            this.undoStack.push(step);
            this.revision++;
        },

        /** Put a map's data and stored axes back to the original. */
        resetMap(map) {
            this.transact(map, (buf) => {
                for (const [a, b] of mapRanges(map)) buf.set(this.original.slice(a, b), a);
            });
        },

        /** Maps other than `map` that use the stored axis at `address`. */
        axisUsers(address, map) {
            if (address === null || address === undefined) return [];
            return this.maps.filter((m) => m.id !== map.id && (m.x?.address === address || m.y?.address === address));
        },

        async saveMaps(maps) {
            const file = await api.update(this.file.id, { maps });
            this.setFile(file);
            this.revision++;
        },
    },
});
