<?php
declare(strict_types=1);

/**
 * JSON API for the ladder (live) and simulator (sim).
 * Supports multiple configs (one per symbol).
 */

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ladder_store.php';
require_once __DIR__ . '/lib/ladder_engine.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$input = $_POST;
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = array_merge($input, $decoded);
    }
}

$action = strtolower(trim((string) ($_GET['action'] ?? $input['action'] ?? 'state')));
$mode = ladderNormalizeMode((string) ($_GET['mode'] ?? $input['mode'] ?? 'live'));
$isPost = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';

function ladderParamFloat(array $input, string $key): ?float
{
    $value = $_GET[$key] ?? $input[$key] ?? null;
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

/**
 * Collect a symbol=>price map from the request.
 * Accepts: prices{SYM:n}, price (single, applied to selected symbol), or live fetch.
 *
 * @return array<string, float>
 */
function ladderRequestPrices(string $mode, array $state, array $input): array
{
    $prices = [];

    if (is_array($input['prices'] ?? null)) {
        foreach ($input['prices'] as $sym => $px) {
            $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $sym) ?? '');
            $px = (float) $px;
            if ($sym !== '' && $px > 0) {
                $prices[$sym] = $px;
            }
        }
    }

    $single = ladderParamFloat($input, 'price');
    $focus = strtoupper(trim((string) ($_GET['symbol'] ?? $input['symbol'] ?? $input['configSymbol'] ?? '')));
    if ($single !== null && $single > 0) {
        if ($focus !== '') {
            $prices[$focus] = $single;
        } else {
            foreach (ladderConfigs($state) as $cfg) {
                if (!isset($prices[(string) $cfg['symbol']])) {
                    $prices[(string) $cfg['symbol']] = $single;
                }
            }
        }
    }

    if ($mode === 'live') {
        foreach (ladderSymbolsInState($state) as $symbol) {
            if (isset($prices[$symbol]) && $prices[$symbol] > 0) {
                continue;
            }
            $live = ladderPrice($symbol);
            if (!empty($live['ok'])) {
                $prices[$symbol] = (float) $live['price'];
            }
        }
    }

    return $prices;
}

if ($action === 'status') {
    respond(200, [
        'ok' => true,
        'authed' => ladderIsAuthed(),
        'passwordConfigured' => ladderPasswordConfigured(),
    ]);
}

if ($action === 'login') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST to sign in.']);
    }
    if (!ladderPasswordConfigured()) {
        respond(400, [
            'ok' => false,
            'error' => 'No password set. Add LADDER_PASSWORD to your .env file first.',
        ]);
    }
    $password = (string) ($input['password'] ?? '');
    if (!ladderLogin($password)) {
        usleep(400000);
        respond(401, ['ok' => false, 'error' => 'Wrong password.']);
    }
    respond(200, ['ok' => true, 'authed' => true]);
}

if ($action === 'logout') {
    ladderLogout();
    respond(200, ['ok' => true, 'authed' => false]);
}

ladderRequireApiAuth();

$state = ladderLoadState($mode);

/**
 * @param array<string, float> $prices
 * @param array<string, mixed> $input
 * @param array<string, mixed> $extra
 */
function ladderPayload(string $mode, array $state, array $prices, array $input = [], array $extra = []): array
{
    $page = (int) ($_GET['page'] ?? $input['page'] ?? 1);
    $perPage = (int) ($_GET['perPage'] ?? $input['perPage'] ?? 10);
    $status = (string) ($_GET['status'] ?? $input['status'] ?? '');
    $symbolFilter = (string) ($_GET['symbolFilter'] ?? $input['symbolFilter'] ?? '');

    $configs = ladderConfigs($state);
    $payload = [
        'ok' => true,
        'mode' => $mode,
        'configs' => $configs,
        // Legacy single-config mirror (first config) for older UI bits.
        'config' => $configs[0] ?? null,
        'prices' => $prices,
        'dashboard' => ladderDashboard($state, $prices),
        'page' => ladderPaginate($state, $prices, $page, $perPage, $status, $symbolFilter),
        'lastRunAt' => $state['lastRunAt'],
        'lastRunLog' => array_slice($state['lastRunLog'] ?? [], 0, 12),
        'simStartUsdt' => (float) ($state['simStartUsdt'] ?? 100),
    ];

    if ($mode === 'sim') {
        $payload['simCash'] = ladderSimCash($state);
        $payload['apiConnected'] = null;
        $payload['apiStatus'] = 'simulation';
    } else {
        $balance = ladderFreeBalance('USDT');
        $connected = !empty($balance['ok']);
        $payload['usdtFree'] = $connected ? (float) $balance['free'] : null;
        $payload['balanceError'] = $connected ? null : ($balance['error'] ?? null);
        $payload['apiConnected'] = $connected;
        $payload['apiStatus'] = $connected ? 'connected' : 'not connected';
    }

    return array_merge($payload, $extra);
}

/* ---------------- read ---------------- */

if ($action === 'state' || $action === 'entries') {
    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input));
}

/* ---------------- config upsert / delete ---------------- */

if ($action === 'config-save' || $action === 'config-upsert') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }

    $incoming = is_array($input['config'] ?? null) ? $input['config'] : $input;
    $next = ladderSanitizeConfig($incoming);
    $next['autoBuyEnabled'] = true;
    $next['autoSellEnabled'] = true;

    $warnings = [];
    // In sim mode skip live exchange checks if they fail; still try.
    $metaRes = ladderSymbolMeta($next['symbol']);
    if (empty($metaRes['ok'])) {
        if ($mode === 'live') {
            respond(400, ['ok' => false, 'error' => $metaRes['error'] ?? 'Symbol check failed.']);
        }
        $warnings[] = 'Could not verify ' . $next['symbol'] . ' on Binance: ' . ($metaRes['error'] ?? '');
    } else {
        $meta = $metaRes['meta'];
        if (($meta['status'] ?? '') !== 'TRADING') {
            $warnings[] = $next['symbol'] . ' is not currently TRADING on Binance.';
        }
        if ($mode === 'live' && $next['dailyUsdt'] > 0 && $next['dailyUsdt'] < (float) $meta['minNotional']) {
            $warnings[] = sprintf(
                'Daily amount %.2f USDT is below the Binance minimum of %.2f for %s.',
                $next['dailyUsdt'],
                (float) $meta['minNotional'],
                $next['symbol']
            );
        }
    }

    if (isset($input['simStartUsdt']) && is_numeric($input['simStartUsdt'])) {
        $state['simStartUsdt'] = max(0.0, (float) $input['simStartUsdt']);
    }

    $state = ladderUpsertConfig($state, $next);
    ladderSaveState($mode, $state);

    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'saved' => true,
        'savedConfig' => ladderFindConfig($state, null, $next['symbol']),
        'warnings' => $warnings,
        'message' => 'Saved config for ' . $next['symbol'],
    ]));
}

if ($action === 'config-delete') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }
    $id = (string) ($input['id'] ?? $input['configId'] ?? '');
    if ($id === '') {
        respond(400, ['ok' => false, 'error' => 'Provide config id.']);
    }

    $cfg = ladderFindConfig($state, $id, null);
    if ($cfg === null) {
        respond(404, ['ok' => false, 'error' => 'Config not found.']);
    }

    $openForSymbol = 0;
    foreach (ladderOpenEntries($state) as $entry) {
        if ((string) $entry['symbol'] === (string) $cfg['symbol']) {
            $openForSymbol++;
        }
    }
    if ($openForSymbol > 0 && empty($input['force'])) {
        respond(400, [
            'ok' => false,
            'error' => sprintf(
                '%s still has %d open entries. Sell them first, or confirm force-delete.',
                $cfg['symbol'],
                $openForSymbol
            ),
            'openEntries' => $openForSymbol,
        ]);
    }

    $state = ladderDeleteConfig($state, $id);
    ladderSaveState($mode, $state);
    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'message' => 'Removed config ' . $cfg['symbol'],
        'deletedId' => $id,
    ]));
}

/* ---------------- manual buy ---------------- */

if ($action === 'buy-now') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }

    $configId = (string) ($input['configId'] ?? $input['id'] ?? '');
    $symbol = strtoupper(trim((string) ($input['symbol'] ?? '')));
    $config = ladderFindConfig($state, $configId !== '' ? $configId : null, $symbol !== '' ? $symbol : null);
    if ($config === null) {
        respond(400, ['ok' => false, 'error' => 'No config found. Add a configuration first.']);
    }

    $usdt = ladderParamFloat($input, 'usdt');
    if ($usdt === null || $usdt <= 0) {
        $usdt = (float) $config['dailyUsdt'];
    }

    $prices = ladderRequestPrices($mode, $state, $input);
    $override = $prices[(string) $config['symbol']] ?? null;
    if ($mode === 'sim' && ($override === null || $override <= 0)) {
        respond(400, ['ok' => false, 'error' => 'Enter a Test price for ' . $config['symbol'] . '.']);
    }

    $res = ladderExecuteBuy($mode, $state, $usdt, 'manual', $override, $config);
    if (empty($res['ok'])) {
        respond(400, ['ok' => false, 'error' => $res['error'] ?? 'Buy failed.']);
    }

    ladderSaveState($mode, $state);
    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'bought' => $res['entry'],
        'message' => sprintf(
            '%s buy: %.2f USDT of %s @ %s',
            $mode === 'sim' ? 'Simulated' : 'Live',
            (float) $res['entry']['costUsdt'],
            $res['entry']['symbol'],
            rtrim(rtrim(sprintf('%.8f', (float) $res['entry']['buyPrice']), '0'), '.')
        ),
    ]));
}

/* ---------------- sell ---------------- */

if ($action === 'sell') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }

    $ids = [];
    if (isset($input['id'])) {
        $ids[] = (string) $input['id'];
    }
    if (is_array($input['ids'] ?? null)) {
        foreach ($input['ids'] as $id) {
            $ids[] = (string) $id;
        }
    }
    if ($ids === []) {
        respond(400, ['ok' => false, 'error' => 'Provide the entry id to sell.']);
    }

    $entrySymbol = null;
    foreach ($state['entries'] as $entry) {
        if (in_array((string) $entry['id'], $ids, true)) {
            $entrySymbol = (string) $entry['symbol'];
            break;
        }
    }

    $prices = ladderRequestPrices($mode, $state, $input);
    $override = $entrySymbol !== null ? ($prices[$entrySymbol] ?? null) : null;
    if ($mode === 'sim' && ($override === null || $override <= 0)) {
        respond(400, ['ok' => false, 'error' => 'Enter a Test price for ' . ($entrySymbol ?? 'this symbol') . '.']);
    }

    $force = !empty($input['force']);
    $res = ladderExecuteSell($mode, $state, $ids, 'manual', $override, $force);
    if (empty($res['ok'])) {
        respond(400, [
            'ok' => false,
            'error' => $res['error'] ?? 'Sell failed.',
            'wouldLose' => !empty($res['wouldLose']),
            'belowNotional' => !empty($res['belowNotional']),
        ]);
    }

    ladderSaveState($mode, $state);
    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'sold' => $res['sold'],
        'message' => sprintf(
            'Sold %d entry(s) for %.4f USDT (%+.4f profit)',
            (int) $res['count'],
            (float) $res['proceeds'],
            (float) $res['profit']
        ),
    ]));
}

if ($action === 'sell-matured') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }

    $prices = ladderRequestPrices($mode, $state, $input);
    if ($prices === []) {
        respond(400, ['ok' => false, 'error' => 'No prices available.']);
    }

    $res = ladderSweepMaturedAll($mode, $state, $prices, true);
    if (empty($res['ok']) && empty($res['sold'])) {
        respond(400, ['ok' => false, 'error' => $res['error'] ?? 'Sweep failed.']);
    }

    ladderSaveState($mode, $state);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'sweep' => $res,
        'message' => $res['msg'] ?? 'Nothing to sell.',
    ]));
}

/* ---------------- automation pass ---------------- */

if ($action === 'run') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }

    $prices = ladderRequestPrices($mode, $state, $input);
    $opts = ['prices' => $prices];
    $single = ladderParamFloat($input, 'price');
    if ($single !== null && $single > 0 && $prices === []) {
        $opts['priceOverride'] = $single;
    }

    $res = ladderRunPass($mode, $opts);
    $fresh = ladderLoadState($mode);
    $outPrices = is_array($res['prices'] ?? null) ? $res['prices'] : $prices;

    respond(200, ladderPayload($mode, $fresh, $outPrices, $input, [
        'run' => [
            'ok' => !empty($res['ok']),
            'actions' => $res['actions'] ?? 0,
            'buy' => $res['buy'] ?? null,
            'sweep' => $res['sweep'] ?? null,
            'log' => $res['log'] ?? [],
        ],
        'message' => !empty($res['ok'])
            ? ('Pass done — ' . (int) ($res['actions'] ?? 0) . ' action(s)')
            : ($res['error'] ?? 'Pass failed'),
    ]));
}

/* ---------------- simulation housekeeping ---------------- */

if ($action === 'delete-entry') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }
    if ($mode !== 'sim') {
        respond(400, ['ok' => false, 'error' => 'Entries can only be deleted in simulation mode.']);
    }

    $id = (string) ($input['id'] ?? '');
    $before = count($state['entries']);
    $state['entries'] = array_values(array_filter(
        $state['entries'],
        static fn($entry) => (string) $entry['id'] !== $id
    ));

    if (count($state['entries']) === $before) {
        respond(404, ['ok' => false, 'error' => 'Entry not found.']);
    }

    ladderSaveState($mode, $state);
    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'message' => 'Simulated entry deleted.',
    ]));
}

if ($action === 'reset') {
    if (!$isPost) {
        respond(405, ['ok' => false, 'error' => 'Use POST.']);
    }
    if ($mode !== 'sim') {
        respond(400, ['ok' => false, 'error' => 'Reset is only allowed in simulation mode.']);
    }

    $state['entries'] = [];
    foreach ($state['configs'] as $i => $cfg) {
        $state['configs'][$i]['lastBuyDate'] = null;
    }
    if (is_array($state['config'] ?? null)) {
        $state['config']['lastBuyDate'] = null;
    }
    $state['lastRunLog'] = [];
    ladderSaveState($mode, $state);

    $prices = ladderRequestPrices($mode, $state, $input);
    respond(200, ladderPayload($mode, $state, $prices, $input, [
        'message' => 'Simulation reset (configs kept, entries cleared).',
    ]));
}

respond(400, [
    'ok' => false,
    'error' => 'Unknown action. Use state, config-save, config-delete, buy-now, sell, sell-matured, run, delete-entry or reset.',
]);
