const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/** Logging in or out rotates the session, and with it the CSRF token the API expects. */
export function setCsrfToken(token) {
    document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
}

async function request(method, url, body, { raw = false } = {}) {
    const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() };
    let payload = body;
    if (body && !(body instanceof FormData) && !(body instanceof Uint8Array) && !(body instanceof Blob)) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }
    if (body instanceof Uint8Array) {
        headers['Content-Type'] = 'application/octet-stream';
    }
    const res = await fetch(`/api${url}`, { method, headers, body: payload });
    if (res.status === 401 && !url.startsWith('/auth')) {
        // session expired or signed out in another tab
        window.dispatchEvent(new CustomEvent('binbench:unauthenticated'));
    }
    if (!res.ok) {
        let message = `${res.status} ${res.statusText}`;
        try {
            const data = await res.json();
            message = data.message || message;
        } catch {
            // not JSON
        }
        throw new Error(message);
    }
    if (raw) {
        return new Uint8Array(await res.arrayBuffer());
    }

    return res.status === 204 ? null : res.json();
}

const query = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== '' && v !== undefined));

export const api = {
    auth: () => request('GET', '/auth'),
    login: (data) => request('POST', '/auth/login', data),
    register: (data) => request('POST', '/auth/register', data),
    logout: () => request('POST', '/auth/logout', {}),
    files: () => request('GET', '/files'),
    file: (id) => request('GET', `/files/${id}`),
    upload: (file) => {
        const form = new FormData();
        form.append('file', file);
        return request('POST', '/files', form);
    },
    update: (id, data) => request('PATCH', `/files/${id}`, data),
    remove: (id) => request('DELETE', `/files/${id}`),
    reanalyze: (id) => request('POST', `/files/${id}/reanalyze`),
    raw: (id) => request('GET', `/files/${id}/raw`, null, { raw: true }),
    versionRaw: (id, versionId) => request('GET', `/files/${id}/versions/${versionId}/raw`, null, { raw: true }),
    checksum: (id, bytes) => request('POST', `/files/${id}/checksum`, bytes),
    dyno: (id, bytes, params = {}) => request('POST', `/files/${id}/dyno?${query(params)}`, bytes ?? undefined),
    diff: (id, bytes, params = {}) => request('POST', `/files/${id}/diff?${query(params)}`, bytes ?? undefined),
    dtcs: (id) => request('GET', `/files/${id}/dtcs`),
    stage1: (id, bytes, params = {}) => request('POST', `/files/${id}/stage1?${query(params)}`, bytes ?? undefined),
    explainStage1: (id, bytes, params = {}) => request('POST', `/files/${id}/stage1/explain?${query(params)}`, bytes ?? undefined),
    saveVersion: (id, bytes, { label, fix, changedMaps }) => {
        const form = new FormData();
        form.append('file', new Blob([bytes]), 'modified.bin');
        form.append('label', label ?? '');
        form.append('fix', fix ? '1' : '0');
        form.append('changed_maps', JSON.stringify(changedMaps ?? []));
        return request('POST', `/files/${id}/versions`, form);
    },
    removeVersion: (id, versionId) => request('DELETE', `/files/${id}/versions/${versionId}`),
    importXdf: (id, file) => {
        const form = new FormData();
        form.append('file', file);
        return request('POST', `/files/${id}/xdf`, form);
    },
    definitions: () => request('GET', '/definitions'),
    removeDefinition: (id) => request('DELETE', `/definitions/${id}`),
};
