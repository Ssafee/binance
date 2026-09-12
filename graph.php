<?php
declare(strict_types=1);

/** Daily price history chart — public, no API keys needed. */
$defaultSymbol = strtoupper((string) ($_GET['symbol'] ?? 'ETHUSDT'));
$defaultSymbol = preg_replace('/[^A-Z0-9]/', '', $defaultSymbol) ?: 'ETHUSDT';
$defaultDays = (int) ($_GET['days'] ?? 30);
if ($defaultDays < 7) {
    $defaultDays = 7;
}
if ($defaultDays > 90) {
    $defaultDays = 90;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Graph of coin — Binance daily history</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css?v=24">
  <link rel="stylesheet" href="assets/graph.css?v=1">
</head>
<body class="graph-body">
  <div class="bg-grid" aria-hidden="true"></div>

  <main class="wrap">
    <header class="brand">
      <div class="brand-mark">
        <span class="brand-dot" aria-hidden="true"></span>
        <span class="brand-label">COIN GRAPH</span>
      </div>
      <h1>Daily price history</h1>
      <p>Pick a USDT pair and view the last month of daily candles from Binance (open, high, low, close, volume).</p>
      <nav class="graph-nav">
        <a href="index.php">← Ladder (home)</a>
        <a href="watch.php">Watch dashboard</a>
        <a href="simulate.php">Simulator</a>
        <a href="calendar.php">Calendar</a>
        <a href="cron-log.php">Cron log</a>
      </nav>
    </header>

    <section class="panel graph-panel" aria-label="Chart controls">
      <div class="graph-controls">
        <label class="graph-field">
          <span>Coin / symbol</span>
          <input
            id="graph-symbol"
            type="text"
            list="graph-symbol-list"
            value="<?= htmlspecialchars($defaultSymbol, ENT_QUOTES) ?>"
            placeholder="ETHUSDT"
            spellcheck="false"
            autocomplete="off"
            maxlength="20"
          >
          <datalist id="graph-symbol-list"></datalist>
        </label>
        <label class="graph-field">
          <span>History</span>
          <select id="graph-days">
            <option value="7"<?= $defaultDays === 7 ? ' selected' : '' ?>>Last 7 days</option>
            <option value="30"<?= $defaultDays === 30 ? ' selected' : '' ?>>Last 30 days</option>
            <option value="60"<?= $defaultDays === 60 ? ' selected' : '' ?>>Last 60 days</option>
            <option value="90"<?= $defaultDays === 90 ? ' selected' : '' ?>>Last 90 days</option>
          </select>
        </label>
        <button id="graph-load" type="button">Load chart</button>
      </div>
      <p id="graph-status" class="graph-status" aria-live="polite"></p>
    </section>

    <section class="graph-stats" id="graph-stats" aria-label="Summary" hidden>
      <div class="graph-stat">
        <p class="graph-stat-label">Symbol</p>
        <p class="graph-stat-value" id="stat-symbol">—</p>
      </div>
      <div class="graph-stat">
        <p class="graph-stat-label">Period</p>
        <p class="graph-stat-value" id="stat-period">—</p>
      </div>
      <div class="graph-stat">
        <p class="graph-stat-label">Close</p>
        <p class="graph-stat-value" id="stat-close">—</p>
      </div>
      <div class="graph-stat">
        <p class="graph-stat-label">Change</p>
        <p class="graph-stat-value" id="stat-change">—</p>
      </div>
      <div class="graph-stat">
        <p class="graph-stat-label">High</p>
        <p class="graph-stat-value" id="stat-high">—</p>
      </div>
      <div class="graph-stat">
        <p class="graph-stat-label">Low</p>
        <p class="graph-stat-value" id="stat-low">—</p>
      </div>
    </section>

    <section class="panel graph-chart-panel" aria-label="Price chart">
      <div id="graph-chart" class="graph-chart"></div>
      <div id="graph-volume" class="graph-volume"></div>
    </section>

    <section class="panel graph-table-panel" aria-label="Daily data">
      <h2 class="graph-table-title">Day-by-day</h2>
      <div class="graph-table-wrap">
        <table class="graph-table">
          <thead>
            <tr>
              <th>Date (UTC)</th>
              <th>Open</th>
              <th>High</th>
              <th>Low</th>
              <th>Close</th>
              <th>Change</th>
              <th>Volume</th>
            </tr>
          </thead>
          <tbody id="graph-rows">
            <tr><td colspan="7" class="graph-empty">Select a coin and load the chart.</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
  <script src="assets/graph.js?v=1"></script>
</body>
</html>
