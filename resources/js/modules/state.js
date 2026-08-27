// Shared application state and small cross-cutting helpers.

export const store = {
    board: null,
    selected: null,
    armed: null,
    values: {},
    drag: null,
    poll: null,
    channel: null,
    remoteUsers: null,
    cursors: {},
    heartbeat: null,
    prevCircuitId: null,
    currentRevision: 0,
};

export const $ = (s) => document.querySelector(s);

export const escapeHtml = (str) =>
    String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

export const toast = (t) => {
    const e = document.createElement('div');
    e.className = 'toast';
    e.textContent = t;
    document.body.append(e);
    setTimeout(() => e.remove(), 2500);
};

export const getState = () => ({
    components: store.board?.components || [],
    wires: store.board?.wires || [],
});

export const sessionUuid = () => localStorage.getItem('ch-session');

export const gates = ['INPUT', 'OUTPUT', 'AND', 'OR', 'NOT', 'XOR', 'NOR', 'NAND'];
