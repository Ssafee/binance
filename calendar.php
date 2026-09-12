<?php
declare(strict_types=1);

/**
 * Ladder transaction calendar — buy, hold span, sell on a timeline.
 */

require_once __DIR__ . '/api/lib/bootstrap.php';
require_once __DIR__ . '/api/lib/env.php';
require_once __DIR__ . '/api/lib/auth.php';

$self = 'calendar.php';
$loginError = null;
$mode = isset($_GET['mode']) && $_GET['mode'] === 'sim' ? 'sim' : 'live';

if (isset($_GET['logout'])) {
    ladderLogout();
    header('Location: ' . $self);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['ladder_password'])) {
    if (!ladderPasswordConfigured()) {
        $loginError = 'No password set yet — add LADDER_PASSWORD to your .env file.';
    } elseif (ladderLogin((string) $_POST['ladder_password'])) {
        header('Location: ' . $self . ($mode === 'sim' ? '?mode=sim' : ''));
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
  <title>Calendar — Ladder transactions</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css?v=24">
  <link rel="stylesheet" href="assets/ladder.css?v=14">
  <link rel="stylesheet" href="assets/calendar.css?v=1">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js" defer></script>
  <script src="assets/calendar.js?v=1" defer></script>
</head>
<body class="lad-body cal-body">
  <div class="bg-grid" aria-hidden="true"></div>

  <main class="wrap">
    <header class="brand">
      <div class="brand-mark">
        <span class="brand-dot" aria-hidden="true"></span>
        <span class="brand-label">LADDER CALENDAR</span>
        <span class="lad-badge <?= $mode === 'sim' ? 'lad-badge-sim' : 'lad-badge-live' ?>">
          <?= $mode === 'sim' ? 'SIMULATION' : 'LIVE' ?>
        </span>
      </div>
      <h1>Buy · Hold · Sell timeline</h1>
      <p>
        Each bar runs from <strong>buy time</strong> to <strong>sell time</strong> (or now if still open).
        Click an event for full details.
      </p>
      <nav class="lad-nav">
        <a href="index.php">← Ladder (home)</a>
        <a href="watch.php">Watch dashboard</a>
        <a href="graph.php">Coin graph</a>
        <a href="cron-log.php">Cron log</a>
        <?php if ($mode === 'sim'): ?>
          <a href="calendar.php">Live calendar</a>
        <?php else: ?>
          <a href="calendar.php?mode=sim">Sim calendar</a>
        <?php endif; ?>
        <?php if ($authed): ?>
          <a class="lad-nav-out" href="<?= htmlspecialchars($self, ENT_QUOTES) ?>?logout=1">Sign out</a>
        <?php endif; ?>
      </nav>
    </header>

<?php if (!$authed): ?>

    <section class="lad-login" aria-label="Sign in">
      <h2>Password required</h2>
      <?php if (!ladderPasswordConfigured()): ?>
        <p class="lad-alert lad-alert-warn">
          Add <code>LADDER_PASSWORD=your-secret</code> to your <code>.env</code> file.
        </p>
      <?php endif; ?>
      <?php if ($loginError !== null): ?>
        <p class="lad-alert lad-alert-bad"><?= htmlspecialchars($loginError, ENT_QUOTES) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= htmlspecialchars($self, ENT_QUOTES) ?>" class="lad-login-form">
        <label for="ladder_password">Password</label>
        <input id="ladder_password" name="ladder_password" type="password" autocomplete="current-password" required autofocus>
        <button type="submit">Sign in</button>
      </form>
    </section>

<?php else: ?>

    <section class="cal-toolbar panel" aria-label="Filters">
      <label class="lad-field lad-field-sm">
        <span>Symbol</span>
        <select id="cal-symbol">
          <option value="">All</option>
        </select>
      </label>
      <label class="lad-field lad-field-sm">
        <span>Show</span>
        <select id="cal-status">
          <option value="">All</option>
          <option value="OPEN">Open / holding</option>
          <option value="SOLD">Sold only</option>
        </select>
      </label>
      <button type="button" id="cal-reload" class="ghost-btn">Reload</button>
      <ul class="cal-legend" aria-label="Legend">
        <li><span class="cal-swatch cal-swatch-sold"></span> Sold</li>
        <li><span class="cal-swatch cal-swatch-hold"></span> Holding</li>
        <li><span class="cal-swatch cal-swatch-left"></span> Leftover</li>
      </ul>
    </section>

    <p id="cal-status" class="lad-status" role="status" aria-live="polite"></p>

    <section class="cal-panel panel" aria-label="Calendar">
      <div id="cal-root"></div>
    </section>

    <aside id="cal-detail" class="cal-detail panel" hidden aria-label="Entry details">
      <button type="button" id="cal-detail-close" class="cal-detail-close" aria-label="Close">✕</button>
      <h2 id="cal-detail-title">Entry</h2>
      <dl class="cal-detail-grid" id="cal-detail-body"></dl>
    </aside>

    <script>
      window.CALENDAR_MODE = <?= json_encode($mode) ?>;
      window.CALENDAR_AUTHED = true;
    </script>

<?php endif; ?>
  </main>
</body>
</html>
