<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/watch_store.php';
require_once __DIR__ . '/lib/env.php';

function watchRespond(int $status, array $payload): void
{
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

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$raw = file_get_contents('php://input');
$input = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

// Prefer query action so POST never falls through to empty "get" if body is stripped
$action = strtolower((string) ($_GET['action'] ?? $input['action'] ?? 'get'));

if ($action === 'get') {
    $state = loadWatchState();
    watchRespond(200, [
        'ok' => true,
        'state' => $state,
        'cronHint' => 'Set CRON_SECRET in .env and schedule cron/auto_trade.php every minute.',
    ]);
}

if ($action === 'save' || $action === 'sync') {
    if ($method !== 'POST') {
        watchRespond(405, ['ok' => false, 'error' => 'Use POST']);
    }
    $items = $input['items'] ?? [];
    $rules = $input['rules'] ?? [];
    $autoEnabled = array_key_exists('autoEnabled', $input)
        ? !empty($input['autoEnabled'])
        : true;
    $serverAuto = array_key_exists('serverAuto', $input)
        ? !empty($input['serverAuto'])
        : false;

    if (!is_array($items)) {
        watchRespond(400, ['ok' => false, 'error' => 'items must be array']);
    }
    if (!is_array($rules)) {
        $rules = [];
    }

    $state = upsertWatchState($items, $rules, $autoEnabled, $serverAuto);
    watchRespond(200, ['ok' => true, 'state' => $state]);
}

if ($action === 'set-server-auto') {
    if ($method !== 'POST') {
        watchRespond(405, ['ok' => false, 'error' => 'Use POST']);
    }
    $state = loadWatchState();
    $state['serverAuto'] = !empty($input['serverAuto']);
    if (array_key_exists('autoEnabled', $input)) {
        $state['autoEnabled'] = !empty($input['autoEnabled']);
    }
    saveWatchState($state);
    watchRespond(200, ['ok' => true, 'state' => $state]);
}

watchRespond(400, ['ok' => false, 'error' => 'Unknown action']);
