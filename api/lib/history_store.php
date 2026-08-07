<?php
declare(strict_types=1);

function historyFilePath(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'trades.json';
}

/**
 * @return list<array<string, mixed>>
 */
function loadTradeHistory(): array
{
    $path = historyFilePath();
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? array_values($data) : [];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function saveTradeHistory(array $rows): void
{
    $path = historyFilePath();
    file_put_contents(
        $path,
        json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * @param array<string, mixed> $order Binance order response
 * @return array<string, mixed>
 */
function normalizeOrderRecord(string $side, string $symbol, array $order, string $source = 'app'): array
{
    $fills = [];
    foreach ($order['fills'] ?? [] as $fill) {
        if (!is_array($fill)) {
            continue;
        }
        $fills[] = [
            'price' => (string) ($fill['price'] ?? ''),
            'qty' => (string) ($fill['qty'] ?? ''),
            'commission' => (string) ($fill['commission'] ?? ''),
            'commissionAsset' => (string) ($fill['commissionAsset'] ?? ''),
        ];
    }

    $time = (int) ($order['transactTime'] ?? $order['updateTime'] ?? $order['time'] ?? round(microtime(true) * 1000));

    return [
        'id' => (string) ($order['orderId'] ?? ('local-' . $time . '-' . mt_rand(1000, 9999))),
        'orderId' => $order['orderId'] ?? null,
        'clientOrderId' => $order['clientOrderId'] ?? null,
        'symbol' => strtoupper($symbol),
        'side' => strtoupper($side),
        'status' => $order['status'] ?? 'FILLED',
        'type' => $order['type'] ?? 'MARKET',
        'executedQty' => (string) ($order['executedQty'] ?? $order['qty'] ?? '0'),
        'cummulativeQuoteQty' => (string) ($order['cummulativeQuoteQty'] ?? $order['quoteQty'] ?? '0'),
        'avgPrice' => calcAvgPrice($order),
        'time' => $time,
        'date' => tradeLocalDate($time),
        'fills' => $fills,
        'source' => $source,
        'savedAt' => round(microtime(true) * 1000),
    ];
}

/**
 * @param array<string, mixed> $order
 */
function calcAvgPrice(array $order): string
{
    $qty = (float) ($order['executedQty'] ?? $order['qty'] ?? 0);
    $quote = (float) ($order['cummulativeQuoteQty'] ?? $order['quoteQty'] ?? 0);
    if ($qty > 0 && $quote > 0) {
        return rtrim(rtrim(sprintf('%.8f', $quote / $qty), '0'), '.') ?: '0';
    }
    if (!empty($order['price']) && (float) $order['price'] > 0) {
        return (string) $order['price'];
    }
    return '0';
}

/**
 * @param array<string, mixed> $record
 */
function appendTradeRecord(array $record): array
{
    $rows = loadTradeHistory();
    $orderId = $record['orderId'] ?? null;
    $tradeId = $record['tradeId'] ?? null;
    $symbol = $record['symbol'] ?? '';

    foreach ($rows as $existing) {
        if ($tradeId !== null && ($existing['tradeId'] ?? null) == $tradeId && ($existing['symbol'] ?? '') === $symbol) {
            return $existing;
        }
        if (
            $tradeId === null
            && $orderId !== null
            && ($existing['orderId'] ?? null) == $orderId
            && ($existing['symbol'] ?? '') === $symbol
            && !isset($existing['tradeId'])
        ) {
            return $existing;
        }
    }

    array_unshift($rows, $record);
    if (count($rows) > 2000) {
        $rows = array_slice($rows, 0, 2000);
    }
    saveTradeHistory($rows);
    return $record;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{trades:list<array<string,mixed>>,summary:array<string,mixed>,byDate:array<string,mixed>,bySymbol:array<string,mixed>}
 */
function buildTradeReport(array $rows): array
{
    $byDate = [];
    $bySymbol = [];
    $buyCount = 0;
    $sellCount = 0;
    $buyQuote = 0.0;
    $sellQuote = 0.0;

    foreach ($rows as $row) {
        $date = (string) ($row['date'] ?? gmdate('Y-m-d', (int) floor(((int) ($row['time'] ?? 0)) / 1000)));
        $symbol = (string) ($row['symbol'] ?? 'UNKNOWN');
        $side = strtoupper((string) ($row['side'] ?? ''));
        $quote = (float) ($row['cummulativeQuoteQty'] ?? 0);
        $qty = (float) ($row['executedQty'] ?? 0);

        if (!isset($byDate[$date])) {
            $byDate[$date] = [
                'date' => $date,
                'buys' => 0,
                'sells' => 0,
                'buyQuote' => 0.0,
                'sellQuote' => 0.0,
                'trades' => 0,
            ];
        }
        if (!isset($bySymbol[$symbol])) {
            $bySymbol[$symbol] = [
                'symbol' => $symbol,
                'buys' => 0,
                'sells' => 0,
                'buyQuote' => 0.0,
                'sellQuote' => 0.0,
                'buyQty' => 0.0,
                'sellQty' => 0.0,
                'trades' => 0,
            ];
        }

        $byDate[$date]['trades']++;
        $bySymbol[$symbol]['trades']++;

        if ($side === 'BUY') {
            $buyCount++;
            $buyQuote += $quote;
            $byDate[$date]['buys']++;
            $byDate[$date]['buyQuote'] += $quote;
            $bySymbol[$symbol]['buys']++;
            $bySymbol[$symbol]['buyQuote'] += $quote;
            $bySymbol[$symbol]['buyQty'] += $qty;
        } elseif ($side === 'SELL') {
            $sellCount++;
            $sellQuote += $quote;
            $byDate[$date]['sells']++;
            $byDate[$date]['sellQuote'] += $quote;
            $bySymbol[$symbol]['sells']++;
            $bySymbol[$symbol]['sellQuote'] += $quote;
            $bySymbol[$symbol]['sellQty'] += $qty;
        }
    }

    krsort($byDate);
    uasort($bySymbol, static fn($a, $b) => ($b['trades'] <=> $a['trades']));

    return [
        'trades' => $rows,
        'summary' => [
            'totalTrades' => count($rows),
            'buys' => $buyCount,
            'sells' => $sellCount,
            'buyQuote' => $buyQuote,
            'sellQuote' => $sellQuote,
            'netQuote' => $sellQuote - $buyQuote,
        ],
        'byDate' => array_values($byDate),
        'bySymbol' => array_values($bySymbol),
    ];
}

/** App "today" in local trading timezone (Pakistan by default). */
function appTimezone(): string
{
    return (string) env('APP_TIMEZONE', 'Asia/Karachi');
}

function todayDate(): string
{
    try {
        return (new DateTime('now', new DateTimeZone(appTimezone())))->format('Y-m-d');
    } catch (Throwable $e) {
        return gmdate('Y-m-d');
    }
}

function tradeLocalDate(int $timeMs): string
{
    try {
        $dt = new DateTime('@' . (int) floor($timeMs / 1000));
        $dt->setTimezone(new DateTimeZone(appTimezone()));
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return gmdate('Y-m-d', (int) floor($timeMs / 1000));
    }
}

/**
 * Today's realized + open positions report (FIFO per symbol).
 *
 * @param array<string, float> $livePrices symbol => last price
 * @return array<string, mixed>
 */
function buildTodayPnL(array $livePrices = []): array
{
    $today = todayDate();
    $buys = [];
    $sells = [];

    foreach (loadTradeHistory() as $row) {
        $time = (int) ($row['time'] ?? 0);
        $date = tradeLocalDate($time);
        if ($date !== $today) {
            continue;
        }
        $side = strtoupper((string) ($row['side'] ?? ''));
        $symbol = strtoupper((string) ($row['symbol'] ?? ''));
        if ($symbol === '') {
            continue;
        }
        $entry = [
            'symbol' => $symbol,
            'qty' => (float) ($row['executedQty'] ?? 0),
            'quote' => (float) ($row['cummulativeQuoteQty'] ?? 0),
            'price' => (float) ($row['avgPrice'] ?? 0),
            'time' => $time,
            'orderId' => $row['orderId'] ?? null,
        ];
        if ($side === 'BUY' && $entry['qty'] > 0) {
            $buys[] = $entry;
        } elseif ($side === 'SELL' && $entry['qty'] > 0) {
            $sells[] = $entry;
        }
    }

    usort($buys, static fn($a, $b) => $a['time'] <=> $b['time']);
    usort($sells, static fn($a, $b) => $a['time'] <=> $b['time']);

    // FIFO lots per symbol from buys
    $lots = [];
    foreach ($buys as $buy) {
        $sym = $buy['symbol'];
        if (!isset($lots[$sym])) {
            $lots[$sym] = [];
        }
        $lots[$sym][] = [
            'qtyLeft' => $buy['qty'],
            'costLeft' => $buy['quote'],
            'unitCost' => $buy['qty'] > 0 ? $buy['quote'] / $buy['qty'] : 0,
        ];
    }

    $realized = 0.0;
    $matchedBuyQuote = 0.0;
    $matchedSellQuote = 0.0;
    $roundTrips = 0;
    $bySymbol = [];

    foreach ($sells as $sell) {
        $sym = $sell['symbol'];
        $qtyToMatch = $sell['qty'];
        $sellQuoteLeft = $sell['quote'];
        $sellUnit = $sell['qty'] > 0 ? $sell['quote'] / $sell['qty'] : 0;

        if (!isset($bySymbol[$sym])) {
            $bySymbol[$sym] = [
                'symbol' => $sym,
                'realized' => 0.0,
                'matchedBuy' => 0.0,
                'matchedSell' => 0.0,
                'openQty' => 0.0,
                'openCost' => 0.0,
                'unrealized' => 0.0,
                'buys' => 0,
                'sells' => 0,
            ];
        }
        $bySymbol[$sym]['sells']++;

        if (!isset($lots[$sym])) {
            $lots[$sym] = [];
        }

        while ($qtyToMatch > 1e-12 && $lots[$sym] !== []) {
            $lot = &$lots[$sym][0];
            $take = min($lot['qtyLeft'], $qtyToMatch);
            if ($take <= 0) {
                array_shift($lots[$sym]);
                continue;
            }
            $cost = $lot['unitCost'] * $take;
            $proceeds = $sellUnit * $take;
            $pnl = $proceeds - $cost;

            $realized += $pnl;
            $matchedBuyQuote += $cost;
            $matchedSellQuote += $proceeds;
            $bySymbol[$sym]['realized'] += $pnl;
            $bySymbol[$sym]['matchedBuy'] += $cost;
            $bySymbol[$sym]['matchedSell'] += $proceeds;
            $roundTrips++;

            $lot['qtyLeft'] -= $take;
            $lot['costLeft'] -= $cost;
            $qtyToMatch -= $take;
            $sellQuoteLeft -= $proceeds;

            if ($lot['qtyLeft'] <= 1e-12) {
                array_shift($lots[$sym]);
            }
            unset($lot);
        }
    }

    foreach ($buys as $buy) {
        $sym = $buy['symbol'];
        if (!isset($bySymbol[$sym])) {
            $bySymbol[$sym] = [
                'symbol' => $sym,
                'realized' => 0.0,
                'matchedBuy' => 0.0,
                'matchedSell' => 0.0,
                'openQty' => 0.0,
                'openCost' => 0.0,
                'unrealized' => 0.0,
                'buys' => 0,
                'sells' => 0,
            ];
        }
        $bySymbol[$sym]['buys']++;
    }

    $unrealized = 0.0;
    $openCost = 0.0;
    $openValue = 0.0;

    foreach ($lots as $sym => $symLots) {
        if (!isset($bySymbol[$sym])) {
            $bySymbol[$sym] = [
                'symbol' => $sym,
                'realized' => 0.0,
                'matchedBuy' => 0.0,
                'matchedSell' => 0.0,
                'openQty' => 0.0,
                'openCost' => 0.0,
                'unrealized' => 0.0,
                'buys' => 0,
                'sells' => 0,
            ];
        }
        $qty = 0.0;
        $cost = 0.0;
        foreach ($symLots as $lot) {
            $qty += $lot['qtyLeft'];
            $cost += max(0, $lot['costLeft']);
        }
        $price = isset($livePrices[$sym]) ? (float) $livePrices[$sym] : 0.0;
        $value = $price > 0 ? $qty * $price : $cost;
        $u = $value - $cost;

        $bySymbol[$sym]['openQty'] = $qty;
        $bySymbol[$sym]['openCost'] = $cost;
        $bySymbol[$sym]['unrealized'] = $u;
        $bySymbol[$sym]['livePrice'] = $price;

        $unrealized += $u;
        $openCost += $cost;
        $openValue += $value;
    }

    $buyQuoteToday = array_sum(array_column($buys, 'quote'));
    $sellQuoteToday = array_sum(array_column($sells, 'quote'));
    $total = $realized + $unrealized;

    uasort($bySymbol, static fn($a, $b) => ($b['realized'] + $b['unrealized']) <=> ($a['realized'] + $a['unrealized']));

    return [
        'date' => $today,
        'timezone' => appTimezone(),
        'buys' => count($buys),
        'sells' => count($sells),
        'buyQuote' => $buyQuoteToday,
        'sellQuote' => $sellQuoteToday,
        'realized' => $realized,
        'unrealized' => $unrealized,
        'total' => $total,
        'openCost' => $openCost,
        'openValue' => $openValue,
        'matchedBuyQuote' => $matchedBuyQuote,
        'matchedSellQuote' => $matchedSellQuote,
        'roundTrips' => $roundTrips,
        'isProfit' => $total >= 0,
        'bySymbol' => array_values($bySymbol),
        'updatedAt' => round(microtime(true) * 1000),
    ];
}

/** Max % of free USDT that may be spent on one symbol in one day (100 = full balance). */
function dailySymbolCapPercent(): float
{
    $raw = env('DAILY_SYMBOL_CAP_PERCENT', '100');
    $pct = is_numeric($raw) ? (float) $raw : 100.0;
    if ($pct <= 0) {
        return 100.0;
    }
    return min(100.0, $pct);
}

/** Fixed USDT amount / max per single trade (Binance min notional is usually ~5). */
function perTradeUsdtLimit(): float
{
    $raw = env('TRADE_AMOUNT_USDT', '5');
    $amt = is_numeric($raw) ? (float) $raw : 5.0;
    return $amt > 0 ? $amt : 5.0;
}

/**
 * Total USDT spent buying a symbol today (UTC).
 */
function boughtQuoteToday(string $symbol): float
{
    $symbol = strtoupper($symbol);
    $today = todayDate();
    $spent = 0.0;

    foreach (loadTradeHistory() as $row) {
        if (($row['symbol'] ?? '') !== $symbol) {
            continue;
        }
        if (strtoupper((string) ($row['side'] ?? '')) !== 'BUY') {
            continue;
        }
        $time = (int) ($row['time'] ?? 0);
        if (tradeLocalDate($time) !== $today) {
            continue;
        }
        $spent += (float) ($row['cummulativeQuoteQty'] ?? 0);
    }

    return $spent;
}

/**
 * @return array{
 *   usdtFree:float,
 *   capPercent:float,
 *   dailyCap:float,
 *   spentToday:float,
 *   remainingToday:float,
 *   suggestedBuy:float,
 *   minNotional:float,
 *   canBuy:bool,
 *   reason:?string,
 *   date:string
 * }
 */
function buildBuySuggestion(string $symbol, float $usdtFree, float $minNotional = 5.0): array
{
    $capPercent = dailySymbolCapPercent();
    $perTrade = perTradeUsdtLimit();
    // Never suggest below exchange min; never above per-trade cap
    $tradeSize = max($minNotional, $perTrade);
    if ($perTrade > 0) {
        $tradeSize = $perTrade;
    }
    // Align with Binance min: if user set 5 and min is 5, use 5
    if ($tradeSize < $minNotional) {
        $tradeSize = $minNotional;
    }

    $dailyCap = round($usdtFree * ($capPercent / 100), 8);
    $spentToday = boughtQuoteToday($symbol);
    $remainingToday = max(0, round($dailyCap - $spentToday, 8));

    $suggested = min($usdtFree, $remainingToday, $tradeSize);
    $suggested = max(0, floor($suggested * 100) / 100);

    $canBuy = $suggested >= $minNotional && $usdtFree >= $minNotional;
    $reason = null;
    if ($usdtFree < $minNotional) {
        $reason = 'Not enough free USDT. Need at least ~' . $minNotional . ' USDT (Binance minimum).';
    } elseif ($suggested < $minNotional) {
        $reason = 'Per-trade limit or remaining balance is below Binance minimum (~' . $minNotional . ' USDT).';
    }

    return [
        'usdtFree' => $usdtFree,
        'capPercent' => $capPercent,
        'perTradeLimit' => $tradeSize,
        'dailyCap' => $dailyCap,
        'spentToday' => $spentToday,
        'remainingToday' => $remainingToday,
        'suggestedBuy' => $suggested,
        'minNotional' => $minNotional,
        'canBuy' => $canBuy,
        'reason' => $reason,
        'date' => todayDate(),
    ];
}
