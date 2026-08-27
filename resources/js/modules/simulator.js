// Combinational logic simulation and truth table generation.

import { $, escapeHtml, toast, getState, store } from './state.js';
import { label, pins, renderWires } from './canvas.js';

export function evaluate(inputs = {}) {
    let cs = getState().components;
    let ws = getState().wires;
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

export function run() {
    store.values = evaluate();
    renderWires();

    let rows = getState().components.filter(c => c.type === 'OUTPUT').map(c => `
        <div class="signal-row">
            <span>${escapeHtml(label(c))}</span>
            <b class="value ${store.values[c.id] ? 'one' : ''}">${store.values[c.id] === undefined ? 'NO SIGNAL' : (store.values[c.id] ? '1 · HIGH' : '0 · LOW')}</b>
        </div>
    `).join('');

    $('#monitor').innerHTML = rows || '<span>No output pins connected</span>';
}

export function truth() {
    let ins = getState().components.filter(c => c.type === 'INPUT');
    let outs = getState().components.filter(c => c.type === 'OUTPUT');
    if (!ins.length && !outs.length) return toast('Add input and output components first.');
    if (ins.length > 7) return toast('Truth tables are limited to 7 inputs.');

    let h = `
        <h2 style="margin:0 0 4px;font-size:20px;letter-spacing:-0.5px;">Truth Table Verification</h2>
        <p style="color:var(--text-muted);font-size:12px;margin:0 0 16px;">${ins.length} Inputs · ${outs.length} Outputs · ${2 ** ins.length} Combinational States</p>
        <div style="overflow-x:auto;">
        <table class="truth-table">
            <thead>
                <tr>
                    ${ins.map(c => `<th>${escapeHtml(label(c))}</th>`).join('')}
                    ${outs.map(c => `<th style="color:var(--accent-amber);">${escapeHtml(label(c))}</th>`).join('')}
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
