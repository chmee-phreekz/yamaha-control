(function () {
    'use strict';

    const state = {
        activeTab: 'inputs',
        autoRefresh: true,
        pollMs: window.QL1_POLL_MS || 4000,
        pollTimer: null,
        lastStatus: null,
        lastRouting: null,
    };

    // Fader-Kennlinie (Position 0–1000 -> dB), audio-like:
    // - Position 0 = physischer unterer Anschlag = -unendlich (Mute-Rastpunkt)
    // - 0–400 (0–40%): grob, deckt -60 dB bis -24 dB ab
    // - 400–1000 (40–100%): fein, deckt den wichtigen Regelbereich -24 dB bis +12 dB ab
    const FADER_POS_MAX = 1000;
    const FADER_TAPER = [
        { pos: 0, db: -60 },
        { pos: 400, db: -24 },
        { pos: 1000, db: 12 },
    ];
    const draggingKeys = new Set();

    // ---------- Globaler Log (letzte 24 Aktionen, fürs Log-Modal) ----------
    const GLOBAL_LOG_MAX = 24;
    const globalLog = [];
    function pushGlobalLog(entries) {
        (entries || []).forEach((e) => {
            globalLog.push({ dir: e.dir, line: e.line, ts: Date.now() });
        });
        while (globalLog.length > GLOBAL_LOG_MAX) globalLog.shift();
    }
    function pushGlobalLogLine(dir, line) {
        pushGlobalLog([{ dir, line }]);
    }

    function posToDb(pos) {
        if (pos <= 0) return null; // -unendlich
        for (let i = 0; i < FADER_TAPER.length - 1; i++) {
            const a = FADER_TAPER[i], b = FADER_TAPER[i + 1];
            if (pos <= b.pos) {
                const t = (pos - a.pos) / (b.pos - a.pos);
                return a.db + t * (b.db - a.db);
            }
        }
        return FADER_TAPER[FADER_TAPER.length - 1].db;
    }

    function dbToPos(db) {
        if (db === null || db === undefined) return 0; // -unendlich -> unterer Anschlag
        const first = FADER_TAPER[0];
        if (db <= first.db) return 2; // knapp über dem Mute-Rastpunkt, nicht exakt 0
        for (let i = 0; i < FADER_TAPER.length - 1; i++) {
            const a = FADER_TAPER[i], b = FADER_TAPER[i + 1];
            if (db <= b.db) {
                const t = (db - a.db) / (b.db - a.db);
                return a.pos + t * (b.pos - a.pos);
            }
        }
        return FADER_TAPER[FADER_TAPER.length - 1].pos;
    }

    const el = (sel) => document.querySelector(sel);
    const els = (sel) => Array.from(document.querySelectorAll(sel));

    function fmtDb(db) {
        if (db === null || db === undefined) return '-\u221e dB';
        return (db > 0 ? '+' : '') + db.toFixed(1) + ' dB';
    }

    function fmtTime(ts) {
        if (!ts) return '-';
        const d = new Date(ts * 1000);
        return d.toLocaleTimeString('de-DE');
    }

    // ---------- Connection indicator ----------
    function setConn(status, text) {
        const dot = el('#connDot');
        dot.classList.remove('ok', 'bad', 'pending');
        dot.classList.add(status);
        el('#connText').textContent = text;
    }

    async function ping() {
        try {
            const res = await fetch('api.php?action=ping');
            const data = await res.json();
            if (data.reachable) {
                setConn('ok', 'Verbunden (' + data.ms + ' ms)');
            } else {
                setConn('bad', 'Nicht erreichbar');
            }
        } catch (e) {
            setConn('bad', 'Fehler: ' + e.message);
        }
    }

    // ---------- Tabs ----------
    function initTabs() {
        els('nav.tabs button').forEach((btn) => {
            btn.addEventListener('click', () => switchTab(btn.dataset.tab));
        });
    }

    function switchTab(tab) {
        state.activeTab = tab;
        els('nav.tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
        els('main .panel').forEach((p) => p.classList.toggle('active', p.id === 'panel-' + tab));

        if (tab === 'inputs' || tab === 'outputs') {
            if (!state.lastStatus) loadStatus();
        } else if (tab === 'routing') {
            loadRouting();
        }
    }

    // ---------- Set (Fader-Level / Mute) ----------
    async function sendSet(category, index, param, value) {
        try {
            const body = new URLSearchParams({ category, index, param, value });
            const res = await fetch('api.php?action=set', { method: 'POST', body });
            const data = await res.json();
            if (!data.ok) {
                showBanner(data.error || 'Setzen fehlgeschlagen.');
            }
            pushGlobalLog(data.debug);
            return data;
        } catch (e) {
            showBanner('Setzen fehlgeschlagen: ' + e.message);
        }
    }

    // ---------- Status (Inputs / Outputs) ----------
    async function loadStatus(showError = true) {
        try {
            const res = await fetch('api.php?action=status');
            const data = await res.json();
            if (!data.ok) {
                if (showError) showBanner(data.error || 'Unbekannter Fehler beim Statusabruf.');
                setConn('bad', 'Fehler');
                return;
            }
            hideBanner();
            setConn('ok', 'Verbunden');
            state.lastStatus = data;
            el('#lastUpdate').textContent = fmtTime(data.fetched_at);
            renderInputs(data.data);
            renderOutputs(data.data);
        } catch (e) {
            if (showError) showBanner('Verbindung zur Weboberfläche/API fehlgeschlagen: ' + e.message);
            setConn('bad', 'Fehler');
        }
    }

    function renderInputs(data) {
        const mono = data.inch || [];
        const half = Math.ceil(mono.length / 2);
        renderFaderGrid(el('#inputsMonoRow1'), mono.slice(0, half), 'inch');
        renderFaderGrid(el('#inputsMonoRow2'), mono.slice(half), 'inch');
        renderFaderGrid(el('#inputsStereo'), data.stinch || [], 'stinch');
    }

    // Baut eine Fader-Spalte einmalig auf und merkt sich DOM-Referenzen
    // zum späteren inkrementellen Update (statt komplettem Neuaufbau).
    function buildFaderColumn(ch, category, key) {
        const wrap = document.createElement('div');
        wrap.className = 'fader-col';
        wrap.dataset.key = key;

        const idx = document.createElement('div');
        idx.className = 'idx';
        idx.textContent = 'CH ' + (ch.index + 1);

        const name = document.createElement('div');
        name.className = 'fader-name';

        const chanBtns = document.createElement('div');
        chanBtns.className = 'fader-chanbtns';
        if (category === 'inch' || category === 'stinch') {
            const eqBtn = document.createElement('button');
            eqBtn.className = 'chanbtn eq';
            eqBtn.type = 'button';
            eqBtn.textContent = 'EQ';
            const dynBtn = document.createElement('button');
            dynBtn.className = 'chanbtn dyn';
            dynBtn.type = 'button';
            dynBtn.textContent = 'DYN';
            chanBtns.append(eqBtn, dynBtn);

            eqBtn.addEventListener('click', () => openEqModal(category, ch.index, wrap._refs ? wrap._refs.name.textContent : ch.name));
            dynBtn.addEventListener('click', () => openDynModal(category, ch.index, wrap._refs ? wrap._refs.name.textContent : ch.name));
        }

        const onBtn = document.createElement('button');
        onBtn.className = 'fader-on';
        onBtn.textContent = 'ON';
        onBtn.type = 'button';

        const trackWrap = document.createElement('div');
        trackWrap.className = 'fader-track-wrap';

        // Referenzlinien: 0 dB (durchgezogen hervorgehoben) sowie -5/-10/-20 dB (fein)
        const zeroLine = document.createElement('div');
        zeroLine.className = 'fader-zero-line';
        zeroLine.title = '0 dB';
        trackWrap.appendChild(zeroLine);

        [-5, -10, -20].forEach((db) => {
            const tick = document.createElement('div');
            tick.className = 'fader-tick-line';
            tick.style.bottom = (dbToPos(db) / FADER_POS_MAX * 100) + '%';
            tick.title = db + ' dB';
            trackWrap.appendChild(tick);
        });

        const input = document.createElement('input');
        input.type = 'range';
        input.className = 'fader-slider';
        input.min = '0';
        input.max = String(FADER_POS_MAX);
        input.step = '2'; // ~0,2 % Auflösung der Fader-Position
        trackWrap.appendChild(input);

        const valEl = document.createElement('div');
        valEl.className = 'fader-value';

        wrap.append(onBtn);
        if (chanBtns.childElementCount > 0) wrap.appendChild(chanBtns);
        wrap.append(name, idx, trackWrap, valEl);

        let isOn = true;

        input.addEventListener('pointerdown', () => draggingKeys.add(key));
        input.addEventListener('input', () => {
            const db = posToDb(parseInt(input.value, 10));
            valEl.textContent = fmtDb(db === null ? null : Math.round(db * 10) / 10);
        });
        input.addEventListener('change', async () => {
            const db = posToDb(parseInt(input.value, 10));
            const raw = db === null ? -32768 : Math.round(db * 100);
            await sendSet(category, ch.index, 'level', raw);
            setTimeout(() => draggingKeys.delete(key), 1200);
        });

        onBtn.addEventListener('click', async () => {
            isOn = !isOn;
            onBtn.classList.toggle('lit', isOn);
            wrap.classList.toggle('muted', !isOn);
            await sendSet(category, ch.index, 'on', isOn ? 1 : 0);
        });

        wrap._refs = { input, valEl, name, onBtn, setIsOn: (v) => { isOn = v; } };
        updateFaderColumn(wrap, ch);
        return wrap;
    }

    // Yamaha CL/QL Kanalfarben. Das Pult liefert den Klarnamen als String
    // (z.B. "Blue"), keinen Zahlenindex - direkt darauf gemappt.
    const CHANNEL_COLORS = {
        off:    null,
        red:    { bg: '#c0384a', fg: '#fff' },
        pink:   { bg: '#d6478a', fg: '#fff' },
        orange: { bg: '#d98a3d', fg: '#1a1206' },
        yellow: { bg: '#d9c23d', fg: '#1a1706' },
        green:  { bg: '#3fae66', fg: '#06170c' },
        cyan:   { bg: '#3fb8c9', fg: '#06171a' },
        blue:   { bg: '#4f7cd9', fg: '#fff' },
        purple: { bg: '#8a5cd9', fg: '#fff' },
    };

    function applyChannelColor(nameEl, colorName) {
        const key = (colorName || '').toString().trim().toLowerCase();
        const c = CHANNEL_COLORS[key];
        if (c) {
            nameEl.style.background = c.bg;
            nameEl.style.color = c.fg;
        } else {
            nameEl.style.background = '';
            nameEl.style.color = '';
        }
    }

    function updateFaderColumn(wrap, ch) {
        const r = wrap._refs;
        r.name.textContent = ch.name;
        r.name.title = ch.name;
        applyChannelColor(r.name, ch.color);
        r.input.value = String(Math.round(dbToPos(ch.level_db)));
        r.valEl.textContent = fmtDb(ch.level_db);

        const isOn = ch.on !== false;
        r.setIsOn(isOn);
        r.onBtn.classList.toggle('lit', isOn);
        wrap.classList.toggle('muted', !isOn);
    }

    // Ziel-Spaltenzahl je nach Fensterbreite: >1600px -> 16, 721-1600px -> 8, <=720px -> 4.
    // Nie mehr Spalten als tatsächlich Kanäle im Container sind (z.B. Stereo/Matrix mit 8).
    function faderGridTargetCols() {
        const w = window.innerWidth;
        if (w > 1600) return 16;
        if (w > 720) return 8;
        return 4;
    }

    function applyFaderGridColumns(container) {
        const count = parseInt(container.dataset.count || '0', 10);
        if (!count) return;
        const cols = Math.max(1, Math.min(faderGridTargetCols(), count));
        container.style.gridTemplateColumns = `repeat(${cols}, minmax(46px, 1fr))`;
    }

    function renderFaderGrid(container, channels, category) {
        if (!container) return;
        if (container.childElementCount === 0) {
            container.dataset.count = String(channels.length);
            applyFaderGridColumns(container);
        }
        channels.forEach((ch) => {
            const key = category + '.' + ch.index;
            let col = container.querySelector(`[data-key="${CSS.escape(key)}"]`);
            if (!col) {
                col = buildFaderColumn(ch, category, key);
                container.appendChild(col);
                return;
            }
            if (draggingKeys.has(key)) return; // während des Ziehens keine Fremdwerte übernehmen
            updateFaderColumn(col, ch);
        });
    }

    function renderOutputs(data) {
        renderFaderGrid(el('#outputsMix'), data.mix || [], 'mix');
        renderFaderGrid(el('#outputsMtrx'), data.mtrx || [], 'mtrx');
    }

    // ---------- Routing ----------
    async function loadRouting() {
        const wrap = el('#routingWrap');
        wrap.innerHTML = '<div class="empty-hint">Lade Matrix-Routing …</div>';
        try {
            const res = await fetch('api.php?action=routing');
            const data = await res.json();
            if (!data.ok) {
                wrap.innerHTML = '';
                showBanner(data.error || 'Fehler beim Abrufen des Routings.');
                return;
            }
            hideBanner();
            state.lastRouting = data;
            renderRouting(data);
        } catch (e) {
            wrap.innerHTML = '';
            showBanner('Routing-Abfrage fehlgeschlagen: ' + e.message);
        }
    }

    function renderRouting(data) {
        const wrap = el('#routingWrap');
        const mixNames = (state.lastStatus && state.lastStatus.data.mix) || [];
        const mtrxNames = (state.lastStatus && state.lastStatus.data.mtrx) || [];

        let html = '<table class="routing"><thead><tr><th>MIX \\ MATRIX</th>';
        for (let x = 0; x < data.mtrx_count; x++) {
            const label = mtrxNames[x] ? mtrxNames[x].name : ('MTRX ' + (x + 1));
            html += `<th>${escapeHtml(label)}</th>`;
        }
        html += '</tr></thead><tbody>';

        for (let m = 0; m < data.mix_count; m++) {
            const label = mixNames[m] ? mixNames[m].name : ('MIX ' + (m + 1));
            html += `<tr><th>${escapeHtml(label)}</th>`;
            for (let x = 0; x < data.mtrx_count; x++) {
                const cell = data.grid[m][x];
                const on = cell.on === true;
                const dbTxt = cell.level_db === null ? '' : ' (' + fmtDb(cell.level_db) + ')';
                html += `<td class="${on ? 'on' : 'off'}">${on ? '\u25CF' : '\u00B7'}${on ? dbTxt : ''}</td>`;
            }
            html += '</tr>';
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // ---------- Helpers ----------
    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function showBanner(msg) {
        const b = el('#errorBanner');
        b.textContent = msg;
        b.style.display = 'block';
    }
    function hideBanner() {
        el('#errorBanner').style.display = 'none';
    }

    // ---------- Modal-Grundgerüst ----------
    function openModal(title, danger) {
        el('#modalTitle').textContent = title;
        const body = el('#modalBody');
        body.innerHTML = '';
        el('#modalPanel').classList.toggle('modal-panel-danger', !!danger);
        el('#modalOverlay').classList.add('active');
        return body;
    }
    function closeModal() {
        el('#modalOverlay').classList.remove('active');
    }

    function renderModalDebug(container, log) {
        if (!container) return;
        container.innerHTML = (log || []).map((l) => {
            const cls = l.dir === 'send' ? 'send' : 'recv';
            const arrow = l.dir === 'send' ? '\u2192' : '\u2190';
            return `<div class="${cls}">${arrow} ${escapeHtml(l.line)}</div>`;
        }).join('');
        container.scrollTop = container.scrollHeight;
    }

    /**
     * Baut eine Eingabefeld-Zeile (statt Fader) für EQ/Dynamics-Werte.
     * rawValue/onCommit arbeiten weiterhin mit dem Rohwert (z.B. Rohwert=dB*100),
     * das Feld selbst zeigt und erwartet den "echten" Wert (z.B. dB, Hz, Q).
     * cfg: { min, max, step, factor, decimals, unit }, alle Grenzen in Rohwert-Einheiten.
     */
    function makeNumberRow(labelText, cfg, rawValue, onCommit, onLive) {
        const factor = cfg.factor || 1;
        const decimals = cfg.decimals ?? 0;
        const dispMin = cfg.min / factor;
        const dispMax = cfg.max / factor;

        const row = document.createElement('div');
        row.className = 'modal-field-row';
        const label = document.createElement('label');
        label.textContent = labelText;

        const inputWrap = document.createElement('div');
        inputWrap.className = 'modal-field-inputwrap';
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'modal-field-input';
        input.min = dispMin.toFixed(decimals);
        input.max = dispMax.toFixed(decimals);
        input.step = (1 / Math.pow(10, decimals)).toString();
        input.value = (rawValue / factor).toFixed(decimals);
        const unitEl = document.createElement('span');
        unitEl.className = 'modal-field-unit';
        unitEl.textContent = cfg.unit || '';
        inputWrap.append(input, unitEl);
        row.append(label, inputWrap);

        function parseClamped() {
            let v = parseFloat(input.value);
            if (isNaN(v)) v = rawValue / factor;
            v = Math.max(dispMin, Math.min(dispMax, v));
            return v;
        }

        input.addEventListener('input', () => {
            if (onLive) onLive(Math.round(parseClamped() * factor));
        });
        input.addEventListener('change', () => {
            const v = parseClamped();
            input.value = v.toFixed(decimals);
            const raw = Math.round(v * factor);
            if (onLive) onLive(raw);
            onCommit(raw);
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') input.blur();
        });

        return { row, input };
    }

    // ---------- EQ-Modal ----------
    // Struktur vom echten QL1-Bildschirm übernommen: HPF ist ein eigener
    // Block (eigenes On/Off, nur Frequenz), danach 4 EQ-Bänder, jedes mit
    // eigenem On/Off-Button. LOW und HIGH sind Shelf-Filter (nur Freq+Gain),
    // LOW-MID und HIGH-MID sind parametrisch (zusätzlich Q).
    const EQ_FREQ_MIN = 20, EQ_FREQ_MAX = 20000;
    const EQ_GAIN_MIN = -1500, EQ_GAIN_MAX = 1500; // Rohwert, /100 = dB
    const EQ_Q_MIN = 10, EQ_Q_MAX = 1200;          // Rohwert, /100 = Q
    const HPF_FREQ_MIN = 20, HPF_FREQ_MAX = 400;
    const EQ_BANDS_META = [
        { key: 'low',     label: 'LOW (L.Shelf)',  hasQ: false, color: '#4fb3ff', defFreq: 100 },
        { key: 'lowmid',  label: 'LOW-MID',        hasQ: true,  color: '#3ddc84', defFreq: 400 },
        { key: 'highmid', label: 'HIGH-MID',       hasQ: true,  color: '#ffcc4d', defFreq: 2000 },
        { key: 'high',    label: 'HIGH (H.Shelf)', hasQ: false, color: '#ff8a5a', defFreq: 8000 },
    ];

    function freqPosToHz(pos) {
        const lo = Math.log10(EQ_FREQ_MIN), hi = Math.log10(EQ_FREQ_MAX);
        return Math.round(Math.pow(10, lo + (pos / 1000) * (hi - lo)));
    }
    function hzToFreqPos(hz) {
        const lo = Math.log10(EQ_FREQ_MIN), hi = Math.log10(EQ_FREQ_MAX);
        const t = (Math.log10(Math.max(EQ_FREQ_MIN, hz)) - lo) / (hi - lo);
        return Math.round(Math.max(0, Math.min(1000, t * 1000)));
    }
    function fmtHz(hz) {
        return hz >= 1000 ? (Math.round(hz / 100) / 10) + ' kHz' : Math.round(hz) + ' Hz';
    }

    async function openEqModal(category, index, name) {
        const body = openModal((name || ('CH ' + (index + 1))) + ' — EQ');
        body.innerHTML = '<div class="empty-hint">Lade EQ-Werte …</div>';

        let data;
        try {
            const res = await fetch(`api.php?action=eq_get&category=${category}&index=${index}`);
            data = await res.json();
        } catch (e) {
            body.innerHTML = `<div class="error-banner" style="display:block">EQ-Abfrage fehlgeschlagen: ${escapeHtml(e.message)}</div>`;
            return;
        }
        if (!data.ok) {
            body.innerHTML = `<div class="error-banner" style="display:block">${escapeHtml(data.error || 'Fehler beim Laden.')}</div>`;
            return;
        }

        body.innerHTML = '';

        const notice = document.createElement('div');
        notice.className = 'modal-notice';
        notice.textContent = 'Pfadnamen für HPF/EQ sind noch unbestätigt (Protokoll evtl. jetzt "RCP" statt "SCP") – bitte am echten Pult gegentesten, Log unten zeigt jede Aktion.';
        body.appendChild(notice);

        const debugLog = document.createElement('div');
        debugLog.className = 'debug-log modal-debug';

        // --- HPF-Block (eigener Schalter) ---
        const hpfBox = document.createElement('div');
        hpfBox.className = 'eq-band-box hpf-box';
        const hpfTitleRow = document.createElement('div');
        hpfTitleRow.className = 'eq-band-title-row';
        const hpfTitle = document.createElement('div');
        hpfTitle.className = 'eq-band-title';
        hpfTitle.textContent = 'HPF (High Pass Filter)';
        const hpfOnBtn = document.createElement('button');
        hpfOnBtn.className = 'modal-toggle small' + (data.hpf.on ? ' active' : '');
        hpfOnBtn.textContent = data.hpf.on ? 'EIN' : 'AUS';
        hpfTitleRow.append(hpfTitle, hpfOnBtn);
        hpfBox.appendChild(hpfTitleRow);

        const hpfFreqRow = makeNumberRow(
            'Freq',
            { min: HPF_FREQ_MIN, max: HPF_FREQ_MAX, factor: 1, decimals: 0, unit: 'Hz' },
            data.hpf.freq_hz || 80,
            async (raw) => {
                const r = await sendEqSet(category, index, 'hpf', 'freq', raw, 0);
                renderModalDebug(debugLog, r && r.debug);
            }
        );
        hpfBox.appendChild(hpfFreqRow.row);

        hpfOnBtn.addEventListener('click', async () => {
            const newOn = !hpfOnBtn.classList.contains('active');
            hpfOnBtn.classList.toggle('active', newOn);
            hpfOnBtn.textContent = newOn ? 'EIN' : 'AUS';
            const r = await sendEqSet(category, index, 'hpf', 'on', newOn ? 1 : 0, 0);
            renderModalDebug(debugLog, r && r.debug);
        });
        body.appendChild(hpfBox);

        // --- Gemeinsamer EQ-Schalter für alle 4 Bänder ---
        const eqOnRow = document.createElement('div');
        eqOnRow.className = 'modal-on-row';
        const eqOnLabel = document.createElement('span');
        eqOnLabel.className = 'modal-on-label';
        eqOnLabel.textContent = 'EQ (4 Bänder)';
        const eqOnBtn = document.createElement('button');
        eqOnBtn.className = 'modal-toggle' + (data.eq_on !== false ? ' active' : '');
        eqOnBtn.textContent = data.eq_on !== false ? 'EQ EIN' : 'EQ AUS';
        eqOnRow.append(eqOnLabel, eqOnBtn);
        body.appendChild(eqOnRow);

        // --- EQ-Kurve ---
        const svgWrap = document.createElement('div');
        svgWrap.className = 'eq-curve-wrap';
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 600 220');
        svg.classList.add('eq-curve-svg');
        svgWrap.appendChild(svg);
        body.appendChild(svgWrap);

        const bandsState = EQ_BANDS_META.map((meta, i) => {
            const b = data.bands[i] || {};
            return {
                freq: b.freq_hz ?? meta.defFreq,
                gain: Math.round((b.gain_db ?? 0) * 100),
                q: Math.round((b.q ?? 1) * 100),
            };
        });
        let eqOn = data.eq_on !== false;

        const bandsWrap = document.createElement('div');
        bandsWrap.className = 'eq-bands';
        body.appendChild(bandsWrap);

        function drawCurve() {
            const W = 600, H = 220, PAD = 28;
            const xForHz = (hz) => PAD + (hzToFreqPos(hz) / 1000) * (W - PAD * 2);
            const yForDb = (db) => H / 2 - (db / 15) * (H / 2 - PAD * 0.6);

            let d = '';
            for (let px = 0; px <= 200; px++) {
                const pos = (px / 200) * 1000;
                const hz = freqPosToHz(pos);
                let totalDb = 0;
                if (eqOn) {
                    bandsState.forEach((b) => {
                        const g = b.gain / 100, q = Math.max(0.1, b.q / 100);
                        const widthOct = 1 / q;
                        const sigma = widthOct / 2 || 0.3;
                        const dist = Math.log2(hz / b.freq) / sigma;
                        totalDb += g * Math.exp(-0.5 * dist * dist);
                    });
                }
                const x = xForHz(hz), y = yForDb(Math.max(-15, Math.min(15, totalDb)));
                d += (px === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1) + ' ';
            }

            let svgContent = `<line x1="${PAD}" y1="${H/2}" x2="${W-PAD}" y2="${H/2}" stroke="#2c323d" stroke-width="1"/>`;
            [20, 100, 1000, 10000].forEach((hz) => {
                const x = xForHz(hz);
                svgContent += `<line x1="${x}" y1="${PAD*0.4}" x2="${x}" y2="${H-PAD*0.4}" stroke="#20242c" stroke-width="1"/>`;
                svgContent += `<text x="${x}" y="${H-6}" fill="#8b93a3" font-size="10" text-anchor="middle">${fmtHz(hz)}</text>`;
            });
            svgContent += `<path d="${d}" fill="none" stroke="${eqOn ? '#4fb3ff' : '#3a4150'}" stroke-width="2"/>`;
            if (eqOn) {
                bandsState.forEach((b, i) => {
                    const x = xForHz(b.freq);
                    const y = yForDb(Math.max(-15, Math.min(15, b.gain / 100)));
                    svgContent += `<circle cx="${x}" cy="${y}" r="4" fill="${EQ_BANDS_META[i].color}" stroke="#0d1015" stroke-width="1.5"/>`;
                });
            }
            svg.innerHTML = svgContent;
        }

        bandsState.forEach((b, i) => {
            const meta = EQ_BANDS_META[i];
            const box = document.createElement('div');
            box.className = 'eq-band-box';
            box.style.setProperty('--band-color', meta.color);

            const title = document.createElement('div');
            title.className = 'eq-band-title';
            title.textContent = meta.label;
            box.appendChild(title);

            const freqRow = makeNumberRow(
                'Freq', { min: EQ_FREQ_MIN, max: EQ_FREQ_MAX, factor: 1, decimals: 0, unit: 'Hz' },
                b.freq,
                async (raw) => {
                    const r = await sendEqSet(category, index, 'eq', 'freq', raw, i);
                    renderModalDebug(debugLog, r && r.debug);
                },
                (raw) => { b.freq = raw; drawCurve(); }
            );
            const gainRow = makeNumberRow(
                'Gain', { min: EQ_GAIN_MIN, max: EQ_GAIN_MAX, factor: 100, decimals: 1, unit: 'dB' },
                b.gain,
                async (raw) => {
                    const r = await sendEqSet(category, index, 'eq', 'gain', raw, i);
                    renderModalDebug(debugLog, r && r.debug);
                },
                (raw) => { b.gain = raw; drawCurve(); }
            );

            box.append(freqRow.row, gainRow.row);

            if (meta.hasQ) {
                const qRow = makeNumberRow(
                    'Q', { min: EQ_Q_MIN, max: EQ_Q_MAX, factor: 100, decimals: 2, unit: '' },
                    b.q,
                    async (raw) => {
                        const r = await sendEqSet(category, index, 'eq', 'q', raw, i);
                        renderModalDebug(debugLog, r && r.debug);
                    },
                    (raw) => { b.q = raw; drawCurve(); }
                );
                box.appendChild(qRow.row);
            }

            bandsWrap.appendChild(box);
        });

        eqOnBtn.addEventListener('click', async () => {
            eqOn = !eqOn;
            eqOnBtn.classList.toggle('active', eqOn);
            eqOnBtn.textContent = eqOn ? 'EQ EIN' : 'EQ AUS';
            bandsWrap.classList.toggle('disabled', !eqOn);
            drawCurve();
            const r = await sendEqSet(category, index, 'eq', 'on', eqOn ? 1 : 0, 0);
            renderModalDebug(debugLog, r && r.debug);
        });
        bandsWrap.classList.toggle('disabled', !eqOn);

        const debugTitle = document.createElement('div');
        debugTitle.className = 'modal-debug-title';
        debugTitle.textContent = 'Rohverkehr (letzte Aktion)';
        renderModalDebug(debugLog, data.debug);
        body.append(debugTitle, debugLog);

        drawCurve();
    }

    async function sendEqSet(category, index, unit, field, value, band) {
        try {
            const body = new URLSearchParams({ category, index, unit, field, value, band });
            const res = await fetch('api.php?action=eq_set', { method: 'POST', body });
            const data = await res.json();
            pushGlobalLog(data.debug);
            return data;
        } catch (e) {
            showBanner('EQ setzen fehlgeschlagen: ' + e.message);
            return null;
        }
    }

    // ---------- Dynamics-Modal (Dyna1 = Gate, Dyna2 = Kompressor) ----------
    const DYN1_RANGES = {
        threshold: { min: -720, max: 0,     factor: 10, decimals: 1, unit: 'dB' },
        range:     { min: -600, max: 0,     factor: 10, decimals: 1, unit: 'dB' },
        attack:    { min: 0,    max: 1000,  factor: 10, decimals: 1, unit: 'ms' },
        hold:      { min: 0,    max: 20000, factor: 10, decimals: 1, unit: 'ms' },
        decay:     { min: 10,   max: 4000,  factor: 10, decimals: 1, unit: 'ms' },
    };
    const DYN1_LABELS = { threshold: 'Threshold', range: 'Range', attack: 'Attack', hold: 'Hold', decay: 'Decay' };
    const DYN1_DEFAULTS = { threshold: -260, range: -300, attack: 10, hold: 100, decay: 500 };

    const DYN2_RANGES = {
        threshold: { min: -540, max: 0,     factor: 10, decimals: 1, unit: 'dB' },
        ratio:     { min: 10,   max: 200,   factor: 10, decimals: 1, unit: ':1' },
        attack:    { min: 0,    max: 1000,  factor: 10, decimals: 1, unit: 'ms' },
        release:   { min: 5,    max: 40000, factor: 10, decimals: 1, unit: 'ms' },
        outgain:   { min: 0,    max: 240,   factor: 10, decimals: 1, unit: 'dB' },
        knee:      { min: 0,    max: 100,   factor: 1,  decimals: 0, unit: '%' },
    };
    const DYN2_LABELS = { threshold: 'Threshold', ratio: 'Ratio', attack: 'Attack', release: 'Release', outgain: 'Out Gain', knee: 'Knee' };
    const DYN2_DEFAULTS = { threshold: -80, ratio: 40, attack: 100, release: 300, outgain: 0, knee: 30 };

    async function openDynModal(category, index, name) {
        const body = openModal((name || ('CH ' + (index + 1))) + ' — Dynamics');
        body.innerHTML = '<div class="empty-hint">Lade Dynamics-Werte …</div>';

        const notice = document.createElement('div');
        notice.className = 'modal-notice';
        notice.textContent = 'Nur Threshold ist bestätigt. Alle anderen Werte (inkl. Pfadnamen) sind Annahmen – bitte am echten Pult gegentesten, Log unten.';

        const tabs = document.createElement('div');
        tabs.className = 'dyn-tabs';
        const tab1 = document.createElement('button');
        tab1.className = 'dyn-tab active';
        tab1.textContent = 'Dyna1 (Gate)';
        const tab2 = document.createElement('button');
        tab2.className = 'dyn-tab';
        tab2.textContent = 'Dyna2 (Kompressor)';
        tabs.append(tab1, tab2);

        const pane1 = document.createElement('div');
        pane1.className = 'dyn-pane active';
        const pane2 = document.createElement('div');
        pane2.className = 'dyn-pane';

        body.innerHTML = '';
        body.append(notice, tabs, pane1, pane2);

        tab1.addEventListener('click', () => {
            tab1.classList.add('active'); tab2.classList.remove('active');
            pane1.classList.add('active'); pane2.classList.remove('active');
        });
        tab2.addEventListener('click', () => {
            tab2.classList.add('active'); tab1.classList.remove('active');
            pane2.classList.add('active'); pane1.classList.remove('active');
        });

        await loadDynPane(pane1, category, index, 'dyna1', DYN1_RANGES, DYN1_LABELS, DYN1_DEFAULTS, false);
        await loadDynPane(pane2, category, index, 'dyna2', DYN2_RANGES, DYN2_LABELS, DYN2_DEFAULTS, true);
    }

    async function loadDynPane(pane, category, index, unit, ranges, labels, defaults, drawCompressorCurve) {
        pane.innerHTML = '<div class="empty-hint">Lade …</div>';
        let data;
        try {
            const res = await fetch(`api.php?action=dyn_get&category=${category}&index=${index}&unit=${unit}`);
            data = await res.json();
        } catch (e) {
            pane.innerHTML = `<div class="error-banner" style="display:block">Abfrage fehlgeschlagen: ${escapeHtml(e.message)}</div>`;
            return;
        }
        if (!data.ok) {
            pane.innerHTML = `<div class="error-banner" style="display:block">${escapeHtml(data.error || 'Fehler beim Laden.')}</div>`;
            return;
        }
        pane.innerHTML = '';

        const vals = {};
        Object.keys(ranges).forEach((k) => {
            const raw = data.values && data.values[k] ? data.values[k].raw : null;
            vals[k] = raw !== null && raw !== undefined ? raw : defaults[k];
        });
        const isOn = data.values && data.values.on ? !!data.values.on.raw : true;

        const onRow = document.createElement('div');
        onRow.className = 'modal-on-row';
        const onBtn = document.createElement('button');
        onBtn.className = 'modal-toggle' + (isOn ? ' active' : '');
        onBtn.textContent = isOn ? 'EIN' : 'AUS';
        onRow.appendChild(onBtn);
        pane.appendChild(onRow);

        let svg = null;
        let topRow = null;
        if (drawCompressorCurve) {
            topRow = document.createElement('div');
            topRow.className = 'dyn-top-row';
            const svgWrap = document.createElement('div');
            svgWrap.className = 'eq-curve-wrap compact';
            svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 300 300');
            svg.classList.add('eq-curve-svg');
            svgWrap.appendChild(svg);
            topRow.appendChild(svgWrap);
            pane.appendChild(topRow);
        }

        function compress(inputDb) {
            const thr = vals.threshold / 10;
            const ratio = Math.max(1, vals.ratio / 10);
            const knee = vals.knee / 100 * 20;
            const makeup = vals.outgain / 10;
            const lower = thr - knee / 2, upper = thr + knee / 2;
            let out;
            if (knee <= 0.01) {
                out = inputDb <= thr ? inputDb : thr + (inputDb - thr) / ratio;
            } else if (inputDb < lower) {
                out = inputDb;
            } else if (inputDb > upper) {
                out = thr + (inputDb - thr) / ratio;
            } else {
                const t = (inputDb - lower) / knee;
                out = inputDb + (1 / ratio - 1) * knee * t * t / 2;
            }
            return out + makeup;
        }

        function drawCurve() {
            if (!svg) return;
            const W = 300, H = 300, PAD = 30, MIN_DB = -60, MAX_DB = 10;
            const xFor = (db) => PAD + ((db - MIN_DB) / (MAX_DB - MIN_DB)) * (W - PAD * 2);
            const yFor = (db) => H - PAD - ((db - MIN_DB) / (MAX_DB - MIN_DB)) * (H - PAD * 2);

            let refD = `M${xFor(MIN_DB)},${yFor(MIN_DB)} L${xFor(MAX_DB)},${yFor(MAX_DB)}`;
            let curveD = '';
            for (let px = 0; px <= 100; px++) {
                const inDb = MIN_DB + (px / 100) * (MAX_DB - MIN_DB);
                const outDb = Math.max(MIN_DB, Math.min(MAX_DB, compress(inDb)));
                const x = xFor(inDb), y = yFor(outDb);
                curveD += (px === 0 ? 'M' : 'L') + x.toFixed(1) + ',' + y.toFixed(1) + ' ';
            }
            const thrX = xFor(vals.threshold / 10);

            svg.innerHTML = `
                <line x1="${PAD}" y1="${H-PAD}" x2="${W-PAD}" y2="${H-PAD}" stroke="#2c323d"/>
                <line x1="${PAD}" y1="${PAD}" x2="${PAD}" y2="${H-PAD}" stroke="#2c323d"/>
                <path d="${refD}" stroke="#3a4150" stroke-width="1" fill="none" stroke-dasharray="4 3"/>
                <line x1="${thrX}" y1="${PAD}" x2="${thrX}" y2="${H-PAD}" stroke="#ffcc4d" stroke-width="1" stroke-dasharray="3 3"/>
                <path d="${curveD}" stroke="#4fb3ff" stroke-width="2.5" fill="none"/>
                <text x="${PAD}" y="16" fill="#8b93a3" font-size="10">Output (dB)</text>
                <text x="${W-PAD}" y="${H-10}" fill="#8b93a3" font-size="10" text-anchor="end">Input (dB)</text>
            `;
        }

        const paramsWrap = document.createElement('div');
        paramsWrap.className = 'dyn-params';
        if (topRow) {
            paramsWrap.classList.add('dyn-params-inline');
            topRow.appendChild(paramsWrap);
        } else {
            pane.appendChild(paramsWrap);
        }

        const debugLog = document.createElement('div');
        debugLog.className = 'debug-log modal-debug';

        Object.keys(ranges).forEach((key) => {
            const cfg = ranges[key];
            const rowObj = makeNumberRow(
                labels[key], cfg, vals[key],
                async (raw) => {
                    const r = await sendDynSet(category, index, unit, key, raw);
                    renderModalDebug(debugLog, r && r.debug);
                },
                (raw) => { vals[key] = raw; drawCurve(); }
            );
            paramsWrap.appendChild(rowObj.row);
        });

        onBtn.addEventListener('click', async () => {
            const newOn = !onBtn.classList.contains('active');
            onBtn.classList.toggle('active', newOn);
            onBtn.textContent = newOn ? 'EIN' : 'AUS';
            const r = await sendDynSet(category, index, unit, 'on', newOn ? 1 : 0);
            renderModalDebug(debugLog, r && r.debug);
        });

        const debugTitle = document.createElement('div');
        debugTitle.className = 'modal-debug-title';
        debugTitle.textContent = 'Rohverkehr (letzte Aktion)';
        renderModalDebug(debugLog, data.debug);
        pane.append(debugTitle, debugLog);

        drawCurve();
    }

    async function sendDynSet(category, index, unit, field, value) {
        try {
            const body = new URLSearchParams({ category, index, unit, field, value });
            const res = await fetch('api.php?action=dyn_set', { method: 'POST', body });
            const data = await res.json();
            pushGlobalLog(data.debug);
            return data;
        } catch (e) {
            showBanner('Dynamics setzen fehlgeschlagen: ' + e.message);
            return null;
        }
    }

    // ---------- Debug-Modal: Sinus-Demo + freie Befehlskonsole ----------
    const DEMO_CHANNEL_COUNT = 16;
    const DEMO_PERIOD_SEC = 10;      // 360° in 10 Sekunden
    const DEMO_TICK_MS = 150;
    const DEMO_AMPLITUDE_DB = 12;    // Ausschlag ± um 0 dB

    const demo = {
        running: false,
        timer: null,
        startedAt: 0,
        savedLevels: null,
        busy: false,        // verhindert überlappende Requests (Backlog-Stau)
        abortCtrl: null,    // zum Abbrechen eines noch laufenden Requests beim Stoppen
    };

    async function sendSetMulti(items, signal) {
        try {
            const res = await fetch('api.php?action=set_multi', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ items }),
                signal,
            });
            return await res.json();
        } catch (e) {
            return null; // inkl. AbortError beim gewollten Abbruch
        }
    }

    function demoTick() {
        // Läuft die Demo nicht mehr, oder ist noch eine Anfrage unterwegs
        // (langsame Verbindung) -> diesen Tick überspringen statt stapeln,
        // sonst könnten nach dem Stoppen noch alte Werte nachträglich ankommen.
        if (!demo.running || demo.busy) return;

        const tSec = (performance.now() - demo.startedAt) / 1000;
        const items = [];
        for (let i = 0; i < DEMO_CHANNEL_COUNT; i++) {
            const phaseDeg = (tSec / DEMO_PERIOD_SEC) * 360 + i * (360 / DEMO_CHANNEL_COUNT);
            const rad = phaseDeg * Math.PI / 180;
            const db = Math.sin(rad) * DEMO_AMPLITUDE_DB;
            items.push({ category: 'inch', index: i, param: 'level', value: Math.round(db * 100) });
        }

        demo.busy = true;
        demo.abortCtrl = new AbortController();
        sendSetMulti(items, demo.abortCtrl.signal).finally(() => {
            demo.busy = false;
        });
    }

    async function startDemo(btn, statusEl) {
        if (demo.running) return;

        statusEl.textContent = 'Sichere aktuelle Fader-Werte …';
        let statusData;
        try {
            const res = await fetch('api.php?action=status');
            statusData = await res.json();
        } catch (e) {
            statusEl.textContent = 'Fehler beim Sichern der Werte: ' + e.message;
            return;
        }
        if (!statusData.ok) {
            statusEl.textContent = 'Fehler beim Sichern der Werte: ' + (statusData.error || 'unbekannt');
            return;
        }

        const inch = statusData.data.inch || [];
        demo.savedLevels = [];
        for (let i = 0; i < DEMO_CHANNEL_COUNT; i++) {
            const ch = inch[i];
            demo.savedLevels.push(ch && ch.level_raw !== null && ch.level_raw !== undefined ? ch.level_raw : -32768);
        }

        demo.running = true;
        demo.busy = false;
        demo.startedAt = performance.now();
        demo.timer = setInterval(demoTick, DEMO_TICK_MS);

        btn.textContent = 'Demo stoppen';
        btn.classList.add('active');
        statusEl.textContent = 'Demo läuft – Sinuswelle über Kanäle 1–' + DEMO_CHANNEL_COUNT + ' (' + DEMO_PERIOD_SEC + ' s / 360°).';
        el('#debugDot').classList.add('active');
        pushGlobalLogLine('send', 'Demo gestartet (Sinuswelle, Kanäle 1–' + DEMO_CHANNEL_COUNT + ')');
    }

    async function stopDemo(btn, statusEl) {
        if (demo.timer) clearInterval(demo.timer);
        demo.timer = null;
        demo.running = false;

        // Eine eventuell noch laufende Anfrage sofort abbrechen, damit kein
        // "alter" Sinus-Wert nach dem Stoppen (oder sogar nach der
        // Wiederherstellung) noch verspätet ankommt.
        if (demo.abortCtrl) {
            demo.abortCtrl.abort();
            demo.abortCtrl = null;
        }
        demo.busy = false;

        btn.textContent = 'Demo starten';
        btn.classList.remove('active');
        el('#debugDot').classList.remove('active');

        if (demo.savedLevels) {
            statusEl.textContent = 'Stelle ursprüngliche Fader-Werte wieder her …';
            const items = demo.savedLevels.map((raw, i) => ({ category: 'inch', index: i, param: 'level', value: raw }));
            await sendSetMulti(items);
            demo.savedLevels = null;
            statusEl.textContent = 'Demo gestoppt, ursprüngliche Werte wiederhergestellt.';
            pushGlobalLogLine('recv', 'Demo gestoppt, ursprüngliche Fader-Werte wiederhergestellt');
        } else {
            statusEl.textContent = 'Demo gestoppt.';
            pushGlobalLogLine('recv', 'Demo gestoppt');
        }
    }

    async function sendRawCmd(cmd) {
        try {
            const body = new URLSearchParams({ cmd });
            const res = await fetch('api.php?action=raw_cmd', { method: 'POST', body });
            const data = await res.json();
            pushGlobalLog(data.debug);
            return data;
        } catch (e) {
            return { ok: false, error: e.message };
        }
    }

    function appendRawLog(container, dir, line) {
        const div = document.createElement('div');
        div.className = dir === 'send' ? 'send' : 'recv';
        div.textContent = (dir === 'send' ? '\u2192 ' : '\u2190 ') + line;
        container.appendChild(div);
        container.scrollTop = container.scrollHeight;
    }

    function fmtLogTime(ts) {
        return new Date(ts).toLocaleTimeString('de-DE');
    }

    function openLogModal() {
        const body = openModal('Log (letzte ' + GLOBAL_LOG_MAX + ' Einträge)');

        const notice = document.createElement('div');
        notice.className = 'modal-notice';
        notice.textContent = 'Zeigt die letzten Aktionen dieser Sitzung (Fader/Mute, EQ, Dynamics, Konsolenbefehle, Presets, Demo). Reine Statusabfragen (Polling) werden nicht mitgeloggt.';
        body.appendChild(notice);

        const logWrap = document.createElement('div');
        logWrap.className = 'debug-log';

        if (globalLog.length === 0) {
            logWrap.innerHTML = '<div class="empty-hint">Noch keine Aktionen in dieser Sitzung.</div>';
        } else {
            logWrap.innerHTML = globalLog.map((l) => {
                const cls = l.dir === 'send' ? 'send' : 'recv';
                const arrow = l.dir === 'send' ? '\u2192' : '\u2190';
                return `<div class="${cls}">[${fmtLogTime(l.ts)}] ${arrow} ${escapeHtml(l.line)}</div>`;
            }).join('');
        }
        body.appendChild(logWrap);
        logWrap.scrollTop = logWrap.scrollHeight;
    }

    function openDebugModal() {
        const body = openModal('Debug-Werkzeuge', true);

        const notice = document.createElement('div');
        notice.className = 'modal-notice danger';
        notice.textContent = 'Achtung: Diese Werkzeuge senden Live-Befehle direkt ans Pult. Der Demo-Modus bewegt echte Fader, die Konsole schickt Rohbefehle ungeprüft weiter.';
        body.appendChild(notice);

        // --- Info: Beispiele externe API ---
        const apiInfo = document.createElement('div');
        apiInfo.className = 'debug-section api-info';
        const apiInfoTitle = document.createElement('div');
        apiInfoTitle.className = 'debug-section-title';
        apiInfoTitle.textContent = 'Externe API – Beispiele';
        apiInfo.appendChild(apiInfoTitle);

        const apiExamples = [
            { label: 'SET Input-Volume', url: 'api.php?action=set&category=inch&index=0&param=level&value=-600' },
            { label: 'SET Input-Name',   url: 'api.php?action=set&category=inch&index=0&param=name&value=HH1' },
            { label: 'GET Out-Volume',   url: 'api.php?action=get&category=mix&index=0&param=level' },
        ];
        apiExamples.forEach((ex) => {
            const row = document.createElement('div');
            row.className = 'api-info-row';
            const label = document.createElement('span');
            label.className = 'api-info-label';
            label.textContent = ex.label;
            const url = document.createElement('code');
            url.className = 'api-info-url';
            url.textContent = ex.url;
            row.append(label, url);
            apiInfo.appendChild(row);
        });
        body.appendChild(apiInfo);

        // --- Demo-Sektion ---
        const demoSection = document.createElement('div');
        demoSection.className = 'debug-section';
        const demoTitle = document.createElement('div');
        demoTitle.className = 'debug-section-title';
        demoTitle.textContent = 'Demo-Modus (Sinus-Welle)';
        const demoBtn = document.createElement('button');
        demoBtn.className = 'modal-toggle danger-toggle' + (demo.running ? ' active' : '');
        demoBtn.textContent = demo.running ? 'Demo stoppen' : 'Demo starten';
        const demoStatus = document.createElement('p');
        demoStatus.className = 'debug-hint';
        demoStatus.textContent = demo.running
            ? 'Demo läuft – Sinuswelle über Kanäle 1–' + DEMO_CHANNEL_COUNT + '.'
            : 'Läuft über die ersten ' + DEMO_CHANNEL_COUNT + ' Mono-Eingänge, phasenversetzt wie eine laufende Welle. Tempo: 360° in ' + DEMO_PERIOD_SEC + ' s. Die aktuellen Fader-Werte werden vor dem Start gesichert und beim Stoppen wiederhergestellt.';
        demoSection.append(demoTitle, demoBtn, demoStatus);
        body.appendChild(demoSection);

        demoBtn.addEventListener('click', () => {
            if (demo.running) {
                stopDemo(demoBtn, demoStatus);
            } else {
                startDemo(demoBtn, demoStatus);
            }
        });

        // --- Konsolen-Sektion ---
        const cmdSection = document.createElement('div');
        cmdSection.className = 'debug-section';
        const cmdTitle = document.createElement('div');
        cmdTitle.className = 'debug-section-title';
        cmdTitle.textContent = 'Eigener SCP/RCP-Befehl';
        cmdSection.appendChild(cmdTitle);

        const examples = [
            'get MIXER:Current/InCh/Fader/Level 0 0',
            'get MIXER:Current/InCh/Label/Name 0 0',
        ];
        const examplesWrap = document.createElement('div');
        examplesWrap.className = 'cmd-examples';
        examples.forEach((ex) => {
            const exBtn = document.createElement('button');
            exBtn.type = 'button';
            exBtn.className = 'cmd-example';
            exBtn.textContent = ex;
            exBtn.title = 'Zum Übernehmen klicken';
            exBtn.addEventListener('click', () => {
                cmdInput.value = ex;
                cmdInput.focus();
            });
            examplesWrap.appendChild(exBtn);
        });
        cmdSection.appendChild(examplesWrap);

        const cmdRow = document.createElement('div');
        cmdRow.className = 'raw-cmd-row';
        const cmdInput = document.createElement('input');
        cmdInput.type = 'text';
        cmdInput.placeholder = 'z.B. get MIXER:Current/InCh/Label/Name 0 0';
        cmdInput.autocomplete = 'off';
        cmdInput.spellcheck = false;
        const cmdSendBtn = document.createElement('button');
        cmdSendBtn.type = 'button';
        cmdSendBtn.textContent = 'Senden';
        cmdRow.append(cmdInput, cmdSendBtn);
        cmdSection.appendChild(cmdRow);

        const cmdLog = document.createElement('div');
        cmdLog.className = 'debug-log';
        cmdLog.id = 'rawCmdLog';
        cmdSection.appendChild(cmdLog);
        body.appendChild(cmdSection);

        async function submitCmd() {
            const cmd = cmdInput.value.trim();
            if (!cmd) return;
            appendRawLog(cmdLog, 'send', cmd);
            cmdSendBtn.disabled = true;
            const r = await sendRawCmd(cmd);
            cmdSendBtn.disabled = false;
            if (!r) {
                appendRawLog(cmdLog, 'recv', '(keine Antwort / Verbindungsfehler)');
                return;
            }
            if (!r.ok) {
                appendRawLog(cmdLog, 'recv', 'FEHLER: ' + (r.error || 'unbekannt'));
                return;
            }
            appendRawLog(cmdLog, 'recv', r.raw === null ? '(Timeout, keine Antwort)' : r.raw);
        }

        cmdSendBtn.addEventListener('click', submitCmd);
        cmdInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') submitCmd();
        });
    }

    // ---------- Presets-Modal ----------
    function fmtPresetTime(ts) {
        if (!ts) return '–';
        return new Date(ts * 1000).toLocaleString('de-DE');
    }

    async function openPresetsModal() {
        const body = openModal('Presets');
        body.innerHTML = '<div class="empty-hint">Lade Preset-Liste …</div>';

        let data;
        try {
            const res = await fetch('api.php?action=preset_list');
            data = await res.json();
        } catch (e) {
            body.innerHTML = `<div class="error-banner" style="display:block">Preset-Liste fehlgeschlagen: ${escapeHtml(e.message)}</div>`;
            return;
        }
        if (!data.ok) {
            body.innerHTML = `<div class="error-banner" style="display:block">${escapeHtml(data.error || 'Fehler beim Laden.')}</div>`;
            return;
        }

        body.innerHTML = '';

        const notice = document.createElement('div');
        notice.className = 'modal-notice';
        notice.textContent = 'Ein Preset umfasst Fader, Namen, Mute, komplettes Matrix-Routing sowie EQ/HPF und Dynamics aller Ein\u00ADgänge. Speichern/Laden liest bzw. sendet dafür über 1000 Einzelbefehle in einer Verbindung – das kann einige Sekunden dauern. Presets liegen als JSON-Dateien in data/presets/ und lassen sich dort auch direkt als Dateien austauschen.';
        body.appendChild(notice);

        const table = document.createElement('table');
        table.className = 'presets-table';
        const thead = document.createElement('thead');
        thead.innerHTML = '<tr><th>Name</th><th>Gespeichert</th><th></th><th></th></tr>';
        const tbody = document.createElement('tbody');
        table.append(thead, tbody);
        body.appendChild(table);

        data.presets.forEach((preset) => {
            const tr = document.createElement('tr');

            const nameTd = document.createElement('td');
            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.className = 'preset-name-input';
            nameInput.value = preset.name || ('Preset ' + preset.slot);
            nameInput.placeholder = 'Preset ' + preset.slot;
            nameTd.appendChild(nameInput);

            const timeTd = document.createElement('td');
            timeTd.className = 'preset-time';
            timeTd.textContent = preset.exists ? fmtPresetTime(preset.saved_at) : 'leer';

            const loadTd = document.createElement('td');
            const loadBtn = document.createElement('button');
            loadBtn.type = 'button';
            loadBtn.textContent = 'Preset laden';
            loadBtn.disabled = !preset.exists;
            loadTd.appendChild(loadBtn);

            const saveTd = document.createElement('td');
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'primary';
            saveBtn.textContent = 'Preset speichern';
            saveTd.appendChild(saveBtn);

            tr.append(nameTd, timeTd, loadTd, saveTd);
            tbody.appendChild(tr);

            loadBtn.addEventListener('click', async () => {
                loadBtn.disabled = true;
                saveBtn.disabled = true;
                const prevText = loadBtn.textContent;
                loadBtn.textContent = 'Lädt …';
                try {
                    const body2 = new URLSearchParams({ slot: preset.slot });
                    const res = await fetch('api.php?action=preset_load', { method: 'POST', body: body2 });
                    const r = await res.json();
                    if (!r.ok) {
                        showBanner(r.error || 'Laden fehlgeschlagen.');
                    } else {
                        loadStatus(false); // Anzeige nach dem Laden aktualisieren
                        pushGlobalLogLine('recv', 'Preset "' + (r.name || preset.slot) + '" geladen (' + r.ok_count + '/' + r.command_count + ' Befehle bestätigt)');
                    }
                } catch (e) {
                    showBanner('Laden fehlgeschlagen: ' + e.message);
                } finally {
                    loadBtn.disabled = false;
                    saveBtn.disabled = false;
                    loadBtn.textContent = prevText;
                }
            });

            saveBtn.addEventListener('click', async () => {
                const name = nameInput.value.trim() || ('Preset ' + preset.slot);
                const confirmed = confirm(
                    'Preset "' + name + '" (Platz ' + preset.slot + ') jetzt mit dem aktuellen ' +
                    'Pult-Zustand überschreiben?\n\nDas kann einige Sekunden dauern und ist nicht rückgängig zu machen.'
                );
                if (!confirmed) return;

                loadBtn.disabled = true;
                saveBtn.disabled = true;
                const prevText = saveBtn.textContent;
                saveBtn.textContent = 'Speichert …';
                try {
                    const body2 = new URLSearchParams({ slot: preset.slot, name });
                    const res = await fetch('api.php?action=preset_save', { method: 'POST', body: body2 });
                    const r = await res.json();
                    if (!r.ok) {
                        showBanner(r.error || 'Speichern fehlgeschlagen.');
                    } else {
                        preset.exists = true;
                        preset.saved_at = r.saved_at;
                        timeTd.textContent = fmtPresetTime(r.saved_at);
                        loadBtn.disabled = false;
                        pushGlobalLogLine('send', 'Preset "' + name + '" gespeichert (Platz ' + preset.slot + ', ' + r.command_count + ' Befehle)');
                    }
                } catch (e) {
                    showBanner('Speichern fehlgeschlagen: ' + e.message);
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = prevText;
                }
            });
        });
    }

    // ---------- Auto-Refresh ----------
    function scheduleRefresh() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(() => {
            if (!state.autoRefresh) return;
            ping();
            if (state.activeTab === 'inputs' || state.activeTab === 'outputs') {
                loadStatus(false);
            }
        }, state.pollMs);
    }

    function initControls() {
        el('#refreshBtn').addEventListener('click', () => {
            ping();
            if (state.activeTab === 'routing') loadRouting();
            else loadStatus();
        });

        el('#autoRefreshToggle').addEventListener('change', (e) => {
            state.autoRefresh = e.target.checked;
        });

        el('#modalClose').addEventListener('click', closeModal);
        el('#modalOverlay').addEventListener('click', (e) => {
            if (e.target.id === 'modalOverlay') closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        el('#debugModalBtn').addEventListener('click', openDebugModal);
        el('#presetsModalBtn').addEventListener('click', openPresetsModal);
        el('#logModalBtn').addEventListener('click', openLogModal);

        let resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                els('.fader-grid').forEach(applyFaderGridColumns);
            }, 150);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initControls();
        ping();
        loadStatus();
        scheduleRefresh();
    });
})();
