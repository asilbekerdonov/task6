// Real-time layer: presence channel, fallback polling, heartbeat, live cursors.

import { $, escapeHtml, store, sessionUuid } from './state.js';
import { api, csrf } from './api.js';
import { render, position, screenToModel } from './canvas.js';
import { run } from './simulator.js';

export function pingSession() {
    let uuid = sessionUuid();
    if (!uuid) return;
    fetch('/api/sessions/ping', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Session-Uuid': uuid,
            'X-CSRF-TOKEN': csrf
        },
        body: JSON.stringify({ session_uuid: uuid }),
        keepalive: true
    }).catch(() => {});
}

export function startHeartbeat() {
    if (store.heartbeat) return;
    pingSession();
    store.heartbeat = setInterval(pingSession, 5000);
}

export function leaveSession() {
    let uuid = sessionUuid();
    if (!uuid) return;
    let body = JSON.stringify({ session_uuid: uuid });
    try {
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api/sessions/leave', new Blob([body], { type: 'application/json' }));
            return;
        }
    } catch (e) {}
    fetch('/api/sessions/leave', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Session-Uuid': uuid
        },
        body,
        keepalive: true
    }).catch(() => {});
}

export function updateSyncStatus(status, isFallback = false) {
    const pill = $('#sync-status');
    const text = $('#sync-text');
    if (!pill || !text) return;
    text.textContent = status;
    if (isFallback) {
        pill.className = 'status-pill fallback';
    } else {
        pill.className = 'status-pill';
    }
}

export async function refresh(targetRevision = null) {
    if (targetRevision && targetRevision < store.currentRevision) return;
    let p = await api(`circuits/${store.board.circuit.id}`);
    if (p?.circuit?.revision !== undefined && p.circuit.revision < store.currentRevision) return;
    store.board = { ...store.board, ...p };
    store.currentRevision = store.board.circuit?.revision || store.currentRevision;
    render();
    run();
}

export function startFallback() {
    if (store.poll) return;
    updateSyncStatus('LIVE · FALLBACK', true);
    store.poll = setInterval(() => {
        refresh()
            .then(() => updateSyncStatus('LIVE · FALLBACK', true))
            .catch(() => updateSyncStatus('RECONNECTING…', true));
    }, 2500);
}

export function updateRemoteCursor(data) {
    if (!data || data.session_uuid === localStorage.getItem('ch-session')) return;
    store.cursors[data.session_uuid] = data;

    let layer = $('#cursors') || $('#nodes');
    if (!layer) return;

    let el = document.getElementById(`cursor-${data.session_uuid}`);
    let p = position({ pos_x: data.x, pos_y: data.y });

    if (!el) {
        el = document.createElement('span');
        el.id = `cursor-${data.session_uuid}`;
        el.className = 'remote-cursor';
        layer.appendChild(el);
    }

    el.innerHTML = `<i></i>${escapeHtml(data.name || 'Peer')}`;
    el.style.left = `${p.x}px`;
    el.style.top = `${p.y}px`;
}

export function connectRealtime() {
    if (!window.joinCircuitPresence) return startFallback();
    if (store.poll) {
        clearInterval(store.poll);
        store.poll = null;
    }

    store.remoteUsers = null;
    store.cursors = {};

    if (window.Echo && store.prevCircuitId != null && store.prevCircuitId !== store.board.circuit.id) {
        window.Echo.leave(`circuit.${store.prevCircuitId}`);
    }
    store.prevCircuitId = store.board.circuit.id;

    store.channel = window.joinCircuitPresence(store.board.circuit.id, localStorage.getItem('ch-session'), {
        here: users => {
            store.remoteUsers = users;
            updateSyncStatus('LIVE');
            render();
        },
        joining: user => {
            store.remoteUsers = [...(store.remoteUsers || []), user];
            render();
        },
        leaving: user => {
            store.remoteUsers = (store.remoteUsers || []).filter(x => x.session_uuid !== user.session_uuid);
            delete store.cursors[user.session_uuid];
            const el = document.getElementById(`cursor-${user.session_uuid}`);
            if (el) el.remove();
            render();
        },
        changed: event => {
            if (event?.revision && event.revision <= store.currentRevision) return;
            refresh(event?.revision).catch(startFallback);
        },
        cursor: data => {
            updateRemoteCursor(data);
        },
        error: () => startFallback()
    });

    if (window.bindEchoStateChange) {
        window.bindEchoStateChange(states => {
            if (states.current === 'connected') {
                if (store.poll) { clearInterval(store.poll); store.poll = null; }
                updateSyncStatus('LIVE');
                refresh().catch(() => {});
            } else if (states.current === 'connecting') {
                updateSyncStatus('CONNECTING…');
            } else if (['unavailable', 'failed', 'disconnected'].includes(states.current)) {
                startFallback();
            }
        });
    }
}

let cursorTimer = 0;

export function bindCursorWhisper() {
    $('#canvas').addEventListener('pointermove', ev => {
        if (!store.channel || Date.now() - cursorTimer < 35) return;
        cursorTimer = Date.now();
        let p = screenToModel(ev.clientX, ev.clientY);
        store.channel.whisper('cursor', {
            x: p.pos_x,
            y: p.pos_y,
            name: store.board?.display_name || localStorage.getItem('ch-display-name') || localStorage.getItem('ch-name') || 'Peer',
            session_uuid: localStorage.getItem('ch-session')
        });
    });
}
