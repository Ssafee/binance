<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function respond(int $status, array $payload): void
{
    if (defined('AUTO_TRADE_CRON') && AUTO_TRADE_CRON) {
        throw new RuntimeException((string) ($payload['error'] ?? 'Request failed'));
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
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

function binanceBaseUrl(): string
{
    $base = rtrim((string) env('BINANCE_BASE_URL', 'https://api.binance.com'), '/');
    return $base !== '' ? $base : 'https://api.binance.com';
}

function binanceCredentials(): array
{
    $key = trim((string) env('BINANCE_API_KEY', ''));
    $secret = trim((string) env('BINANCE_API_SECRET', ''));
    return [$key, $secret];
}

function requireApiKeys(): array
{
    [$key, $secret] = binanceCredentials();
    if ($key === '' || $secret === '') {
        respond(400, [
            'ok' => false,
            'error' => 'Set BINANCE_API_KEY and BINANCE_API_SECRET in the .env file.',
        ]);
    }
    return [$key, $secret];
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
 * @param array<string, scalar|null> $params
 * @return array{httpCode:int, body:array, raw:string}
 */
function binanceRequest(string $method, string $path, array $params = [], bool $signed = false): array
{
    $method = strtoupper($method);
    $url = binanceBaseUrl() . $path;
    $headers = [
        'Accept: application/json',
        'User-Agent: BinanceMarketApp/1.0',
    ];

    if ($signed) {
        [$apiKey, $apiSecret] = requireApiKeys();
        $params['timestamp'] = (int) round(microtime(true) * 1000);
        if (!isset($params['recvWindow'])) {
            $params['recvWindow'] = 5000;
        }
        $query = buildBinanceQuery($params);
        $signature = hash_hmac('sha256', $query, $apiSecret);
        $query .= '&signature=' . $signature;
        $headers[] = 'X-MBX-APIKEY: ' . $apiKey;
    } else {
        $query = $params === [] ? '' : buildBinanceQuery($params);
        [$apiKey] = binanceCredentials();
        if ($apiKey !== '') {
            $headers[] = 'X-MBX-APIKEY: ' . $apiKey;
        }
    }

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
        if ($method !== 'POST') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        respond(502, [
            'ok' => false,
            'error' => 'Could not reach Binance API.',
            'detail' => $error !== '' ? $error : 'Network error',
        ]);
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        respond(502, ['ok' => false, 'error' => 'Invalid response from Binance.']);
    }

    return [
        'httpCode' => $httpCode,
        'body' => $body,
        'raw' => $raw,
    ];
}

/**
 * @param array<string, scalar|null> $params
 */
function buildBinanceQuery(array $params): string
{
    $parts = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }
    return implode('&', $parts);
}

function binanceOrFail(array $result): array
{
    $code = $result['httpCode'];
    $body = $result['body'];
    if ($code >= 400) {
        $msg = $body['msg'] ?? 'Binance request failed.';
        $binanceCode = $body['code'] ?? null;

        if ($binanceCode === -1013 || stripos($msg, 'NOTIONAL') !== false) {
            $msg = 'Order value too small (NOTIONAL). Binance needs about 5+ USDT per order. '
                . 'Your BTC amount is dust — buy more first, or convert dust in Binance app. '
                . 'Original: ' . ($body['msg'] ?? 'Filter failure: NOTIONAL');
        } elseif ($binanceCode === -2015 || stripos($msg, 'Invalid API-key') !== false) {
            $msg = 'Invalid API-key, IP, or permissions. Check Binance API Management: '
                . 'Spot trading enabled, IP whitelist matches your PC IP (or unrestricted), '
                . 'and .env Key/Secret are correct with no extra spaces.';
        } elseif ($binanceCode === -2010 || stripos($msg, 'insufficient') !== false) {
            $msg = 'Insufficient balance. ' . $msg;
        }

        respond($code >= 500 ? 502 : 400, [
            'ok' => false,
            'error' => $msg,
            'code' => $binanceCode,
        ]);
    }
    return $body;
}

/**
 * @return array{baseAsset:string,quoteAsset:string,stepSize:string,minQty:string,minNotional:float}
 */
function getSymbolMeta(string $symbol): array
{
    $info = binanceOrFail(binanceRequest('GET', '/api/v3/exchangeInfo', ['symbol' => $symbol]));
    $item = $info['symbols'][0] ?? null;
    if (!is_array($item)) {
        respond(400, ['ok' => false, 'error' => 'Symbol not found on Binance.']);
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

    return [
        'baseAsset' => (string) ($item['baseAsset'] ?? ''),
        'quoteAsset' => (string) ($item['quoteAsset'] ?? ''),
        'stepSize' => $stepSize,
        'minQty' => $minQty,
        'minNotional' => $minNotional,
    ];
}

function floorToStep(float $qty, string $stepSize): string
{
    $step = (float) $stepSize;
    if ($step <= 0) {
        return rtrim(rtrim(sprintf('%.8f', $qty), '0'), '.') ?: '0';
    }

    $precision = max(0, strlen(rtrim(substr(strrchr($stepSize, '.'), 1) ?: '', '0')));
    $floored = floor($qty / $step + 1e-12) * $step;
    return number_format($floored, $precision, '.', '');
}

function getFreeBalance(string $asset): float
{
    $account = binanceOrFail(binanceRequest('GET', '/api/v3/account', [], true));
    foreach ($account['balances'] ?? [] as $row) {
        if (($row['asset'] ?? '') === $asset) {
            return (float) ($row['free'] ?? 0);
        }
    }
    return 0.0;
}
