<?php
declare(strict_types=1);

function dataDir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function watchStatePath(): string
{
    return dataDir() . DIRECTORY_SEPARATOR . 'watch_state.json';
}

function autoLockPath(): string
{
    return dataDir() . DIRECTORY_SEPARATOR . 'auto.lock';
}

function cronLogPath(): string
{
    return dataDir() . DIRECTORY_SEPARATOR . 'cron_log.json';
}

/**
 * Append a cron run row (keeps last 200).
 *
 * @param array<string, mixed> $row
 */
function appendCronLog(array $row): void
{
    $path = cronLogPath();
    $rows = [];
    if (is_readable($path)) {
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $rows = $decoded;
        }
    }
    array_unshift($rows, $row);
    if (count($rows) > 200) {
        $rows = array_slice($rows, 0, 200);
    }
    file_put_contents(
        $path,
        json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * @return array<string, mixed>
 */
function defaultWatchState(): array
{
    return [
        'autoEnabled' => true,
        'serverAuto' => false,
        'rules' => [
            'buyDrop' => 0.1,
            'feePct' => 0.1,
            'netProfitPct' => 0.3,
            'buyUsdt' => 6.0,
        ],
        'items' => [],
        'lastRunAt' => null,
        'lastRunOk' => null,
        'lastRunLog' => [],
        'updatedAt' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function loadWatchState(): array
{
    $path = watchStatePath();
    if (!is_readable($path)) {
        return defaultWatchState();
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return defaultWatchState();
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultWatchState();
    }
    $base = defaultWatchState();
    $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
    $items = [];
    foreach ($data['items'] ?? [] as $item) {
        if (!is_array($item) || empty($item['symbol'])) {
            continue;
        }
        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $item['symbol']) ?? '');
        $basePrice = (float) ($item['basePrice'] ?? 0);
        if ($symbol === '' || $basePrice <= 0) {
            continue;
        }
        $items[] = [
            'symbol' => $symbol,
            'basePrice' => $basePrice,
            'holding' => !empty($item['holding']),
            'addedAt' => (int) ($item['addedAt'] ?? round(microtime(true) * 1000)),
            'lastSignal' => (string) ($item['lastSignal'] ?? 'WAIT'),
            'lastActionAt' => isset($item['lastActionAt']) ? (int) $item['lastActionAt'] : null,
            'lastError' => isset($item['lastError']) ? (string) $item['lastError'] : null,
        ];
    }

    return [
        'autoEnabled' => !empty($data['autoEnabled']),
        'serverAuto' => !empty($data['serverAuto']),
        'rules' => [
            'buyDrop' => max(0.0, (float) ($rules['buyDrop'] ?? $base['rules']['buyDrop'])),
            'feePct' => max(0.0, (float) ($rules['feePct'] ?? $base['rules']['feePct'])),
            'netProfitPct' => max(0.0, (float) ($rules['netProfitPct'] ?? $base['rules']['netProfitPct'])),
            'buyUsdt' => max(5.0, (float) ($rules['buyUsdt'] ?? $base['rules']['buyUsdt'])),
        ],
        'items' => $items,
        'lastRunAt' => $data['lastRunAt'] ?? null,
        'lastRunOk' => $data['lastRunOk'] ?? null,
        'lastRunLog' => is_array($data['lastRunLog'] ?? null) ? array_slice($data['lastRunLog'], 0, 30) : [],
        'updatedAt' => $data['updatedAt'] ?? null,
    ];
}

/**
 * @param array<string, mixed> $state
 */
function saveWatchState(array $state): void
{
    $state['updatedAt'] = (int) round(microtime(true) * 1000);
    file_put_contents(
        watchStatePath(),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed> $rules
 * @return array<string, mixed>
 */
function upsertWatchState(array $items, array $rules, bool $autoEnabled, bool $serverAuto): array
{
    $state = loadWatchState();
    $state['autoEnabled'] = $autoEnabled;
    $state['serverAuto'] = $serverAuto;
    $state['rules'] = [
        'buyDrop' => max(0.0, (float) ($rules['buyDrop'] ?? 0.1)),
        'feePct' => max(0.0, (float) ($rules['feePct'] ?? 0.1)),
        'netProfitPct' => max(0.0, (float) ($rules['netProfitPct'] ?? 0.3)),
        'buyUsdt' => max(5.0, (float) ($rules['buyUsdt'] ?? 6)),
    ];

    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['symbol'])) {
            continue;
        }
        $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $item['symbol']) ?? '');
        $basePrice = (float) ($item['basePrice'] ?? 0);
        if ($symbol === '' || $basePrice <= 0) {
            continue;
        }
        $clean[] = [
            'symbol' => $symbol,
            'basePrice' => $basePrice,
            'holding' => !empty($item['holding']),
            'addedAt' => (int) ($item['addedAt'] ?? round(microtime(true) * 1000)),
            'lastSignal' => (string) ($item['lastSignal'] ?? 'WAIT'),
            'lastActionAt' => isset($item['lastActionAt']) ? (int) $item['lastActionAt'] : null,
            'lastError' => isset($item['lastError']) ? (string) $item['lastError'] : null,
        ];
    }
    $state['items'] = $clean;
    saveWatchState($state);
    return $state;
}

function acquireAutoLock(int $ttlSeconds = 50): bool
{
    $path = autoLockPath();
    if (is_file($path)) {
        $age = time() - (int) filemtime($path);
        if ($age < $ttlSeconds) {
            return false;
        }
    }
    return file_put_contents($path, (string) getmypid(), LOCK_EX) !== false;
}

function releaseAutoLock(): void
{
    $path = autoLockPath();
    if (is_file($path)) {
        @unlink($path);
    }
}
