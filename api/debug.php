<?php
/**
 * Open in browser: /api/debug.php
 * Shows PHP version + whether APIs can boot.
 */
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$checks = [
    'php' => PHP_VERSION,
    'app_debug' => defined('APP_DEBUG') ? APP_DEBUG : null,
    'curl' => function_exists('curl_init'),
    'json' => function_exists('json_encode'),
    'env_readable' => is_readable(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env'),
    'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data',
    'data_writable' => is_dir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data')
        ? is_writable(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data')
        : false,
];

try {
    require_once __DIR__ . '/lib/env.php';
    $checks['env_loaded'] = true;
    $checks['has_api_key'] = trim((string) env('BINANCE_API_KEY', '')) !== '';
} catch (Throwable $e) {
    $checks['env_loaded'] = false;
    $checks['env_error'] = $e->getMessage();
}

try {
    require_once __DIR__ . '/lib/watch_store.php';
    $state = loadWatchState();
    $checks['watch_items'] = count($state['items'] ?? []);
    $checks['watch_ok'] = true;
} catch (Throwable $e) {
    $checks['watch_ok'] = false;
    $checks['watch_error'] = $e->getMessage();
}

// This is the IP Binance actually sees when this server calls its API —
// whitelist THIS one on the Binance API key, not your local/browser IP.
$checks['outbound_ip'] = null;
if (function_exists('curl_init')) {
    $ch = curl_init('https://api.ipify.org?format=text');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $ip = curl_exec($ch);
    if (is_string($ip) && $ip !== '' && curl_errno($ch) === 0) {
        $checks['outbound_ip'] = trim($ip);
    }
    curl_close($ch);
}

echo json_encode(['ok' => true, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
