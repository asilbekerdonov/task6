// UI wiring: lobby, board switching, toolbar, palette, modals, hotkeys.

import { $, escapeHtml, toast, getState, store, gates } from './state.js';
import { api } from './api.js';
import { render } from './canvas.js';
import { run, truth } from './simulator.js';
import { startHeartbeat, connectRealtime, leaveSession, pingSession, refresh } from './realtime.js';
import { exportPdf } from './exporter.js';

export async function loadCircuitOptions() {
    let circuits = await api('circuits').catch(() => []);

    // Lobby select
    let lobbySelect = $('#lobby-circuit-select');
    if (lobbySelect) {
        let opts = `<option value="new">+ Create New Board...</option>`;
        circuits.forEach(c => {
            opts += `<option value="${c.id}">${escapeHtml(c.name)} (${c.grid_size}px grid)</option>`;
        });
        lobbySelect.innerHTML = opts;
        if (circuits.length > 0) {
            lobbySelect.value = circuits[0].id;
            $('#new-board-fields').style.display = 'none';
        } else {
            lobbySelect.value = 'new';
            $('#new-board-fields').style.display = 'grid';
        }
    }

    // Topbar switch select
    let switchSelect = $('#switch-board-select');
    if (switchSelect) {
        let opts = `<option value="" disabled>Switch Board...</option>`;
        circuits.forEach(c => {
            let isCurrent = store.board && store.board.circuit?.id === c.id;
            opts += `<option value="${c.id}" ${isCurrent ? 'selected' : ''}>${escapeHtml(c.name)}</option>`;
        });
        switchSelect.innerHTML = opts;
    }
}

async function add(type) {
    let n = getState().components.length;
    let grid = store.board.circuit.grid_size;
    let c = await api(`circuits/${store.board.circuit.id}/components`, 'POST', {
        type,
        pos_x: Math.min(1120, 80 + (n % 5) * 190),
        pos_y: Math.min(640, 80 + Math.floor(n / 5) * 130),
        label: type === 'INPUT' ? `Input ${getState().components.filter(x => x.type === 'INPUT').length + 1}` :
               type === 'OUTPUT' ? `Output ${getState().components.filter(x => x.type === 'OUTPUT').length + 1}` : null
    });
    store.board.components.push(c);
    store.selected = c.id;
    render();
    run();
}

async function load3InverterDemo() {
    if (getState().components.length && !confirm('Load the 3-Inverter (strictly 2 NOTs) demonstration schematic? Current elements will be replaced.')) {
        return;
    }
    toast('Generating 3-Inverter circuit...');
    let res = await api(`circuits/${store.board.circuit.id}/demo`, 'POST');
    store.board = { ...store.board, ...res };
    store.currentRevision = store.board.circuit?.revision || store.currentRevision;
    store.selected = null;
    render();
    run();
    toast('3-Inverter demonstration loaded (uses strictly 2 NOT gates).');
}

async function clearBoard() {
    if (!confirm('Clear all components and wires from this board?')) return;
    let res = await api(`circuits/${store.board.circuit.id}/clear`, 'POST');
    store.board = { ...store.board, ...res };
    store.currentRevision = store.board.circuit?.revision || store.currentRevision;
    store.selected = null;
    store.values = {};
    render();
    run();
    toast('Board cleared.');
}

async function joinCircuit(circuitId) {
    let name = $('#name').value.trim();
    if (!name) return toast('Add a name to continue.');
    localStorage.setItem('ch-name', name);

    let p = await api(`circuits/${circuitId}/join`, 'POST', {
        name,
        session_uuid: localStorage.getItem('ch-session') || null
    });

    localStorage.setItem('ch-session', p.session_uuid);
    if (p.display_name) {
        localStorage.setItem('ch-display-name', p.display_name);
    }
    store.board = p;
    store.currentRevision = store.board.circuit?.revision || 0;

    $('#lobby').hidden = true;
    $('#workspace').hidden = false;

    await loadCircuitOptions();
    render();
    run();
    startHeartbeat();
    connectRealtime();
}

async function createAndJoinCircuit(name, gridSize) {
    let userName = $('#name').value.trim();
    if (!userName) return toast('Add a name to continue.');
    localStorage.setItem('ch-name', userName);

    let c = await api('circuits', 'POST', {
        name: name || 'Engineering Signal Lab',
        grid_size: +gridSize || 20
    });

    await joinCircuit(c.id);
}

async function enter() {
    let choice = $('#lobby-circuit-select').value;
    if (choice === 'new') {
        let boardName = $('#new-circuit-name').value.trim();
        let gridSize = $('#new-circuit-grid').value;
        await createAndJoinCircuit(boardName, gridSize);
    } else {
        await joinCircuit(choice);
    }
}

export function initUi() {
    $('#continue').onclick = () => enter().catch(e => toast(e.message));
    $('#name').addEventListener('keydown', e => e.key === 'Enter' && enter().catch(x => toast(x.message)));
    $('#name').value = localStorage.getItem('ch-name') || '';

    $('#lobby-circuit-select').onchange = e => {
        $('#new-board-fields').style.display = e.target.value === 'new' ? 'grid' : 'none';
    };

    $('#switch-board-select').onchange = e => {
        if (e.target.value) {
            joinCircuit(e.target.value).catch(err => toast(err.message));
        }
    };

    $('#palette').innerHTML = gates.map(g => `
        <button data-gate="${g}">
            <span class="gate-icon">${g === 'INPUT' ? '→' : g === 'OUTPUT' ? '←' : g}</span>
            <small>${g === 'INPUT' ? 'Signal Source' : g === 'OUTPUT' ? 'Signal Probe' : 'Logic Gate'}</small>
        </button>
    `).join('');

    $('#palette').onclick = e => {
        let b = e.target.closest('[data-gate]');
        if (b) add(b.dataset.gate).catch(x => toast(x.message));
    };

    $('#run').onclick = () => {
        run();
        toast('Simulation recalculated.');
    };

    $('#truth').onclick = truth;
    $('#demo').onclick = () => load3InverterDemo().catch(e => toast(e.message));
    $('#clear').onclick = () => clearBoard().catch(e => toast(e.message));
    $('#export').onclick = () => exportPdf().catch(e => toast(e.message));

    $('#new-circuit').onclick = () => {
        $('#create-modal').showModal();
    };

    $('#cancel-create-board').onclick = () => {
        $('#create-modal').close();
    };

    $('#create-board-form').onsubmit = async e => {
        e.preventDefault();
        let name = $('#modal-circuit-name').value.trim();
        let grid = $('#modal-circuit-grid').value;
        $('#create-modal').close();
        await createAndJoinCircuit(name, grid).catch(err => toast(err.message));
    };

    $('#exit-lobby').onclick = () => {
        if (window.Echo && store.board?.circuit?.id) {
            window.Echo.leave(`circuit.${store.board.circuit.id}`);
        }
        leaveSession();
        if (store.heartbeat) { clearInterval(store.heartbeat); store.heartbeat = null; }
        if (store.poll) { clearInterval(store.poll); store.poll = null; }
        store.board = null;
        $('#workspace').hidden = true;
        $('#lobby').hidden = false;
        loadCircuitOptions();
    };

    $('.close-modal').onclick = () => $('#modal').close();

    document.addEventListener('keydown', e => {
        if (e.key === 'Delete' && store.selected) {
            let c = getState().components.find(x => x.id === store.selected);
            if (c && confirm('Delete this component?')) {
                api(`components/${c.id}`, 'DELETE').then(() => {
                    store.selected = null;
                    refresh();
                });
            }
        }
    });

    window.addEventListener('resize', render);
    window.addEventListener('pagehide', leaveSession);
    window.addEventListener('beforeunload', leaveSession);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            if (!store.heartbeat && store.board) startHeartbeat();
            else if (store.board) pingSession();
        }
    });
}
