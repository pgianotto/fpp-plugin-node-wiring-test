<?php
// Node Wiring Test - step or auto-chase one node at a time across a model's
// real wiring order, using FPP's own channel-test API, so the physical prop
// can be watched against each node's expected position/channel range.
?>
<style>
.nwtApp { max-width: 1000px; }
.nwtApp h2 { margin-bottom: 0.2em; }
.nwtApp .nwtNote { color: #666; font-style: italic; margin-bottom: 1em; }
.nwtApp .nwtRow { display: flex; align-items: center; gap: 0.6em; margin-bottom: 0.6em; flex-wrap: wrap; }
.nwtApp label { font-weight: 600; }
.nwtApp select, .nwtApp input[type=number], .nwtApp input[type=color] { padding: 0.2em 0.4em; }
.nwtApp button { padding: 0.3em 0.9em; cursor: pointer; }
.nwtApp button:disabled { cursor: default; opacity: 0.5; }
.nwtInfoBox { border: 1px solid #ccc; border-radius: 4px; padding: 0.6em 1em; margin: 0.8em 0; background: #fafafa; }
.nwtInfoBox .nwtInfoLine { margin: 0.15em 0; }
.nwtInfoBox .nwtMismatchFlag { color: #b00020; font-weight: 600; }
#nwtGridWrap { overflow: auto; border: 1px solid #ddd; padding: 10px; margin-bottom: 1em; max-height: 480px; background: #111; }
#nwtGridCanvas { display: block; }
#nwtGridPlaceholder { color: #999; padding: 1em; }
.nwtLinearNote { color: #999; font-size: 0.85em; margin-top: -0.4em; margin-bottom: 0.6em; }
.nwtLogTable { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
.nwtLogTable th, .nwtLogTable td { border: 1px solid #ccc; padding: 0.3em 0.5em; text-align: left; font-size: 0.9em; }
.nwtStatus { font-weight: 600; }
.nwtStatus.nwtRunning { color: #0a7d2c; }
#nwtCamWrap { position: relative; display: none; max-width: 640px; background: #000; margin-bottom: 0.6em; }
#nwtCamVideo, #nwtCamImg { width: 100%; display: block; }
#nwtCamOverlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
.nwtCamDot { }
.nwtRowMismatch { background: #fdecea; }
.nwtRowOk { background: #eaf7ec; }
.nwtRowUnknown { background: #f5f5f5; color: #888; }
#nwtFitQuality.nwtFitPoor { color: #b00020; font-weight: 600; }
#nwtFitQuality.nwtFitGood { color: #0a7d2c; font-weight: 600; }
</style>

<div class="nwtApp">
    <h2>Node Wiring Test</h2>
    <p class="nwtNote">
        Picks one model, then commands FPP's own channel-test engine
        (the same one behind Channel Output Testing) to light exactly one
        node's channels at a time, in the model's real wiring order. Watch
        the prop and confirm the lit node matches the position/index shown
        here; log anything that doesn't match. Leaving this page or clicking
        Stop turns the test off and clears the channels.
    </p>

    <div class="nwtRow">
        <label for="nwtModel">Model:</label>
        <select id="nwtModel"><option value="">Loading models...</option></select>
        <button id="nwtRefreshModels" type="button" title="Reload model list">&#x21bb;</button>
    </div>

    <div class="nwtRow">
        <label for="nwtChannelsPerNode">Channels/node:</label>
        <input type="number" id="nwtChannelsPerNode" min="1" max="32" value="3" style="width:4em;">
        <label for="nwtColor">Color:</label>
        <input type="color" id="nwtColor" value="#ffffff">
        <span id="nwtWhiteWrap" style="display:none;">
            <label for="nwtWhite">White:</label>
            <input type="range" id="nwtWhite" min="0" max="255" value="255" style="width:6em;">
        </span>
        <label for="nwtIntervalMs">Interval (ms):</label>
        <input type="number" id="nwtIntervalMs" min="100" max="10000" step="100" value="750" style="width:5.5em;">
    </div>

    <div class="nwtRow">
        <button id="nwtPrev" type="button" disabled>&#9664;&#9664; Prev</button>
        <button id="nwtPlay" type="button" disabled>&#9654; Play</button>
        <button id="nwtNext" type="button" disabled>Next &#9654;&#9654;</button>
        <button id="nwtStop" type="button" disabled>&#9632; Stop</button>
        <span class="nwtStatus" id="nwtStatus">Idle</span>
    </div>

    <div class="nwtInfoBox" id="nwtInfoBox" style="display:none;">
        <div class="nwtInfoLine"><b>Model:</b> <span id="nwtInfoModel"></span></div>
        <div class="nwtInfoLine"><b>Node:</b> <span id="nwtInfoNode"></span></div>
        <div class="nwtInfoLine"><b>Expected buffer position:</b> <span id="nwtInfoPos"></span></div>
        <div class="nwtInfoLine"><b>Absolute channels being driven:</b> <span id="nwtInfoChannels"></span></div>
        <div class="nwtInfoLine">
            <button id="nwtMarkMismatch" type="button">Mark this node as mismatched</button>
            <input type="text" id="nwtMismatchNote" placeholder="optional note (e.g. 'lit where node 12 should be')" style="width:22em;">
        </div>
    </div>

    <div id="nwtGridWrap">
        <canvas id="nwtGridCanvas" style="display:none;"></canvas>
        <div id="nwtGridPlaceholder"></div>
    </div>
    <div class="nwtLinearNote" id="nwtLinearNote" style="display:none;">
        This model's buffer is too large (or has no simple grid) to lay out
        geometrically, so nodes are shown in plain wiring order left to right
        instead of their real physical layout. Node order/index is still
        accurate for diagnosing wiring problems.
    </div>

    <h3>Mismatch log</h3>
    <div class="nwtRow">
        <button id="nwtExportLog" type="button">Export log (CSV)</button>
        <button id="nwtClearLog" type="button">Clear log</button>
    </div>
    <table class="nwtLogTable" id="nwtLogTable">
        <thead><tr><th>Time</th><th>Model</th><th>Node</th><th>Expected position</th><th>Channels</th><th>Note</th></tr></thead>
        <tbody></tbody>
    </table>

    <h3>Camera auto-scan (experimental)</h3>
    <p class="nwtNote">
        Point a camera at the physical prop, then run an auto-scan: it flashes
        each node on/off in order and watches for it in the camera image. No
        manual calibration step is needed - the scan itself fits a position
        mapping from every node it detects, flags any node whose detected
        position doesn't match where it was expected, and can build a
        corrected <code>.xmodel</code> that places each node at its real
        physical position without re-wiring anything.
    </p>

    <div class="nwtRow">
        <label for="nwtCamSource">Camera source:</label>
        <select id="nwtCamSource">
            <option value="local">This device's camera (phone/tablet/laptop)</option>
            <option value="stream">Stream/snapshot URL (e.g. USB camera on the FPP Pi)</option>
        </select>
    </div>
    <div class="nwtRow" id="nwtCamLocalRow">
        <label for="nwtCamDevice">Camera:</label>
        <select id="nwtCamDevice"><option value="">(default)</option></select>
        <button id="nwtCamStart" type="button">Start Camera</button>
    </div>
    <div class="nwtRow" id="nwtCamStreamRow" style="display:none;">
        <label for="nwtCamStreamUrl">Stream URL:</label>
        <input type="text" id="nwtCamStreamUrl" placeholder="http://&lt;pi-ip&gt;:8080/?action=stream" style="width:22em;">
        <button id="nwtCamStreamStart" type="button">Load Stream</button>
        <span class="nwtLinearNote" style="margin:0;">
            Needs something on the Pi already serving MJPEG/snapshots at this
            URL (e.g. mjpg-streamer) - this plugin doesn't install that yet.
        </span>
    </div>

    <div id="nwtCamWrap">
        <video id="nwtCamVideo" autoplay playsinline muted></video>
        <img id="nwtCamImg" alt="camera stream" style="display:none;">
        <canvas id="nwtCamOverlay"></canvas>
    </div>

    <div class="nwtRow">
        <label for="nwtSettleMs">Settle (ms):</label>
        <input type="number" id="nwtSettleMs" value="220" min="50" max="3000" step="10" style="width:5.5em;">
        <button id="nwtRunScan" type="button" disabled>Run Auto Scan</button>
        <button id="nwtCancelScan" type="button" style="display:none;">Cancel</button>
        <span id="nwtScanStatus"></span>
    </div>

    <div id="nwtScanResults" style="display:none;">
        <div class="nwtInfoLine"><b>Calibration fit:</b> <span id="nwtFitQuality"></span></div>
        <div class="nwtRow">
            <button id="nwtDownloadXModel" type="button" disabled>Download corrected .xmodel</button>
        </div>
        <table class="nwtLogTable" id="nwtScanTable">
            <thead><tr><th>Slot (expected node)</th><th>Status</th><th>Physically matches node</th><th>Residual (px, 0-1 scale)</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<script>
(function () {
    'use strict';

    // ---- State -------------------------------------------------------
    var state = {
        models: [],          // list of model names
        model: null,          // raw model config object from /api/models/{name}
        modelName: null,
        layout: null,         // { nodeCount, cpn, startChannel0, visualWidth, visualHeight, nodePos: [{x,y}|null], linear: bool }
        currentIndex: -1,     // 0-based node index currently commanded, or -1
        playTimer: null,
        testActive: false
    };
    var mismatchLog = [];

    // Camera / scan state, kept separate from the manual step/play state above.
    var cam = {
        kind: null,       // 'local' | 'stream' | null
        stream: null,     // MediaStream, for 'local'
        streamRefreshTimer: null,
        scanning: false,
        cancelRequested: false,
        detections: null, // array parallel to layout.nodePos: {x,y,strength}|null (normalized 0-1 camera coords)
        fit: null,        // {a,b,c,d,e,f} affine: camX = a*ex+b*ey+c, camY = d*ex+e*ey+f
        threshold: null,  // residual distance (normalized) above which a node is flagged
        assignment: null  // array parallel to layout.nodePos: node index (0-based) physically occupying that slot
    };
    var CAM_CAPTURE_W = 160, CAM_CAPTURE_H = 120;

    // ---- DOM handles ---------------------------------------------------
    var el = {};
    ['nwtModel', 'nwtRefreshModels', 'nwtChannelsPerNode', 'nwtColor', 'nwtWhiteWrap', 'nwtWhite',
     'nwtIntervalMs', 'nwtPrev', 'nwtPlay', 'nwtNext', 'nwtStop', 'nwtStatus',
     'nwtInfoBox', 'nwtInfoModel', 'nwtInfoNode', 'nwtInfoPos', 'nwtInfoChannels',
     'nwtMarkMismatch', 'nwtMismatchNote', 'nwtGridCanvas', 'nwtGridPlaceholder', 'nwtGridWrap', 'nwtLinearNote',
     'nwtExportLog', 'nwtClearLog', 'nwtLogTable',
     'nwtCamSource', 'nwtCamLocalRow', 'nwtCamStreamRow', 'nwtCamDevice', 'nwtCamStart',
     'nwtCamStreamUrl', 'nwtCamStreamStart', 'nwtCamWrap', 'nwtCamVideo', 'nwtCamImg', 'nwtCamOverlay',
     'nwtSettleMs', 'nwtRunScan', 'nwtCancelScan', 'nwtScanStatus', 'nwtScanResults',
     'nwtFitQuality', 'nwtScanTable', 'nwtDownloadXModel'
    ].forEach(function (id) { el[id] = document.getElementById(id); });

    // ---- FPP API helpers -------------------------------------------------
    function apiGet(path) {
        return fetch(path, { cache: 'no-store' }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status + ' for ' + path); }
            return r.json();
        });
    }
    function apiPostTest(config) {
        return fetch('/api/fppd/testing', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(config)
        }).then(function (r) { return r.json().catch(function () { return {}; }); });
    }
    function stopTestBestEffort() {
        // Used on unload/page hide, where we can't wait on a normal fetch.
        try {
            var blob = new Blob([JSON.stringify({ enabled: 0 })], { type: 'application/json' });
            navigator.sendBeacon('/api/fppd/testing', blob);
        } catch (e) { /* best effort only */ }
    }

    // ---- Model list -------------------------------------------------
    function loadModels() {
        el.nwtModel.innerHTML = '<option value="">Loading models...</option>';
        apiGet('/api/models?simple=true').then(function (data) {
            var names = Array.isArray(data) ? data
                : (Array.isArray(data.models) ? data.models
                : (Array.isArray(data.Models) ? data.Models : []));
            state.models = names;
            if (!names.length) {
                el.nwtModel.innerHTML = '<option value="">No models configured</option>';
                return;
            }
            el.nwtModel.innerHTML = '<option value="">Select a model...</option>' +
                names.map(function (n) {
                    var name = (typeof n === 'string') ? n : (n.Name || n.name || '');
                    return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
                }).join('');
        }).catch(function (err) {
            el.nwtModel.innerHTML = '<option value="">Error loading models</option>';
            setStatus('Error loading models: ' + err.message, false);
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---- Model detail + node layout -------------------------------------
    function onModelChange() {
        stopPlayback();
        disableTest();
        resetScanState();
        var name = el.nwtModel.value;
        state.modelName = name || null;
        state.model = null;
        state.layout = null;
        state.currentIndex = -1;
        el.nwtInfoBox.style.display = 'none';
        renderGridPlaceholder('Select a model to begin.');
        setControlsEnabled(false);
        updateScanButtonEnabled();
        if (!name) { return; }

        apiGet('/api/models/' + encodeURIComponent(name)).then(function (model) {
            state.model = model;
            var layout = computeLayout(model);
            state.layout = layout;
            el.nwtChannelsPerNode.value = layout.cpn;
            el.nwtWhiteWrap.style.display = (layout.cpn >= 4) ? '' : 'none';
            renderGrid(layout);
            setControlsEnabled(layout.nodeCount > 0);
            setStatus('Ready (' + layout.nodeCount + ' node' + (layout.nodeCount === 1 ? '' : 's') + ')', false);
            updateScanButtonEnabled();
        }).catch(function (err) {
            setStatus('Error loading model: ' + err.message, false);
            renderGridPlaceholder('Could not load model definition.');
        });
    }

    // Port of FPP's PixelOverlayModel node ordering (src/overlays/PixelOverlayModel.cpp)
    // so the node a wiring-order step highlights matches what fppd itself will light.
    function computeLayout(m) {
        var type = m.Type || '';
        var orientation = m.Orientation || '';
        var startCorner = m.StartCorner || '';
        var cpn = (m.ChannelCountPerNode === undefined || m.ChannelCountPerNode === null || m.ChannelCountPerNode === '')
            ? 3 : parseInt(m.ChannelCountPerNode, 10);
        if (!cpn || isNaN(cpn)) { cpn = 1; }
        if (type === 'FB') { cpn = 3; }
        cpn = Math.max(1, Math.min(32, cpn));

        var startChannel0 = (parseInt(m.StartChannel, 10) || 0) - 1; // 0-based
        var channelCount = parseInt(m.ChannelCount, 10) || 0;
        var stringCount = parseInt(m.StringCount, 10) || 0;
        var sps = parseInt(m.StrandsPerString, 10) || 1;

        var width, height;
        if (type !== 'Channel') {
            var pixelSize = parseInt(m.PixelSize, 10) || 0;
            var cfgWidth = parseInt(m.Width, 10) || 0;
            var cfgHeight = parseInt(m.Height, 10) || 0;
            width = (pixelSize > 1) ? Math.floor(cfgWidth / pixelSize) : cfgWidth;
            height = (pixelSize > 1) ? Math.floor(cfgHeight / pixelSize) : cfgHeight;
            orientation = 'horizontal';
            startCorner = 'TL';
            channelCount = width * height * cpn;
        } else {
            height = Math.max(1, stringCount * Math.max(1, sps));
            width = Math.floor(channelCount / cpn);
            width = Math.floor(width / height) || 1;
        }

        var TtoB = startCorner.length < 1 || startCorner[0] === 'T';
        var LtoR = startCorner.length < 2 || startCorner[1] === 'L';

        var nodePos = [];
        var visualWidth = width, visualHeight = height;
        var nodeCount = 0;
        var linear = false;

        if (orientation === 'C' || orientation === 'custom') {
            var parsed = parseCustomLayout(m);
            nodePos = parsed.nodePos;
            visualWidth = parsed.width;
            visualHeight = parsed.height;
            nodeCount = parsed.nodeCount;
        } else if (orientation === 'V' || orientation === 'vertical') {
            var nw = height, nh = width; // swapped
            nodePos = new Array(nw * nh).fill(null);
            var sps2 = Math.max(1, sps);
            for (var x = 0; x < nw; x++) {
                var segment = x % sps2;
                for (var y = 0; y < nh; y++) {
                    var outX = LtoR ? x : (nw - 1 - x);
                    var outY = (TtoB === (segment % 2 === 1)) ? (nh - 1 - y) : y;
                    var mpos = outX * nh + outY;
                    if (mpos >= 0 && mpos < nodePos.length) { nodePos[mpos] = { x: x, y: y }; }
                }
            }
            visualWidth = nw; visualHeight = nh;
            nodeCount = nw * nh;
        } else {
            nodePos = new Array(Math.max(0, width * height)).fill(null);
            var sps3 = Math.max(1, sps);
            for (var yy = 0; yy < height; yy++) {
                var seg2 = yy % sps3;
                for (var xx = 0; xx < width; xx++) {
                    var outX2 = (LtoR === (seg2 % 2 === 1)) ? (width - 1 - xx) : xx;
                    var outY2 = TtoB ? yy : (height - 1 - yy);
                    var mpos2 = outY2 * width + outX2;
                    if (mpos2 >= 0 && mpos2 < nodePos.length) { nodePos[mpos2] = { x: xx, y: yy }; }
                }
            }
            visualWidth = width; visualHeight = height;
            nodeCount = width * height;
        }

        if (!nodeCount && channelCount && cpn) {
            nodeCount = Math.floor(channelCount / cpn);
        }

        // No usable geometry (or an unreasonably huge buffer, as a safety
        // valve) -> fall back to a plain left-to-right wiring-order strip.
        // Node order/index is still exactly what will be commanded, just not
        // drawn to scale. The grid itself is canvas-based (see renderGrid),
        // so real props with tens of thousands of nodes (e.g. a big RGB
        // matrix) still get their real layout drawn, just at a small
        // per-cell pixel size.
        if (!visualWidth || !visualHeight || visualWidth * visualHeight > 2000000 || visualWidth * visualHeight <= 0) {
            linear = true;
            visualWidth = nodeCount;
            visualHeight = 1;
            nodePos = [];
            for (var i = 0; i < nodeCount; i++) { nodePos.push({ x: i, y: 0 }); }
        }

        return {
            nodeCount: nodeCount,
            cpn: cpn,
            startChannel0: startChannel0,
            visualWidth: visualWidth,
            visualHeight: visualHeight,
            nodePos: nodePos,
            linear: linear
        };
    }

    function parseCustomLayout(m) {
        var compressed = m.compressedData || '';
        var dense = m.data || '';
        var nodePosByIndex = []; // 0-based node index -> {x,y}
        var width = 1, height = 1, nodeCount = 0;

        if (compressed) {
            var maxRow = 0, maxCol = 0, maxLayer = 0;
            var entries = [];
            compressed.split(';').forEach(function (tok) {
                if (!tok) { return; }
                var parts = tok.split(',');
                if (parts.length < 3) { return; }
                var node = parseInt(parts[0], 10);
                var row = parseInt(parts[1], 10);
                var col = parseInt(parts[2], 10);
                var layer = parts.length >= 4 ? (parseInt(parts[3], 10) || 0) : 0;
                if (!node || node <= 0 || row < 0 || col < 0 || layer < 0) { return; }
                entries.push({ node: node, row: row, col: col, layer: layer });
                maxRow = Math.max(maxRow, row);
                maxCol = Math.max(maxCol, col);
                maxLayer = Math.max(maxLayer, layer);
                nodeCount = Math.max(nodeCount, node);
            });
            width = maxCol + 1;
            height = (maxRow + 1) * (maxLayer + 1);
            var layerHeight = maxRow + 1;
            entries.forEach(function (ne) {
                var y = ne.row + ne.layer * layerHeight;
                nodePosByIndex[ne.node - 1] = { x: ne.col, y: y };
            });
        } else if (dense) {
            var layers = dense.split('|');
            var rows = [];
            width = 1;
            layers.forEach(function (layer) {
                layer.split(';').forEach(function (line) {
                    rows.push(line.split(','));
                    width = Math.max(width, rows[rows.length - 1].length);
                });
            });
            height = Math.max(1, rows.length);
            rows.forEach(function (cells, y) {
                cells.forEach(function (cell, x) {
                    var s = (cell || '').trim();
                    if (s === '' || s.length > 10) { return; }
                    var node = parseInt(s, 10);
                    if (isNaN(node) || node <= 0) { return; }
                    nodePosByIndex[node - 1] = { x: x, y: y };
                    nodeCount = Math.max(nodeCount, node);
                });
            });
        }

        var nodePos = [];
        for (var i = 0; i < nodeCount; i++) { nodePos.push(nodePosByIndex[i] || null); }
        return { nodePos: nodePos, width: width, height: height, nodeCount: nodeCount };
    }

    // ---- Grid rendering (canvas-based so even large real props, like a
    // 100k+ cell RGB matrix, render at a small cell size instead of falling
    // back to a plain strip) ------------------------------------------
    var gridRender = null; // { layout, cell, cellByPos: {"x,y": nodeIdx} }
    var MAX_GRID_CANVAS_W = 1000, MAX_GRID_CANVAS_H = 640;

    function renderGridPlaceholder(msg) {
        el.nwtGridCanvas.style.display = 'none';
        el.nwtGridPlaceholder.style.display = '';
        el.nwtGridPlaceholder.textContent = msg;
        el.nwtLinearNote.style.display = 'none';
        gridRender = null;
    }

    function renderGrid(layout) {
        el.nwtLinearNote.style.display = layout.linear ? '' : 'none';
        el.nwtGridPlaceholder.style.display = 'none';
        el.nwtGridCanvas.style.display = '';

        var cellByPos = {};
        layout.nodePos.forEach(function (pos, idx) {
            if (pos) { cellByPos[pos.x + ',' + pos.y] = idx; }
        });

        var cell = Math.max(1, Math.min(16, Math.floor(Math.min(
            MAX_GRID_CANVAS_W / layout.visualWidth,
            MAX_GRID_CANVAS_H / layout.visualHeight
        ))));
        el.nwtGridCanvas.width = layout.visualWidth * cell;
        el.nwtGridCanvas.height = layout.visualHeight * cell;

        gridRender = { layout: layout, cell: cell, cellByPos: cellByPos };
        drawGridCanvas();
    }

    function drawGridCanvas() {
        if (!gridRender) { return; }
        var layout = gridRender.layout, cell = gridRender.cell, cellByPos = gridRender.cellByPos;
        var ctx = el.nwtGridCanvas.getContext('2d');
        var gap = cell > 3 ? 1 : 0;
        ctx.fillStyle = '#111';
        ctx.fillRect(0, 0, el.nwtGridCanvas.width, el.nwtGridCanvas.height);
        for (var y = 0; y < layout.visualHeight; y++) {
            for (var x = 0; x < layout.visualWidth; x++) {
                var nodeIdx = cellByPos[x + ',' + y];
                if (nodeIdx === undefined) { continue; }
                ctx.fillStyle = (nodeIdx === state.currentIndex) ? '#fff' : '#3a3a3a';
                ctx.fillRect(x * cell, y * cell, cell - gap, cell - gap);
            }
        }
    }

    function highlightCurrent() {
        drawGridCanvas();
        if (!gridRender || state.currentIndex < 0) { return; }
        var pos = gridRender.layout.nodePos[state.currentIndex];
        if (!pos) { return; }
        var cell = gridRender.cell;
        var targetY = pos.y * cell;
        var wrap = el.nwtGridWrap;
        if (targetY < wrap.scrollTop || targetY > wrap.scrollTop + wrap.clientHeight - cell) {
            wrap.scrollTop = Math.max(0, targetY - wrap.clientHeight / 2);
        }
    }

    // ---- Stepping / playback --------------------------------------------
    function hexToRgb(hex) {
        var v = hex.replace('#', '');
        return { r: parseInt(v.substr(0, 2), 16), g: parseInt(v.substr(2, 2), 16), b: parseInt(v.substr(4, 2), 16) };
    }

    function currentCpn() {
        return parseInt(el.nwtChannelsPerNode.value, 10) || (state.layout ? state.layout.cpn : 3);
    }

    // 0-based absolute channel range for one node, given the layout + channels/node in use.
    function absRangeForNode(layout, cpn, index) {
        var absStart = layout.startChannel0 + index * cpn;
        return { absStart: absStart, absEnd: absStart + cpn - 1 };
    }

    // Fire-and-track RGBFill on an arbitrary absolute channel range (1-based, inclusive).
    function postRangeColor(absStart0, absEnd0, r, g, b, w) {
        var config = {
            enabled: 1,
            mode: 'RGBFill',
            channelSet: (absStart0 + 1) + '-' + (absEnd0 + 1),
            channelSetType: 'channelRange',
            color1: r, color2: g, color3: b
        };
        if (w !== undefined) { config.color4 = w; }
        return apiPostTest(config);
    }

    function goToNode(index) {
        var layout = state.layout;
        if (!layout || layout.nodeCount <= 0) { return; }
        index = Math.max(0, Math.min(layout.nodeCount - 1, index));
        state.currentIndex = index;

        var cpn = currentCpn();
        var range = absRangeForNode(layout, cpn, index);
        var absStart = range.absStart, absEnd = range.absEnd;
        var rgb = hexToRgb(el.nwtColor.value);

        var config = {
            enabled: 1,
            mode: 'RGBFill',
            channelSet: (absStart + 1) + '-' + (absEnd + 1),
            channelSetType: 'channelRange',
            color1: rgb.r,
            color2: rgb.g,
            color3: rgb.b
        };
        if (cpn >= 4) {
            config.color4 = parseInt(el.nwtWhite.value, 10) || 0;
        }

        apiPostTest(config).then(function () {
            state.testActive = true;
            setStatus(state.playTimer ? 'Playing...' : 'Stepped', true);
        }).catch(function (err) {
            setStatus('Error sending test command: ' + err.message, false);
        });

        highlightCurrent();
        updateInfoBox(index, absStart, absEnd);
    }

    function updateInfoBox(index, absStart, absEnd) {
        var layout = state.layout;
        el.nwtInfoBox.style.display = '';
        el.nwtInfoModel.textContent = state.modelName;
        el.nwtInfoNode.textContent = (index + 1) + ' of ' + layout.nodeCount;
        var pos = layout.nodePos[index];
        el.nwtInfoPos.textContent = (pos && !layout.linear) ? ('x=' + pos.x + ', y=' + pos.y) : '(wiring order #' + (index + 1) + ')';
        el.nwtInfoChannels.textContent = (absStart + 1) + '-' + (absEnd + 1) + ' (absolute)';
    }

    function stepNext() { goToNode(state.currentIndex + 1 < 0 ? 0 : state.currentIndex + 1); }
    function stepPrev() { goToNode(state.currentIndex - 1); }

    function startPlayback() {
        if (state.playTimer) { return; }
        if (state.currentIndex < 0) { state.currentIndex = -1; }
        var intervalMs = Math.max(100, parseInt(el.nwtIntervalMs.value, 10) || 750);
        stepNext();
        state.playTimer = setInterval(function () {
            if (state.currentIndex >= state.layout.nodeCount - 1) {
                goToNode(0);
            } else {
                stepNext();
            }
        }, intervalMs);
        el.nwtPlay.textContent = '❚❚ Pause';
    }

    function stopPlayback() {
        if (state.playTimer) {
            clearInterval(state.playTimer);
            state.playTimer = null;
        }
        el.nwtPlay.textContent = '▶ Play';
    }

    function togglePlayback() {
        if (state.playTimer) { stopPlayback(); setStatus('Paused', true); }
        else { startPlayback(); }
    }

    function disableTest() {
        stopPlayback();
        if (state.testActive) {
            apiPostTest({ enabled: 0 }).catch(function () {});
            state.testActive = false;
        }
        state.currentIndex = -1;
        drawGridCanvas();
        setStatus('Stopped', false);
    }

    function setStatus(msg, running) {
        el.nwtStatus.textContent = msg;
        el.nwtStatus.classList.toggle('nwtRunning', !!running);
    }

    function setControlsEnabled(enabled) {
        [el.nwtPrev, el.nwtPlay, el.nwtNext, el.nwtStop].forEach(function (b) { b.disabled = !enabled; });
    }

    // ---- Mismatch log -----------------------------------------------
    function markMismatch() {
        if (state.currentIndex < 0 || !state.layout) { return; }
        var layout = state.layout;
        var pos = layout.nodePos[state.currentIndex];
        var cpn = parseInt(el.nwtChannelsPerNode.value, 10) || layout.cpn;
        var absStart = layout.startChannel0 + state.currentIndex * cpn + 1;
        var absEnd = absStart + cpn - 1;
        var entry = {
            time: new Date().toLocaleTimeString(),
            model: state.modelName,
            node: state.currentIndex + 1,
            pos: (pos && !layout.linear) ? ('x=' + pos.x + ', y=' + pos.y) : ('#' + (state.currentIndex + 1)),
            channels: absStart + '-' + absEnd,
            note: el.nwtMismatchNote.value || ''
        };
        mismatchLog.push(entry);
        el.nwtMismatchNote.value = '';
        renderLog();
    }

    function renderLog() {
        var tbody = el.nwtLogTable.querySelector('tbody');
        tbody.innerHTML = mismatchLog.map(function (e) {
            return '<tr><td>' + escapeHtml(e.time) + '</td><td>' + escapeHtml(e.model) + '</td><td>' +
                e.node + '</td><td>' + escapeHtml(e.pos) + '</td><td>' + escapeHtml(e.channels) +
                '</td><td>' + escapeHtml(e.note) + '</td></tr>';
        }).join('');
    }

    function exportLog() {
        if (!mismatchLog.length) { return; }
        var header = 'Time,Model,Node,ExpectedPosition,Channels,Note\n';
        var rows = mismatchLog.map(function (e) {
            return [e.time, e.model, e.node, e.pos, e.channels, e.note]
                .map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\n');
        var blob = new Blob([header + rows], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'node-wiring-test-log.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // ---- Camera -----------------------------------------------------
    function sleep(ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); }

    function activeMediaEl() {
        if (cam.kind === 'local') { return el.nwtCamVideo; }
        if (cam.kind === 'stream') { return el.nwtCamImg; }
        return null;
    }

    function updateScanButtonEnabled() {
        var ready = !!cam.kind && state.layout && state.layout.nodeCount > 0 && !cam.scanning;
        el.nwtRunScan.disabled = !ready;
    }

    function stopCamera() {
        if (cam.streamRefreshTimer) { clearInterval(cam.streamRefreshTimer); cam.streamRefreshTimer = null; }
        if (cam.stream) {
            cam.stream.getTracks().forEach(function (t) { t.stop(); });
            cam.stream = null;
        }
        cam.kind = null;
        el.nwtCamWrap.style.display = 'none';
        el.nwtCamVideo.style.display = 'none';
        el.nwtCamImg.style.display = 'none';
        updateScanButtonEnabled();
    }

    function startLocalCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setScanStatus('This browser does not support camera access (getUserMedia).');
            return;
        }
        stopCamera();
        var deviceId = el.nwtCamDevice.value;
        var videoConstraints = deviceId ? { deviceId: { exact: deviceId } } : { facingMode: 'environment' };
        navigator.mediaDevices.getUserMedia({ video: videoConstraints, audio: false }).then(function (stream) {
            cam.kind = 'local';
            cam.stream = stream;
            el.nwtCamVideo.srcObject = stream;
            el.nwtCamWrap.style.display = '';
            el.nwtCamVideo.style.display = '';
            el.nwtCamImg.style.display = 'none';
            updateScanButtonEnabled();
            // Device labels are only populated after permission is granted.
            return navigator.mediaDevices.enumerateDevices();
        }).then(function (devices) {
            if (!devices) { return; }
            var cams = devices.filter(function (d) { return d.kind === 'videoinput'; });
            el.nwtCamDevice.innerHTML = '<option value="">(default)</option>' + cams.map(function (d) {
                return '<option value="' + escapeHtml(d.deviceId) + '">' + escapeHtml(d.label || 'Camera') + '</option>';
            }).join('');
        }).catch(function (err) {
            setScanStatus('Camera error: ' + err.message);
        });
    }

    function startStreamCamera() {
        var url = el.nwtCamStreamUrl.value.trim();
        if (!url) { return; }
        stopCamera();
        cam.kind = 'stream';
        el.nwtCamImg.src = url;
        el.nwtCamWrap.style.display = '';
        el.nwtCamImg.style.display = '';
        el.nwtCamVideo.style.display = 'none';
        // Harmless for a continuous MJPEG stream, and necessary for a plain
        // snapshot endpoint that doesn't update on its own.
        cam.streamRefreshTimer = setInterval(function () {
            var sep = url.indexOf('?') >= 0 ? '&' : '?';
            el.nwtCamImg.src = url + sep + '_t=' + Date.now();
        }, 1000);
        updateScanButtonEnabled();
    }

    // Grabs a downscaled frame from whichever source is active as raw RGBA bytes.
    function captureFrame() {
        var src = activeMediaEl();
        if (!src) { return null; }
        var w = CAM_CAPTURE_W, h = CAM_CAPTURE_H;
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        var ctx = c.getContext('2d', { willReadFrequently: true });
        try {
            ctx.drawImage(src, 0, 0, w, h);
            return ctx.getImageData(0, 0, w, h);
        } catch (e) {
            return null; // e.g. stream not loaded yet / tainted canvas
        }
    }

    // Finds the brightest blob that appeared between two frames (baseline vs
    // this node flashed on) and returns its centroid in normalized 0-1
    // camera coordinates, or null if nothing clearly lit up.
    function diffAndFindBlob(baseline, frame) {
        if (!baseline || !frame) { return null; }
        var w = frame.width, h = frame.height;
        var a = baseline.data, b = frame.data;
        var n = w * h;
        var diffs = new Float32Array(n);
        var maxD = 0;
        for (var i = 0; i < n; i++) {
            var o = i * 4;
            var dr = Math.max(0, b[o] - a[o]);
            var dg = Math.max(0, b[o + 1] - a[o + 1]);
            var db = Math.max(0, b[o + 2] - a[o + 2]);
            var d = dr + dg + db;
            diffs[i] = d;
            if (d > maxD) { maxD = d; }
        }
        var MIN_DETECT = 40; // out of a max possible ~765 (255*3)
        if (maxD < MIN_DETECT) { return null; }
        var threshold = Math.max(maxD * 0.6, 30);
        var sumX = 0, sumY = 0, sumW = 0, count = 0;
        for (var y = 0; y < h; y++) {
            for (var x = 0; x < w; x++) {
                var dd = diffs[y * w + x];
                if (dd >= threshold) {
                    sumX += x * dd; sumY += y * dd; sumW += dd; count++;
                }
            }
        }
        if (sumW <= 0) { return null; }
        return { x: (sumX / sumW) / w, y: (sumY / sumW) / h, strength: maxD, count: count };
    }

    function setScanStatus(msg) { el.nwtScanStatus.textContent = msg; }

    function resetScanState() {
        cam.detections = null;
        cam.fit = null;
        cam.threshold = null;
        cam.assignment = null;
        el.nwtScanResults.style.display = 'none';
        el.nwtDownloadXModel.disabled = true;
        drawOverlay();
    }

    function cancelScan() { cam.cancelRequested = true; }

    function runAutoScan() {
        var layout = state.layout;
        if (!layout || layout.nodeCount <= 0 || !cam.kind) { return; }
        if (cam.scanning) { return; }
        cam.scanning = true;
        cam.cancelRequested = false;
        resetScanState();
        el.nwtRunScan.style.display = 'none';
        el.nwtCancelScan.style.display = '';
        updateScanButtonEnabled();

        var cpn = currentCpn();
        var settleMs = Math.max(50, parseInt(el.nwtSettleMs.value, 10) || 220);
        var detections = new Array(layout.nodeCount).fill(null);

        // Blank the whole model once and use a single baseline frame for
        // every node's diff - assumes ambient light/camera framing hold
        // steady for the duration of the scan.
        setScanStatus('Blanking model for baseline...');
        apiPostTest({ enabled: 0 }).then(function () {
            var wholeStart = layout.startChannel0;
            var wholeEnd = layout.startChannel0 + layout.nodeCount * cpn - 1;
            return postRangeColor(wholeStart, wholeEnd, 0, 0, 0, cpn >= 4 ? 0 : undefined);
        }).then(function () {
            return sleep(settleMs);
        }).then(function () {
            var baseline = captureFrame();
            if (!baseline) {
                setScanStatus('Could not capture a camera frame - check the camera source.');
                finishScan();
                return Promise.reject(new Error('no-frame'));
            }

            function stepNode(i) {
                if (cam.cancelRequested || i >= layout.nodeCount) { return Promise.resolve(); }
                setScanStatus('Scanning node ' + (i + 1) + ' of ' + layout.nodeCount + '...');
                var range = absRangeForNode(layout, cpn, i);
                return postRangeColor(range.absStart, range.absEnd, 255, 255, 255, cpn >= 4 ? 255 : undefined)
                    .then(function () { return sleep(settleMs); })
                    .then(function () {
                        var frame = captureFrame();
                        detections[i] = diffAndFindBlob(baseline, frame);
                        return postRangeColor(range.absStart, range.absEnd, 0, 0, 0, cpn >= 4 ? 0 : undefined);
                    })
                    .then(function () { return stepNode(i + 1); });
            }
            return stepNode(0);
        }).then(function () {
            return apiPostTest({ enabled: 0 });
        }).then(function () {
            if (cam.cancelRequested) {
                setScanStatus('Scan cancelled.');
                finishScan();
                return;
            }
            cam.detections = detections;
            processScanResults();
            finishScan();
        }).catch(function (err) {
            if (err && err.message !== 'no-frame') { setScanStatus('Scan error: ' + err.message); }
            apiPostTest({ enabled: 0 }).catch(function () {});
            finishScan();
        });
    }

    function finishScan() {
        cam.scanning = false;
        el.nwtRunScan.style.display = '';
        el.nwtCancelScan.style.display = 'none';
        updateScanButtonEnabled();
    }

    // ---- Fit + matching --------------------------------------------------
    // Solves least squares for z = p*ex + q*ey + r over the given pairs.
    function solveLinear3(pairs, zKey) {
        var Sxx = 0, Sxy = 0, Sx = 0, Syy = 0, Sy = 0, Sn = pairs.length;
        var Sxz = 0, Syz = 0, Sz = 0;
        pairs.forEach(function (p) {
            var ex = p.ex, ey = p.ey, z = p[zKey];
            Sxx += ex * ex; Sxy += ex * ey; Sx += ex;
            Syy += ey * ey; Sy += ey;
            Sxz += ex * z; Syz += ey * z; Sz += z;
        });
        // Normal equations matrix for [p, q, r]:
        // [Sxx Sxy Sx] [p]   [Sxz]
        // [Sxy Syy Sy] [q] = [Syz]
        // [Sx  Sy  Sn] [r]   [Sz ]
        var m = [[Sxx, Sxy, Sx], [Sxy, Syy, Sy], [Sx, Sy, Sn]];
        var v = [Sxz, Syz, Sz];
        return solve3x3(m, v); // [p, q, r] or null if singular
    }

    function solve3x3(m, v) {
        var det = m[0][0] * (m[1][1] * m[2][2] - m[1][2] * m[2][1])
                - m[0][1] * (m[1][0] * m[2][2] - m[1][2] * m[2][0])
                + m[0][2] * (m[1][0] * m[2][1] - m[1][1] * m[2][0]);
        if (Math.abs(det) < 1e-9) { return null; }
        function replaceCol(col) {
            var mm = m.map(function (row) { return row.slice(); });
            for (var i = 0; i < 3; i++) { mm[i][col] = v[i]; }
            return mm[0][0] * (mm[1][1] * mm[2][2] - mm[1][2] * mm[2][1])
                 - mm[0][1] * (mm[1][0] * mm[2][2] - mm[1][2] * mm[2][0])
                 + mm[0][2] * (mm[1][0] * mm[2][1] - mm[1][1] * mm[2][0]);
        }
        return [replaceCol(0) / det, replaceCol(1) / det, replaceCol(2) / det];
    }

    function solveAffine(pairs) {
        if (pairs.length < 3) { return null; }
        var px = solveLinear3(pairs, 'dx');
        var py = solveLinear3(pairs, 'dy');
        if (!px || !py) { return null; }
        return { a: px[0], b: px[1], c: px[2], d: py[0], e: py[1], f: py[2] };
    }

    function applyFit(fit, ex, ey) {
        return { x: fit.a * ex + fit.b * ey + fit.c, y: fit.d * ex + fit.e * ey + fit.f };
    }

    function dist(p1, p2) { return Math.hypot(p1.x - p2.x, p1.y - p2.y); }

    function median(arr) {
        if (!arr.length) { return 0; }
        var s = arr.slice().sort(function (a, b) { return a - b; });
        var mid = Math.floor(s.length / 2);
        return s.length % 2 ? s[mid] : (s[mid - 1] + s[mid]) / 2;
    }

    // Iteratively reweighted fit: refits a few times, dropping the points
    // that disagree most, so a handful of actually-miswired nodes don't drag
    // the fit toward themselves.
    function robustFit(allPairs) {
        var inliers = allPairs;
        var fit = solveAffine(inliers);
        var thresh = 0.15;
        for (var iter = 0; iter < 4 && fit; iter++) {
            var residuals = allPairs.map(function (p) {
                var pred = applyFit(fit, p.ex, p.ey);
                return dist(pred, { x: p.dx, y: p.dy });
            });
            var med = median(residuals);
            thresh = Math.max(med * 2.5, 0.03);
            var next = allPairs.filter(function (p, idx) { return residuals[idx] <= thresh; });
            if (next.length < 3 || next.length === inliers.length) { inliers = next; break; }
            inliers = next;
            var refit = solveAffine(inliers);
            if (!refit) { break; }
            fit = refit;
        }
        return { fit: fit, threshold: thresh, inlierCount: inliers.length, totalCount: allPairs.length };
    }

    function processScanResults() {
        var layout = state.layout;
        var detections = cam.detections;
        var pairs = [];
        layout.nodePos.forEach(function (pos, i) {
            var det = detections[i];
            if (pos && det) { pairs.push({ ex: pos.x, ey: pos.y, dx: det.x, dy: det.y, node: i }); }
        });

        el.nwtScanResults.style.display = '';
        var detectedCount = detections.filter(Boolean).length;

        if (pairs.length < 3) {
            cam.fit = null;
            setScanStatus('Scan finished: only ' + detectedCount + ' of ' + layout.nodeCount +
                ' nodes were detected - not enough to fit a position mapping.');
            el.nwtFitQuality.textContent = 'Not enough detections to calibrate.';
            el.nwtFitQuality.className = 'nwtFitPoor';
            renderScanTable(null);
            return;
        }

        var result = robustFit(pairs);
        cam.fit = result.fit;
        cam.threshold = result.threshold;

        if (!cam.fit) {
            setScanStatus('Could not fit a position mapping from this scan.');
            el.nwtFitQuality.textContent = 'Fit failed.';
            el.nwtFitQuality.className = 'nwtFitPoor';
            renderScanTable(null);
            return;
        }

        var quality = result.inlierCount / result.totalCount;
        el.nwtFitQuality.textContent = result.inlierCount + ' of ' + result.totalCount +
            ' detected nodes agree with the fit (residual threshold ' + result.threshold.toFixed(3) + ').';
        el.nwtFitQuality.className = quality >= 0.7 ? 'nwtFitGood' : 'nwtFitPoor';

        cam.assignment = computeAssignment(layout, cam.fit);
        setScanStatus('Scan finished: ' + detectedCount + ' of ' + layout.nodeCount + ' nodes detected.');
        renderScanTable(result);
        drawOverlay();
        el.nwtDownloadXModel.disabled = layout.linear; // no real geometry to export
    }

    // Greedy nearest-neighbor match between each slot's predicted camera
    // position and every node's actually detected camera position, so we
    // know which physical node is really sitting in each expected slot.
    function computeAssignment(layout, fit) {
        var n = layout.nodeCount;
        var predicted = new Array(n);
        layout.nodePos.forEach(function (pos, i) {
            predicted[i] = pos ? applyFit(fit, pos.x, pos.y) : null;
        });
        var candidates = [];
        for (var slot = 0; slot < n; slot++) {
            if (!predicted[slot]) { continue; }
            for (var node = 0; node < n; node++) {
                var det = cam.detections[node];
                if (!det) { continue; }
                candidates.push({ slot: slot, node: node, d: dist(predicted[slot], det) });
            }
        }
        candidates.sort(function (a, b) { return a.d - b.d; });
        var assignment = new Array(n).fill(null);
        var usedNodes = {};
        var usedSlots = {};
        candidates.forEach(function (c) {
            if (usedSlots[c.slot] !== undefined || usedNodes[c.node] !== undefined) { return; }
            assignment[c.slot] = c.node;
            usedSlots[c.slot] = true;
            usedNodes[c.node] = true;
        });
        // Fall back to identity for any slot a detection couldn't be matched to.
        for (var i = 0; i < n; i++) {
            if (assignment[i] === null) { assignment[i] = i; }
        }
        return assignment;
    }

    function renderScanTable(result) {
        var layout = state.layout;
        var tbody = el.nwtScanTable.querySelector('tbody');
        var rows = [];
        for (var i = 0; i < layout.nodeCount; i++) {
            var det = cam.detections[i];
            var rowClass = 'nwtRowUnknown';
            var statusText = 'Not detected';
            var matchText = '-';
            var residualText = '-';
            if (det && cam.fit && layout.nodePos[i]) {
                var predicted = applyFit(cam.fit, layout.nodePos[i].x, layout.nodePos[i].y);
                var r = dist(predicted, det);
                residualText = r.toFixed(3);
                var mismatched = r > cam.threshold;
                rowClass = mismatched ? 'nwtRowMismatch' : 'nwtRowOk';
                statusText = mismatched ? 'MISMATCH' : 'OK';
                var assigned = cam.assignment ? cam.assignment[i] : i;
                matchText = 'Node ' + (assigned + 1) + (assigned === i ? ' (as expected)' : ' (physically here instead)');
            } else if (det) {
                statusText = 'Detected (no fit yet)';
            }
            rows.push('<tr class="' + rowClass + '"><td>' + (i + 1) + '</td><td>' + statusText +
                '</td><td>' + matchText + '</td><td>' + residualText + '</td></tr>');
        }
        tbody.innerHTML = rows.join('');
    }

    function drawOverlay() {
        var canvas = el.nwtCamOverlay;
        var mediaEl = activeMediaEl();
        if (!mediaEl) { return; }
        var w = mediaEl.videoWidth || mediaEl.naturalWidth || CAM_CAPTURE_W;
        var h = mediaEl.videoHeight || mediaEl.naturalHeight || CAM_CAPTURE_H;
        canvas.width = w; canvas.height = h;
        var ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, w, h);
        if (!cam.fit || !state.layout) { return; }
        var layout = state.layout;
        layout.nodePos.forEach(function (pos, i) {
            if (!pos) { return; }
            var p = applyFit(cam.fit, pos.x, pos.y);
            var det = cam.detections ? cam.detections[i] : null;
            var mismatched = det && (dist(p, det) > cam.threshold);
            ctx.beginPath();
            ctx.arc(p.x * w, p.y * h, 4, 0, Math.PI * 2);
            ctx.fillStyle = !det ? 'rgba(180,180,180,0.6)' : (mismatched ? 'rgba(220,0,0,0.85)' : 'rgba(0,200,0,0.85)');
            ctx.fill();
            if (det) {
                ctx.beginPath();
                ctx.arc(det.x * w, det.y * h, 3, 0, Math.PI * 2);
                ctx.strokeStyle = 'yellow';
                ctx.lineWidth = 1;
                ctx.stroke();
            }
        });
    }

    // ---- .xmodel export ---------------------------------------------
    function xmlEscape(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildCorrectedXModel() {
        var layout = state.layout;
        if (!layout || layout.linear || !cam.assignment) { return null; }
        var w = layout.visualWidth, h = layout.visualHeight;
        var grid = [];
        for (var y = 0; y < h; y++) { grid.push(new Array(w).fill(null)); }
        layout.nodePos.forEach(function (pos, slot) {
            if (!pos) { return; }
            var physicalNode = cam.assignment[slot]; // 0-based node index actually sitting at this slot
            grid[pos.y][pos.x] = physicalNode + 1; // xLights dense format is 1-based node numbers
        });

        var denseRows = grid.map(function (row) {
            return row.map(function (v) { return v === null ? '' : String(v); }).join(',');
        });
        var dense = denseRows.join(';');

        var compressedEntries = [];
        for (var y2 = 0; y2 < h; y2++) {
            for (var x2 = 0; x2 < w; x2++) {
                if (grid[y2][x2] !== null) { compressedEntries.push(grid[y2][x2] + ',' + y2 + ',' + x2); }
            }
        }
        var compressed = compressedEntries.join(';');

        var cpn = currentCpn();
        var stringType = cpn >= 4 ? '4 Channel RGBW' : 'RGB Nodes';
        var name = (state.modelName || 'CorrectedModel') + '-corrected';
        var startChannel1 = layout.startChannel0 + 1;

        // The XML declaration is built from separate string pieces on
        // purpose. With short_open_tag on, PHP scans the WHOLE raw file -
        // including this JS string - for a less-than sign immediately
        // followed by a question mark, and starts parsing PHP right there;
        // writing the declaration as one literal broke this page with a
        // parse error on "xml version" before this was split up.
        return '<' + '?xml version="1.0" encoding="UTF-8"?' + '>\n' +
            '<custommodel name="' + xmlEscape(name) + '" CustomWidth="' + w + '" CustomHeight="' + h + '"' +
            ' Depth="1" StringType="' + xmlEscape(stringType) + '" Transparency="0" PixelSize="2" Antialias="1"' +
            ' StrandNames="" NodeNames="" LayoutGroup="Default"' +
            ' CustomModel="' + xmlEscape(dense) + '" CustomModelCompressed="' + xmlEscape(compressed) + '"' +
            ' StartChannel="' + startChannel1 + '" SourceVersion="2024.10" />\n';
    }

    function downloadCorrectedXModel() {
        var xml = buildCorrectedXModel();
        if (!xml) { return; }
        var blob = new Blob([xml], { type: 'application/xml' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (state.modelName || 'model') + '-corrected.xmodel';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    // ---- Wire up events -----------------------------------------------
    el.nwtModel.addEventListener('change', onModelChange);
    el.nwtRefreshModels.addEventListener('click', loadModels);
    el.nwtChannelsPerNode.addEventListener('change', function () {
        el.nwtWhiteWrap.style.display = (parseInt(el.nwtChannelsPerNode.value, 10) >= 4) ? '' : 'none';
    });
    el.nwtPrev.addEventListener('click', function () { stopPlayback(); stepPrev(); });
    el.nwtNext.addEventListener('click', function () { stopPlayback(); stepNext(); });
    el.nwtPlay.addEventListener('click', togglePlayback);
    el.nwtStop.addEventListener('click', disableTest);
    el.nwtMarkMismatch.addEventListener('click', markMismatch);
    el.nwtExportLog.addEventListener('click', exportLog);
    el.nwtClearLog.addEventListener('click', function () { mismatchLog = []; renderLog(); });

    el.nwtCamSource.addEventListener('change', function () {
        stopCamera();
        resetScanState();
        var isStream = el.nwtCamSource.value === 'stream';
        el.nwtCamLocalRow.style.display = isStream ? 'none' : '';
        el.nwtCamStreamRow.style.display = isStream ? '' : 'none';
    });
    el.nwtCamStart.addEventListener('click', startLocalCamera);
    el.nwtCamStreamStart.addEventListener('click', startStreamCamera);
    el.nwtRunScan.addEventListener('click', runAutoScan);
    el.nwtCancelScan.addEventListener('click', cancelScan);
    el.nwtDownloadXModel.addEventListener('click', downloadCorrectedXModel);
    el.nwtCamVideo.addEventListener('loadedmetadata', drawOverlay);

    window.addEventListener('beforeunload', function () {
        stopTestBestEffort();
        stopCamera();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && state.testActive) { stopTestBestEffort(); }
    });

    loadModels();
    renderGridPlaceholder('Select a model to begin.');
})();
</script>
