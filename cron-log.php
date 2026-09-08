<?php
declare(strict_types=1);

/**
 * Last 30 ladder cron runs (from data/ladder_cron_log.json).
 */

require_once __DIR__ . '/api/lib/bootstrap.php';
require_once __DIR__ . '/api/lib/env.php';
require_once __DIR__ . '/api/lib/auth.php';
require_once __DIR__ . '/api/lib/cron_log_store.php';

$self = 'cron-log.php';
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
$runs = $authed ? ladderCronLogLoad() : [];
$heartbeat = '';
$heartbeatAge = null;
if ($authed) {
    $hbPath = ladderCronHeartbeatPath();
    if (is_readable($hbPath)) {
        $heartbeat = trim((string) file_get_contents($hbPath));
        $mtime = @filemtime($hbPath);
        if ($mtime) {
            $heartbeatAge = max(0, time() - $mtime);
        }
    }
}
$tz = (string) env('APP_TIMEZONE', 'UTC');

function cronLogLocalTime(int $ms, string $tz): string
{
    try {
        $dt = new DateTimeImmutable('@' . (int) floor($ms / 1000));
        $dt = $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format('Y-m-d H:i:s T');
    } catch (Throwable $e) {
        return gmdate('Y-m-d H:i:s', (int) floor($ms / 1000)) . ' UTC';
    }
}

function cronLogStatus(array $run): string
{
    if (!empty($run['dry'])) {
        return 'dry';
    }
    if (!empty($run['skipped'])) {
        return 'skipped';
    }
    if (empty($run['ok'])) {
        return 'error';
    }
    if ((int) ($run['actions'] ?? 0) > 0) {
        return 'action';
    }
    return 'ok';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cron log — Ladder</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css?v=24">
  <link rel="stylesheet" href="assets/ladder.css?v=12">
</head>
<body class="lad-body">
  <div class="bg-grid" aria-hidden="true"></div>

  <main class="wrap">
    <header class="brand">
      <div class="brand-mark">
        <span class="brand-dot" aria-hidden="true"></span>
        <span class="brand-label">LADDER CRON LOG</span>
      </div>
      <h1>Last 30 cron runs</h1>
      <p>
        Each time <code>ladder_cron.php</code> runs, a row is saved to
        <code>data/ladder_cron_log.json</code> (keeps the newest 30).
      </p>
      <nav class="lad-nav">
        <a href="ladder.php">← Live ladder</a>
        <a href="simulate.php">Simulator</a>
        <a href="index.php">Main dashboard</a>
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
          Add <code>LADDER_PASSWORD</code> to <code>.env</code>, then reload.
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

    <section class="panel lad-panel" aria-label="Cron history">
      <div class="panel-head">
        <div>
          <p class="eyebrow">History</p>
          <h2><?= count($runs) ?> saved run(s)</h2>
        </div>
        <button type="button" class="ghost-btn" onclick="location.reload()">Refresh</button>
      </div>

      <div class="lad-sim-box" style="margin-top:0">
        <p class="lad-label">Last heartbeat</p>
        <?php if ($heartbeat === ''): ?>
          <p class="lad-alert lad-alert-bad">
            No heartbeat yet — cron may not be reaching the script, or
            <code>api/lib/cron_log_store.php</code> / <code>cron/ladder_cron.php</code> are not uploaded.
          </p>
        <?php else: ?>
          <p class="lad-value" style="font-size:1rem"><?= htmlspecialchars($heartbeat, ENT_QUOTES) ?></p>
          <p class="lad-muted">
            <?php if ($heartbeatAge !== null): ?>
              File age: <?= (int) $heartbeatAge ?>s ago
              <?= $heartbeatAge > 120 ? '(⚠️ older than 2 minutes — cron may be stuck/failing)' : '(ok)' ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>

      <?php if ($runs === []): ?>
        <p class="lad-empty">
          No cron runs logged yet. Wait for the next scheduled job, or run manually:<br>
          <code>/usr/bin/php …/cron/ladder_cron.php</code>
        </p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="lad-table lad-cron-table">
            <thead>
              <tr>
                <th>When</th>
                <th>Status</th>
                <th>Actions</th>
                <th>Ticks</th>
                <th>Configs</th>
                <th>Detail</th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($runs as $run):
    $status = cronLogStatus($run);
    $when = isset($run['at']) ? cronLogLocalTime((int) $run['at'], $tz) : '—';
    $symbols = is_array($run['symbols'] ?? null) ? implode(', ', $run['symbols']) : '';
    $detailParts = [];
    if (!empty($run['reason'])) {
        $detailParts[] = (string) $run['reason'];
    }
    if (!empty($run['error'])) {
        $detailParts[] = (string) $run['error'];
    }
    if ($symbols !== '') {
        $detailParts[] = $symbols;
    }
    $logLines = is_array($run['log'] ?? null) ? $run['log'] : [];
?>
              <tr class="lad-cron-<?= htmlspecialchars($status, ENT_QUOTES) ?>">
                <td>
                  <strong><?= htmlspecialchars($when, ENT_QUOTES) ?></strong>
                  <div class="lad-muted"><?= htmlspecialchars((string) ($run['atIso'] ?? ''), ENT_QUOTES) ?></div>
                </td>
                <td><span class="lad-cron-pill"><?= htmlspecialchars(strtoupper($status), ENT_QUOTES) ?></span></td>
                <td><?= (int) ($run['actions'] ?? 0) ?></td>
                <td><?= (int) ($run['ticks'] ?? 0) ?></td>
                <td><?= (int) ($run['configs'] ?? 0) ?></td>
                <td>
                  <?php if ($detailParts !== []): ?>
                    <div><?= htmlspecialchars(implode(' · ', $detailParts), ENT_QUOTES) ?></div>
                  <?php endif; ?>
                  <?php if ($logLines !== []): ?>
                    <ul class="lad-cron-msgs">
                      <?php foreach (array_slice($logLines, 0, 6) as $msg): ?>
                        <li><?= htmlspecialchars((string) $msg, ENT_QUOTES) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php elseif ($detailParts === []): ?>
                    <span class="lad-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

<?php endif; ?>
  </main>
</body>
</html>
