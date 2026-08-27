// Canvas rendering: nodes, pins, wires, drag-and-drop, inspector.

import { $, escapeHtml, toast, getState, store } from './state.js';
import { api } from './api.js';
import { run } from './simulator.js';
import { refresh } from './realtime.js';

export function label(c) {
    return c.label || ({ INPUT: 'Input', OUTPUT: 'Output' }[c.type] || c.type + ' gate');
}

export function pins(c) {
    return c.type === 'INPUT' ? { in: 0, out: 1 } :
           c.type === 'OUTPUT' || c.type === 'NOT' ? { in: 1, out: 1 } :
           { in: 2, out: 1 };
}

export function position(c) {
    const el = $('#canvas');
    const w = store.board?.circuit?.canvas_width || 1200;
    const h = store.board?.circuit?.canvas_height || 720;
    const x = (c.pos_x / w) * el.clientWidth;
    const y = (c.pos_y / h) * el.clientHeight;
    return { x, y };
}

export function screenToModel(x, y) {
    const r = $('#canvas').getBoundingClientRect();
    const grid = store.board?.circuit?.grid_size || 20;
    const w = store.board?.circuit?.canvas_width || 1200;
    const h = store.board?.circuit?.canvas_height || 720;
    return {
        pos_x: Math.max(0, Math.min(1160, Math.round(((x - r.left) / r.width * w) / grid) * grid)),
        pos_y: Math.max(0, Math.min(680, Math.round(((y - r.top) / r.height * h) / grid) * grid))
    };
}

export function render() {
    if (!store.board) return;
    $('#board-title').textContent = store.board.circuit.name;
    $('#grid-readout').textContent = store.board.circuit.grid_size;
    $('#canvas').style.backgroundSize = `${store.board.circuit.grid_size}px ${store.board.circuit.grid_size}px`;

    $('#empty').hidden = getState().components.length > 0;

    // Collaborative team avatars
    let people = store.remoteUsers || store.board.active_participants || (store.board.participants || []).map(p => ({
        name: p.display_name,
        session_uuid: p.session_uuid
    }));

    $('#team').innerHTML = people.map(p => {
        let name = p.name || p.display_name || 'User';
        const numMatch = name.match(/\d+$/);
        const initial = numMatch ? (name[0].toUpperCase() + numMatch[0]) : name.slice(0, 2).toUpperCase();
        return `<span class="avatar" title="${escapeHtml(name)}">${initial}</span>`;
    }).join('');

    // Canvas nodes
    let layer = $('#nodes');
    layer.innerHTML = '';

    getState().components.forEach(c => {
        let p = position(c);
        let pc = pins(c);
        let e = document.createElement('div');
        let isInputActive = c.type === 'INPUT' && !!(store.values[c.id] ?? c.initial_value);
        e.className = `node ${c.type.toLowerCase()} ${store.selected === c.id ? 'selected' : ''} ${isInputActive ? 'active' : ''}`;
        e.dataset.id = c.id;
        e.style.left = p.x + 'px';
        e.style.top = p.y + 'px';

        let innerContent = c.type === 'INPUT'
            ? (store.values[c.id] ? 'HIGH · 1' : 'LOW · 0')
            : (c.type === 'OUTPUT' ? (store.values[c.id] ? '1' : '0') : c.type);

        e.innerHTML = `
            <div class="body">${innerContent}</div>
            ${pc.in ? Array.from({ length: pc.in }, (_, i) => `<i class="pin in ${i ? 'two' : ''}" data-pin="${i}" title="Input ${i + 1}"></i>`).join('') : ''}
            ${pc.out ? `<i class="pin out" data-pin="0" title="Output"></i>` : ''}
            <span class="label">${escapeHtml(label(c))}</span>
        `;

        e.querySelector('.body').onpointerdown = ev => startDrag(ev, c);
        e.querySelector('.body').onclick = ev => {
            if (!store.drag) {
                if (c.type === 'INPUT') {
                    toggleInputValue(c);
                }
                store.selected = c.id;
                renderInspector();
                render();
            }
        };
        e.querySelectorAll('.pin').forEach(pin => pin.onclick = ev => connect(ev, c, pin));
        layer.append(e);
    });

    renderCursors();
    renderWires();
    renderInspector();
}

export function renderCursors() {
    let layer = $('#cursors') || $('#nodes');
    if (!layer) return;

    layer.querySelectorAll('.remote-cursor').forEach(el => {
        const id = el.id.replace('cursor-', '');
        if (!store.cursors[id] || id === localStorage.getItem('ch-session')) {
            el.remove();
        }
    });

    Object.entries(store.cursors).forEach(([id, c]) => {
        if (id === localStorage.getItem('ch-session')) return;
        let p = position({ pos_x: c.x, pos_y: c.y });
        let el = document.getElementById(`cursor-${id}`);
        if (!el) {
            el = document.createElement('span');
            el.id = `cursor-${id}`;
            el.className = 'remote-cursor';
            el.innerHTML = `<i></i>${escapeHtml(c.name || 'Peer')}`;
            layer.appendChild(el);
        }
        el.style.left = `${p.x}px`;
        el.style.top = `${p.y}px`;
    });
}

export function renderWires() {
    let s = $('#wires');
    s.querySelectorAll('.wire').forEach(x => x.remove());

    getState().wires.forEach(w => {
        let a = getState().components.find(c => c.id === w.from_component_id);
        let b = getState().components.find(c => c.id === w.to_component_id);
        if (!a || !b) return;

        let x1 = a.pos_x + 108, y1 = a.pos_y + 28;
        let x2 = b.pos_x, y2 = b.pos_y + (w.to_pin ? 44 : 28);
        let mx = (x1 + x2) / 2;

        let high = store.values[a.id];
        s.insertAdjacentHTML('beforeend', `<path class="wire ${high ? 'high' : ''}" d="M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}"/>`);
    });
}

export async function connect(ev, c, pin) {
    ev.stopPropagation();
    let out = pin.classList.contains('out');
    if (out) {
        store.armed = { id: c.id, pin: +pin.dataset.pin };
        toast(`Source ${label(c)} armed — click a destination input pin.`);
        document.querySelectorAll('.pin.out').forEach(x => x.classList.remove('armed'));
        pin.classList.add('armed');
        return;
    }
    if (!store.armed) return toast('Select an output pin first.');

    try {
        await api(`circuits/${store.board.circuit.id}/wires`, 'POST', {
            from_component_id: store.armed.id,
            from_pin: store.armed.pin,
            to_component_id: c.id,
            to_pin: +pin.dataset.pin
        });
        store.armed = null;
        await refresh();
        toast('Wire connected.');
    } catch (e) {
        toast(e.message);
    }
}

export function startDrag(ev, c) {
    ev.preventDefault();
    store.selected = c.id;
    renderInspector();
    store.drag = { c, startX: ev.clientX, startY: ev.clientY, ox: c.pos_x, oy: c.pos_y, moved: false };
    window.addEventListener('pointermove', moveDrag);
    window.addEventListener('pointerup', endDrag, { once: true });
}

export function moveDrag(ev) {
    if (!store.drag) return;
    const dx = ev.clientX - store.drag.startX;
    const dy = ev.clientY - store.drag.startY;
    if (!store.drag.moved && Math.hypot(dx, dy) <= 4) return;
    store.drag.moved = true;
    let w = store.board?.circuit?.canvas_width || 1200;
    let h = store.board?.circuit?.canvas_height || 720;
    let scaleX = w / $('#canvas').clientWidth;
    let scaleY = h / $('#canvas').clientHeight;

    store.drag.c.pos_x = Math.max(0, Math.min(1160, store.drag.ox + dx * scaleX));
    store.drag.c.pos_y = Math.max(0, Math.min(680, store.drag.oy + dy * scaleY));
    render();
}

export async function endDrag() {
    window.removeEventListener('pointermove', moveDrag);
    if (!store.drag) return;
    let c = store.drag.c;
    let moved = store.drag.moved;
    store.drag = null;
    if (moved) {
        const grid = store.board.circuit.grid_size;
        c.pos_x = Math.round(c.pos_x / grid) * grid;
        c.pos_y = Math.round(c.pos_y / grid) * grid;
        render();
        await api(`components/${c.id}`, 'PATCH', { pos_x: c.pos_x, pos_y: c.pos_y }).catch(e => toast(e.message));
    }
}

export function renderInspector() {
    let c = getState().components.find(x => x.id === store.selected);
    let box = $('#inspector');
    $('#selected-type').textContent = c ? c.type : '—';
    if (!c) {
        box.innerHTML = '<div class="no-selection">Select any component on the canvas to inspect pins and parameters.</div>';
        return;
    }

    box.innerHTML = `
        <div class="field">
            <label>LABEL</label>
            <input id="label-edit" value="${escapeHtml(label(c))}">
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
        store.selected = null;
        refresh();
    };
}

export async function toggleInputValue(c) {
    c.initial_value = !c.initial_value;
    store.values[c.id] = c.initial_value;
    run();
    render();
    await api(`components/${c.id}`, 'PATCH', { initial_value: c.initial_value }).catch(e => toast(e.message));
}
