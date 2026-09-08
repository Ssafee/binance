<?php
declare(strict_types=1);

/**
 * Persist recent ladder cron runs (last 30) for the cron-log page.
 * Self-contained path helper — does not depend on other stores loading first.
 */

require_once __DIR__ . '/bootstrap.php';

function ladderCronDataDir(): string
{
    if (function_exists('dataDir')) {
        return dataDir();
    }
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function ladderCronLogPath(): string
{
    return ladderCronDataDir() . DIRECTORY_SEPARATOR . 'ladder_cron_log.json';
}

function ladderCronHeartbeatPath(): string
{
    return ladderCronDataDir() . DIRECTORY_SEPARATOR . 'ladder_cron_heartbeat.txt';
}

/** Touch a simple heartbeat so we can see cron fired even if JSON log fails. */
function ladderCronHeartbeat(string $note = ''): void
{
    $line = gmdate('Y-m-d H:i:s') . ' UTC'
        . ($note !== '' ? ' | ' . $note : '')
        . "\n";
    @file_put_contents(ladderCronHeartbeatPath(), $line, LOCK_EX);
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
    try {
        ladderCronHeartbeat($entry['reason'] ?? ($entry['error'] ?? 'run'));

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

        $ok = @file_put_contents(
            ladderCronLogPath(),
            json_encode(['updatedAt' => $nowMs, 'runs' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        if ($ok === false) {
            ladderCronHeartbeat('WRITE FAIL ladder_cron_log.json — check data/ permissions');
        }
    } catch (Throwable $e) {
        ladderCronHeartbeat('log append error: ' . $e->getMessage());
    }
}
