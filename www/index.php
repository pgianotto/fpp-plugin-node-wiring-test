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
#nwtGrid { display: grid; gap: 2px; }
.nwtCell { width: 16px; height: 16px; background: #333; border-radius: 2px; }
.nwtCell.nwtCurrent { outline: 2px solid #fff; box-shadow: 0 0 6px 2px #fff; }
.nwtCell.nwtEmpty { background: transparent; }
.nwtLinearNote { color: #999; font-size: 0.85em; margin-top: -0.4em; margin-bottom: 0.6em; }
.nwtLogTable { width: 100%; border-collapse: collapse; margin-top: 0.5em; }
.nwtLogTable th, .nwtLogTable td { border: 1px solid #ccc; padding: 0.3em 0.5em; text-align: left; font-size: 0.9em; }
.nwtStatus { font-weight: 600; }
.nwtStatus.nwtRunning { color: #0a7d2c; }
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

    <div id="nwtGridWrap"><div id="nwtGrid"></div></div>
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

    // ---- DOM handles ---------------------------------------------------
    var el = {};
    ['nwtModel', 'nwtRefreshModels', 'nwtChannelsPerNode', 'nwtColor', 'nwtWhiteWrap', 'nwtWhite',
     'nwtIntervalMs', 'nwtPrev', 'nwtPlay', 'nwtNext', 'nwtStop', 'nwtStatus',
     'nwtInfoBox', 'nwtInfoModel', 'nwtInfoNode', 'nwtInfoPos', 'nwtInfoChannels',
     'nwtMarkMismatch', 'nwtMismatchNote', 'nwtGrid', 'nwtGridWrap', 'nwtLinearNote',
     'nwtExportLog', 'nwtClearLog', 'nwtLogTable'
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
        var name = el.nwtModel.value;
        state.modelName = name || null;
        state.model = null;
        state.layout = null;
        state.currentIndex = -1;
        el.nwtInfoBox.style.display = 'none';
        renderGridPlaceholder('Select a model to begin.');
        setControlsEnabled(false);
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

        // Grid too big to render sanely (or no usable geometry) -> fall back
        // to a plain left-to-right wiring-order strip. Node order/index is
        // still exactly what will be commanded, just not drawn to scale.
        if (!visualWidth || !visualHeight || visualWidth * visualHeight > 6000 || visualWidth * visualHeight <= 0) {
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

    // ---- Grid rendering -------------------------------------------------
    function renderGridPlaceholder(msg) {
        el.nwtGrid.style.gridTemplateColumns = '';
        el.nwtGrid.innerHTML = '<div style="color:#999;padding:1em;">' + escapeHtml(msg) + '</div>';
        el.nwtLinearNote.style.display = 'none';
    }

    function renderGrid(layout) {
        el.nwtLinearNote.style.display = layout.linear ? '' : 'none';
        el.nwtGrid.style.gridTemplateColumns = 'repeat(' + layout.visualWidth + ', 16px)';
        var cellByPos = {};
        layout.nodePos.forEach(function (pos, idx) {
            if (pos) { cellByPos[pos.x + ',' + pos.y] = idx; }
        });
        var frag = document.createDocumentFragment();
        for (var y = 0; y < layout.visualHeight; y++) {
            for (var x = 0; x < layout.visualWidth; x++) {
                var div = document.createElement('div');
                var nodeIdx = cellByPos[x + ',' + y];
                div.className = 'nwtCell' + (nodeIdx === undefined ? ' nwtEmpty' : '');
                if (nodeIdx !== undefined) {
                    div.id = 'nwtCell_' + nodeIdx;
                    div.title = 'Node ' + (nodeIdx + 1);
                }
                frag.appendChild(div);
            }
        }
        el.nwtGrid.innerHTML = '';
        el.nwtGrid.appendChild(frag);
    }

    function highlightCurrent() {
        var prev = el.nwtGrid.querySelector('.nwtCurrent');
        if (prev) { prev.classList.remove('nwtCurrent'); }
        if (state.currentIndex >= 0) {
            var cell = document.getElementById('nwtCell_' + state.currentIndex);
            if (cell) {
                cell.classList.add('nwtCurrent');
                cell.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }
    }

    // ---- Stepping / playback --------------------------------------------
    function hexToRgb(hex) {
        var v = hex.replace('#', '');
        return { r: parseInt(v.substr(0, 2), 16), g: parseInt(v.substr(2, 2), 16), b: parseInt(v.substr(4, 2), 16) };
    }

    function goToNode(index) {
        var layout = state.layout;
        if (!layout || layout.nodeCount <= 0) { return; }
        index = Math.max(0, Math.min(layout.nodeCount - 1, index));
        state.currentIndex = index;

        var cpn = parseInt(el.nwtChannelsPerNode.value, 10) || layout.cpn;
        var absStart = layout.startChannel0 + index * cpn; // 0-based
        var absEnd = absStart + cpn - 1;
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
        var cell = el.nwtGrid.querySelector('.nwtCurrent');
        if (cell) { cell.classList.remove('nwtCurrent'); }
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

    window.addEventListener('beforeunload', stopTestBestEffort);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden' && state.testActive) { stopTestBestEffort(); }
    });

    loadModels();
    renderGridPlaceholder('Select a model to begin.');
})();
</script>
