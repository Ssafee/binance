<?php
declare(strict_types=1);

/**
 * Shared markup for the ladder pages.
 * Set $LADDER_MODE to 'live' or 'sim' before including this file.
 */

require_once dirname(__DIR__) . '/api/lib/auth.php';

$mode = ($LADDER_MODE ?? 'live') === 'sim' ? 'sim' : 'live';
$isSim = $mode === 'sim';
$self = $isSim ? 'simulate.php' : 'ladder.php';
$other = $isSim ? 'ladder.php' : 'simulate.php';
$otherLabel = $isSim ? 'Go to live ladder' : 'Go to simulator';

$loginError = null;

if (isset($_GET['logout'])) {
    ladderLogout();
    header('Location: ' . $self);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['ladder_password'])) {
    if (!ladderPasswordConfigured()) {
        $loginError = 'No password set yet — add LADDER_PASSWORD to your .env file.';
    } elseif (ladderLogin((string) $_POST['ladder_password'])) {
        header('Location: ' . $self);
        exit;
    } else {
        $loginError = 'Wrong password.';
    }
}

$authed = ladderIsAuthed();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isSim ? 'Simulator' : 'Ladder' ?> — Binance daily buy / sell at profit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css?v=24">
  <link rel="stylesheet" href="assets/ladder.css?v=12">
</head>
<body class="lad-body<?= $isSim ? ' lad-sim' : '' ?>">
  <div class="bg-grid" aria-hidden="true"></div>

  <main class="wrap">
    <header class="brand">
      <div class="brand-mark">
        <span class="brand-dot" aria-hidden="true"></span>
        <span class="brand-label">BINANCE <?= $isSim ? 'SIMULATOR' : 'LADDER' ?></span>
        <span class="lad-badge <?= $isSim ? 'lad-badge-sim' : 'lad-badge-live' ?>">
          <?= $isSim ? 'SIMULATION — no real orders' : 'LIVE — real money' ?>
        </span>
      </div>
      <h1><?= $isSim ? 'Practice the ladder' : 'Buy daily · Sell only in profit' ?></h1>
      <p>
        Buy a fixed amount every day, hold each buy as its own entry, and sell it only once it clears
        fees plus your target profit. Losing entries are never sold — they just wait.
      </p>
      <nav class="lad-nav">
        <a href="index.php">← Main dashboard</a>
        <a href="<?= $other ?>"><?= $otherLabel ?></a>
        <a href="graph.php">Coin graph</a>
        <a href="cron-log.php">Cron log</a>
        <?php if ($authed): ?>
          <a class="lad-nav-out" href="<?= $self ?>?logout=1">Sign out</a>
        <?php endif; ?>
      </nav>
    </header>

<?php if (!$authed): ?>

    <section class="lad-login" aria-label="Sign in">
      <h2>Password required</h2>
      <?php if (!ladderPasswordConfigured()): ?>
        <p class="lad-alert lad-alert-warn">
          No password is set. Add <code>LADDER_PASSWORD=your-secret</code> to your
          <code>.env</code> file, then reload this page.
        </p>
      <?php endif; ?>
      <?php if ($loginError !== null): ?>
        <p class="lad-alert lad-alert-bad"><?= htmlspecialchars($loginError, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= $self ?>" class="lad-login-form">
        <label for="ladder_password">Password</label>
        <input
          id="ladder_password"
          name="ladder_password"
          type="password"
          autocomplete="current-password"
          required
          autofocus
        >
        <button type="submit">Sign in</button>
      </form>
    </section>

<?php else: ?>

    <section class="lad-strip" aria-label="Status">
<?php if ($isSim): ?>
      <div>
        <p class="lad-label">Test price</p>
        <p class="lad-value lad-price" id="lad-price">—</p>
      </div>
      <div>
        <p class="lad-label">Simulated cash</p>
        <p class="lad-value" id="lad-cash">—</p>
      </div>
<?php else: ?>
      <div>
        <p class="lad-label">API status</p>
        <p class="lad-value" id="lad-api-status">Checking…</p>
      </div>
      <div>
        <p class="lad-label">USDT free</p>
        <p class="lad-value" id="lad-cash">—</p>
      </div>
<?php endif; ?>
      <div>
        <p class="lad-label">Open entries</p>
        <p class="lad-value" id="lad-open-count">—</p>
      </div>
      <div>
        <p class="lad-label">Ready to sell</p>
        <p class="lad-value" id="lad-matured">—</p>
      </div>
      <div>
        <p class="lad-label">Last auto run</p>
        <p class="lad-value" id="lad-lastrun">—</p>
      </div>
    </section>

    <p id="lad-status" class="lad-status" role="status" aria-live="polite"></p>

    <details class="lad-panel" id="lad-config-panel" open>
      <summary>
        <span>Configurations</span>
        <small id="lad-config-summary" class="lad-config-summary">—</small>
      </summary>

      <div class="lad-table-wrap" style="margin-top:0.75rem">
        <table class="lad-table">
          <thead>
            <tr>
              <th>Symbol</th>
              <th>Amount/day</th>
              <th>Fee %</th>
              <th>Target %</th>
              <th>Last buy day</th>
<?php if ($isSim): ?>
              <th>Test price</th>
<?php endif; ?>
              <th class="lad-col-act">Actions</th>
            </tr>
          </thead>
          <tbody id="lad-config-rows">
            <tr><td colspan="<?= $isSim ? 7 : 6 ?>" class="lad-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>

      <h3 class="lad-subhead" id="cfg-form-title">Add configuration</h3>
      <div class="lad-grid-form">
        <label class="lad-field lad-field-symbol">
          <span>Coin / symbol</span>
          <input
            id="cfg-symbol"
            type="text"
            list="lad-symbol-list"
            placeholder="ETHUSDT"
            spellcheck="false"
            autocomplete="off"
            maxlength="20"
          >
          <datalist id="lad-symbol-list"></datalist>
        </label>
        <label class="lad-field">
          <span>Amount per day (USDT)</span>
          <input id="cfg-daily" type="number" step="0.01" min="0" placeholder="5">
        </label>
        <label class="lad-field">
          <span>Fee per side (%)</span>
          <input id="cfg-fee" type="number" step="0.01" min="0" placeholder="0.1" value="0.1">
        </label>
        <label class="lad-field">
          <span>Net profit target (%)</span>
          <input id="cfg-profit" type="number" step="0.01" min="0" placeholder="0.3" value="0.3">
        </label>
<?php if ($isSim): ?>
        <label class="lad-field">
          <span>Sim start balance (USDT)</span>
          <input id="cfg-sim-start" type="number" step="0.01" min="0" placeholder="100">
        </label>
<?php endif; ?>
      </div>
      <input type="hidden" id="cfg-id" value="">

      <div class="lad-actions">
        <button id="cfg-save" type="button">Save configuration</button>
        <button id="cfg-clear" type="button" class="ghost-btn">Clear form</button>
      </div>
      <div id="lad-config-warnings"></div>
    </details>

    <section class="lad-cards" aria-label="Investment and profit">
      <article class="lad-card">
        <span>Invested (open)</span>
        <strong id="dash-invested-open">—</strong>
        <small id="dash-invested-total">—</small>
      </article>
      <article class="lad-card">
        <span>Value now</span>
        <strong id="dash-open-value">—</strong>
        <small id="dash-open-qty">—</small>
      </article>
      <article class="lad-card">
        <span>Unrealized</span>
        <strong id="dash-unrealized">—</strong>
        <small id="dash-unrealized-pct">—</small>
      </article>
      <article class="lad-card lad-card-key">
        <span>Realized profit</span>
        <strong id="dash-realized">—</strong>
        <small id="dash-sold-count">—</small>
      </article>
      <article class="lad-card lad-card-key">
        <span>Total profit</span>
        <strong id="dash-total">—</strong>
        <small id="dash-avg-hold">—</small>
      </article>
      <article class="lad-card">
        <span>Best / worst sale</span>
        <strong id="dash-best">—</strong>
        <small id="dash-worst">—</small>
      </article>
    </section>

    <section class="lad-panel lad-open" aria-label="Actions">
      <h2>Actions</h2>
<?php if ($isSim): ?>
      <div class="lad-sim-box">
        <h3>Paper trading — set a test price per coin in Configurations</h3>
        <p class="lad-hint" style="margin-top:0">
          This page never places real Binance orders. Enter a test price on each config row,
          then buy / raise the price / sell to practice the ladder.
        </p>
        <div class="lad-grid-form">
          <label class="lad-field">
            <span>Buy amount override (USDT, optional)</span>
            <input id="sim-amount" type="number" step="0.01" min="0" placeholder="from config">
          </label>
        </div>
      </div>
<?php endif; ?>
      <div class="lad-actions">
        <button id="act-buy" type="button"><?= $isSim ? 'Add simulated entry' : 'Buy now (extra entry)' ?></button>
        <button id="act-sell-matured" type="button" class="lad-btn-good">Sell all matured</button>
        <button id="act-run" type="button" class="ghost-btn">Run auto pass now</button>
        <button id="act-refresh" type="button" class="ghost-btn">Refresh</button>
<?php if ($isSim): ?>
        <button id="act-reset" type="button" class="lad-btn-bad">Reset simulation</button>
<?php endif; ?>
      </div>
<?php if (!$isSim): ?>
      <label class="lad-check lad-check-inline">
        <input id="act-browser-auto" type="checkbox">
        <span>Browser auto-sell while this tab is open (the cron does this anyway)</span>
      </label>
<?php endif; ?>
    </section>

    <section class="lad-panel" aria-label="Entries">
      <div class="lad-table-head">
        <h2>Entries</h2>
        <div class="lad-table-tools">
          <label class="lad-field lad-field-sm">
            <span>Symbol</span>
            <select id="flt-symbol">
              <option value="">All</option>
            </select>
          </label>
          <label class="lad-field lad-field-sm">
            <span>Show</span>
            <select id="flt-status">
              <option value="">All</option>
              <option value="OPEN">Open only</option>
              <option value="MATURED">Ready to sell</option>
              <option value="SOLD">Sold only</option>
            </select>
          </label>
          <label class="lad-field lad-field-sm">
            <span>Per page</span>
            <select id="flt-perpage">
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </label>
        </div>
      </div>

      <div class="lad-table-wrap">
        <table class="lad-table">
          <thead>
            <tr>
              <th>Day</th>
              <th>Buy price</th>
              <th>Qty</th>
              <th>Cost</th>
              <th>Value now</th>
              <th>Sell target</th>
              <th>Now</th>
              <th>P/L</th>
              <th>Status</th>
              <th class="lad-col-act">Action</th>
            </tr>
          </thead>
          <tbody id="lad-rows">
            <tr><td colspan="10" class="lad-empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>

      <div class="lad-pager">
        <button id="pg-prev" type="button" class="ghost-btn">← Prev</button>
        <span id="pg-info">—</span>
        <button id="pg-next" type="button" class="ghost-btn">Next →</button>
      </div>
    </section>

    <section class="lad-panel" aria-label="Daily breakdown">
      <h2>Day by day</h2>
      <div class="lad-table-wrap">
        <table class="lad-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Buys</th>
              <th>Invested</th>
              <th>Still open</th>
              <th>Sold</th>
              <th>Profit</th>
            </tr>
          </thead>
          <tbody id="lad-byday">
            <tr><td colspan="6" class="lad-empty">—</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="lad-panel" aria-label="Automation log">
      <h2>Automation log</h2>
      <ul class="lad-log" id="lad-log"><li>—</li></ul>
      <p class="lad-hint">
        Cron: <code>* * * * * /usr/bin/php <?= htmlspecialchars(dirname(__DIR__), ENT_QUOTES) ?>/cron/ladder_cron.php</code>
      </p>
    </section>

<?php endif; ?>
  </main>

  <script>
    window.LADDER_MODE = <?= json_encode($mode) ?>;
    window.LADDER_AUTHED = <?= $authed ? 'true' : 'false' ?>;
  </script>
  <script src="assets/ladder.js?v=16"></script>
</body>
</html>
