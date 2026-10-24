import { reactive } from 'vue';

export const toasts = reactive([]);
let seq = 0;

export function toast(message, type = 'ok', ms = 3500) {
    const id = ++seq;
    toasts.push({ id, message, type });
    setTimeout(() => {
        const i = toasts.findIndex((t) => t.id === id);
        if (i >= 0) toasts.splice(i, 1);
    }, ms);
}

export const toastError = (e) => toast(e?.message ?? String(e), 'error', 6000);
