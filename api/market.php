<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const BINANCE_BASE = 'https://api.binance.com';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = '{"ok":false,"error":"JSON encode failed"}';
    }
    echo $json;
    exit;
}

function sanitizeSymbol(string $raw): string
{
    $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    if ($symbol === '' || strlen($symbol) > 20) {
        respond(400, ['ok' => false, 'error' => 'Enter a valid symbol, e.g. BTCUSDT.']);
    }
    return $symbol;
}

/**
 * @return list<string>
 */
function sanitizeSymbols(string $raw): array
{
    $parts = preg_split('/[\s,]+/', $raw) ?: [];
    $symbols = [];

    foreach ($parts as $part) {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '');
        if ($clean === '' || strlen($clean) > 20) {
            continue;
        }
        $symbols[$clean] = $clean;
    }

    $list = array_values($symbols);
    if ($list === []) {
        respond(400, ['ok' => false, 'error' => 'Add at least one valid symbol.']);
    }
    if (count($list) > 50) {
        respond(400, ['ok' => false, 'error' => 'Max 50 symbols at a time.']);
    }

    return $list;
}

function binanceGet(string $path, array $query = []): array
{
    $url = BINANCE_BASE . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: BinanceMarketApp/1.0',
        ],
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        respond(502, [
            'ok' => false,
            'error' => 'Could not reach Binance API.',
            'detail' => $error !== '' ? $error : 'Network error',
        ]);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        respond(502, ['ok' => false, 'error' => 'Invalid response from Binance.']);
    }

    if ($httpCode >= 400) {
        $msg = $decoded['msg'] ?? 'Binance request failed.';
        respond($httpCode >= 500 ? 502 : 400, [
            'ok' => false,
            'error' => $msg,
            'code' => $decoded['code'] ?? null,
        ]);
    }

    return $decoded;
}

$action = $_GET['action'] ?? 'prices';
$action = is_string($action) ? strtolower($action) : 'prices';

if ($action === 'symbols') {
    $info = binanceGet('/api/v3/exchangeInfo');
    $symbols = [];

    foreach ($info['symbols'] ?? [] as $item) {
        if (($item['status'] ?? '') !== 'TRADING') {
            continue;
        }
        if (($item['quoteAsset'] ?? '') !== 'USDT') {
            continue;
        }
        $symbols[] = $item['symbol'];
    }

    sort($symbols);
    respond(200, ['ok' => true, 'symbols' => $symbols]);
}

if ($action === 'price') {
    $symbol = sanitizeSymbol((string) ($_GET['symbol'] ?? ''));
    $data = binanceGet('/api/v3/ticker/price', ['symbol' => $symbol]);
    respond(200, [
        'ok' => true,
        'symbol' => $data['symbol'] ?? $symbol,
        'price' => (float) ($data['price'] ?? 0),
    ]);
}

if ($action === 'prices') {
    $raw = (string) ($_GET['symbols'] ?? $_GET['symbol'] ?? '');
    $symbols = sanitizeSymbols($raw);

    if (count($symbols) === 1) {
        $data = binanceGet('/api/v3/ticker/price', ['symbol' => $symbols[0]]);
        $rows = [$data];
    } else {
        $rows = binanceGet('/api/v3/ticker/price', [
            'symbols' => json_encode(array_values($symbols), JSON_UNESCAPED_SLASHES),
        ]);
        if (!is_array($rows) || (function_exists('array_is_list') ? !array_is_list($rows) : !isset($rows[0]))) {
            $rows = [$rows];
        }
    }

    $prices = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['symbol'], $row['price'])) {
            continue;
        }
        $prices[$row['symbol']] = (float) $row['price'];
    }

    respond(200, [
        'ok' => true,
        'prices' => $prices,
        'ts' => (int) round(microtime(true) * 1000),
    ]);
}

if ($action === 'hour' || $action === 'move') {
    $symbol = sanitizeSymbol((string) ($_GET['symbol'] ?? ''));
    $allowedMinutes = [15, 30, 60, 120, 240, 360, 720, 1440];
    $minutes = (int) ($_GET['minutes'] ?? 60);
    if (!in_array($minutes, $allowedMinutes, true)) {
        $minutes = 60;
    }

    // Pick candle size so we stay well under Binance limit (1000)
    if ($minutes <= 60) {
        $interval = '1m';
        $limit = $minutes;
        $barMinutes = 1;
    } elseif ($minutes <= 360) {
        $interval = '5m';
        $barMinutes = 5;
        $limit = (int) round($minutes / $barMinutes);
    } else {
        $interval = '15m';
        $barMinutes = 15;
        $limit = (int) round($minutes / $barMinutes);
    }

    $rows = binanceGet('/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => $interval,
        'limit' => $limit,
    ]);

    if (!is_array($rows) || $rows === []) {
        respond(502, ['ok' => false, 'error' => 'No candle data for this window.']);
    }

    $open = (float) ($rows[0][1] ?? 0);
    $close = (float) ($rows[count($rows) - 1][4] ?? 0);
    $high = 0.0;
    $low = PHP_FLOAT_MAX;
    $upBars = 0;
    $downBars = 0;

    foreach ($rows as $i => $candle) {
        if (!is_array($candle) || count($candle) < 5) {
            continue;
        }
        $h = (float) $candle[2];
        $l = (float) $candle[3];
        $c = (float) $candle[4];
        if ($h > $high) {
            $high = $h;
        }
        if ($l < $low) {
            $low = $l;
        }
        if ($i > 0) {
            $prevClose = (float) ($rows[$i - 1][4] ?? $c);
            if ($c > $prevClose) {
                $upBars++;
            } elseif ($c < $prevClose) {
                $downBars++;
            }
        }
    }

    if (!($open > 0) || !($close > 0) || !($high > 0) || !($low > 0) || $low === PHP_FLOAT_MAX) {
        respond(502, ['ok' => false, 'error' => 'Invalid candle data.']);
    }

    $changePct = (($close - $open) / $open) * 100;
    $rangePct = (($high - $low) / $low) * 100;
    $dropFromHighPct = (($high - $close) / $high) * 100;
    $riseFromLowPct = (($close - $low) / $low) * 100;

    $label = $minutes < 60
        ? ($minutes . 'm')
        : ($minutes === 60 ? '1h' : ((string) ($minutes / 60) . 'h'));

    respond(200, [
        'ok' => true,
        'symbol' => $symbol,
        'minutes' => $minutes,
        'label' => $label,
        'interval' => $interval,
        'open' => $open,
        'high' => $high,
        'low' => $low,
        'close' => $close,
        'changePct' => $changePct,
        'rangePct' => $rangePct,
        'dropFromHighPct' => $dropFromHighPct,
        'riseFromLowPct' => $riseFromLowPct,
        'upMinutes' => $upBars,
        'downMinutes' => $downBars,
        'upBars' => $upBars,
        'downBars' => $downBars,
        'candles' => count($rows),
        'ts' => (int) round(microtime(true) * 1000),
    ]);
}

if ($action === 'history' || $action === 'daily') {
    $symbol = sanitizeSymbol((string) ($_GET['symbol'] ?? ''));
    $days = (int) ($_GET['days'] ?? 30);
    if ($days < 7) {
        $days = 7;
    }
    if ($days > 90) {
        $days = 90;
    }

    $rows = binanceGet('/api/v3/klines', [
        'symbol' => $symbol,
        'interval' => '1d',
        'limit' => $days,
    ]);

    if (!is_array($rows) || $rows === []) {
        respond(502, ['ok' => false, 'error' => 'No daily candle data for ' . $symbol . '.']);
    }

    $candles = [];
    foreach ($rows as $candle) {
        if (!is_array($candle) || count($candle) < 6) {
            continue;
        }
        $openTime = (int) ($candle[0] ?? 0);
        $open = (float) ($candle[1] ?? 0);
        $high = (float) ($candle[2] ?? 0);
        $low = (float) ($candle[3] ?? 0);
        $close = (float) ($candle[4] ?? 0);
        $volume = (float) ($candle[5] ?? 0);
        if ($open <= 0 || $close <= 0) {
            continue;
        }
        $candles[] = [
            'time' => (int) floor($openTime / 1000),
            'date' => gmdate('Y-m-d', (int) floor($openTime / 1000)),
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => $volume,
            'changePct' => (($close - $open) / $open) * 100,
        ];
    }

    if ($candles === []) {
        respond(502, ['ok' => false, 'error' => 'Invalid candle data from Binance.']);
    }

    $first = $candles[0];
    $last = $candles[count($candles) - 1];
    $periodHigh = max(array_column($candles, 'high'));
    $periodLow = min(array_column($candles, 'low'));
    $periodChangePct = $first['open'] > 0
        ? (($last['close'] - $first['open']) / $first['open']) * 100
        : 0.0;

    respond(200, [
        'ok' => true,
        'symbol' => $symbol,
        'interval' => '1d',
        'days' => count($candles),
        'from' => $first['date'],
        'to' => $last['date'],
        'open' => $first['open'],
        'close' => $last['close'],
        'high' => $periodHigh,
        'low' => $periodLow,
        'changePct' => $periodChangePct,
        'candles' => $candles,
        'ts' => (int) round(microtime(true) * 1000),
    ]);
}

respond(400, ['ok' => false, 'error' => 'Unknown action.']);
