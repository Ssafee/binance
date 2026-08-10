<?php
declare(strict_types=1);

require_once __DIR__ . '/binance.php';
require_once __DIR__ . '/history_store.php';
require_once __DIR__ . '/watch_store.php';

/**
 * When false, the cron/auto engine will never open new positions —
 * it only manages (sells) symbols that are already marked `holding`.
 * Set CRON_AUTO_BUY=0 in .env to enable "sell-only" mode.
 */
function cronAutoBuyEnabled(): bool
{
    $raw = strtolower(trim((string) env('CRON_AUTO_BUY', '1')));
    return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
}

/**
 * @param array<string, mixed> $rules
 * @return array{breakevenPct:float,sellTriggerPct:float}
 */
function computeSellRules(array $rules): array
{
    $feePct = max(0.0, (float) ($rules['feePct'] ?? 0.1));
    $netProfitPct = max(0.0, (float) ($rules['netProfitPct'] ?? 0.3));
    $fee = $feePct / 100;
    $keep = max(1e-9, 1 - $fee);
    $breakevenMult = 1 / ($keep * $keep);
    $targetMult = (1 + $netProfitPct / 100) * $breakevenMult;
    return [
        'breakevenPct' => ($breakevenMult - 1) * 100,
        'sellTriggerPct' => ($targetMult - 1) * 100,
    ];
}

/**
 * @return array{ok:bool,price?:float,error?:string}
 */
function fetchTickerPrice(string $symbol): array
{
    try {
        $row = binanceOrFail(binanceRequest('GET', '/api/v3/ticker/price', ['symbol' => $symbol]));
        $price = (float) ($row['price'] ?? 0);
        if ($price <= 0) {
            return ['ok' => false, 'error' => 'Invalid price'];
        }
        return ['ok' => true, 'price' => $price];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return array{ok:bool,order?:array,entry?:float,error?:string}
 */
function serverMarketBuy(string $symbol, float $quoteQty): array
{
    try {
        $meta = getSymbolMeta($symbol);
        $usdtFree = getFreeBalance('USDT');
        $minBuy = max((float) $meta['minNotional'], 5.0);
        if ($quoteQty < $minBuy) {
            return ['ok' => false, 'error' => 'Buy at least ' . $minBuy . ' USDT'];
        }
        if ($quoteQty > $usdtFree + 1e-8) {
            return ['ok' => false, 'error' => 'Insufficient USDT (' . $usdtFree . ')'];
        }

        $order = binanceOrFail(binanceRequest('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'BUY',
            'type' => 'MARKET',
            'quoteOrderQty' => rtrim(rtrim(sprintf('%.8f', $quoteQty), '0'), '.') ?: '0',
            'newOrderRespType' => 'FULL',
        ], true));

        appendTradeRecord(normalizeOrderRecord('BUY', $symbol, $order, 'cron'));

        $qty = (float) ($order['executedQty'] ?? 0);
        $quote = (float) ($order['cummulativeQuoteQty'] ?? $quoteQty);
        $entry = $qty > 0 && $quote > 0 ? $quote / $qty : 0.0;

        return ['ok' => true, 'order' => $order, 'entry' => $entry];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @return array{ok:bool,order?:array,error?:string}
 */
function serverMarketSellAll(string $symbol): array
{
    try {
        $meta = getSymbolMeta($symbol);
        $free = getFreeBalance($meta['baseAsset']);
        $qtyStr = floorToStep($free, $meta['stepSize']);
        $qtyNum = (float) $qtyStr;
        if ($qtyNum <= 0 || $qtyNum < (float) $meta['minQty']) {
            return ['ok' => false, 'error' => 'No sellable balance'];
        }

        $priceRow = binanceOrFail(binanceRequest('GET', '/api/v3/ticker/price', ['symbol' => $symbol]));
        $lastPrice = (float) ($priceRow['price'] ?? 0);
        $notional = $qtyNum * $lastPrice;
        $minNotional = (float) $meta['minNotional'];
        if ($lastPrice > 0 && $notional < $minNotional) {
            return [
                'ok' => false,
                'error' => sprintf('NOTIONAL too small (~%.4f < %.2f USDT)', $notional, $minNotional),
            ];
        }

        $order = binanceOrFail(binanceRequest('POST', '/api/v3/order', [
            'symbol' => $symbol,
            'side' => 'SELL',
            'type' => 'MARKET',
            'quantity' => $qtyStr,
            'newOrderRespType' => 'FULL',
        ], true));

        appendTradeRecord(normalizeOrderRecord('SELL', $symbol, $order, 'cron'));
        return ['ok' => true, 'order' => $order];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Run one auto-trade pass for all watch items.
 *
 * @param array{manageLock?:bool,quiet?:bool} $opts
 * @return array<string, mixed>
 */
function runServerAutoPass(array $opts = []): array
{
    $manageLock = !array_key_exists('manageLock', $opts) || !empty($opts['manageLock']);
    $quiet = !empty($opts['quiet']);
    $state = loadWatchState();
    $log = [];
    $actions = 0;
    $nowMs = (int) round(microtime(true) * 1000);
    // Short cooldown so 5s ticks can still sell after a buy in same minute
    $cooldownMs = 8000;

    if (empty($state['serverAuto']) || empty($state['autoEnabled'])) {
        $state['lastRunAt'] = $nowMs;
        $state['lastRunOk'] = true;
        if (!$quiet) {
            $state['lastRunLog'] = array_slice(array_merge([
                ['t' => $nowMs, 'msg' => 'Server auto OFF — skipped'],
            ], $state['lastRunLog'] ?? []), 0, 40);
            saveWatchState($state);
        }
        return ['ok' => true, 'skipped' => true, 'log' => $state['lastRunLog'] ?? [], 'state' => $state];
    }

    if ($manageLock && !acquireAutoLock(70)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'locked', 'state' => $state];
    }

    try {
        $rules = $state['rules'];
        $sell = computeSellRules($rules);
        $buyDrop = (float) $rules['buyDrop'];
        $buyUsdt = (float) $rules['buyUsdt'];
        $sellTrigger = (float) $sell['sellTriggerPct'];
        $autoBuyEnabled = cronAutoBuyEnabled();

        $usdtFree = 0.0;
        try {
            $usdtFree = getFreeBalance('USDT');
        } catch (Throwable $e) {
            $log[] = ['t' => $nowMs, 'msg' => 'Balance error: ' . $e->getMessage()];
            $state['lastRunAt'] = $nowMs;
            $state['lastRunOk'] = false;
            $state['lastRunLog'] = array_slice(array_merge($log, $state['lastRunLog'] ?? []), 0, 40);
            saveWatchState($state);
            return ['ok' => false, 'error' => $e->getMessage(), 'log' => $log, 'state' => $state];
        }

        foreach ($state['items'] as &$item) {
            $symbol = $item['symbol'];
            $base = (float) $item['basePrice'];
            if ($base <= 0) {
                continue;
            }

            $lastAction = (int) ($item['lastActionAt'] ?? 0);
            if ($lastAction > 0 && ($nowMs - $lastAction) < $cooldownMs) {
                continue;
            }

            $px = fetchTickerPrice($symbol);
            if (!$px['ok']) {
                $item['lastError'] = $px['error'] ?? 'price fail';
                $log[] = ['t' => $nowMs, 'symbol' => $symbol, 'msg' => 'Price fail: ' . $item['lastError']];
                continue;
            }

            $price = (float) $px['price'];
            $changePct = (($price - $base) / $base) * 100;
            $holding = !empty($item['holding']);

            if (!$holding) {
                if (!$autoBuyEnabled) {
                    $item['lastSignal'] = 'WAIT';
                    if (!$quiet) {
                        $log[] = [
                            't' => $nowMs,
                            'symbol' => $symbol,
                            'msg' => sprintf('Auto-buy OFF (sell-only mode) price=%s chg=%+.3f%%', $price, $changePct),
                        ];
                    }
                    continue;
                }
                $buyHit = $buyDrop === 0.0 ? $changePct < 0 : $changePct <= -$buyDrop;
                if ($buyHit) {
                    if ($usdtFree < $buyUsdt - 1e-8) {
                        $item['lastSignal'] = 'BUY';
                        $item['lastError'] = 'USDT low';
                        $log[] = ['t' => $nowMs, 'symbol' => $symbol, 'msg' => 'BUY signal, USDT low'];
                        continue;
                    }
                    $buy = serverMarketBuy($symbol, $buyUsdt);
                    if ($buy['ok']) {
                        $entry = (float) ($buy['entry'] ?? 0);
                        if ($entry <= 0) {
                            $entry = $price;
                        }
                        $item['basePrice'] = $entry;
                        $item['holding'] = true;
                        $item['lastSignal'] = 'WAIT';
                        $item['lastActionAt'] = $nowMs;
                        $item['lastError'] = null;
                        $usdtFree = max(0.0, $usdtFree - $buyUsdt);
                        $actions++;
                        $log[] = [
                            't' => $nowMs,
                            'symbol' => $symbol,
                            'msg' => 'BUY filled @ ' . $entry . ' · ' . $buyUsdt . ' USDT',
                        ];
                    } else {
                        $item['lastError'] = $buy['error'] ?? 'buy fail';
                        $log[] = ['t' => $nowMs, 'symbol' => $symbol, 'msg' => 'BUY fail: ' . $item['lastError']];
                    }
                } else {
                    $item['lastSignal'] = 'WAIT';
                    if (!$quiet) {
                        $log[] = [
                            't' => $nowMs,
                            'symbol' => $symbol,
                            'msg' => sprintf('FLAT price=%s chg=%+.3f%%', $price, $changePct),
                        ];
                    }
                }
                continue;
            }

            if ($changePct >= $sellTrigger) {
                $sellRes = serverMarketSellAll($symbol);
                if ($sellRes['ok']) {
                    $item['holding'] = false;
                    $item['basePrice'] = $price;
                    $item['lastSignal'] = 'WAIT';
                    $item['lastActionAt'] = $nowMs;
                    $item['lastError'] = null;
                    $actions++;
                    $log[] = [
                        't' => $nowMs,
                        'symbol' => $symbol,
                        'msg' => 'SELL filled · rebase ' . $price,
                    ];
                    try {
                        $usdtFree = getFreeBalance('USDT');
                    } catch (Throwable $e) {
                        // ignore
                    }
                } else {
                    $item['lastError'] = $sellRes['error'] ?? 'sell fail';
                    $log[] = ['t' => $nowMs, 'symbol' => $symbol, 'msg' => 'SELL fail: ' . $item['lastError']];
                }
            } else {
                $item['lastSignal'] = 'SELL';
                if (!$quiet) {
                    $log[] = [
                        't' => $nowMs,
                        'symbol' => $symbol,
                        'msg' => sprintf('HOLD price=%s chg=%+.3f%% need=+%.3f%%', $price, $changePct, $sellTrigger),
                    ];
                }
            }
        }
        unset($item);

        $state['lastRunAt'] = $nowMs;
        $state['lastRunOk'] = true;
        if ($log !== []) {
            $state['lastRunLog'] = array_slice(array_merge($log, $state['lastRunLog'] ?? []), 0, 40);
        }
        saveWatchState($state);

        return [
            'ok' => true,
            'actions' => $actions,
            'log' => $log,
            'state' => $state,
        ];
    } finally {
        if ($manageLock) {
            releaseAutoLock();
        }
    }
}

/**
 * One cPanel cron minute: poll every N seconds for ~55s (more spot chances).
 *
 * @return array<string, mixed>
 */
function runServerAutoLoop(): array
{
    @set_time_limit(90);
    @ignore_user_abort(true);

    $tickSec = max(3, (int) env('CRON_TICK_SECONDS', '5'));
    $loopSec = max($tickSec, (int) env('CRON_LOOP_SECONDS', '55'));
    $started = time();
    $ticks = 0;
    $totalActions = 0;
    $allLogs = [];
    $lastState = loadWatchState();

    if (empty($lastState['serverAuto']) || empty($lastState['autoEnabled'])) {
        $pass = runServerAutoPass(['manageLock' => true, 'quiet' => false]);
        appendCronLog([
            't' => (int) round(microtime(true) * 1000),
            'ticks' => 0,
            'actions' => 0,
            'skipped' => true,
            'msg' => 'Server auto OFF',
        ]);
        return $pass;
    }

    if (!acquireAutoLock(70)) {
        appendCronLog([
            't' => (int) round(microtime(true) * 1000),
            'ticks' => 0,
            'actions' => 0,
            'skipped' => true,
            'msg' => 'Locked — previous minute still running',
        ]);
        return ['ok' => true, 'skipped' => true, 'reason' => 'locked', 'state' => $lastState];
    }

    try {
        while (true) {
            $ticks++;
            // First tick verbose; later ticks quiet unless trade/error
            $pass = runServerAutoPass([
                'manageLock' => false,
                'quiet' => $ticks > 1,
            ]);
            $lastState = $pass['state'] ?? $lastState;
            $actions = (int) ($pass['actions'] ?? 0);
            $totalActions += $actions;
            foreach ($pass['log'] ?? [] as $row) {
                $allLogs[] = $row;
            }

            $elapsed = time() - $started;
            if ($elapsed + $tickSec >= $loopSec) {
                break;
            }
            sleep($tickSec);
        }
    } finally {
        releaseAutoLock();
    }

    $summary = [
        't' => (int) round(microtime(true) * 1000),
        'ticks' => $ticks,
        'actions' => $totalActions,
        'tickSec' => $tickSec,
        'loopSec' => $loopSec,
        'items' => count($lastState['items'] ?? []),
        'log' => array_slice($allLogs, 0, 40),
        'msg' => sprintf('%d ticks × %ds · %d trade action(s)', $ticks, $tickSec, $totalActions),
    ];
    appendCronLog($summary);

    $lastState['lastRunLog'] = array_slice(array_merge([
        ['t' => $summary['t'], 'msg' => $summary['msg']],
    ], $allLogs, $lastState['lastRunLog'] ?? []), 0, 40);
    $lastState['lastRunAt'] = $summary['t'];
    $lastState['lastRunOk'] = true;
    saveWatchState($lastState);

    return [
        'ok' => true,
        'actions' => $totalActions,
        'ticks' => $ticks,
        'tickSec' => $tickSec,
        'loopSec' => $loopSec,
        'log' => $allLogs,
        'state' => $lastState,
    ];
}
