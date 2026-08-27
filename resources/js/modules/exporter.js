// Schematic export: vector PDF via jsPDF + svg2pdf, with SVG fallback.

import { store, getState, escapeHtml, toast } from './state.js';
import { label } from './canvas.js';

export async function exportPdf() {
    const w = store.board?.circuit?.canvas_width || 1200;
    const h = store.board?.circuit?.canvas_height || 720;

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
  ${getState().wires.map(w => {
      let a = getState().components.find(c => c.id === w.from_component_id);
      let b = getState().components.find(c => c.id === w.to_component_id);
      if (!a || !b) return '';
      let x1 = a.pos_x + 108, y1 = a.pos_y + 28;
      let x2 = b.pos_x, y2 = b.pos_y + (w.to_pin ? 44 : 28);
      let mx = (x1 + x2) / 2;
      let high = store.values[a.id];
      return `<path class="wire ${high ? 'high' : ''}" d="M${x1},${y1} C${mx},${y1} ${mx},${y2} ${x2},${y2}"/>`;
  }).join('')}

  <!-- COMPONENTS -->
  ${getState().components.map(c => {
      let t = c.type.toLowerCase();
      let txt = c.type === 'INPUT' ? (store.values[c.id] ? 'HIGH 1' : 'LOW 0') : (c.type === 'OUTPUT' ? (store.values[c.id] ? '1' : '0') : c.type);
      return `
      <g transform="translate(${c.pos_x}, ${c.pos_y})">
        <rect width="108" height="56" class="gate-box ${t}"/>
        <text x="54" y="28" class="gate-text">${txt}</text>
        <text x="54" y="70" class="gate-label">${escapeHtml(label(c))}</text>
        <circle cx="0" cy="28" r="6" class="pin-point"/>
        <circle cx="108" cy="28" r="6" class="pin-point"/>
      </g>`;
  }).join('')}
</svg>`;

    const host = document.createElement('div');
    host.style.position = 'absolute';
    host.style.left = '-99999px';
    host.style.top = '0';
    host.innerHTML = svgData;
    document.body.appendChild(host);
    const svgEl = host.firstElementChild;

    try {
        if (window.jspdf && window.svg2pdf) {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF({
                orientation: w >= h ? 'landscape' : 'portrait',
                unit: 'pt',
                format: [w, h]
            });
            await window.svg2pdf.svg2pdf(svgEl, doc, { x: 0, y: 0, width: w, height: h });
            doc.save(`${store.board?.circuit?.name || 'circuit'}-schematic.pdf`);
            toast('PDF schematic downloaded.');
        } else {
            const blob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${store.board?.circuit?.name || 'circuit'}-schematic.svg`;
            a.click();
            URL.revokeObjectURL(url);
            toast('SVG schematic downloaded.');
        }
    } finally {
        host.remove();
    }
}
