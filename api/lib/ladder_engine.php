<?php
declare(strict_types=1);

/**
 * Execution engine for the ladder.
 *
 * Rule #1: an entry is only ever sold by automation when the price is at or
 * above that entry's own profit target. There is no stop-loss and no time
 * limit — an entry simply stays OPEN until it is green.
 *
 * Matured entries are sold in ONE combined market order and the proceeds are
 * split back pro-rata. That keeps small daily lots above Binance's minimum
 * order value (NOTIONAL) and avoids losing size to LOT_SIZE rounding.
 *
 * This file deliberately uses its own HTTP helpers instead of the ones in
 * binance.php: those call respond() which exits the request on failure, which
 * would be unsafe in the middle of a multi-entry sell.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/binance.php';
require_once __DIR__ . '/history_store.php';
require_once __DIR__ . '/ladder_store.php';

/**
 * Safe Binance call — always returns a structured result, never exits.
 *
 * @param array<string, scalar|null> $params
 * @return array{ok:bool,httpCode:int,body:array,error:?string,code:mixed}
 */
function ladderRequest(string $method, string $path, array $params = [], bool $signed = false): array
{
    $method = strtoupper($method);
    $base = rtrim((string) env('BINANCE_BASE_URL', 'https://api.binance.com'), '/');
    if ($base === '') {
        $base = 'https://api.binance.com';
    }

    $headers = ['Accept: application/json', 'User-Agent: BinanceLadder/1.0'];
    $apiKey = trim((string) env('BINANCE_API_KEY', ''));
    $apiSecret = trim((string) env('BINANCE_API_SECRET', ''));

    if ($signed) {
        if ($apiKey === '' || $apiSecret === '') {
            return [
                'ok' => false,
                'httpCode' => 0,
                'body' => [],
                'error' => 'Set BINANCE_API_KEY and BINANCE_API_SECRET in .env',
                'code' => null,
            ];
        }
        $params['timestamp'] = (int) round(microtime(true) * 1000);
        if (!isset($params['recvWindow'])) {
            $params['recvWindow'] = 5000;
        }
        $query = buildBinanceQuery($params);
        $query .= '&signature=' . hash_hmac('sha256', $query, $apiSecret);
        $headers[] = 'X-MBX-APIKEY: ' . $apiKey;
    } else {
        $query = $params === [] ? '' : buildBinanceQuery($params);
        if ($apiKey !== '') {
            $headers[] = 'X-MBX-APIKEY: ' . $apiKey;
        }
    }

    $url = $base . $path;
    $ch = curl_init();

    if ($method === 'GET' || $method === 'DELETE') {
        if ($query !== '') {
            $url .= '?' . $query;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || !is_string($raw)) {
        return [
            'ok' => false,
            'httpCode' => $httpCode,
            'body' => [],
            'error' => 'Could not reach Binance: ' . ($curlError !== '' ? $curlError : 'network error'),
            'code' => null,
        ];
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        return [
            'ok' => false,
            'httpCode' => $httpCode,
            'body' => [],
            'error' => 'Invalid response from Binance.',
            'code' => null,
        ];
    }

    if ($httpCode >= 400) {
        return [
            'ok' => false,
            'httpCode' => $httpCode,
            'body' => $body,
            'error' => (string) ($body['msg'] ?? 'Binance request failed.'),
            'code' => $body['code'] ?? null,
        ];
    }

    return ['ok' => true, 'httpCode' => $httpCode, 'body' => $body, 'error' => null, 'code' => null];
}

/**
 * @return array{ok:bool,price?:float,error?:string}
 */
function ladderPrice(string $symbol): array
{
    $res = ladderRequest('GET', '/api/v3/ticker/price', ['symbol' => $symbol]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }
    $price = (float) ($res['body']['price'] ?? 0);
    if ($price <= 0) {
        return ['ok' => false, 'error' => 'Invalid price for ' . $symbol];
    }
    return ['ok' => true, 'price' => $price];
}

/**
 * @return array{ok:bool,meta?:array<string,mixed>,error?:string}
 */
function ladderSymbolMeta(string $symbol): array
{
    static $cache = [];
    if (isset($cache[$symbol])) {
        return $cache[$symbol];
    }

    $res = ladderRequest('GET', '/api/v3/exchangeInfo', ['symbol' => $symbol]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }

    $item = $res['body']['symbols'][0] ?? null;
    if (!is_array($item)) {
        return ['ok' => false, 'error' => 'Symbol not found on Binance: ' . $symbol];
    }

    $stepSize = '0.00000001';
    $minQty = '0';
    $minNotional = 5.0;

    foreach ($item['filters'] ?? [] as $filter) {
        $type = $filter['filterType'] ?? '';
        if ($type === 'LOT_SIZE') {
            $stepSize = (string) ($filter['stepSize'] ?? $stepSize);
            $minQty = (string) ($filter['minQty'] ?? $minQty);
        }
        if ($type === 'NOTIONAL' || $type === 'MIN_NOTIONAL') {
            $minNotional = (float) ($filter['minNotional'] ?? $filter['notional'] ?? $minNotional);
        }
    }

    $out = [
        'ok' => true,
        'meta' => [
            'symbol' => (string) ($item['symbol'] ?? $symbol),
            'baseAsset' => (string) ($item['baseAsset'] ?? ''),
            'quoteAsset' => (string) ($item['quoteAsset'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'stepSize' => $stepSize,
            'minQty' => $minQty,
            'minNotional' => $minNotional,
        ],
    ];
    $cache[$symbol] = $out;
    return $out;
}

/**
 * @return array{ok:bool,free?:float,error?:string}
 */
function ladderFreeBalance(string $asset): array
{
    $res = ladderRequest('GET', '/api/v3/account', [], true);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }
    foreach ($res['body']['balances'] ?? [] as $row) {
        if (($row['asset'] ?? '') === $asset) {
            return ['ok' => true, 'free' => (float) ($row['free'] ?? 0)];
        }
    }
    return ['ok' => true, 'free' => 0.0];
}

/**
 * Net base qty received and net quote spent for a filled BUY.
 *
 * @param array<string, mixed> $order
 * @return array{qty:float,cost:float}
 */
function ladderBuyFills(array $order, string $baseAsset, string $quoteAsset): array
{
    $qty = (float) ($order['executedQty'] ?? 0);
    $cost = (float) ($order['cummulativeQuoteQty'] ?? 0);

    foreach ($order['fills'] ?? [] as $fill) {
        if (!is_array($fill)) {
            continue;
        }
        $commission = (float) ($fill['commission'] ?? 0);
        $asset = (string) ($fill['commissionAsset'] ?? '');
        if ($commission <= 0) {
            continue;
        }
        if ($asset === $baseAsset) {
            $qty -= $commission;   // fee paid in the coin we bought
        } elseif ($asset === $quoteAsset) {
            $cost += $commission;  // fee paid in USDT
        }
    }

    return ['qty' => max(0.0, $qty), 'cost' => max(0.0, $cost)];
}

/**
 * Net quote received for a filled SELL.
 *
 * @param array<string, mixed> $order
 */
function ladderSellProceeds(array $order, string $quoteAsset): float
{
    $proceeds = (float) ($order['cummulativeQuoteQty'] ?? 0);
    foreach ($order['fills'] ?? [] as $fill) {
        if (!is_array($fill)) {
            continue;
        }
        $commission = (float) ($fill['commission'] ?? 0);
        if ($commission > 0 && (string) ($fill['commissionAsset'] ?? '') === $quoteAsset) {
            $proceeds -= $commission;
        }
    }
    return max(0.0, $proceeds);
}

/* ------------------------------------------------------------------ *
 * Buy
 * ------------------------------------------------------------------ */

/**
 * Add one entry (real order in live mode, paper entry in sim mode).
 *
 * @param array<string, mixed> $state
 * @param array<string, mixed>|null $config Which ladder config to buy under
 * @return array{ok:bool,entry?:array<string,mixed>,error?:string,price?:float}
 */
function ladderExecuteBuy(
    string $mode,
    array &$state,
    float $usdt,
    string $source = 'manual',
    ?float $priceOverride = null,
    ?array $config = null
): array {
    $mode = ladderNormalizeMode($mode);
    $config = ladderSanitizeConfig($config ?? ($state['config'] ?? ladderDefaultConfig()));
    $symbol = (string) $config['symbol'];
    $feePct = (float) $config['feePct'];
    $netProfitPct = (float) $config['netProfitPct'];

    if ($usdt <= 0) {
        return ['ok' => false, 'error' => 'Buy amount must be greater than 0.'];
    }

    $nowMs = (int) round(microtime(true) * 1000);

    if ($mode === 'sim') {
        $price = $priceOverride !== null && $priceOverride > 0 ? $priceOverride : null;
        if ($price === null) {
            return [
                'ok' => false,
                'error' => 'Simulation needs a Test price for ' . $symbol . '.',
            ];
        }

        $cash = ladderSimCash($state);
        if ($cash + 1e-9 < $usdt) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Simulated cash too low: have %.2f USDT, need %.2f. Raise sim start balance.',
                    $cash,
                    $usdt
                ),
            ];
        }

        $qty = ($usdt / $price) * ladderFeeKeep($feePct);
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Computed quantity was zero.'];
        }

        $entry = ladderWithTargets([
            'id' => ladderNewId(),
            'configId' => (string) $config['id'],
            'mode' => 'sim',
            'symbol' => $symbol,
            'status' => 'OPEN',
            'buyAt' => $nowMs,
            'buyDate' => binanceTradeDate($nowMs),
            'buyPrice' => $usdt / $qty,
            'qty' => $qty,
            'costUsdt' => $usdt,
            'feePct' => $feePct,
            'netProfitPct' => $netProfitPct,
            'targetPrice' => 0.0,
            'targetUsdt' => 0.0,
            'buyOrderId' => null,
            'sellAt' => null,
            'sellDate' => null,
            'sellPrice' => null,
            'proceedsUsdt' => null,
            'profitUsdt' => null,
            'profitPct' => null,
            'sellOrderId' => null,
            'sellReason' => null,
            'source' => $source,
            'note' => sprintf('Simulated buy @ %s', $price),
        ]);

        $state['entries'][] = $entry;
        ladderClearSellDustWait($state, $symbol);
        return ['ok' => true, 'entry' => $entry, 'price' => $price];
    }

    // ---- live ----
    $metaRes = ladderSymbolMeta($symbol);
    if (!$metaRes['ok']) {
        return ['ok' => false, 'error' => $metaRes['error']];
    }
    $meta = $metaRes['meta'];
    $minBuy = max((float) $meta['minNotional'], 5.0);

    if ($usdt + 1e-9 < $minBuy) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Binance minimum order for %s is ~%.2f USDT. Raise "Amount per day" to at least that.',
                $symbol,
                $minBuy
            ),
        ];
    }

    $balance = ladderFreeBalance('USDT');
    if (!$balance['ok']) {
        return ['ok' => false, 'error' => 'Balance check failed: ' . $balance['error']];
    }
    if ((float) $balance['free'] + 1e-9 < $usdt) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Not enough free USDT: have %.2f, need %.2f.',
                (float) $balance['free'],
                $usdt
            ),
        ];
    }

    $order = ladderRequest('POST', '/api/v3/order', [
        'symbol' => $symbol,
        'side' => 'BUY',
        'type' => 'MARKET',
        'quoteOrderQty' => rtrim(rtrim(sprintf('%.8f', $usdt), '0'), '.') ?: '0',
        'newOrderRespType' => 'FULL',
    ], true);

    if (!$order['ok']) {
        return ['ok' => false, 'error' => 'Buy rejected: ' . $order['error']];
    }

    $body = $order['body'];
    $fills = ladderBuyFills($body, (string) $meta['baseAsset'], (string) $meta['quoteAsset']);
    if ($fills['qty'] <= 0 || $fills['cost'] <= 0) {
        return ['ok' => false, 'error' => 'Buy filled but quantity/cost could not be read.'];
    }

    $filledAt = (int) ($body['transactTime'] ?? $nowMs);
    $entry = ladderWithTargets([
        'id' => ladderNewId(),
        'configId' => (string) $config['id'],
        'mode' => 'live',
        'symbol' => $symbol,
        'status' => 'OPEN',
        'buyAt' => $filledAt,
        'buyDate' => binanceTradeDate($filledAt),
        'buyPrice' => $fills['cost'] / $fills['qty'],
        'qty' => $fills['qty'],
        'costUsdt' => $fills['cost'],
        'feePct' => $feePct,
        'netProfitPct' => $netProfitPct,
        'targetPrice' => 0.0,
        'targetUsdt' => 0.0,
        'buyOrderId' => $body['orderId'] ?? null,
        'sellAt' => null,
        'sellDate' => null,
        'sellPrice' => null,
        'proceedsUsdt' => null,
        'profitUsdt' => null,
        'profitPct' => null,
        'sellOrderId' => null,
        'sellReason' => null,
        'source' => $source,
        'note' => null,
    ]);

    $state['entries'][] = $entry;
    ladderClearSellDustWait($state, $symbol);

    // Mirror into the normal trade history so the main dashboard sees it too.
    appendTradeRecord(normalizeOrderRecord('BUY', $symbol, $body, 'ladder'));

    return ['ok' => true, 'entry' => $entry, 'price' => $entry['buyPrice']];
}

/* ------------------------------------------------------------------ *
 * Sell
 * ------------------------------------------------------------------ */

/**
 * Sell one or many OPEN entries as a single order, splitting proceeds pro-rata.
 *
 * @param array<string, mixed> $state
 * @param list<string>         $entryIds
 * @return array{ok:bool,sold?:list<array<string,mixed>>,proceeds?:float,profit?:float,error?:string,price?:float}
 */
function ladderExecuteSell(
    string $mode,
    array &$state,
    array $entryIds,
    string $reason = 'manual',
    ?float $priceOverride = null,
    bool $force = false
): array {
    $mode = ladderNormalizeMode($mode);
    $symbol = (string) (($state['config']['symbol'] ?? '') ?: '');

    $targets = [];
    foreach ($state['entries'] as $index => $entry) {
        if ($entry['status'] !== 'OPEN') {
            continue;
        }
        if (!in_array((string) $entry['id'], $entryIds, true)) {
            continue;
        }
        $targets[$index] = $entry;
    }

    // Oldest first, so a partial fill closes the longest-held lots (FIFO).
    uasort($targets, static function ($a, $b) {
        return ((int) $a['buyAt']) <=> ((int) $b['buyAt']);
    });

    if ($targets === []) {
        return ['ok' => false, 'error' => 'No matching open entries to sell.'];
    }

    $symbols = [];
    foreach ($targets as $entry) {
        $symbols[(string) $entry['symbol']] = true;
    }
    if (count($symbols) > 1) {
        return ['ok' => false, 'error' => 'Cannot sell entries of different symbols in one order.'];
    }
    $symbol = (string) array_key_first($symbols);

    $price = $priceOverride !== null && $priceOverride > 0 ? $priceOverride : null;
    if ($price === null) {
        if ($mode === 'sim') {
            return [
                'ok' => false,
                'error' => 'Simulation needs a Test price. Enter one on the page — live Binance prices are not used here.',
            ];
        }
        $live = ladderPrice($symbol);
        if (!$live['ok']) {
            return ['ok' => false, 'error' => $live['error']];
        }
        $price = (float) $live['price'];
    }

    // Never sell at a loss unless the human explicitly forces it.
    if (!$force) {
        foreach ($targets as $entry) {
            if ($reason === 'target' && $price + 1e-12 < (float) $entry['targetPrice']) {
                return [
                    'ok' => false,
                    'error' => sprintf(
                        'Entry %s has not reached its target yet (%.8f needed, price %.8f).',
                        $entry['id'],
                        (float) $entry['targetPrice'],
                        $price
                    ),
                ];
            }
            if ($reason !== 'target') {
                $breakEven = ladderBreakEvenPrice((float) $entry['costUsdt'], (float) $entry['qty'], (float) $entry['feePct']);
                if ($price + 1e-12 < $breakEven) {
                    return [
                        'ok' => false,
                        'error' => sprintf(
                            'Selling entry %s now would be a loss (break-even %.8f, price %.8f). Confirm to force it.',
                            $entry['id'],
                            $breakEven,
                            $price
                        ),
                        'wouldLose' => true,
                    ];
                }
            }
        }
    }

    $totalQty = 0.0;
    foreach ($targets as $entry) {
        $totalQty += (float) $entry['qty'];
    }
    if ($totalQty <= 0) {
        return ['ok' => false, 'error' => 'Nothing to sell (quantity is zero).'];
    }

    $nowMs = (int) round(microtime(true) * 1000);
    $sellPrice = $price;
    $proceedsTotal = 0.0;
    $sellOrderId = null;
    $note = null;
    $soldQty = $totalQty; // how much of totalQty actually got sold

    if ($mode === 'sim') {
        foreach ($targets as $entry) {
            $proceedsTotal += ladderProceeds((float) $entry['qty'], $price, (float) $entry['feePct']);
        }
    } else {
        $metaRes = ladderSymbolMeta($symbol);
        if (!$metaRes['ok']) {
            return ['ok' => false, 'error' => $metaRes['error']];
        }
        $meta = $metaRes['meta'];

        $balance = ladderFreeBalance((string) $meta['baseAsset']);
        if (!$balance['ok']) {
            return ['ok' => false, 'error' => 'Balance check failed: ' . $balance['error']];
        }

        $sellable = min($totalQty, (float) $balance['free']);
        $qtyStr = floorToStep($sellable, (string) $meta['stepSize']);
        $qtyNum = (float) $qtyStr;

        if ($qtyNum <= 0 || $qtyNum < (float) $meta['minQty']) {
            return [
                'ok' => false,
                'tooSmall' => true,
                'error' => sprintf(
                    'Quantity too small to sell on Binance (have %.8f %s, step %s).',
                    $sellable,
                    $meta['baseAsset'],
                    $meta['stepSize']
                ),
            ];
        }

        $notional = $qtyNum * $price;
        if ($notional + 1e-9 < (float) $meta['minNotional']) {
            return [
                'ok' => false,
                'tooSmall' => true,
                'belowNotional' => true,
                'error' => sprintf(
                    'This sell is worth ~%.2f USDT but Binance needs at least %.2f (NOTIONAL). '
                    . 'Wait for more matured entries and sell them together, or increase the daily amount.',
                    $notional,
                    (float) $meta['minNotional']
                ),
            ];
        }

        $order = ladderRequest('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $qtyStr,
            'newOrderRespType' => 'FULL',
        ], true);

        if (!$order['ok']) {
            return ['ok' => false, 'error' => 'Sell rejected: ' . $order['error']];
        }

        $body = $order['body'];
        $proceedsTotal = ladderSellProceeds($body, (string) $meta['quoteAsset']);
        $executed = (float) ($body['executedQty'] ?? $qtyNum);
        $grossQuote = (float) ($body['cummulativeQuoteQty'] ?? 0);
        if ($executed > 0 && $grossQuote > 0) {
            $sellPrice = $grossQuote / $executed;
        }
        $soldQty = $executed > 0 ? $executed : $qtyNum;
        $sellOrderId = $body['orderId'] ?? null;
        $nowMs = (int) ($body['transactTime'] ?? $nowMs);

        if ($soldQty + 1e-12 < $totalQty) {
            $note = sprintf(
                'Partial: sold %s of %.8f (LOT_SIZE step %s). Remainder stays open at the same target.',
                rtrim(rtrim(sprintf('%.8f', $soldQty), '0'), '.'),
                $totalQty,
                $meta['stepSize']
            );
        }

        appendTradeRecord(normalizeOrderRecord('SELL', $symbol, $body, 'ladder'));
    }

    if ($soldQty <= 0) {
        return ['ok' => false, 'error' => 'Sell order did not fill.'];
    }

    /*
     * Allocate the realised proceeds FIFO. Anything that could not be sold
     * (LOT_SIZE rounding, or a balance shortfall) is NOT written off — the
     * lot stays open with its cost reduced by the same fraction, which keeps
     * its target price identical and lets it sell later alongside newer lots.
     */
    $unitProceeds = $proceedsTotal / $soldQty;
    $remaining = $soldQty;
    $sold = [];
    $residuals = [];
    $profitTotal = 0.0;

    foreach ($targets as $index => $entry) {
        if ($remaining <= 1e-12) {
            break; // untouched lots simply stay OPEN
        }

        $entryQty = (float) $entry['qty'];
        $cost = (float) $entry['costUsdt'];
        $take = min($entryQty, $remaining);
        $remaining -= $take;

        $isPartial = $take + 1e-12 < $entryQty;
        $fraction = $entryQty > 0 ? $take / $entryQty : 1.0;
        $soldCost = $isPartial ? $cost * $fraction : $cost;
        $entryProceeds = $take * $unitProceeds;
        $profit = $entryProceeds - $soldCost;
        $profitTotal += $profit;

        $closed = $entry;
        $closed['status'] = 'SOLD';
        $closed['qty'] = $take;
        $closed['costUsdt'] = $soldCost;
        $closed['sellAt'] = $nowMs;
        $closed['sellDate'] = binanceTradeDate($nowMs);
        $closed['sellPrice'] = $sellPrice;
        $closed['proceedsUsdt'] = $entryProceeds;
        $closed['profitUsdt'] = $profit;
        $closed['profitPct'] = $soldCost > 0 ? ($profit / $soldCost) * 100 : null;
        $closed['sellOrderId'] = $sellOrderId;
        $closed['sellReason'] = $reason;
        if ($note !== null) {
            $closed['note'] = $note;
        }

        if ($isPartial) {
            // Keep the un-sellable remainder open, cost-adjusted.
            $residual = ladderWithTargets(array_merge($entry, [
                'id' => ladderNewId(),
                'qty' => $entryQty - $take,
                'costUsdt' => $cost - $soldCost,
                'note' => 'Remainder of ' . $entry['id'] . ' — too small for one order, waiting to join the next sell',
            ]));
            $residuals[] = $residual;
            $closed['id'] = $entry['id'] . '-p';
        }

        $state['entries'][$index] = $closed;
        $sold[] = $closed;
    }

    foreach ($residuals as $residual) {
        $state['entries'][] = $residual;
    }

    return [
        'ok' => true,
        'sold' => $sold,
        'residuals' => count($residuals),
        'proceeds' => $proceedsTotal,
        'profit' => $profitTotal,
        'price' => $sellPrice,
        'count' => count($sold),
    ];
}

/* ------------------------------------------------------------------ *
 * Automation
 * ------------------------------------------------------------------ */

/**
 * Buy today's lot for one config if it hasn't been bought yet.
 *
 * @param array<string, mixed> $state
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function ladderDailyBuyForConfig(
    string $mode,
    array &$state,
    array $config,
    ?float $priceOverride = null,
    bool $afterSell = false
): array {
    $config = ladderSanitizeConfig($config);
    $symbol = (string) $config['symbol'];
    $today = binanceTodayDate();
    $multi = !empty($config['multiBuyEnabled']);

    if ($multi) {
        ladderConfigSyncBuyDay($config);
        ladderConfigRefreshCycle($state, $config);
        ladderUpdateConfigInState($state, $config);

        $buysToday = (int) ($config['buysToday'] ?? 0);
        $buysPerDay = max(1, (int) ($config['buysPerDay'] ?? 1));

        if ($buysToday >= $buysPerDay) {
            return [
                'ok' => true,
                'skipped' => true,
                'symbol' => $symbol,
                'reason' => sprintf(
                    '%s daily buy limit reached (%d/%d for %s UTC)',
                    $symbol,
                    $buysToday,
                    $buysPerDay,
                    $today
                ),
            ];
        }

        if (ladderConfigCycleBlocksBuy($state, $config)) {
            return [
                'ok' => true,
                'skipped' => true,
                'symbol' => $symbol,
                'reason' => $symbol . ' waiting for today\'s cycle to sell before next buy',
            ];
        }

        // First buy of the day: UTC window (unless this run just sold and freed the slot).
        if ($buysToday === 0 && !$afterSell && !ladderIsUtcBuyWindow()) {
            return [
                'ok' => true,
                'skipped' => true,
                'symbol' => $symbol,
                'reason' => $symbol . ' waiting for UTC buy window (00:00–01:59). Next: ' . ladderNextUtcMidnight(),
            ];
        }
    } else {
        if ((string) $config['lastBuyDate'] === $today) {
            return [
                'ok' => true,
                'skipped' => true,
                'symbol' => $symbol,
                'reason' => $symbol . ' already bought this Binance day (' . $today . ' UTC)',
            ];
        }

        if (!ladderIsUtcBuyWindow()) {
            return [
                'ok' => true,
                'skipped' => true,
                'symbol' => $symbol,
                'reason' => $symbol . ' waiting for UTC buy window (00:00–01:59). Next: ' . ladderNextUtcMidnight(),
            ];
        }
    }

    $usdt = (float) $config['dailyUsdt'];
    if ($usdt <= 0) {
        return ['ok' => true, 'skipped' => true, 'symbol' => $symbol, 'reason' => $symbol . ' daily amount is 0'];
    }

    $res = ladderExecuteBuy($mode, $state, $usdt, 'auto', $priceOverride, $config);
    if (!empty($res['ok'])) {
        $entryId = (string) ($res['entry']['id'] ?? '');
        if ($multi && $entryId !== '') {
            ladderConfigRecordAutoBuy($config, $entryId);
            ladderUpdateConfigInState($state, $config);
        } else {
            foreach ($state['configs'] as $i => $row) {
                if ((string) $row['id'] === (string) $config['id']) {
                    $state['configs'][$i]['lastBuyDate'] = $today;
                    break;
                }
            }
            $state['config'] = $state['configs'][0] ?? $config;
        }

        $buyNum = $multi ? (int) ($config['buysToday'] ?? 1) : 1;
        $buyLabel = $multi
            ? sprintf('buy %d/%d', $buyNum, max(1, (int) ($config['buysPerDay'] ?? 1)))
            : 'daily buy';

        return [
            'ok' => true,
            'bought' => true,
            'symbol' => $symbol,
            'entry' => $res['entry'],
            'msg' => sprintf(
                '%s %s %.2f USDT @ %s (Binance day %s UTC)',
                $symbol,
                $buyLabel,
                $usdt,
                $res['entry']['buyPrice'],
                $today
            ),
        ];
    }

    return [
        'ok' => false,
        'symbol' => $symbol,
        'error' => $symbol . ': ' . ($res['error'] ?? 'Daily buy failed'),
    ];
}

/**
 * Run daily buys for every configured symbol.
 *
 * @param array<string, mixed> $state
 * @param array<string, float> $prices
 * @return array<string, mixed>
 */
function ladderDailyBuyAll(string $mode, array &$state, array $prices = [], bool $afterSell = false): array
{
    $results = [];
    $bought = 0;
    $errors = [];

    foreach (ladderConfigs($state) as $config) {
        $symbol = (string) $config['symbol'];
        $price = $prices[$symbol] ?? null;
        $res = ladderDailyBuyForConfig($mode, $state, $config, $price, $afterSell);
        $results[] = $res;
        if (!empty($res['bought'])) {
            $bought++;
        }
        if (!empty($res['error'])) {
            $errors[] = $res['error'];
        }
    }

    return [
        'ok' => $errors === [],
        'bought' => $bought,
        'results' => $results,
        'error' => $errors[0] ?? null,
        'errors' => $errors,
    ];
}

/**
 * After a sell, try the next multi-buy cycle for configs that just freed a slot.
 *
 * @param array<string, mixed> $state
 * @param array<string, float> $prices
 * @return array<string, mixed>
 */
function ladderFollowUpMultiBuy(string $mode, array &$state, array $prices): array
{
    $anyMulti = false;
    foreach (ladderConfigs($state) as $cfg) {
        if (!empty($cfg['multiBuyEnabled'])) {
            $anyMulti = true;
            break;
        }
    }
    if (!$anyMulti) {
        return ['ok' => true, 'bought' => 0, 'results' => []];
    }

    return ladderDailyBuyAll($mode, $state, $prices, true);
}

/**
 * Sell matured entries for one symbol as a combined order.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function ladderSweepMaturedForSymbol(
    string $mode,
    array &$state,
    string $symbol,
    float $price,
    bool $ignoreToggle = false
): array {
    $symbol = strtoupper($symbol);
    if ($price <= 0) {
        return ['ok' => true, 'skipped' => true, 'symbol' => $symbol, 'reason' => 'No price for ' . $symbol];
    }

    // Auto-sell is always on for ladder configs; ignoreToggle kept for API compatibility.
    unset($ignoreToggle);

    $ids = ladderMaturedEntryIds($state, $symbol, $price);

    if ($ids === []) {
        // Nothing at target — dust wait can skip further API calls until lots change.
        if ($mode === 'live' && ladderIsSellDustWaitActive($state, $symbol)) {
            return [
                'ok' => true,
                'skipped' => true,
                'waiting' => true,
                'silent' => true,
                'symbol' => $symbol,
                'reason' => $symbol . ' leftover too small to sell alone — will join the next buy',
            ];
        }
        return [
            'ok' => true,
            'skipped' => true,
            'symbol' => $symbol,
            'reason' => 'No ' . $symbol . ' entry has reached its target',
        ];
    }

    // Target hit — always try to sell (manual buy may have made combined qty large enough).
    ladderClearSellDustWait($state, $symbol);

    $res = ladderExecuteSell($mode, $state, $ids, 'target', $price, false);
    if (empty($res['ok'])) {
        // Dust leftover (below LOT_SIZE / NOTIONAL) — wait for the next daily buy to combine.
        if (!empty($res['tooSmall']) || !empty($res['belowNotional'])) {
            ladderMarkSellDustWait($state, $symbol);
            return [
                'ok' => true,
                'skipped' => true,
                'waiting' => true,
                'symbol' => $symbol,
                'reason' => $symbol . ' leftover too small to sell alone — will join the next buy',
            ];
        }
        return [
            'ok' => false,
            'symbol' => $symbol,
            'error' => $symbol . ': ' . ($res['error'] ?? 'Sweep failed'),
            'candidates' => count($ids),
        ];
    }

    ladderClearSellDustWait($state, $symbol);

    return [
        'ok' => true,
        'symbol' => $symbol,
        'sold' => $res['count'],
        'proceeds' => $res['proceeds'],
        'profit' => $res['profit'],
        'msg' => sprintf(
            '%s sold %d matured entry(s) for %+.4f USDT profit',
            $symbol,
            $res['count'],
            $res['profit']
        ),
    ];
}

/**
 * Sweep every symbol that has a price.
 *
 * @param array<string, mixed> $state
 * @param array<string, float> $prices
 * @return array<string, mixed>
 */
function ladderSweepMaturedAll(string $mode, array &$state, array $prices, bool $ignoreToggle = false): array
{
    $sold = 0;
    $profit = 0.0;
    $results = [];
    $errors = [];

    $symbols = array_unique(array_merge(array_keys($prices), ladderSymbolsInState($state)));
    foreach ($symbols as $symbol) {
        $price = (float) ($prices[$symbol] ?? 0);
        if ($price <= 0) {
            continue;
        }
        $res = ladderSweepMaturedForSymbol($mode, $state, $symbol, $price, $ignoreToggle);
        $results[] = $res;
        if (!empty($res['sold'])) {
            $sold += (int) $res['sold'];
            $profit += (float) ($res['profit'] ?? 0);
        }
        if (!empty($res['error'])) {
            $errors[] = $res['error'];
        }
    }

    return [
        'ok' => $errors === [],
        'sold' => $sold,
        'profit' => $profit,
        'results' => $results,
        'error' => $errors[0] ?? null,
        'msg' => $sold > 0
            ? sprintf('Sold %d matured entry(s) across symbols for %+.4f USDT', $sold, $profit)
            : 'No matured entries to sell',
    ];
}

/** @deprecated use ladderSweepMaturedAll */
function ladderSweepMatured(string $mode, array &$state, float $price, bool $ignoreToggle = false): array
{
    $symbol = (string) (($state['config']['symbol'] ?? '') ?: '');
    $prices = $symbol !== '' ? [$symbol => $price] : [];
    return ladderSweepMaturedAll($mode, $state, $prices, $ignoreToggle);
}

/**
 * One full automation pass across all configs.
 *
 * @param array{prices?:array<string,float>,priceOverride?:float} $opts
 * @return array<string, mixed>
 */
function ladderRunPass(string $mode, array $opts = []): array
{
    $mode = ladderNormalizeMode($mode);
    $state = ladderLoadState($mode);
    $nowMs = (int) round(microtime(true) * 1000);
    $log = [];
    $actions = 0;

    /** @var array<string, float> $prices */
    $prices = [];
    if (is_array($opts['prices'] ?? null)) {
        foreach ($opts['prices'] as $sym => $px) {
            $sym = strtoupper((string) $sym);
            $px = (float) $px;
            if ($sym !== '' && $px > 0) {
                $prices[$sym] = $px;
            }
        }
    }

    // Legacy single priceOverride applied to every config symbol (sim convenience).
    if (isset($opts['priceOverride']) && (float) $opts['priceOverride'] > 0) {
        $single = (float) $opts['priceOverride'];
        foreach (ladderConfigs($state) as $cfg) {
            $prices[(string) $cfg['symbol']] = $single;
        }
    }

    if ($mode === 'live') {
        foreach (ladderSymbolsInState($state) as $symbol) {
            if (isset($prices[$symbol]) && $prices[$symbol] > 0) {
                continue;
            }
            // Skip price only when dust-waiting AND nothing is open to watch for a target sell.
            if (ladderIsSellDustWaitActive($state, $symbol) && !ladderSymbolHasOpenEntries($state, $symbol)) {
                continue;
            }
            $live = ladderPrice($symbol);
            if (!empty($live['ok'])) {
                $prices[$symbol] = (float) $live['price'];
            } else {
                $log[] = ['t' => $nowMs, 'msg' => $symbol . ' price fail: ' . ($live['error'] ?? '')];
            }
        }
    } elseif ($prices === []) {
        $msg = 'Simulation needs a Test price per symbol — live Binance prices are not used here.';
        $state['lastRunAt'] = $nowMs;
        $state['lastRunLog'] = array_slice(array_merge(
            [['t' => $nowMs, 'msg' => $msg]],
            $state['lastRunLog']
        ), 0, 60);
        ladderSaveState($mode, $state);
        return ['ok' => false, 'error' => $msg, 'log' => $state['lastRunLog'], 'prices' => []];
    }

    $buy = ladderDailyBuyAll($mode, $state, $prices);
    foreach ($buy['results'] as $row) {
        if (!empty($row['bought'])) {
            $actions++;
            $log[] = ['t' => $nowMs, 'msg' => $row['msg']];
        } elseif (!empty($row['error'])) {
            $log[] = ['t' => $nowMs, 'msg' => 'Daily buy failed: ' . $row['error']];
        } elseif (!empty($row['skipped'])) {
            $log[] = ['t' => $nowMs, 'msg' => 'Daily buy skipped — ' . $row['reason']];
        }
    }

    $sweep = ladderSweepMaturedAll($mode, $state, $prices);
    if (!empty($sweep['sold'])) {
        $actions += (int) $sweep['sold'];
        $log[] = ['t' => $nowMs, 'msg' => $sweep['msg']];
        foreach ($sweep['results'] as $row) {
            if (!empty($row['msg'])) {
                $log[] = ['t' => $nowMs, 'msg' => $row['msg']];
            }
        }

        $followUp = ladderFollowUpMultiBuy($mode, $state, $prices);
        foreach ($followUp['results'] ?? [] as $row) {
            if (!empty($row['bought'])) {
                $actions++;
                $log[] = ['t' => $nowMs, 'msg' => $row['msg']];
            } elseif (!empty($row['error'])) {
                $log[] = ['t' => $nowMs, 'msg' => 'Follow-up buy failed: ' . $row['error']];
            } elseif (!empty($row['skipped']) && !empty($row['reason'])) {
                $log[] = ['t' => $nowMs, 'msg' => 'Follow-up buy skipped — ' . $row['reason']];
            }
        }
    } elseif (!empty($sweep['error'])) {
        $log[] = ['t' => $nowMs, 'msg' => 'Sweep: ' . $sweep['error']];
    } else {
        $waiting = [];
        foreach ($sweep['results'] ?? [] as $row) {
            if (!empty($row['waiting']) && !empty($row['reason']) && empty($row['silent'])) {
                $waiting[] = $row['reason'];
            }
        }
        $log[] = ['t' => $nowMs, 'msg' => $waiting !== []
            ? 'Sweep skipped — ' . implode('; ', $waiting)
            : 'Sweep skipped — no matured entries'];
    }

    $state['lastRunAt'] = $nowMs;
    $state['lastRunLog'] = array_slice(array_merge($log, $state['lastRunLog']), 0, 60);
    ladderSaveState($mode, $state);

    return [
        'ok' => true,
        'mode' => $mode,
        'prices' => $prices,
        'actions' => $actions,
        'buy' => $buy,
        'sweep' => $sweep,
        'log' => $log,
        'dashboard' => ladderDashboard($state, $prices),
    ];
}
