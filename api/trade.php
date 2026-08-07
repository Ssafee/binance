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

$action = $_GET['action'] ?? $input['action'] ?? 'status';
$action = is_string($action) ? strtolower(trim($action)) : 'status';

if ($action === 'status') {
    [$key, $secret] = binanceCredentials();
    $configured = $key !== '' && $secret !== '';

    if (!$configured) {
        respond(200, [
            'ok' => true,
            'configured' => false,
            'connected' => false,
            'message' => 'Add BINANCE_API_KEY and BINANCE_API_SECRET to .env',
            'baseUrl' => binanceBaseUrl(),
        ]);
    }

    $result = binanceRequest('GET', '/api/v3/account', [], true);
    if ($result['httpCode'] >= 400) {
        $raw = $result['body']['msg'] ?? 'API auth failed.';
        $code = $result['body']['code'] ?? null;
        $error = $raw;
        if ($code === -2015 || stripos($raw, 'Invalid API-key') !== false) {
            $error = 'Invalid API-key, IP, or permissions. Fix on Binance: '
                . '1) Enable Spot & Margin Trading on this API key, '
                . '2) If IP restriction is ON, whitelist your current public IP, '
                . '3) Or create a new key, paste Key+Secret into .env (no spaces), then Refresh API.';
        }
        respond(200, [
            'ok' => false,
            'configured' => true,
            'connected' => false,
            'error' => $error,
            'code' => $code,
            'baseUrl' => binanceBaseUrl(),
        ]);
    }

    $account = $result['body'];
    $usdtFree = 0.0;
    $usdtLocked = 0.0;
    $btcFree = 0.0;
    $btcLocked = 0.0;

    foreach ($account['balances'] ?? [] as $row) {
        $asset = $row['asset'] ?? '';
        $free = (float) ($row['free'] ?? 0);
        $locked = (float) ($row['locked'] ?? 0);
        if ($asset === 'USDT') {
            $usdtFree = $free;
            $usdtLocked = $locked;
        } elseif ($asset === 'BTC') {
            $btcFree = $free;
            $btcLocked = $locked;
        }
    }

    respond(200, [
        'ok' => true,
        'configured' => true,
        'connected' => true,
        'canTrade' => (bool) ($account['canTrade'] ?? false),
        'usdtFree' => $usdtFree,
        'usdtLocked' => $usdtLocked,
        'usdtTotal' => $usdtFree + $usdtLocked,
        'btcFree' => $btcFree,
        'btcLocked' => $btcLocked,
        'btcTotal' => $btcFree + $btcLocked,
        'wallet' => [
            'USDT' => [
                'free' => $usdtFree,
                'locked' => $usdtLocked,
                'total' => $usdtFree + $usdtLocked,
            ],
            'BTC' => [
                'free' => $btcFree,
                'locked' => $btcLocked,
                'total' => $btcFree + $btcLocked,
            ],
        ],
        'dailyCapPercent' => dailySymbolCapPercent(),
        'perTradeLimit' => perTradeUsdtLimit(),
        'baseUrl' => binanceBaseUrl(),
        'message' => 'Spot API connected',
    ]);
}

if ($action === 'suggest') {
    $symbol = sanitizeSymbol((string) ($input['symbol'] ?? $_GET['symbol'] ?? ''));
    $usdt = getFreeBalance('USDT');
    $meta = getSymbolMeta($symbol);
    $suggestion = buildBuySuggestion($symbol, $usdt, (float) $meta['minNotional']);

    respond(200, [
        'ok' => true,
        'symbol' => $symbol,
        'suggestion' => $suggestion,
    ]);
}

if ($action === 'suggest_many') {
    $rawSymbols = (string) ($input['symbols'] ?? $_GET['symbols'] ?? '');
    $parts = preg_split('/[\s,]+/', $rawSymbols) ?: [];
    $symbols = [];
    foreach ($parts as $part) {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $part) ?? '');
        if ($clean !== '') {
            $symbols[$clean] = $clean;
        }
    }
    $list = array_values($symbols);
    if ($list === []) {
        respond(400, ['ok' => false, 'error' => 'Provide symbols.']);
    }

    $usdt = getFreeBalance('USDT');
    $out = [];
    foreach ($list as $symbol) {
        try {
            $meta = getSymbolMeta($symbol);
            $out[$symbol] = buildBuySuggestion($symbol, $usdt, (float) $meta['minNotional']);
        } catch (Throwable $e) {
            $out[$symbol] = buildBuySuggestion($symbol, $usdt, 5.0);
        }
    }

    respond(200, [
        'ok' => true,
        'usdtFree' => $usdt,
        'dailyCapPercent' => dailySymbolCapPercent(),
        'perTradeLimit' => perTradeUsdtLimit(),
        'suggestions' => $out,
    ]);
}

if ($action === 'balance') {
    $symbol = sanitizeSymbol((string) ($input['symbol'] ?? $_GET['symbol'] ?? ''));
    $meta = getSymbolMeta($symbol);
    $baseFree = getFreeBalance($meta['baseAsset']);
    $quoteFree = getFreeBalance($meta['quoteAsset']);

    respond(200, [
        'ok' => true,
        'symbol' => $symbol,
        'baseAsset' => $meta['baseAsset'],
        'quoteAsset' => $meta['quoteAsset'],
        'baseFree' => $baseFree,
        'quoteFree' => $quoteFree,
        'sellQty' => floorToStep($baseFree, $meta['stepSize']),
        'minNotional' => $meta['minNotional'],
    ]);
}

if ($action === 'buy' || $action === 'sell') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Use POST for trade actions.']);
    }

    $symbol = sanitizeSymbol((string) ($input['symbol'] ?? ''));
    $meta = getSymbolMeta($symbol);

    if ($action === 'buy') {
        $usdtFree = getFreeBalance('USDT');
        $suggestion = buildBuySuggestion($symbol, $usdtFree, (float) $meta['minNotional']);

        $quoteQty = (float) ($input['quoteOrderQty'] ?? $input['amount'] ?? 0);
        // Manual amount required from UI
        if ($quoteQty <= 0) {
            respond(400, [
                'ok' => false,
                'error' => 'Enter Buy USDT amount (minimum ~' . $meta['minNotional'] . ').',
                'suggestion' => $suggestion,
            ]);
        }
        if ($quoteQty > $usdtFree + 1e-8) {
            respond(400, [
                'ok' => false,
                'error' => 'Amount exceeds free USDT balance (' . $usdtFree . ').',
                'suggestion' => $suggestion,
            ]);
        }
        // Enforce slightly above min so fees/rounding don't leave unsellable dust
        $minBuy = max((float) $meta['minNotional'], 5.0);
        if ($quoteQty < $minBuy) {
            respond(400, [
                'ok' => false,
                'error' => 'Buy at least ' . $minBuy . ' USDT. Smaller buys create dust that Binance will not let you sell (NOTIONAL error).',
                'suggestion' => $suggestion,
            ]);
        }

        $order = binanceOrFail(binanceRequest('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quoteOrderQty' => rtrim(rtrim(sprintf('%.8f', $quoteQty), '0'), '.') ?: '0',
            'newOrderRespType' => 'FULL',
        ], true));

        $record = appendTradeRecord(normalizeOrderRecord('BUY', $symbol, $order, 'app'));
        $fresh = buildBuySuggestion($symbol, getFreeBalance('USDT'), (float) $meta['minNotional']);

        respond(200, [
            'ok' => true,
            'side' => 'BUY',
            'symbol' => $symbol,
            'order' => $order,
            'history' => $record,
            'suggestion' => $fresh,
        ]);
    }

    // SELL: sell all free base, or optional quantity
    $qtyInput = isset($input['quantity']) ? (float) $input['quantity'] : null;
    $free = getFreeBalance($meta['baseAsset']);
    $qty = $qtyInput !== null && $qtyInput > 0 ? $qtyInput : $free;
    $qtyStr = floorToStep($qty, $meta['stepSize']);
    $qtyNum = (float) $qtyStr;

    if ($qtyNum <= 0 || $qtyNum < (float) $meta['minQty']) {
        respond(400, [
            'ok' => false,
            'error' => 'No sellable ' . $meta['baseAsset'] . ' balance (free: ' . $free . ').',
        ]);
    }

    // Check NOTIONAL: qty * price must be >= minNotional (~5 USDT)
    $priceRow = binanceOrFail(binanceRequest('GET', '/api/v3/ticker/price', ['symbol' => $symbol]));
    $lastPrice = (float) ($priceRow['price'] ?? 0);
    $notional = $qtyNum * $lastPrice;
    $minNotional = (float) $meta['minNotional'];

    if ($lastPrice > 0 && $notional < $minNotional) {
        respond(400, [
            'ok' => false,
            'error' => sprintf(
                'Cannot sell: position worth ~%.4f USDT, but Binance minimum is ~%.2f USDT (NOTIONAL). '
                . 'You have %s %s. Buy more first (at least %.2f USDT), or convert dust in Binance app.',
                $notional,
                $minNotional,
                $qtyStr,
                $meta['baseAsset'],
                $minNotional
            ),
            'code' => -1013,
            'notional' => $notional,
            'minNotional' => $minNotional,
            'qty' => $qtyStr,
        ]);
    }

    $order = binanceOrFail(binanceRequest('POST', '/api/v3/order', [
        'symbol' => $symbol,
        'side' => 'SELL',
        'type' => 'MARKET',
        'quantity' => $qtyStr,
        'newOrderRespType' => 'FULL',
    ], true));

    $record = appendTradeRecord(normalizeOrderRecord('SELL', $symbol, $order, 'app'));

    respond(200, [
        'ok' => true,
        'side' => 'SELL',
        'symbol' => $symbol,
        'quantity' => $qtyStr,
        'order' => $order,
        'history' => $record,
    ]);
}

respond(400, ['ok' => false, 'error' => 'Unknown action. Use status, balance, buy, or sell.']);
