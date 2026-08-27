// Minimal fetch client with CSRF / X-Socket-ID headers and unified error handling.

export const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

export const api = async (path, method = 'GET', body) => {
    const headers = {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'Accept': 'application/json'
    };
    if (window.Echo?.socketId && window.Echo.socketId()) {
        headers['X-Socket-ID'] = window.Echo.socketId();
    }
    const r = await fetch('/api/' + path, {
        method,
        headers,
        body: body ? JSON.stringify(body) : undefined
    });
    if (!r.ok) {
        const e = await r.json().catch(() => ({}));
        throw Error(e.message || 'Could not save this change.');
    }
    return r.status === 204 ? null : r.json();
};
