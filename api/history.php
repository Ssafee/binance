<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/binance.php';
require_once __DIR__ . '/lib/history_store.php';

$input = $_POST;
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $input = array_merge($input, $json);
    }
}

$action = $_GET['action'] ?? $input['action'] ?? 'report';
$action = is_string($action) ? strtolower(trim($action)) : 'report';

/**
 * @return list<array<string, mixed>>
 */
function filterTrades(array $rows, ?string $symbol, ?string $from, ?string $to, ?string $side): array
{
    $symbol = $symbol !== null && $symbol !== '' ? strtoupper($symbol) : null;
    $side = $side !== null && $side !== '' ? strtoupper($side) : null;
    $fromTs = $from ? strtotime($from . ' 00:00:00 UTC') : null;
    $toTs = $to ? strtotime($to . ' 23:59:59 UTC') : null;

    $out = [];
    foreach ($rows as $row) {
        if ($symbol !== null && ($row['symbol'] ?? '') !== $symbol) {
            continue;
        }
        if ($side !== null && strtoupper((string) ($row['side'] ?? '')) !== $side) {
            continue;
        }
        $time = (int) ($row['time'] ?? 0);
        $sec = (int) floor($time / 1000);
        if ($fromTs !== null && $fromTs !== false && $sec < $fromTs) {
            continue;
        }
        if ($toTs !== null && $toTs !== false && $sec > $toTs) {
            continue;
        }
        $out[] = $row;
    }

    usort($out, static fn($a, $b) => ((int) ($b['time'] ?? 0)) <=> ((int) ($a['time'] ?? 0)));
    return $out;
}

/**
 * Pull recent fills from Binance for given symbols and merge into local store.
 *
 * @param list<string> $symbols
 * @return array{imported:int,symbols:list<string>}
 */
function syncBinanceTrades(array $symbols, int $limit = 100): array
{
    requireApiKeys();
    $imported = 0;
    $synced = [];

    foreach ($symbols as $symbol) {
        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol) ?? '');
        if ($symbol === '') {
            continue;
        }

        $result = binanceRequest('GET', '/api/v3/myTrades', [
            'symbol' => $symbol,
            'limit' => max(1, min(1000, $limit)),
        ], true);

        if ($result['httpCode'] >= 400) {
            continue;
        }

        $synced[] = $symbol;
        $trades = $result['body'];
        if (!is_array($trades)) {
            continue;
        }

        foreach ($trades as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $isBuyer = (bool) ($trade['isBuyer'] ?? false);
            $side = $isBuyer ? 'BUY' : 'SELL';
            $qty = (string) ($trade['qty'] ?? '0');
            $quote = (string) ($trade['quoteQty'] ?? '0');
            $beforeCount = count(loadTradeHistory());
            appendTradeRecord([
                'id' => 'trade-' . ($trade['id'] ?? uniqid('', true)),
                'orderId' => $trade['orderId'] ?? null,
                'tradeId' => $trade['id'] ?? null,
                'clientOrderId' => null,
                'symbol' => $symbol,
                'side' => $side,
                'status' => 'FILLED',
                'type' => 'MARKET',
                'executedQty' => $qty,
                'cummulativeQuoteQty' => $quote,
                'avgPrice' => (string) ($trade['price'] ?? '0'),
                'time' => (int) ($trade['time'] ?? 0),
                'date' => tradeLocalDate((int) ($trade['time'] ?? 0)),
                'fills' => [[
                    'price' => (string) ($trade['price'] ?? ''),
                    'qty' => $qty,
                    'commission' => (string) ($trade['commission'] ?? ''),
                    'commissionAsset' => (string) ($trade['commissionAsset'] ?? ''),
                ]],
                'source' => 'binance',
                'savedAt' => round(microtime(true) * 1000),
            ]);
            if (count(loadTradeHistory()) > $beforeCount) {
                $imported++;
            }
        }
    }

    return [
        'imported' => $imported,
        'symbols' => $synced,
    ];
}

if ($action === 'today') {
    $rawSymbols = (string) ($input['symbols'] ?? $_GET['symbols'] ?? '');
    $parts = preg_split('/[\s,]+/', $rawSymbols) ?: [];
    $symbols = [];
    foreach ($parts as $part) {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '');
        if ($clean !== '') {
            $symbols[$clean] = $clean;
        }
    }
    // Also include symbols from today's trades / open lots
    foreach (loadTradeHistory() as $row) {
        $time = (int) ($row['time'] ?? 0);
        if (tradeLocalDate($time) !== todayDate()) {
            continue;
        }
        $sym = strtoupper((string) ($row['symbol'] ?? ''));
        if ($sym !== '') {
            $symbols[$sym] = $sym;
        }
    }

    $list = array_values($symbols);
    $prices = [];
    if ($list !== []) {
        if (count($list) === 1) {
            $data = binanceOrFail(binanceRequest('GET', '/api/v3/ticker/price', ['symbol' => $list[0]]));
            $prices[$data['symbol']] = (float) $data['price'];
        } else {
            $rows = binanceOrFail(binanceRequest('GET', '/api/v3/ticker/price', [
                'symbols' => json_encode(array_values($list), JSON_UNESCAPED_SLASHES),
            ]));
            if (!is_array($rows) || (function_exists('array_is_list') ? !array_is_list($rows) : !isset($rows[0]))) {
                $rows = [$rows];
            }
            foreach ($rows as $row) {
                if (isset($row['symbol'], $row['price'])) {
                    $prices[$row['symbol']] = (float) $row['price'];
                }
            }
        }
    }

    // Merge any live prices passed from client (optional)
    $clientPrices = $input['prices'] ?? null;
    if (is_array($clientPrices)) {
        foreach ($clientPrices as $sym => $price) {
            $sym = strtoupper((string) $sym);
            $price = (float) $price;
            if ($sym !== '' && $price > 0) {
                $prices[$sym] = $price;
            }
        }
    }

    $pnl = buildTodayPnL($prices);
    respond(200, [
        'ok' => true,
        'pnl' => $pnl,
    ]);
}

if ($action === 'sync') {
    $symbolsRaw = (string) ($input['symbols'] ?? $_GET['symbols'] ?? '');
    $symbols = array_values(array_filter(array_map(
        static fn($s) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? ''),
        preg_split('/[\s,]+/', $symbolsRaw) ?: []
    )));

    if ($symbols === []) {
        // sync symbols already in local history + common fallback
        $existing = loadTradeHistory();
        foreach ($existing as $row) {
            if (!empty($row['symbol'])) {
                $symbols[$row['symbol']] = $row['symbol'];
            }
        }
        $symbols = array_values($symbols);
    }

    if ($symbols === []) {
        respond(400, [
            'ok' => false,
            'error' => 'Provide symbols to sync, e.g. BTCUSDT,ETHUSDT',
        ]);
    }

    if (count($symbols) > 20) {
        respond(400, ['ok' => false, 'error' => 'Max 20 symbols per sync.']);
    }

    $result = syncBinanceTrades($symbols, (int) ($input['limit'] ?? $_GET['limit'] ?? 100));
    $rows = loadTradeHistory();
    $report = buildTradeReport($rows);

    respond(200, [
        'ok' => true,
        'sync' => $result,
        'report' => $report,
    ]);
}

if ($action === 'report' || $action === 'list') {
    $symbol = isset($_GET['symbol']) ? (string) $_GET['symbol'] : (string) ($input['symbol'] ?? '');
    $from = isset($_GET['from']) ? (string) $_GET['from'] : (string) ($input['from'] ?? '');
    $to = isset($_GET['to']) ? (string) $_GET['to'] : (string) ($input['to'] ?? '');
    $side = isset($_GET['side']) ? (string) $_GET['side'] : (string) ($input['side'] ?? '');

    $rows = filterTrades(
        loadTradeHistory(),
        $symbol !== '' ? $symbol : null,
        $from !== '' ? $from : null,
        $to !== '' ? $to : null,
        $side !== '' ? $side : null
    );

    $report = buildTradeReport($rows);
    respond(200, [
        'ok' => true,
        'filters' => [
            'symbol' => $symbol !== '' ? strtoupper($symbol) : null,
            'from' => $from !== '' ? $from : null,
            'to' => $to !== '' ? $to : null,
            'side' => $side !== '' ? strtoupper($side) : null,
        ],
        'report' => $report,
    ]);
}

respond(400, ['ok' => false, 'error' => 'Unknown action. Use report or sync.']);
