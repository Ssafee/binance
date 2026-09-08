<?php
declare(strict_types=1);

/**
 * Persist recent ladder cron runs (last 30) for the cron-log page.
 */

require_once __DIR__ . '/bootstrap.php';

function ladderCronLogPath(): string
{
    return dataDir() . DIRECTORY_SEPARATOR . 'ladder_cron_log.json';
}

/**
 * @return list<array<string, mixed>>
 */
function ladderCronLogLoad(): array
{
    $path = ladderCronLogPath();
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $runs = $data['runs'] ?? $data;
    if (!is_array($runs)) {
        return [];
    }
    $out = [];
    foreach ($runs as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $entry
 */
function ladderCronLogAppend(array $entry): void
{
    $nowMs = (int) round(microtime(true) * 1000);
    $record = [
        'id' => 'R' . base_convert((string) $nowMs, 10, 36) . '-' . substr(bin2hex(random_bytes(2)), 0, 4),
        'at' => $nowMs,
        'atIso' => gmdate('Y-m-d\TH:i:s\Z', (int) floor($nowMs / 1000)),
        'ok' => !empty($entry['ok']),
        'skipped' => !empty($entry['skipped']),
        'dry' => !empty($entry['dry']),
        'mode' => (string) ($entry['mode'] ?? 'live'),
        'actions' => (int) ($entry['actions'] ?? 0),
        'ticks' => (int) ($entry['ticks'] ?? 0),
        'configs' => (int) ($entry['configs'] ?? 0),
        'symbols' => array_values(array_map('strval', is_array($entry['symbols'] ?? null) ? $entry['symbols'] : [])),
        'openEntries' => isset($entry['openEntries']) ? (int) $entry['openEntries'] : null,
        'error' => isset($entry['error']) && $entry['error'] !== '' && $entry['error'] !== null
            ? (string) $entry['error']
            : null,
        'reason' => isset($entry['reason']) ? (string) $entry['reason'] : null,
        'emailSent' => !empty($entry['emailSent']),
        'log' => [],
    ];

    foreach ($entry['log'] ?? [] as $row) {
        if (is_string($row)) {
            $record['log'][] = $row;
        } elseif (is_array($row) && isset($row['msg'])) {
            $record['log'][] = (string) $row['msg'];
        }
        if (count($record['log']) >= 12) {
            break;
        }
    }

    $runs = ladderCronLogLoad();
    array_unshift($runs, $record);
    $runs = array_slice($runs, 0, 30);

    file_put_contents(
        ladderCronLogPath(),
        json_encode(['updatedAt' => $nowMs, 'runs' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}
