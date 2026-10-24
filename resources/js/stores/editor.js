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
        compareTo: null, // { label, bytes } for version comparison
        liveChecksum: null,
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
            const [a, b] = mapRange(map);
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
