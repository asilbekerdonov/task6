<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>CircuitHub — Collaborative Logic Studio</title>
    <link rel="stylesheet" href="/circuithub.css">
    @vite('resources/js/app.js')
</head>
<body>
<main id="app">
    <!-- LOBBY SCREEN -->
    <section id="lobby" class="lobby">
        <div class="brand">
            <span class="mark">⌁</span> CIRCUIT<span>HUB</span>
        </div>
        <div class="lobby-grid">
            <div class="hero">
                <p class="eyebrow">Collaborative Logic Dashboard</p>
                <h1>Make the signal<br><em>visible.</em></h1>
                <p class="intro">A high-performance, real-time workspace for engineering teams designing combinational logic, running simulations, and verifying circuits together.</p>
                <div class="hero-line"></div>
                <p class="micro">Zero setup. Instant collaboration for up to 5 remote peers.<br>Enter your name to launch or connect to a live board.</p>
            </div>
            <div class="join-card">
                <p class="eyebrow">VISUAL IDENTIFIER</p>
                <label>
                    What should the room call you?
                    <input id="name" maxlength="48" placeholder="e.g. Ada Lovelace" autocomplete="off">
                </label>
                <p class="hint">If your name matches an active teammate, we'll append a clean numbered identifier.</p>
                <button id="continue" class="primary">
                    Launch Studio <span>→</span>
                </button>
            </div>
        </div>
    </section>

    <!-- WORKSPACE SCREEN -->
    <section id="workspace" hidden>
        <header class="topbar">
            <div class="logo">
                <span class="mark">⌁</span> CIRCUIT<span>HUB</span>
            </div>
            <div class="crumb">
                <span id="circuit-name">Signal Board</span>
                <span id="sync-status" class="status-pill">
                    <span class="status-dot"></span>
                    <span id="sync-text">LIVE</span>
                </span>
            </div>
            <div class="team" id="team"></div>
            <button id="new-circuit" class="text-btn">+ New Board</button>
        </header>

        <div class="shell">
            <!-- PALETTE PANEL -->
            <aside class="left-panel">
                <div class="panel-heading">
                    <p>GATE PALETTE</p>
                    <small>click to add</small>
                </div>
                <div id="palette" class="palette"></div>
                <div class="shortcuts">
                    <p>CANVAS CONTROLS</p>
                    <span><kbd>Click</kbd> Toggle input value (0/1)</span>
                    <span><kbd>Click</kbd> Out-pin → In-pin to wire</span>
                    <span><kbd>Drag</kbd> Move gate with grid snap</span>
                    <span><kbd>Del</kbd> Delete selected gate</span>
                </div>
            </aside>

            <!-- MAIN STUDIO -->
            <section class="studio">
                <div class="studio-head">
                    <div>
                        <p class="eyebrow">Active Schematic</p>
                        <h2 id="board-title">Signal Board</h2>
                    </div>
                    <div class="actions">
                        <button id="demo" class="demo-btn">★ Load 3-Inverter (2 NOTs)</button>
                        <button id="truth">Truth Table</button>
                        <button id="export">Export SVG / PDF</button>
                        <button id="clear">Clear</button>
                        <button id="run" class="run">▶ Run Circuit</button>
                    </div>
                </div>

                <div class="canvas-frame">
                    <div id="canvas" class="canvas">
                        <svg id="wires" viewBox="0 0 1200 720" preserveAspectRatio="none">
                            <defs>
                                <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
                                    <feGaussianBlur stdDeviation="3" result="blur" />
                                    <feMerge>
                                        <feMergeNode in="blur" />
                                        <feMergeNode in="SourceGraphic" />
                                    </feMerge>
                                </filter>
                            </defs>
                        </svg>
                        <div id="nodes"></div>
                        <div id="empty" class="empty-state">
                            <b>Canvas is empty</b>
                            <span>Select a gate on the left or load the 3-Inverter demonstration.</span>
                        </div>
                    </div>
                </div>

                <div class="canvas-foot">
                    <span id="selection-help">Click any INPUT gate to toggle its live signal.</span>
                    <span>GRID <b id="grid-readout">20</b> px</span>
                </div>
            </section>

            <!-- INSPECTOR & MONITOR -->
            <aside class="right-panel">
                <div class="inspector-title">
                    <p>INSPECTOR</p>
                    <span id="selected-type">—</span>
                </div>
                <div id="inspector" class="inspector">
                    <div class="no-selection">Select any component on the canvas to inspect pins and parameters.</div>
                </div>

                <div class="signal-card">
                    <p>SIGNAL MONITOR</p>
                    <div id="monitor">
                        <span>No output pins connected</span>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</main>

<!-- TRUTH TABLE MODAL -->
<dialog id="modal">
    <div id="modal-body"></div>
    <button class="close-modal">Close Window</button>
</dialog>

<script src="/circuithub.js"></script>
</body>
</html>
