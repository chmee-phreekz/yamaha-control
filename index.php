<?php
$config = require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Yamaha QL1 Regie</title>
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
<div id="app">

    <header class="top">
        <div class="title">
            Yamaha QL1 Regie
            <span class="badge">SCP</span>
            <span class="badge">v<?= htmlspecialchars($config['app_version']) ?></span>
        </div>
        <div class="conn-info">
            <span><span class="dot pending" id="connDot"></span><span id="connText">Verbinde …</span></span>
            <span>Pult: <b><?= htmlspecialchars($config['mixer_ip']) ?>:<?= (int)$config['mixer_port'] ?></b></span>
            <span>Letzte Aktualisierung: <b id="lastUpdate">-</b></span>
        </div>
        <div class="actions">
            <label class="switch">
                <input type="checkbox" id="autoRefreshToggle" checked>
                Auto-Refresh (<?= (int)($config['poll_interval_ms'] / 1000) ?>s)
            </label>
            <button class="primary" id="refreshBtn">Jetzt aktualisieren</button>
            <button id="presetsModalBtn" type="button">Presets</button>
            <button class="btn-ghost" id="logModalBtn" type="button">Log</button>
            <button class="btn-ghost" id="debugModalBtn" type="button">Debug<span class="ghost-dot" id="debugDot"></span></button>
        </div>
    </header>

    <nav class="tabs">
        <button data-tab="inputs" class="active">Eingänge</button>
        <button data-tab="outputs">Ausgänge (Mix/Matrix)</button>
        <button data-tab="routing">Matrix-Routing</button>
    </nav>

    <main>
        <div class="error-banner" id="errorBanner" style="display:none;"></div>

        <section class="panel active" id="panel-inputs">
            <div class="section-title">Mono-Eingänge (<?= (int)$config['inch_count'] ?>)</div>
            <div class="fader-grid" id="inputsMonoRow1"></div>
            <div class="fader-grid" id="inputsMonoRow2"></div>

            <div class="section-title">Stereo-Eingänge (<?= (int)$config['stinch_count'] ?>)</div>
            <div class="fader-grid" id="inputsStereo"></div>
        </section>

        <section class="panel" id="panel-outputs">
            <div class="section-title">MIX-Busse (<?= (int)$config['mix_count'] ?>)</div>
            <div class="fader-grid" id="outputsMix"></div>

            <div class="section-title">MATRIX-Busse (<?= (int)$config['mtrx_count'] ?>)</div>
            <div class="fader-grid" id="outputsMtrx"></div>
        </section>

        <section class="panel" id="panel-routing">
            <div class="section-title">MIX → MATRIX Routing</div>
            <div class="routing-wrap" id="routingWrap">
                <div class="empty-hint">Wird geladen, sobald der Tab geöffnet wird …</div>
            </div>
        </section>
    </main>

    <div class="modal-overlay" id="modalOverlay">
        <div class="modal-panel" id="modalPanel">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">-</div>
                <button class="modal-close" id="modalClose" type="button">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
        </div>
    </div>
</div>

<script>window.QL1_POLL_MS = <?= (int)$config['poll_interval_ms'] ?>;</script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
