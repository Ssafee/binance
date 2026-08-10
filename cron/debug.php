<?php
declare(strict_types=1);

/**
 * SAFE, READ-ONLY diagnostic for the auto-trade cron.
 * Does NOT place any buy/sell order and does NOT run the 55s loop —
 * it only checks config, Binance connectivity, and simulates the
 * buy/sell decision for each watch item so you can verify the cron
 * would behave correctly, without risking a real trade.
 *
 * Browser: /cron/debug.php?key=YOUR_CRON_SECRET
 * CLI:     php cron/debug.php
 */

require_once dirname(__DIR__) . '/api/lib/bootstrap.php';
require_once dirname(__DIR__) . '/api/lib/env.php';
require_once dirname(__DIR__) . '/api/lib/binance.php';
require_once dirname(__DIR__) . '/api/lib/history_store.php';
require_once dirname(__DIR__) . '/api/lib/watch_store.php';
require_once dirname(__DIR__) . '/api/lib/auto_engine.php';

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

$out = [
    'ok' => true,
    'mode' => 'DRY RUN — read-only, no orders placed',
    'php' => PHP_VERSION,
    'now' => date('c'),
];

$out['config'] = [
    'cronSecretSet' => $secret !== '',
    'tickSeconds' => (int) env('CRON_TICK_SECONDS', '5'),
    'loopSeconds' => (int) env('CRON_LOOP_SECONDS', '55'),
    'timezone' => appTimezone(),
    'autoBuyEnabled' => cronAutoBuyEnabled(),
    'mode2' => cronAutoBuyEnabled() ? 'buy dips + sell at profit' : 'SELL-ONLY (auto-buy disabled)',
];

// What the real cron (crontab) has actually been doing, if it's running.
$state = loadWatchState();
$out['lastRealRun'] = [
    'lastRunAt' => $state['lastRunAt'],
    'lastRunAtLocal' => $state['lastRunAt']
        ? tradeLocalDate((int) $state['lastRunAt']) . ' ' . date('H:i:s', (int) ($state['lastRunAt'] / 1000))
        : null,
    'lastRunOk' => $state['lastRunOk'],
    'serverAuto' => $state['serverAuto'],
    'autoEnabled' => $state['autoEnabled'],
    'recentLog' => array_slice($state['lastRunLog'] ?? [], 0, 10),
];

$cronLogFile = cronLogPath();
$out['cronLogFile'] = [
    'path' => 'data/cron_log.json',
    'exists' => is_file($cronLogFile),
    'recent' => [],
];
if (is_file($cronLogFile)) {
    $raw = file_get_contents($cronLogFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $out['cronLogFile']['recent'] = array_slice($decoded, 0, 5);
    }
}

// Binance connectivity check — read-only account balance call.
[$apiKey, $apiSecret] = binanceCredentials();
$out['binance'] = ['configured' => $apiKey !== '' && $apiSecret !== ''];
if ($out['binance']['configured']) {
    try {
        $out['binance']['usdtFree'] = getFreeBalance('USDT');
        $out['binance']['connected'] = true;
    } catch (Throwable $e) {
        $out['binance']['connected'] = false;
        $out['binance']['error'] = $e->getMessage();
    }
} else {
    $out['binance']['connected'] = false;
}

// Dry-run the decision logic for every watch item, without trading.
$rules = $state['rules'];
$sell = computeSellRules($rules);
$buyDrop = (float) $rules['buyDrop'];
$sellTrigger = (float) $sell['sellTriggerPct'];
$autoBuyEnabled = cronAutoBuyEnabled();

$items = [];
foreach ($state['items'] ?? [] as $item) {
    $symbol = (string) $item['symbol'];
    $base = (float) $item['basePrice'];
    $holding = !empty($item['holding']);

    $row = [
        'symbol' => $symbol,
        'holding' => $holding,
        'basePrice' => $base,
        'price' => null,
        'changePct' => null,
        'wouldAction' => 'WAIT',
        'note' => null,
    ];

    $px = fetchTickerPrice($symbol); // public price lookup, no order
    if (!$px['ok']) {
        $row['note'] = 'Price fetch failed: ' . ($px['error'] ?? 'unknown error');
        $items[] = $row;
        continue;
    }

    $price = (float) $px['price'];
    $changePct = $base > 0 ? (($price - $base) / $base) * 100 : 0.0;
    $row['price'] = $price;
    $row['changePct'] = round($changePct, 4);

    if (!$holding) {
        if (!$autoBuyEnabled) {
            $row['wouldAction'] = 'WAIT';
            $row['note'] = 'Auto-buy disabled (CRON_AUTO_BUY=0) — sell-only mode';
        } else {
            $buyHit = $buyDrop === 0.0 ? $changePct < 0 : $changePct <= -$buyDrop;
            $row['wouldAction'] = $buyHit ? 'WOULD BUY' : 'WAIT';
            $row['note'] = sprintf('Buys at <= -%.3f%% (currently %+.3f%%)', $buyDrop, $changePct);
        }
    } else {
        $sellHit = $changePct >= $sellTrigger;
        $row['wouldAction'] = $sellHit ? 'WOULD SELL' : 'HOLD';
        $row['note'] = sprintf('Sells at >= +%.3f%% net (currently %+.3f%%)', $sellTrigger, $changePct);
    }

    $items[] = $row;
}

$out['watchItemCount'] = count($items);
$out['watchItems'] = $items;

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($isCli) {
    echo $json . PHP_EOL;
} else {
    echo $json;
}
