<?php
declare(strict_types=1);

/**
 * cPanel cron (every 1 minute). Inside each run: loop + sleep every 5s for ~55s.
 *
 * Cron:
 *   * * * * * /usr/bin/php /home/USER/public_html/binance/cron/auto_trade.php
 *
 * Prefer CLI (not HTTP) so the 55s loop is not killed by web timeout.
 */

define('AUTO_TRADE_CRON', true);

require_once dirname(__DIR__) . '/api/lib/bootstrap.php';
require_once dirname(__DIR__) . '/api/lib/env.php';
require_once dirname(__DIR__) . '/api/lib/auto_engine.php';
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

try {
    $result = runServerAutoLoop();
    $out = [
        'ok' => !empty($result['ok']),
        'actions' => $result['actions'] ?? 0,
        'ticks' => $result['ticks'] ?? 0,
        'tickSec' => $result['tickSec'] ?? null,
        'loopSec' => $result['loopSec'] ?? null,
        'skipped' => $result['skipped'] ?? false,
        'log' => array_slice($result['log'] ?? [], 0, 20),
        'lastRunAt' => $result['state']['lastRunAt'] ?? null,
        'serverAuto' => !empty($result['state']['serverAuto']),
        'items' => count($result['state']['items'] ?? []),
        'files' => [
            'watch' => 'data/watch_state.json',
            'trades' => 'data/trades.json',
            'cron' => 'data/cron_log.json',
        ],
    ];
    $out['emailSent'] = sendCronEmail($out);
    if ($isCli) {
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($out, JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    $payload = ['ok' => false, 'error' => $e->getMessage()];
    appendCronLog([
        't' => (int) round(microtime(true) * 1000),
        'error' => $e->getMessage(),
    ]);
    $payload['emailSent'] = sendCronEmail($payload);
    if ($isCli) {
        fwrite(STDERR, json_encode($payload) . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode($payload);
}
