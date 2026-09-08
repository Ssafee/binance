<?php
declare(strict_types=1);

/**
 * Ladder cron — buys today's lot once per day and sells matured entries.
 * Runs a short inner loop so a target that is hit mid-minute is caught fast.
 *
 * cPanel cron (every minute, CLI preferred):
 *   * * * * * /usr/bin/php /home/USER/public_html/binance/cron/ladder_cron.php
 *
 * Browser/manual:
 *   /cron/ladder_cron.php?key=CRON_SECRET
 *   /cron/ladder_cron.php?key=CRON_SECRET&dry=1   (report only, no orders)
 */

define('AUTO_TRADE_CRON', true);

require_once dirname(__DIR__) . '/api/lib/bootstrap.php';
require_once dirname(__DIR__) . '/api/lib/env.php';
require_once dirname(__DIR__) . '/api/lib/ladder_store.php';
require_once dirname(__DIR__) . '/api/lib/ladder_engine.php';
require_once dirname(__DIR__) . '/api/lib/mailer.php';

$isCli = PHP_SAPI === 'cli';
$secret = trim((string) env('CRON_SECRET', ''));

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden — pass ?key=CRON_SECRET']);
        exit;
    }
}

$argvOpts = $isCli ? array_slice($argv ?? [], 1) : [];
$isDry = in_array('--dry', $argvOpts, true) || !empty($_GET['dry']) || !empty($_POST['dry']);
$mode = ladderNormalizeMode((string) ($_GET['mode'] ?? $_POST['mode'] ?? (in_array('--sim', $argvOpts, true) ? 'sim' : 'live')));

function ladderLockPath(): string
{
    return dataDir() . DIRECTORY_SEPARATOR . 'ladder.lock';
}

function ladderAcquireLock(int $ttlSeconds = 70): bool
{
    $path = ladderLockPath();
    if (is_file($path) && (time() - (int) filemtime($path)) < $ttlSeconds) {
        return false;
    }
    return file_put_contents($path, (string) getmypid(), LOCK_EX) !== false;
}

function ladderReleaseLock(): void
{
    $path = ladderLockPath();
    if (is_file($path)) {
        @unlink($path);
    }
}

function ladderEmit(array $payload, bool $isCli): void
{
    $flags = JSON_UNESCAPED_SLASHES | ($isCli ? JSON_PRETTY_PRINT : 0);
    echo json_encode($payload, $flags) . ($isCli ? PHP_EOL : '');
}

/* ---------------- dry run: report only, never trades ---------------- */

if ($isDry) {
    $state = ladderLoadState($mode);
    $configs = ladderConfigs($state);
    $prices = [];
    foreach (ladderSymbolsInState($state) as $symbol) {
        $priceRes = ladderPrice($symbol);
        if (!empty($priceRes['ok'])) {
            $prices[$symbol] = (float) $priceRes['price'];
        }
    }

    $matured = [];
    foreach (ladderOpenEntries($state) as $entry) {
        $price = (float) ($prices[(string) $entry['symbol']] ?? 0);
        if ($price > 0 && (float) $entry['targetPrice'] > 0 && $price >= (float) $entry['targetPrice']) {
            $matured[] = [
                'id' => $entry['id'],
                'symbol' => $entry['symbol'],
                'buyDate' => $entry['buyDate'],
                'costUsdt' => $entry['costUsdt'],
                'targetPrice' => $entry['targetPrice'],
            ];
        }
    }

    $today = binanceTodayDate();
    $due = [];
    foreach ($configs as $cfg) {
        if ((string) $cfg['lastBuyDate'] !== $today) {
            $due[] = $cfg['symbol'];
        }
    }

    ladderEmit([
        'ok' => true,
        'mode' => $mode . ' (DRY RUN — no orders placed)',
        'configs' => $configs,
        'prices' => $prices,
        'today' => $today,
        'dailyBuyDue' => $due,
        'dayBoundary' => 'UTC (Binance day start = 00:00 UTC)',
        'openEntries' => count(ladderOpenEntries($state)),
        'maturedNow' => count($matured),
        'matured' => $matured,
        'dashboard' => ladderDashboard($state, $prices),
    ], $isCli);
    exit;
}

/* ---------------- real pass ---------------- */

if (!ladderAcquireLock(70)) {
    ladderEmit(['ok' => true, 'skipped' => true, 'reason' => 'Previous ladder run still active'], $isCli);
    exit;
}

$tickSec = max(3, (int) env('CRON_TICK_SECONDS', '5'));
$loopSec = max($tickSec, (int) env('CRON_LOOP_SECONDS', '55'));
$started = time();
$ticks = 0;
$actions = 0;
$log = [];
$error = null;

@set_time_limit(90);
@ignore_user_abort(true);

try {
    while (true) {
        $ticks++;
        $pass = ladderRunPass($mode);
        $actions += (int) ($pass['actions'] ?? 0);

        foreach ($pass['log'] ?? [] as $row) {
            // Only keep noteworthy lines after the first tick to limit noise.
            $msg = (string) ($row['msg'] ?? '');
            if ($ticks === 1 || stripos($msg, 'skip') === false) {
                $log[] = $row;
            }
        }

        if (empty($pass['ok']) && !empty($pass['error'])) {
            $error = (string) $pass['error'];
        }

        $elapsed = time() - $started;
        if ($elapsed + $tickSec >= $loopSec) {
            break;
        }
        sleep($tickSec);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    ladderReleaseLock();
}

$state = ladderLoadState($mode);
$configs = ladderConfigs($state);
$prices = [];
foreach (ladderSymbolsInState($state) as $symbol) {
    $priceRes = ladderPrice($symbol);
    if (!empty($priceRes['ok'])) {
        $prices[$symbol] = (float) $priceRes['price'];
    }
}
$dashboard = ladderDashboard($state, $prices);
$serverAuto = false;
foreach ($configs as $cfg) {
    if (!empty($cfg['autoBuyEnabled']) || !empty($cfg['autoSellEnabled'])) {
        $serverAuto = true;
        break;
    }
}

$out = [
    'ok' => $error === null,
    'mode' => $mode,
    'configs' => count($configs),
    'symbols' => array_values(array_map(static fn ($c) => $c['symbol'], $configs)),
    'ticks' => $ticks,
    'actions' => $actions,
    'tickSec' => $tickSec,
    'loopSec' => $loopSec,
    'prices' => $prices,
    'error' => $error,
    'openEntries' => $dashboard['openCount'],
    'soldEntries' => $dashboard['soldCount'],
    'investedOpen' => $dashboard['investedOpen'],
    'realizedProfit' => $dashboard['realizedProfit'],
    'log' => array_slice($log, 0, 20),
];

// Reuse the existing cron mailer (it decides whether to actually send,
// based on CRON_EMAIL_ENABLED / CRON_EMAIL_MODE).
$out['emailSent'] = sendCronEmail([
    'ok' => $out['ok'],
    'actions' => $actions,
    'ticks' => $ticks,
    'skipped' => false,
    'serverAuto' => $serverAuto,
    'items' => $dashboard['openCount'],
    'error' => $error,
    'log' => $log,
]);

if ($error !== null && $isCli) {
    ladderEmit($out, $isCli);
    exit(1);
}

if ($error !== null) {
    http_response_code(500);
}

ladderEmit($out, $isCli);
