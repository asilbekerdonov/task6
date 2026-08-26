const $ = s => document.querySelector(s), csrf = $('meta[name=csrf-token]').content;

const api = async (path, method = 'GET', body) => {
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
        let e = await r.json().catch(() => ({}));
        throw Error(e.message || 'Could not save this change.');
    }
    return r.status === 204 ? null : r.json();
};

const gates = ['INPUT', 'OUTPUT', 'AND', 'OR', 'NOT', 'XOR', 'NOR', 'NAND'];
let board = null, selected = null, armed = null, values = {}, drag = null;
let poll = null, channel = null, remoteUsers = null, cursors = {}, heartbeat = null;
let currentRevision = 0;

const sessionUuid = () => localStorage.getItem('ch-session');

function pingSession() {
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

function startHeartbeat() {
    if (heartbeat) return;
    pingSession();
    heartbeat = setInterval(pingSession, 5000);
}

function leaveSession() {
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

const state = () => ({
    components: board?.components || [],
    wires: board?.wires || []
});

function toast(t) {
    let e = document.createElement('div');
    e.className = 'toast';
    e.textContent = t;
    document.body.append(e);
    setTimeout(() => e.remove(), 2500);
}

function label(c) {
    return c.label || ({ INPUT: 'Input', OUTPUT: 'Output' }[c.type] || c.type + ' gate');
}

function pins(c) {
    return c.type === 'INPUT' ? { in: 0, out: 1 } :
           c.type === 'OUTPUT' || c.type === 'NOT' ? { in: 1, out: 1 } :
           { in: 2, out: 1 };
}

function position(c) {
    const el = $('#canvas');
    const w = board?.circuit?.canvas_width || 1200;
    const h = board?.circuit?.canvas_height || 720;
    const x = (c.pos_x / w) * el.clientWidth;
    const y = (c.pos_y / h) * el.clientHeight;
    return { x, y };
}

function screenToModel(x, y) {
    const r = $('#canvas').getBoundingClientRect();
    const grid = board?.circuit?.grid_size || 20;
    const w = board?.circuit?.canvas_width || 1200;
    const h = board?.circuit?.canvas_height || 720;
    return {
        pos_x: Math.max(0, Math.min(1160, Math.round(((x - r.left) / r.width * w) / grid) * grid)),
        pos_y: Math.max(0, Math.min(680, Math.round(((y - r.top) / r.height * h) / grid) * grid))
    };
}

function updateSyncStatus(status, isFallback = false) {
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

function render() {
    if (!board) return;
    $('#circuit-name').textContent = $('#board-title').textContent = board.circuit.name;
    $('#grid-readout').textContent = board.circuit.grid_size;
    $('#canvas').style.backgroundSize = `${board.circuit.grid_size}px ${board.circuit.grid_size}px`;

    $('#empty').hidden = state().components.length > 0;
    
    // Render Collaborative Team Avatars
    let people = remoteUsers || board.active_participants || (board.participants || []).map(p => ({
        name: p.display_name,
        session_uuid: p.session_uuid
    }));
    
    $('#team').innerHTML = people.map(p => {
        let name = p.name || p.display_name || 'User';
        let initial = name.slice(0, 2).toUpperCase();
        return `<span class="avatar" title="${name}">${initial}</span>`;
    }).join('');

    // Render Canvas Nodes
    let layer = $('#nodes');
    layer.innerHTML = '';
    
    state().components.forEach(c => {
        let p = position(c);
        let pc = pins(c);
        let e = document.createElement('div');
        let isInputActive = c.type === 'INPUT' && !!(values[c.id] ?? c.initial_value);
        e.className = `node ${c.type.toLowerCase()} ${selected === c.id ? 'selected' : ''} ${isInputActive ? 'active' : ''}`;
        e.dataset.id = c.id;
        e.style.left = p.x + 'px';
        e.style.top = p.y + 'px';

        let innerContent = c.type === 'INPUT' 
            ? (values[c.id] ? 'HIGH · 1' : 'LOW · 0')
            : (c.type === 'OUTPUT' ? (values[c.id] ? '1' : '0') : c.type);

        e.innerHTML = `
            <div class="body">${innerContent}</div>
            ${pc.in ? Array.from({ length: pc.in }, (_, i) => `<i class="pin in ${i ? 'two' : ''}" data-pin="${i}" title="Input ${i + 1}"></i>`).join('') : ''}
            ${pc.out ? `<i class="pin out" data-pin="0" title="Output"></i>` : ''}
            <span class="label">${label(c)}</span>
        `;

        e.querySelector('.body').onpointerdown = ev => startDrag(ev, c);
        e.querySelector('.body').onclick = ev => {
            if (!drag) {
                if (c.type === 'INPUT') {
                    // Quick toggle on click for inputs!
                    toggleInputValue(c);
                }
                selected = c.id;
                renderInspector();
                render();
            }
        };
        e.querySelectorAll('.pin').forEach(pin => pin.onclick = ev => connect(ev, c, pin));
        layer.append(e);
    });

    // Render Remote Collaborator Cursors
    Object.entries(cursors).forEach(([id, c]) => {
        if (id === localStorage.getItem('ch-session')) return;
        let p = position({ pos_x: c.x, pos_y: c.y });
        layer.insertAdjacentHTML('beforeend', `<span class="remote-cursor" style="left:${p.x}px;top:${p.y}px"><i></i>${c.name || 'Peer'}</span>`);
    });

    renderWires();
    renderInspector();
}

function renderWires() {
    let s = $('#wires');
    s.querySelectorAll('.wire').forEach(x => x.remove());
    
    state().wires.forEach(w => {
        let a = state().components.find(c => c.id === w.from_component_id);
        let b = state().components.find(c => c.id === w.to_component_id);
        if (!a || !b) return;

        let A = position(a), B = position(b);
        let x1 = A.x + 108, y1 = A.y + 28;
        let x2 = B.x, y2 = B.y + (w.to_pin ? 44 : 28);
        let mx = (x1 + x2) / 2;

        let high = values[a.id];
        s.insertAdjacentHTML('beforeend', `<path class="wire ${high ? 'high' : ''}" d="M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}"/>`);
    });
}

async function connect(ev, c, pin) {
    ev.stopPropagation();
    let out = pin.classList.contains('out');
    if (out) {
        armed = { id: c.id, pin: +pin.dataset.pin };
        toast(`Source ${label(c)} armed — click a destination input pin.`);
        document.querySelectorAll('.pin.out').forEach(x => x.classList.remove('armed'));
        pin.classList.add('armed');
        return;
    }
    if (!armed) return toast('Select an output pin first.');

    try {
        await api(`circuits/${board.circuit.id}/wires`, 'POST', {
            from_component_id: armed.id,
            from_pin: armed.pin,
            to_component_id: c.id,
            to_pin: +pin.dataset.pin
        });
        armed = null;
        await refresh();
        toast('Wire connected.');
    } catch (e) {
        toast(e.message);
    }
}

function startDrag(ev, c) {
    ev.preventDefault();
    drag = { c, startX: ev.clientX, startY: ev.clientY, ox: c.pos_x, oy: c.pos_y };
    window.addEventListener('pointermove', moveDrag);
    window.addEventListener('pointerup', endDrag, { once: true });
}

function moveDrag(ev) {
    if (!drag) return;
    let w = board?.circuit?.canvas_width || 1200;
    let h = board?.circuit?.canvas_height || 720;
    let scaleX = w / $('#canvas').clientWidth;
    let scaleY = h / $('#canvas').clientHeight;
    let grid = board.circuit.grid_size;

    drag.c.pos_x = Math.max(0, Math.min(1160, Math.round((drag.ox + (ev.clientX - drag.startX) * scaleX) / grid) * grid));
    drag.c.pos_y = Math.max(0, Math.min(680, Math.round((drag.oy + (ev.clientY - drag.startY) * scaleY) / grid) * grid));
    render();
}

async function endDrag() {
    window.removeEventListener('pointermove', moveDrag);
    if (!drag) return;
    let c = drag.c;
    drag = null;
    await api(`components/${c.id}`, 'PATCH', { pos_x: c.pos_x, pos_y: c.pos_y }).catch(e => toast(e.message));
}

function renderInspector() {
    let c = state().components.find(x => x.id === selected);
    let box = $('#inspector');
    $('#selected-type').textContent = c ? c.type : '—';
    if (!c) {
        box.innerHTML = '<div class="no-selection">Select any component on the canvas to inspect pins and parameters.</div>';
        return;
    }

    box.innerHTML = `
        <div class="field">
            <label>LABEL</label>
            <input id="label-edit" value="${label(c)}">
        </div>
        ${c.type === 'INPUT' ? `
        <div class="toggle">
            <span>INPUT VALUE</span>
            <button id="toggle" class="${c.initial_value ? 'on' : ''}">${c.initial_value ? 'HIGH · 1' : 'LOW · 0'}</button>
        </div>` : ''}
        <button class="delete" id="delete">Delete Component</button>
    `;

    $('#label-edit').onchange = async e => {
        await api(`components/${c.id}`, 'PATCH', { label: e.target.value });
        c.label = e.target.value;
        render();
    };

    if ($('#toggle')) {
        $('#toggle').onclick = () => toggleInputValue(c);
    }

    $('#delete').onclick = async () => {
        await api(`components/${c.id}`, 'DELETE');
        selected = null;
        refresh();
    };
}

async function toggleInputValue(c) {
    c.initial_value = !c.initial_value;
    values[c.id] = c.initial_value;
    run();
    render();
    await api(`components/${c.id}`, 'PATCH', { initial_value: c.initial_value }).catch(e => toast(e.message));
}

// TOPOLOGICAL LOGIC SIMULATION
function evaluate(inputs = {}) {
    let cs = state().components;
    let ws = state().wires;
    let out = {};
    let remaining = new Set(cs.map(c => c.id));

    for (let rounds = 0; rounds < cs.length; rounds++) {
        let progressed = false;
        cs.forEach(c => {
            if (!remaining.has(c.id)) return;
            let incoming = ws.filter(w => w.to_component_id === c.id);
            let need = pins(c).in;

            if (c.type === 'INPUT') {
                out[c.id] = inputs[c.id] !== undefined ? !!inputs[c.id] : !!c.initial_value;
                remaining.delete(c.id);
                progressed = true;
                return;
            }

            if (incoming.length < need || incoming.some(w => out[w.from_component_id] === undefined)) {
                return;
            }

            let v = incoming.sort((a, b) => a.to_pin - b.to_pin).map(w => out[w.from_component_id]);

            out[c.id] = ({
                OUTPUT: v[0],
                NOT: !v[0],
                AND: v.every(Boolean),
                NAND: !v.every(Boolean),
                OR: v.some(Boolean),
                NOR: !v.some(Boolean),
                XOR: !!(v[0] ^ v[1])
            })[c.type];

            remaining.delete(c.id);
            progressed = true;
        });
        if (!progressed) break;
    }
    return out;
}

function run() {
    values = evaluate();
    renderWires();
    
    let rows = state().components.filter(c => c.type === 'OUTPUT').map(c => `
        <div class="signal-row">
            <span>${label(c)}</span>
            <b class="value ${values[c.id] ? 'one' : ''}">${values[c.id] ? '1 · HIGH' : '0 · LOW'}</b>
        </div>
    `).join('');

    $('#monitor').innerHTML = rows || '<span>No output pins connected</span>';
}

async function add(type) {
    let n = state().components.length;
    let grid = board.circuit.grid_size;
    let c = await api(`circuits/${board.circuit.id}/components`, 'POST', {
        type,
        pos_x: Math.min(1120, 80 + (n % 5) * 190),
        pos_y: Math.min(640, 80 + Math.floor(n / 5) * 130),
        label: type === 'INPUT' ? `Input ${state().components.filter(x => x.type === 'INPUT').length + 1}` :
               type === 'OUTPUT' ? `Output ${state().components.filter(x => x.type === 'OUTPUT').length + 1}` : null
    });
    board.components.push(c);
    selected = c.id;
    render();
    run();
}

function truth() {
    let ins = state().components.filter(c => c.type === 'INPUT');
    let outs = state().components.filter(c => c.type === 'OUTPUT');
    if (!ins.length && !outs.length) return toast('Add input and output components first.');
    if (ins.length > 7) return toast('Truth tables are limited to 7 inputs.');

    let h = `
        <h2 style="margin:0 0 4px;font-size:20px;letter-spacing:-0.5px;">Truth Table Verification</h2>
        <p style="color:var(--text-muted);font-size:12px;margin:0 0 16px;">${ins.length} Inputs · ${outs.length} Outputs · ${2 ** ins.length} Combinational States</p>
        <div style="overflow-x:auto;">
        <table class="truth-table">
            <thead>
                <tr>
                    ${ins.map(c => `<th>${label(c)}</th>`).join('')}
                    ${outs.map(c => `<th style="color:var(--accent-amber);">${label(c)}</th>`).join('')}
                </tr>
            </thead>
            <tbody>
    `;

    for (let i = 0; i < (2 ** ins.length); i++) {
        let vals = {};
        ins.forEach((c, j) => {
            vals[c.id] = !!(i & (1 << (ins.length - j - 1)));
        });
        let o = evaluate(vals);
        h += `
            <tr>
                ${ins.map(c => `<td class="${vals[c.id] ? 'bit-one' : 'bit-zero'}">${+vals[c.id]}</td>`).join('')}
                ${outs.map(c => `<td class="${o[c.id] ? 'bit-one' : 'bit-zero'}">${o[c.id] !== undefined ? +!!o[c.id] : '—'}</td>`).join('')}
            </tr>
        `;
    }

    h += '</tbody></table></div>';
    $('#modal-body').innerHTML = h;
    $('#modal').showModal();
}

async function load3InverterDemo() {
    if (state().components.length && !confirm('Load the 3-Inverter (strictly 2 NOTs) demonstration schematic? Current elements will be replaced.')) {
        return;
    }
    toast('Generating 3-Inverter circuit...');
    let res = await api(`circuits/${board.circuit.id}/demo`, 'POST');
    board = { ...board, ...res };
    currentRevision = board.circuit?.revision || currentRevision;
    selected = null;
    render();
    run();
    toast('3-Inverter demonstration loaded (uses strictly 2 NOT gates).');
}

async function clearBoard() {
    if (!confirm('Clear all components and wires from this board?')) return;
    let res = await api(`circuits/${board.circuit.id}/clear`, 'POST');
    board = { ...board, ...res };
    currentRevision = board.circuit?.revision || currentRevision;
    selected = null;
    values = {};
    render();
    run();
    toast('Board cleared.');
}

function exportSvg() {
    const svg = $('#wires');
    const nodes = $('#nodes');
    const w = board?.circuit?.canvas_width || 1200;
    const h = board?.circuit?.canvas_height || 720;

    let svgData = `
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" style="background:#070b12;font-family:'JetBrains Mono',monospace;">
  <defs>
    <style>
      .wire { fill: none; stroke: #334155; stroke-width: 2.5; }
      .wire.high { stroke: #10b981; stroke-width: 3; }
      .gate-box { fill: #141f36; stroke: #4f46e5; stroke-width: 1.5; rx: 8px; }
      .gate-box.input { stroke: #0284c7; fill: #0f2937; }
      .gate-box.output { stroke: #d97706; fill: #2d1a0e; }
      .gate-box.not { stroke: #e11d48; fill: #2d121e; }
      .gate-text { fill: #f1f5f9; font-size: 13px; font-weight: bold; text-anchor: middle; dominant-baseline: middle; }
      .gate-label { fill: #94a3b8; font-size: 10px; text-anchor: middle; }
      .pin-point { fill: #0f172a; stroke: #64748b; stroke-width: 2; }
    </style>
  </defs>
  <rect width="100%" height="100%" fill="#070b12"/>
  
  <!-- WIRES -->
  ${state().wires.map(w => {
      let a = state().components.find(c => c.id === w.from_component_id);
      let b = state().components.find(c => c.id === w.to_component_id);
      if (!a || !b) return '';
      let x1 = a.pos_x + 108, y1 = a.pos_y + 28;
      let x2 = b.pos_x, y2 = b.pos_y + (w.to_pin ? 44 : 28);
      let mx = (x1 + x2) / 2;
      let high = values[a.id];
      return `<path class="wire ${high ? 'high' : ''}" d="M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}"/>`;
  }).join('')}

  <!-- COMPONENTS -->
  ${state().components.map(c => {
      let t = c.type.toLowerCase();
      let txt = c.type === 'INPUT' ? (values[c.id] ? 'HIGH 1' : 'LOW 0') : (c.type === 'OUTPUT' ? (values[c.id] ? '1' : '0') : c.type);
      return `
      <g transform="translate(${c.pos_x}, ${c.pos_y})">
        <rect width="108" height="56" class="gate-box ${t}"/>
        <text x="54" y="28" class="gate-text">${txt}</text>
        <text x="54" y="70" class="gate-label">${label(c)}</text>
        <circle cx="0" cy="28" r="6" class="pin-point"/>
        <circle cx="108" cy="28" r="6" class="pin-point"/>
      </g>`;
  }).join('')}
</svg>`;

    const blob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${board?.circuit?.name || 'circuit'}-schematic.svg`;
    a.click();
    URL.revokeObjectURL(url);
    toast('Vector SVG schematic downloaded.');
}

async function refresh(targetRevision = null) {
    if (targetRevision && targetRevision < currentRevision) return;
    let p = await api(`circuits/${board.circuit.id}`);
    if (p?.circuit?.revision !== undefined && p.circuit.revision < currentRevision) return;
    board = { ...board, ...p };
    currentRevision = board.circuit?.revision || currentRevision;
    render();
    run();
}

function startFallback() {
    if (poll) return;
    updateSyncStatus('LIVE · FALLBACK', true);
    poll = setInterval(() => {
        refresh()
            .then(() => updateSyncStatus('LIVE · FALLBACK', true))
            .catch(() => updateSyncStatus('RECONNECTING…', true));
    }, 2500);
}

function connectRealtime() {
    if (!window.joinCircuitPresence) return startFallback();
    if (poll) {
        clearInterval(poll);
        poll = null;
    }

    remoteUsers = null;
    cursors = {};

    channel = window.joinCircuitPresence(board.circuit.id, localStorage.getItem('ch-session'), {
        here: users => {
            remoteUsers = users;
            updateSyncStatus('LIVE');
            render();
        },
        joining: user => {
            remoteUsers = [...(remoteUsers || []), user];
            render();
        },
        leaving: user => {
            remoteUsers = (remoteUsers || []).filter(x => x.session_uuid !== user.session_uuid);
            delete cursors[user.session_uuid];
            render();
        },
        changed: event => {
            if (event?.revision && event.revision <= currentRevision) return;
            refresh(event?.revision).catch(startFallback);
        },
        cursor: data => {
            if (data.session_uuid !== localStorage.getItem('ch-session')) {
                cursors[data.session_uuid] = data;
                render();
            }
        },
        error: () => startFallback()
    });

    if (window.bindEchoStateChange) {
        window.bindEchoStateChange(states => {
            if (states.current === 'connected') {
                if (poll) { clearInterval(poll); poll = null; }
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

// Remote cursor whisper broadcast
let cursorTimer = 0;
$('#canvas').addEventListener('pointermove', ev => {
    if (!channel || Date.now() - cursorTimer < 70) return;
    cursorTimer = Date.now();
    let p = screenToModel(ev.clientX, ev.clientY);
    channel.whisper('cursor', {
        x: p.pos_x,
        y: p.pos_y,
        name: localStorage.getItem('ch-name'),
        session_uuid: localStorage.getItem('ch-session')
    });
});

async function enter() {
    let name = $('#name').value.trim();
    if (!name) return toast('Add a name to continue.');
    localStorage.setItem('ch-name', name);

    let circuits = await api('circuits');
    let c = circuits[0] || await api('circuits', 'POST', { name: 'Team Signal Board', grid_size: 20 });
    let p = await api(`circuits/${c.id}/join`, 'POST', {
        name,
        session_uuid: localStorage.getItem('ch-session') || null
    });

    localStorage.setItem('ch-session', p.session_uuid);
    board = p;
    currentRevision = board.circuit?.revision || 0;

    $('#lobby').hidden = true;
    $('#workspace').hidden = false;

    render();
    run();
    startHeartbeat();
    connectRealtime();
}

// BUTTON HANDLERS
$('#continue').onclick = () => enter().catch(e => toast(e.message));
$('#name').addEventListener('keydown', e => e.key === 'Enter' && enter().catch(x => toast(x.message)));
$('#name').value = localStorage.getItem('ch-name') || '';

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
$('#export').onclick = exportSvg;

$('#new-circuit').onclick = async () => {
    let name = prompt('Name for this new board:');
    if (!name) return;
    let c = await api('circuits', 'POST', { name, grid_size: 20 });
    let p = await api(`circuits/${c.id}/join`, 'POST', {
        name: localStorage.getItem('ch-name'),
        session_uuid: localStorage.getItem('ch-session')
    });
    board = p;
    currentRevision = board.circuit?.revision || 0;
    render();
    run();
    startHeartbeat();
    connectRealtime();
};

$('.close-modal').onclick = () => $('#modal').close();

document.addEventListener('keydown', e => {
    if (e.key === 'Delete' && selected) {
        let c = state().components.find(x => x.id === selected);
        if (c && confirm('Delete this component?')) {
            api(`components/${c.id}`, 'DELETE').then(() => {
                selected = null;
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
        if (!heartbeat) startHeartbeat();
        else pingSession();
    }
});
