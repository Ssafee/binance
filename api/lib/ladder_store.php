<?php
declare(strict_types=1);

/**
 * Storage + math for the "daily buy, sell only at profit" ladder.
 *
 * Two independent stores:
 *   live -> data/ladder_live.json  (real Binance orders)
 *   sim  -> data/ladder_sim.json   (paper trading, no orders)
 *
 * Multiple configs (one per symbol) can run side by side. Each keeps its own
 * daily amount, fee/target rules, and lastBuyDate. Entries always store the
 * symbol they were bought under.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/watch_store.php';
require_once __DIR__ . '/history_store.php';

function ladderModes(): array
{
    return ['live', 'sim'];
}

function ladderNormalizeMode(?string $mode): string
{
    $mode = strtolower(trim((string) $mode));
    return $mode === 'sim' ? 'sim' : 'live';
}

function ladderStatePath(string $mode): string
{
    $mode = ladderNormalizeMode($mode);
    return dataDir() . DIRECTORY_SEPARATOR . 'ladder_' . $mode . '.json';
}

function ladderNewId(): string
{
    return 'L' . base_convert((string) (int) round(microtime(true) * 1000), 10, 36)
        . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function ladderNewConfigId(): string
{
    return 'C' . base_convert((string) (int) round(microtime(true) * 1000), 10, 36)
        . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
}

/**
 * Template values for a new config form — does NOT invent a saved config.
 *
 * @return array<string, mixed>
 */
function ladderDefaultConfig(): array
{
    $symbol = strtoupper((string) env('LADDER_SYMBOL', 'ETHUSDT'));
    return [
        'id' => 'C-' . strtolower($symbol),
        'symbol' => $symbol,
        'dailyUsdt' => (float) env('LADDER_DAILY_USDT', '5'),
        'feePct' => (float) env('LADDER_FEE_PCT', '0.1'),
        'netProfitPct' => (float) env('LADDER_NET_PROFIT_PCT', '0.3'),
        'autoBuyEnabled' => true,
        'autoSellEnabled' => true,
        'lastBuyDate' => null,
    ];
}

/**
 * Fresh state: no configs until the user saves one.
 *
 * @return array<string, mixed>
 */
function ladderDefaultState(): array
{
    return [
        'configs' => [],
        'config' => null,
        'simStartUsdt' => (float) env('LADDER_SIM_START_USDT', '100'),
        'entries' => [],
        'sellDustWait' => [],
        'lastRunAt' => null,
        'lastRunLog' => [],
        'updatedAt' => null,
    ];
}

/**
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function ladderSanitizeConfig(array $raw): array
{
    $defaults = [
        'symbol' => strtoupper((string) env('LADDER_SYMBOL', 'ETHUSDT')),
        'dailyUsdt' => (float) env('LADDER_DAILY_USDT', '5'),
        'feePct' => (float) env('LADDER_FEE_PCT', '0.1'),
        'netProfitPct' => (float) env('LADDER_NET_PROFIT_PCT', '0.3'),
    ];

    $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($raw['symbol'] ?? '')) ?? '');
    if ($symbol === '' || strlen($symbol) > 20) {
        $symbol = $defaults['symbol'];
    }

    $id = trim((string) ($raw['id'] ?? ''));
    // Stable id when missing — random ids on every load broke delete ("Config not found").
    if ($id === '') {
        $id = 'C-' . strtolower($symbol);
    }

    return [
        'id' => $id,
        'symbol' => $symbol,
        'dailyUsdt' => max(0.0, (float) ($raw['dailyUsdt'] ?? $defaults['dailyUsdt'])),
        'feePct' => max(0.0, (float) ($raw['feePct'] ?? $defaults['feePct'])),
        'netProfitPct' => max(0.0, (float) ($raw['netProfitPct'] ?? $defaults['netProfitPct'])),
        'autoBuyEnabled' => true,
        'autoSellEnabled' => true,
        'lastBuyDate' => isset($raw['lastBuyDate']) && $raw['lastBuyDate'] !== null
            ? (string) $raw['lastBuyDate']
            : null,
    ];
}

/**
 * @param array<string, mixed> $state
 * @return list<array<string, mixed>>
 */
function ladderConfigs(array $state): array
{
    // If the multi-config key exists (even as []), trust it — do not resurrect
    // a deleted list from the legacy single `config` mirror.
    if (array_key_exists('configs', $state)) {
        $list = [];
        foreach ($state['configs'] ?? [] as $row) {
            if (is_array($row)) {
                $list[] = ladderSanitizeConfig($row);
            }
        }
        return $list;
    }

    // Legacy files that only have `config`.
    if (is_array($state['config'] ?? null)) {
        return [ladderSanitizeConfig($state['config'])];
    }
    return [];
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>|null
 */
function ladderFindConfig(array $state, ?string $id = null, ?string $symbol = null): ?array
{
    $configs = ladderConfigs($state);
    $hasId = $id !== null && $id !== '';
    $hasSymbol = $symbol !== null && $symbol !== '';

    if ($hasId) {
        foreach ($configs as $cfg) {
            if ((string) $cfg['id'] === $id) {
                return $cfg;
            }
        }
        // Id was requested but not found — do not silently return another config.
        if (!$hasSymbol) {
            return null;
        }
    }

    if ($hasSymbol) {
        $symbol = strtoupper($symbol);
        foreach ($configs as $cfg) {
            if ((string) $cfg['symbol'] === $symbol) {
                return $cfg;
            }
        }
        return null;
    }

    return $configs[0] ?? null;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function ladderUpsertConfig(array $state, array $config): array
{
    $config = ladderSanitizeConfig($config);
    $configs = ladderConfigs($state);
    $found = false;

    foreach ($configs as $i => $existing) {
        if ((string) $existing['id'] === (string) $config['id']
            || (string) $existing['symbol'] === (string) $config['symbol']
        ) {
            // Preserve lastBuyDate unless the caller explicitly set one.
            if ($config['lastBuyDate'] === null) {
                $config['lastBuyDate'] = $existing['lastBuyDate'];
            }
            // Keep stable id when matching by symbol.
            $config['id'] = $existing['id'];
            $configs[$i] = $config;
            $found = true;
            break;
        }
    }

    if (!$found) {
        // Reject duplicate symbols with a different id.
        foreach ($configs as $existing) {
            if ((string) $existing['symbol'] === (string) $config['symbol']) {
                $config['id'] = $existing['id'];
                $config['lastBuyDate'] = $existing['lastBuyDate'];
                // replace
            }
        }
        $replaced = false;
        foreach ($configs as $i => $existing) {
            if ((string) $existing['symbol'] === (string) $config['symbol']) {
                $configs[$i] = $config;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $configs[] = $config;
        }
    }

    $state['configs'] = array_values($configs);
    $state['config'] = $state['configs'][0] ?? null;
    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function ladderDeleteConfig(array $state, string $id): array
{
    $configs = array_values(array_filter(
        ladderConfigs($state),
        static fn($cfg) => (string) $cfg['id'] !== $id
    ));
    $state['configs'] = $configs;
    // Keep legacy mirror in sync; null when the list is empty so delete sticks.
    $state['config'] = $configs[0] ?? null;
    return $state;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function ladderSanitizeEntry(array $row): ?array
{
    $symbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['symbol'] ?? '')) ?? '');
    $qty = (float) ($row['qty'] ?? 0);
    $cost = (float) ($row['costUsdt'] ?? 0);
    if ($symbol === '' || $qty <= 0 || $cost <= 0) {
        return null;
    }

    $status = strtoupper((string) ($row['status'] ?? 'OPEN')) === 'SOLD' ? 'SOLD' : 'OPEN';
    $buyAt = (int) ($row['buyAt'] ?? round(microtime(true) * 1000));

    return [
        'id' => (string) ($row['id'] ?? ladderNewId()),
        'configId' => isset($row['configId']) ? (string) $row['configId'] : null,
        'mode' => ladderNormalizeMode((string) ($row['mode'] ?? 'live')),
        'symbol' => $symbol,
        'status' => $status,
        'buyAt' => $buyAt,
        'buyDate' => (string) ($row['buyDate'] ?? binanceTradeDate($buyAt)),
        'buyPrice' => (float) ($row['buyPrice'] ?? ($qty > 0 ? $cost / $qty : 0)),
        'qty' => $qty,
        'costUsdt' => $cost,
        'feePct' => (float) ($row['feePct'] ?? 0.1),
        'netProfitPct' => (float) ($row['netProfitPct'] ?? 0.3),
        'targetPrice' => (float) ($row['targetPrice'] ?? 0),
        'targetUsdt' => (float) ($row['targetUsdt'] ?? 0),
        'buyOrderId' => $row['buyOrderId'] ?? null,
        'sellAt' => isset($row['sellAt']) && $row['sellAt'] !== null ? (int) $row['sellAt'] : null,
        'sellDate' => isset($row['sellDate']) && $row['sellDate'] !== null ? (string) $row['sellDate'] : null,
        'sellPrice' => isset($row['sellPrice']) && $row['sellPrice'] !== null ? (float) $row['sellPrice'] : null,
        'proceedsUsdt' => isset($row['proceedsUsdt']) && $row['proceedsUsdt'] !== null ? (float) $row['proceedsUsdt'] : null,
        'profitUsdt' => isset($row['profitUsdt']) && $row['profitUsdt'] !== null ? (float) $row['profitUsdt'] : null,
        'profitPct' => isset($row['profitPct']) && $row['profitPct'] !== null ? (float) $row['profitPct'] : null,
        'sellOrderId' => $row['sellOrderId'] ?? null,
        'sellReason' => isset($row['sellReason']) && $row['sellReason'] !== null ? (string) $row['sellReason'] : null,
        'source' => (string) ($row['source'] ?? 'manual'),
        'note' => isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
    ];
}

/**
 * @return array<string, mixed>
 */
function ladderLoadState(string $mode): array
{
    $mode = ladderNormalizeMode($mode);
    $path = ladderStatePath($mode);
    $state = ladderDefaultState();

    if (!is_readable($path)) {
        return $state;
    }

    $raw = file_get_contents($path);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return $state;
    }

    $configs = [];
    if (array_key_exists('configs', $data) && is_array($data['configs'])) {
        foreach ($data['configs'] as $row) {
            if (is_array($row)) {
                $configs[] = ladderSanitizeConfig($row);
            }
        }
        // Empty list is valid (user deleted every config).
    } elseif (is_array($data['config'] ?? null)) {
        // Migrate legacy single-config files.
        $legacy = ladderSanitizeConfig($data['config']);
        if (isset($data['config']['simStartUsdt'])) {
            $state['simStartUsdt'] = max(0.0, (float) $data['config']['simStartUsdt']);
        }
        $configs[] = $legacy;
    }
    // else: leave configs empty — user has not saved any yet.

    // Deduplicate by symbol (keep first).
    $bySymbol = [];
    foreach ($configs as $cfg) {
        $sym = (string) $cfg['symbol'];
        if (!isset($bySymbol[$sym])) {
            $bySymbol[$sym] = $cfg;
        }
    }
    $configs = array_values($bySymbol);

    $state['configs'] = $configs;
    $state['config'] = $configs[0] ?? null;
    if (isset($data['simStartUsdt'])) {
        $state['simStartUsdt'] = max(0.0, (float) $data['simStartUsdt']);
    }

    $entries = [];
    foreach ($data['entries'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $clean = ladderSanitizeEntry($row);
        if ($clean !== null) {
            $clean['mode'] = $mode;
            $entries[] = $clean;
        }
    }
    $state['entries'] = $entries;
    $state['sellDustWait'] = is_array($data['sellDustWait'] ?? null) ? $data['sellDustWait'] : [];
    $state['lastRunAt'] = $data['lastRunAt'] ?? null;
    $state['lastRunLog'] = is_array($data['lastRunLog'] ?? null)
        ? array_slice($data['lastRunLog'], 0, 60)
        : [];
    $state['updatedAt'] = $data['updatedAt'] ?? null;

    return $state;
}

/**
 * Fingerprint of all OPEN lots for a symbol — changes when a new buy adds quantity.
 */
function ladderOpenFingerprint(array $state, string $symbol): string
{
    $symbol = strtoupper($symbol);
    $parts = [];
    foreach (ladderOpenEntries($state) as $entry) {
        if ((string) $entry['symbol'] !== $symbol) {
            continue;
        }
        $parts[] = (string) $entry['id'] . ':' . sprintf('%.8f', (float) $entry['qty']);
    }
    sort($parts);
    return hash('sha256', implode('|', $parts));
}

function ladderClearSellDustWait(array &$state, ?string $symbol = null): void
{
    if (!isset($state['sellDustWait']) || !is_array($state['sellDustWait'])) {
        $state['sellDustWait'] = [];
    }
    if ($symbol === null) {
        $state['sellDustWait'] = [];
        return;
    }
    unset($state['sellDustWait'][strtoupper($symbol)]);
}

function ladderMarkSellDustWait(array &$state, string $symbol): void
{
    $symbol = strtoupper($symbol);
    if (!isset($state['sellDustWait']) || !is_array($state['sellDustWait'])) {
        $state['sellDustWait'] = [];
    }
    $state['sellDustWait'][$symbol] = [
        'fingerprint' => ladderOpenFingerprint($state, $symbol),
        'since' => (int) round(microtime(true) * 1000),
    ];
}

/** True when a prior run found dust too small and open lots have not changed since. */
function ladderIsSellDustWaitActive(array $state, string $symbol): bool
{
    $symbol = strtoupper($symbol);
    $wait = $state['sellDustWait'][$symbol] ?? null;
    if (!is_array($wait) || empty($wait['fingerprint'])) {
        return false;
    }
    return hash_equals((string) $wait['fingerprint'], ladderOpenFingerprint($state, $symbol));
}

/**
 * @param array<string, mixed> $state
 */
function ladderSaveState(string $mode, array $state): void
{
    $state['configs'] = ladderConfigs($state);
    // Never invent a default config on save — that made "Remove" look broken.
    $state['config'] = $state['configs'][0] ?? null;
    $state['updatedAt'] = (int) round(microtime(true) * 1000);
    file_put_contents(
        ladderStatePath($mode),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

/** @return list<string> */
function ladderSymbolsInState(array $state): array
{
    $symbols = [];
    foreach (ladderConfigs($state) as $cfg) {
        $symbols[(string) $cfg['symbol']] = true;
    }
    foreach ($state['entries'] as $entry) {
        if (!empty($entry['symbol'])) {
            $symbols[(string) $entry['symbol']] = true;
        }
    }
    return array_keys($symbols);
}

/* ------------------------------------------------------------------ *
 * Fee / target math
 * ------------------------------------------------------------------ */

function ladderFeeKeep(float $feePct): float
{
    return max(1e-9, 1 - (max(0.0, $feePct) / 100));
}

function ladderTargetPrice(float $costUsdt, float $qty, float $feePct, float $netProfitPct): float
{
    if ($qty <= 0) {
        return 0.0;
    }
    $want = $costUsdt * (1 + max(0.0, $netProfitPct) / 100);
    return $want / ($qty * ladderFeeKeep($feePct));
}

function ladderBreakEvenPrice(float $costUsdt, float $qty, float $feePct): float
{
    return ladderTargetPrice($costUsdt, $qty, $feePct, 0.0);
}

function ladderProceeds(float $qty, float $price, float $feePct): float
{
    return $qty * $price * ladderFeeKeep($feePct);
}

/**
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function ladderWithTargets(array $entry): array
{
    $entry['targetPrice'] = ladderTargetPrice(
        (float) $entry['costUsdt'],
        (float) $entry['qty'],
        (float) $entry['feePct'],
        (float) $entry['netProfitPct']
    );
    $entry['targetUsdt'] = (float) $entry['costUsdt'] * (1 + (float) $entry['netProfitPct'] / 100);
    return $entry;
}

/**
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function ladderDecorateEntry(array $entry, float $price): array
{
    $qty = (float) $entry['qty'];
    $cost = (float) $entry['costUsdt'];
    $fee = (float) $entry['feePct'];

    $entry['breakEvenPrice'] = ladderBreakEvenPrice($cost, $qty, $fee);

    if ($entry['status'] === 'SOLD') {
        $entry['matured'] = false;
        $entry['nowValueUsdt'] = null;
        $entry['nowProfitUsdt'] = null;
        $entry['toTargetPct'] = null;
        $entry['holdDays'] = $entry['sellAt'] !== null
            ? round((((int) $entry['sellAt']) - ((int) $entry['buyAt'])) / 86400000, 2)
            : null;
        return $entry;
    }

    $target = (float) $entry['targetPrice'];
    $entry['holdDays'] = round(((microtime(true) * 1000) - ((int) $entry['buyAt'])) / 86400000, 2);

    if ($price > 0) {
        $value = ladderProceeds($qty, $price, $fee);
        $entry['nowValueUsdt'] = $value;
        $entry['nowProfitUsdt'] = $value - $cost;
        $entry['nowProfitPct'] = $cost > 0 ? (($value - $cost) / $cost) * 100 : null;
        $entry['toTargetPct'] = $target > 0 ? (($target - $price) / $price) * 100 : null;
        $entry['matured'] = $target > 0 && $price >= $target;
    } else {
        $entry['nowValueUsdt'] = null;
        $entry['nowProfitUsdt'] = null;
        $entry['nowProfitPct'] = null;
        $entry['toTargetPct'] = null;
        $entry['matured'] = false;
    }

    return $entry;
}

/**
 * @param array<string, mixed> $state
 * @return list<array<string, mixed>>
 */
function ladderOpenEntries(array $state): array
{
    $out = [];
    foreach ($state['entries'] as $entry) {
        if ($entry['status'] === 'OPEN') {
            $out[] = $entry;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $state
 */
function ladderSimCash(array $state): float
{
    $cash = (float) ($state['simStartUsdt'] ?? 100);
    foreach ($state['entries'] as $entry) {
        $cash -= (float) $entry['costUsdt'];
        if ($entry['status'] === 'SOLD') {
            $cash += (float) ($entry['proceedsUsdt'] ?? 0);
        }
    }
    return $cash;
}

/**
 * Price for one symbol from a price map (or a single float for legacy).
 *
 * @param array<string, float>|float $prices
 */
function ladderPriceFor(array|float $prices, string $symbol): float
{
    if (is_float($prices) || is_int($prices)) {
        return (float) $prices;
    }
    $symbol = strtoupper($symbol);
    return isset($prices[$symbol]) ? (float) $prices[$symbol] : 0.0;
}

/* ------------------------------------------------------------------ *
 * Reporting
 * ------------------------------------------------------------------ */

/**
 * @param array<string, mixed> $state
 * @param array<string, float>|float $prices
 * @return array<string, mixed>
 */
function ladderDashboard(array $state, array|float $prices): array
{
    $openCount = 0;
    $soldCount = 0;
    $investedOpen = 0.0;
    $investedTotal = 0.0;
    $realizedProfit = 0.0;
    $realizedProceeds = 0.0;
    $openValue = 0.0;
    $maturedCount = 0;
    $maturedValue = 0.0;
    $bestProfit = null;
    $worstProfit = null;
    $holdDaysTotal = 0.0;
    $byDay = [];
    $bySymbol = [];
    $pricedOpen = 0.0;
    $pricedOpenCost = 0.0;

    foreach ($state['entries'] as $entry) {
        $cost = (float) $entry['costUsdt'];
        $symbol = (string) $entry['symbol'];
        $investedTotal += $cost;
        $date = (string) $entry['buyDate'];
        $price = ladderPriceFor($prices, $symbol);

        if (!isset($byDay[$date])) {
            $byDay[$date] = [
                'date' => $date,
                'buys' => 0,
                'invested' => 0.0,
                'sold' => 0,
                'profit' => 0.0,
                'openCount' => 0,
            ];
        }
        if (!isset($bySymbol[$symbol])) {
            $bySymbol[$symbol] = [
                'symbol' => $symbol,
                'openCount' => 0,
                'soldCount' => 0,
                'investedOpen' => 0.0,
                'realizedProfit' => 0.0,
                'maturedCount' => 0,
                'price' => $price > 0 ? $price : null,
            ];
        }
        $byDay[$date]['buys']++;
        $byDay[$date]['invested'] += $cost;

        if ($entry['status'] === 'SOLD') {
            $soldCount++;
            $profit = (float) ($entry['profitUsdt'] ?? 0);
            $realizedProfit += $profit;
            $realizedProceeds += (float) ($entry['proceedsUsdt'] ?? 0);
            $byDay[$date]['sold']++;
            $byDay[$date]['profit'] += $profit;
            $bySymbol[$symbol]['soldCount']++;
            $bySymbol[$symbol]['realizedProfit'] += $profit;

            $bestProfit = $bestProfit === null ? $profit : max($bestProfit, $profit);
            $worstProfit = $worstProfit === null ? $profit : min($worstProfit, $profit);

            if ($entry['sellAt'] !== null) {
                $holdDaysTotal += (((int) $entry['sellAt']) - ((int) $entry['buyAt'])) / 86400000;
            }
            continue;
        }

        $openCount++;
        $investedOpen += $cost;
        $byDay[$date]['openCount']++;
        $bySymbol[$symbol]['openCount']++;
        $bySymbol[$symbol]['investedOpen'] += $cost;

        if ($price > 0) {
            $value = ladderProceeds((float) $entry['qty'], $price, (float) $entry['feePct']);
            $openValue += $value;
            $pricedOpen += $value;
            $pricedOpenCost += $cost;
            if ((float) $entry['targetPrice'] > 0 && $price >= (float) $entry['targetPrice']) {
                $maturedCount++;
                $maturedValue += $value;
                $bySymbol[$symbol]['maturedCount']++;
            }
        }
    }

    krsort($byDay);
    ksort($bySymbol);

    $unrealized = $pricedOpenCost > 0 ? $pricedOpen - $pricedOpenCost : null;
    $today = binanceTodayDate();
    $anyDue = false;
    foreach (ladderConfigs($state) as $cfg) {
        if ((string) $cfg['lastBuyDate'] !== $today && ladderIsUtcBuyWindow()) {
            $anyDue = true;
            break;
        }
    }

    return [
        'configs' => count(ladderConfigs($state)),
        'symbols' => ladderSymbolsInState($state),
        'entries' => count($state['entries']),
        'openCount' => $openCount,
        'soldCount' => $soldCount,
        'maturedCount' => $maturedCount,
        'maturedValueUsdt' => $maturedValue,
        'investedOpen' => $investedOpen,
        'investedTotal' => $investedTotal,
        'openValue' => $pricedOpen > 0 ? $openValue : null,
        'unrealized' => $unrealized,
        'unrealizedPct' => $unrealized !== null && $pricedOpenCost > 0
            ? ($unrealized / $pricedOpenCost) * 100
            : null,
        'realizedProfit' => $realizedProfit,
        'realizedProceeds' => $realizedProceeds,
        'realizedPct' => $realizedProceeds - $realizedProfit > 0
            ? ($realizedProfit / ($realizedProceeds - $realizedProfit)) * 100
            : null,
        'totalProfit' => $realizedProfit + ($unrealized ?? 0.0),
        'avgHoldDays' => $soldCount > 0 ? round($holdDaysTotal / $soldCount, 2) : null,
        'bestProfit' => $bestProfit,
        'worstProfit' => $worstProfit,
        'byDay' => array_values($byDay),
        'bySymbol' => array_values($bySymbol),
        'lastRunAt' => $state['lastRunAt'],
        'today' => $today,
        'timezone' => 'UTC (Binance day)',
        'nextBuyAtUtc' => $anyDue
            ? 'due now (UTC 00:00–01:59 window)'
            : ladderNextUtcMidnight(),
        'appTimezone' => appTimezone(),
    ];
}

/**
 * @param array<string, mixed> $state
 * @param array<string, float>|float $prices
 * @return array{rows:list<array<string,mixed>>,page:int,perPage:int,total:int,pages:int}
 */
function ladderPaginate(
    array $state,
    array|float $prices,
    int $page = 1,
    int $perPage = 10,
    string $status = '',
    string $symbolFilter = ''
): array {
    $status = strtoupper(trim($status));
    $symbolFilter = strtoupper(trim($symbolFilter));
    $rows = [];

    foreach ($state['entries'] as $entry) {
        if ($symbolFilter !== '' && (string) $entry['symbol'] !== $symbolFilter) {
            continue;
        }
        if ($status === 'OPEN' && $entry['status'] !== 'OPEN') {
            continue;
        }
        if ($status === 'SOLD' && $entry['status'] !== 'SOLD') {
            continue;
        }
        $price = ladderPriceFor($prices, (string) $entry['symbol']);
        $decorated = ladderDecorateEntry($entry, $price);
        if ($status === 'MATURED' && empty($decorated['matured'])) {
            continue;
        }
        $rows[] = $decorated;
    }

    usort($rows, static function ($a, $b) {
        return ((int) $b['buyAt']) <=> ((int) $a['buyAt']);
    });

    $total = count($rows);
    $perPage = max(1, min(100, $perPage));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $perPage;

    return [
        'rows' => array_slice($rows, $offset, $perPage),
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'pages' => $pages,
        'status' => $status !== '' ? $status : 'ALL',
        'symbol' => $symbolFilter !== '' ? $symbolFilter : 'ALL',
    ];
}
