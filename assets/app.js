(() => {
  const STORAGE_KEY = 'binance-watchlist-v1';
  const AUTO_KEY = 'binance-auto-trade-v1';
  const SERVER_AUTO_KEY = 'binance-server-auto-v1';
  const POLL_MS = 2000;

  const els = {
    symbol: document.getElementById('symbol'),
    basePrice: document.getElementById('base-price'),
    addBtn: document.getElementById('add-btn'),
    status: document.getElementById('status'),
    tickStatus: document.getElementById('tick-status'),
    symbolList: document.getElementById('symbol-list'),
    watchList: document.getElementById('watch-list'),
    watchEmpty: document.getElementById('watch-empty'),
    buyDrop: document.getElementById('buy-drop'),
    sellUp: document.getElementById('sell-up'),
    feePct: document.getElementById('fee-pct'),
    feeHint: document.getElementById('fee-hint'),
    buyAmount: document.getElementById('buy-amount'),
    soundToggle: document.getElementById('sound-toggle'),
    autoTrade: document.getElementById('auto-trade'),
    serverAuto: document.getElementById('server-auto'),
    autoBanner: document.getElementById('auto-banner'),
    serverBanner: document.getElementById('server-banner'),
    serverCronStatus: document.getElementById('server-cron-status'),
    apiStatus: document.getElementById('api-status'),
    usdtFree: document.getElementById('usdt-free'),
    usdtTotal: document.getElementById('usdt-total'),
    usdtLocked: document.getElementById('usdt-locked'),
    btcWallet: document.getElementById('btc-wallet'),
    dailyCap: document.getElementById('daily-cap'),
    buyHint: document.getElementById('buy-hint'),
    refreshApi: document.getElementById('refresh-api'),
    histSymbol: document.getElementById('hist-symbol'),
    histSide: document.getElementById('hist-side'),
    histFrom: document.getElementById('hist-from'),
    histTo: document.getElementById('hist-to'),
    histLoad: document.getElementById('hist-load'),
    histSync: document.getElementById('hist-sync'),
    histSummary: document.getElementById('hist-summary'),
    histByDate: document.getElementById('hist-by-date'),
    histBySymbol: document.getElementById('hist-by-symbol'),
    histTrades: document.getElementById('hist-trades'),
    pnlDate: document.getElementById('pnl-date'),
    pnlRealized: document.getElementById('pnl-realized'),
    pnlUnrealized: document.getElementById('pnl-unrealized'),
    pnlTotal: document.getElementById('pnl-total'),
    pnlBought: document.getElementById('pnl-bought'),
    pnlSold: document.getElementById('pnl-sold'),
    pnlTrades: document.getElementById('pnl-trades'),
    pnlSymbols: document.getElementById('pnl-symbols'),
    pnlPanel: document.getElementById('pnl-panel'),
    calcBuy: document.getElementById('calc-buy'),
    calcUsdt: document.getElementById('calc-usdt'),
    calcBreakeven: document.getElementById('calc-breakeven'),
    calcTarget: document.getElementById('calc-target'),
    calcAfter: document.getElementById('calc-after'),
    calcProfit: document.getElementById('calc-profit'),
    calcNetLabel: document.getElementById('calc-net-label'),
    calcHint: document.getElementById('calc-hint'),
    calcSymbol: document.getElementById('calc-symbol'),
    calcWindow: document.getElementById('calc-window'),
    calcHourBtn: document.getElementById('calc-hour-btn'),
    hourStats: document.getElementById('hour-stats'),
    hourHlLabel: document.getElementById('hour-hl-label'),
    hourHl: document.getElementById('hour-hl'),
    hourChange: document.getElementById('hour-change'),
    hourRange: document.getElementById('hour-range'),
    hourMinutes: document.getElementById('hour-minutes'),
    hourChance: document.getElementById('hour-chance'),
    hourProfit: document.getElementById('hour-profit'),
    hourHint: document.getElementById('hour-hint'),
  };

  /** @type {{symbol:string, basePrice:number, addedAt:number, lastSignal?:string, holding?:boolean}[]} */
  let watchlist = loadWatchlist();
  let polling = false;
  let trading = false;
  let timer = null;
  let audioCtx = null;
  let apiConnected = false;
  let usdtFreeBalance = 0;
  /** @type {Record<string, any>} */
  let suggestions = {};
  let lastSuggestAt = 0;
  let lastPnlAt = 0;
  let lastServerPullAt = 0;
  /** @type {Record<string, number>} */
  let lastPriceMap = {};
  /** Manual USDT amount typed for buying BTC */
  let manualBuyUsdt = Number(els.buyAmount?.value) > 0 ? Number(els.buyAmount.value) : 6;

  function getBuyAmount() {
    const active = document.activeElement;
    if (active && active.matches && active.matches('input[data-buy-usdt]')) {
      const n = Number(active.value);
      if (Number.isFinite(n) && n > 0) {
        manualBuyUsdt = n;
        return n;
      }
    }
    if (Number.isFinite(manualBuyUsdt) && manualBuyUsdt > 0) return manualBuyUsdt;
    const top = Number(els.buyAmount?.value);
    return Number.isFinite(top) && top > 0 ? top : 0;
  }

  function syncBuyAmountInputs(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) return;
    manualBuyUsdt = n;
    if (els.buyAmount && document.activeElement !== els.buyAmount) {
      els.buyAmount.value = String(n);
    }
    document.querySelectorAll('input[data-buy-usdt]').forEach((input) => {
      if (document.activeElement !== input) {
        input.value = String(n);
      }
    });
  }

  function moneyClass(n) {
    if (!Number.isFinite(n)) return '';
    if (n > 0) return 'up';
    if (n < 0) return 'down';
    return '';
  }

  function renderTodayPnL(pnl) {
    if (!els.pnlRealized) return;
    const realized = Number(pnl.realized) || 0;
    const unrealized = Number(pnl.unrealized) || 0;
    const total = Number(pnl.total) || 0;

    els.pnlDate.textContent = `${pnl.date || '—'} · ${pnl.timezone || ''} · updated ${new Date(
      pnl.updatedAt || Date.now()
    ).toLocaleTimeString()}`;
    els.pnlRealized.textContent = `${fmt.num(realized, 4)} USDT`;
    els.pnlRealized.className = moneyClass(realized);
    els.pnlUnrealized.textContent = `${fmt.num(unrealized, 4)} USDT`;
    els.pnlUnrealized.className = moneyClass(unrealized);
    els.pnlTotal.textContent = `${total >= 0 ? '+' : ''}${fmt.num(total, 4)} USDT`;
    els.pnlTotal.className = moneyClass(total);
    els.pnlBought.textContent = `${fmt.num(pnl.buyQuote, 4)} USDT`;
    els.pnlSold.textContent = `${fmt.num(pnl.sellQuote, 4)} USDT`;
    els.pnlTrades.textContent = `${pnl.buys || 0} buys · ${pnl.sells || 0} sells`;

    if (els.pnlPanel) {
      els.pnlPanel.classList.toggle('is-profit', total > 0);
      els.pnlPanel.classList.toggle('is-loss', total < 0);
    }

    const rows = pnl.bySymbol || [];
    els.pnlSymbols.innerHTML =
      rows.length === 0
        ? '<p class="empty-note">No trades yet today. P&amp;L will appear after buys/sells.</p>'
        : `<table class="hist-table">
            <thead>
              <tr>
                <th>Symbol</th>
                <th>Realized</th>
                <th>Unrealized</th>
                <th>Open qty</th>
                <th>Buys</th>
                <th>Sells</th>
              </tr>
            </thead>
            <tbody>
              ${rows
                .map((r) => {
                  const rt = Number(r.realized) || 0;
                  const ur = Number(r.unrealized) || 0;
                  return `<tr>
                    <td>${r.symbol}</td>
                    <td class="${moneyClass(rt)}">${fmt.num(rt, 4)}</td>
                    <td class="${moneyClass(ur)}">${fmt.num(ur, 4)}</td>
                    <td>${fmt.num(r.openQty)}</td>
                    <td>${r.buys || 0}</td>
                    <td>${r.sells || 0}</td>
                  </tr>`;
                })
                .join('')}
            </tbody>
          </table>`;
  }

  async function refreshTodayPnL(priceMap = {}) {
    try {
      const symbols = [
        ...new Set([
          ...watchlist.map((w) => w.symbol),
          ...Object.keys(priceMap || {}),
        ]),
      ];
      const res = await fetch('api/history.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'today',
          symbols: symbols.join(','),
          prices: priceMap,
        }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.error || 'P&L load failed');
      renderTodayPnL(data.pnl || {});
      lastPnlAt = Date.now();
    } catch (err) {
      if (els.pnlDate) {
        els.pnlDate.textContent = err.message || 'Could not load today P&L';
      }
    }
  }
  let autoBusy = false;

  const DAILY_CAP_PCT = 100;
  const PER_TRADE_USDT = 5;
  const MIN_BUY_USDT = 5;

  function canBuyNow() {
    return usdtFreeBalance >= MIN_BUY_USDT - 1e-8;
  }

  function isSellOnlyMode() {
    return apiConnected && !canBuyNow();
  }

  function setWalletDisplay(data = {}) {
    const free = Number(data.usdtFree);
    const locked = Number(data.usdtLocked);
    const total = Number(data.usdtTotal);
    const btcFree = Number(data.btcFree);
    const btcTotal = Number(data.btcTotal);

    usdtFreeBalance = Number.isFinite(free) ? free : usdtFreeBalance;

    if (els.usdtFree) {
      els.usdtFree.textContent = Number.isFinite(free) ? fmt.num(free, 4) : '—';
    }
    if (els.usdtLocked) {
      els.usdtLocked.textContent = Number.isFinite(locked) ? fmt.num(locked, 4) : '—';
    }
    if (els.usdtTotal) {
      const shown = Number.isFinite(total)
        ? total
        : Number.isFinite(free)
          ? free + (Number.isFinite(locked) ? locked : 0)
          : null;
      els.usdtTotal.textContent = shown == null ? '—' : fmt.num(shown, 4);
    }
    if (els.btcWallet) {
      if (Number.isFinite(btcTotal) && btcTotal > 0) {
        els.btcWallet.textContent = `${fmt.num(btcTotal, 8)} (free ${fmt.num(btcFree, 8)})`;
      } else if (Number.isFinite(btcFree)) {
        els.btcWallet.textContent = fmt.num(btcFree, 8);
      } else {
        els.btcWallet.textContent = '0';
      }
    }
  }

  function isAutoTradeOn() {
    return !!(els.autoTrade && els.autoTrade.checked);
  }

  function isServerAutoOn() {
    return !!(els.serverAuto && els.serverAuto.checked);
  }

  function syncAutoBanner() {
    if (els.autoBanner) {
      const on = isAutoTradeOn() && !isServerAutoOn();
      els.autoBanner.hidden = !on;
    }
    if (els.serverBanner) {
      els.serverBanner.hidden = !isServerAutoOn();
    }
    localStorage.setItem(AUTO_KEY, isAutoTradeOn() ? '1' : '0');
    localStorage.setItem(SERVER_AUTO_KEY, isServerAutoOn() ? '1' : '0');
  }

  function loadAutoPref() {
    if (els.autoTrade) {
      const saved = localStorage.getItem(AUTO_KEY);
      els.autoTrade.checked = saved === null ? true : saved === '1';
    }
    if (els.serverAuto) {
      els.serverAuto.checked = localStorage.getItem(SERVER_AUTO_KEY) === '1';
    }
    // Prefer server auto: turn off browser auto to avoid double buys
    if (isServerAutoOn() && els.autoTrade) {
      els.autoTrade.checked = false;
    }
    syncAutoBanner();
  }

  function formatCronAge(ts) {
    if (!ts) return 'Never';
    const age = Math.max(0, Math.floor((Date.now() - Number(ts)) / 1000));
    if (age < 60) return `${age}s ago`;
    if (age < 3600) return `${Math.floor(age / 60)}m ago`;
    return `${Math.floor(age / 3600)}h ago`;
  }

  function updateServerCronStatus(state) {
    if (!els.serverCronStatus) return;
    if (!state) {
      els.serverCronStatus.textContent = '—';
      return;
    }
    const on = !!state.serverAuto;
    const age = formatCronAge(state.lastRunAt);
    els.serverCronStatus.textContent = on ? `ON · ${age}` : `OFF · ${age}`;
    els.serverCronStatus.className = on ? 'api-value up' : 'api-value';
  }

  let syncTimer = null;
  let syncingWatch = false;
  let suppressServerSync = false;

  async function readJson(res) {
    const text = await res.text();
    if (!text || !String(text).trim()) {
      throw new Error(`Empty response (${res.status}) from server`);
    }
    try {
      return JSON.parse(text);
    } catch {
      throw new Error(`Invalid JSON (${res.status}): ${String(text).slice(0, 120)}`);
    }
  }

  function buildServerPayload() {
    const r = getRules();
    return {
      action: 'sync',
      autoEnabled: isAutoTradeOn() || isServerAutoOn(),
      serverAuto: isServerAutoOn(),
      rules: {
        buyDrop: r.buyDrop,
        feePct: r.feePct,
        netProfitPct: r.netProfitPct,
        buyUsdt: getBuyAmount() || Number(els.buyAmount?.value) || 6,
      },
      items: watchlist.map((w) => ({
        symbol: w.symbol,
        basePrice: w.basePrice,
        holding: !!w.holding,
        addedAt: w.addedAt,
        lastSignal: w.lastSignal || 'WAIT',
      })),
    };
  }

  async function syncWatchToServer(opts = {}) {
    if (syncingWatch || suppressServerSync) return null;
    syncingWatch = true;
    try {
      const res = await fetch('api/watch.php?action=sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(buildServerPayload()),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.error || 'Server sync failed');
      updateServerCronStatus(data.state);
      return data.state;
    } catch (err) {
      if (opts.forceStatus) {
        setStatus(err.message || 'Server sync failed', true);
      }
      return null;
    } finally {
      syncingWatch = false;
    }
  }

  function scheduleServerSync() {
    if (suppressServerSync) return;
    clearTimeout(syncTimer);
    syncTimer = setTimeout(() => {
      syncWatchToServer();
    }, 400);
  }

  function applyServerState(state, opts = {}) {
    if (!state || !Array.isArray(state.items)) return;
    suppressServerSync = true;
    try {
      if (opts.replaceItems !== false) {
        watchlist = state.items.map((item) => ({
          symbol: String(item.symbol).toUpperCase(),
          basePrice: Number(item.basePrice),
          addedAt: Number(item.addedAt) || Date.now(),
          lastSignal: item.lastSignal || 'WAIT',
          holding: !!item.holding,
        }));
        saveWatchlist();
      }
      if (state.rules && opts.applyRules !== false) {
        if (els.buyDrop && state.rules.buyDrop != null) els.buyDrop.value = String(state.rules.buyDrop);
        if (els.feePct && state.rules.feePct != null) els.feePct.value = String(state.rules.feePct);
        if (els.sellUp && state.rules.netProfitPct != null) {
          els.sellUp.value = String(state.rules.netProfitPct);
        }
        if (els.buyAmount && state.rules.buyUsdt != null) {
          syncBuyAmountInputs(state.rules.buyUsdt);
        }
        updateFeeHint();
        updateSellCalc();
      }
      if (els.serverAuto && typeof state.serverAuto === 'boolean') {
        els.serverAuto.checked = !!state.serverAuto;
        if (state.serverAuto && els.autoTrade) els.autoTrade.checked = false;
        syncAutoBanner();
      }
      updateServerCronStatus(state);
    } finally {
      suppressServerSync = false;
    }
  }

  async function loadWatchFromServer() {
    try {
      const res = await fetch('api/watch.php?action=get');
      const data = await readJson(res);
      if (!res.ok || !data.ok) return;
      const state = data.state;
      updateServerCronStatus(state);
      if (state?.items?.length) {
        applyServerState(state, { applyRules: true, replaceItems: true });
        renderList();
      } else if (watchlist.length) {
        await syncWatchToServer();
      } else if (state) {
        applyServerState(state, { replaceItems: false, applyRules: true });
      }
    } catch (err) {
      setStatus(err.message || 'Watch sync failed', true);
    }
  }

  function assetFromSymbol(symbol) {
    const s = String(symbol || '');
    if (s.endsWith('USDT')) return s.slice(0, -4) || 'COIN';
    return s || 'COIN';
  }

  const fmt = {
    num(value, max = 8) {
      const n = Number(value);
      if (!Number.isFinite(n)) return '—';
      return n.toLocaleString(undefined, {
        maximumFractionDigits: max,
        minimumFractionDigits: 0,
      });
    },
    pct(value) {
      const n = Number(value);
      if (!Number.isFinite(n)) return '—';
      const sign = n > 0 ? '+' : '';
      return `${sign}${n.toFixed(3)}%`;
    },
  };

  function loadWatchlist() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(parsed)) return [];
      return parsed
        .filter((item) => item && typeof item.symbol === 'string' && Number(item.basePrice) > 0)
        .map((item) => ({
          symbol: String(item.symbol).toUpperCase(),
          basePrice: Number(item.basePrice),
          addedAt: Number(item.addedAt) || Date.now(),
          lastSignal: item.lastSignal || 'WAIT',
          holding: !!item.holding,
        }));
    } catch {
      return [];
    }
  }

  function saveWatchlist() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify(
        watchlist.map(({ symbol, basePrice, addedAt, lastSignal, holding }) => ({
          symbol,
          basePrice,
          addedAt,
          lastSignal,
          holding: !!holding,
        }))
      )
    );
    scheduleServerSync();
  }

  function setStatus(message, isError = false) {
    els.status.textContent = message;
    els.status.classList.toggle('error', isError);
  }

  function normalizeSymbol(raw) {
    return String(raw || '')
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, '');
  }

  function getRules() {
    const buyRaw = Number(els.buyDrop.value);
    const sellRaw = Number(els.sellUp.value);
    const feeRaw = Number(els.feePct?.value);
    const buyDrop = Number.isFinite(buyRaw) && buyRaw >= 0 ? buyRaw : 0;
    const netProfitPct = Number.isFinite(sellRaw) && sellRaw >= 0 ? sellRaw : 0.3;
    const feePct = Number.isFinite(feeRaw) && feeRaw >= 0 ? feeRaw : 0.1;

    // Cover buy fee + sell fee + desired net profit:
    // P_sell / P_buy >= (1 + net) / (1 - fee)^2
    const fee = feePct / 100;
    const keep = Math.max(1e-9, 1 - fee);
    const breakevenMult = 1 / (keep * keep);
    const targetMult = (1 + netProfitPct / 100) * breakevenMult;
    const breakevenPct = (breakevenMult - 1) * 100;
    const sellTriggerPct = (targetMult - 1) * 100;

    return {
      buyDrop,
      netProfitPct,
      feePct,
      breakevenPct,
      sellTriggerPct,
      sellUp: sellTriggerPct,
    };
  }

  function updateFeeHint() {
    if (!els.feeHint) return;
    const r = getRules();
    els.feeHint.textContent =
      `Fee ${fmt.num(r.feePct, 2)}% × 2 ≈ ${fmt.num(r.feePct * 2, 2)}% round-trip. ` +
      `Break-even ≈ +${fmt.num(r.breakevenPct, 3)}% price. ` +
      `For ${fmt.num(r.netProfitPct, 2)}% net profit → sell when price ↑ ${fmt.num(r.sellTriggerPct, 3)}%.`;
  }

  function getCalcUsdtAmount() {
    const n = Number(els.calcUsdt?.value);
    if (Number.isFinite(n) && n > 0) return n;
    return getBuyAmount() || Number(els.buyAmount?.value) || 6;
  }

  function updateSellCalc() {
    if (!els.calcBreakeven || !els.calcTarget) return;
    const r = getRules();
    const buy = Number(els.calcBuy?.value);
    const usdt = getCalcUsdtAmount();
    const profit = usdt * (r.netProfitPct / 100);
    const after = usdt + profit;

    if (els.calcNetLabel) {
      els.calcNetLabel.textContent = fmt.num(r.netProfitPct, 2);
    }
    if (els.calcAfter) {
      els.calcAfter.textContent = `${fmt.num(after, 4)} USDT`;
      els.calcAfter.className = 'up';
    }
    if (els.calcProfit) {
      els.calcProfit.textContent = `+${fmt.num(profit, 4)} USDT`;
      els.calcProfit.className = 'up';
    }

    if (!(Number.isFinite(buy) && buy > 0)) {
      els.calcBreakeven.textContent = '—';
      els.calcTarget.textContent = '—';
      if (els.calcHint) {
        els.calcHint.textContent =
          `Example: ${fmt.num(usdt, 2)} USDT khareedo → 1 cycle (+${fmt.num(r.netProfitPct, 2)}% net) ke baad ≈ ${fmt.num(after, 4)} USDT (faida +${fmt.num(profit, 4)}). ` +
          `Fee ${fmt.num(r.feePct, 2)}%×2 already included.`;
      }
      return;
    }

    const breakeven = buy * (1 + r.breakevenPct / 100);
    const target = buy * (1 + r.sellTriggerPct / 100);
    els.calcBreakeven.textContent = fmt.num(breakeven);
    els.calcTarget.textContent = fmt.num(target);
    if (els.calcHint) {
      els.calcHint.textContent =
        `${fmt.num(usdt, 2)} USDT @ ${fmt.num(buy)} buy → sell ≥ ${fmt.num(target)} → ` +
        `1 cycle baad ≈ ${fmt.num(after, 4)} USDT (+${fmt.num(profit, 4)} net). ` +
        `Break-even sell = ${fmt.num(breakeven)}.`;
    }
  }

  /** @type {null | Record<string, number>} */
  let lastHourMove = null;

  function windowLabel(minutes) {
    const m = Number(minutes);
    if (m < 60) return `${m}m`;
    if (m === 60) return '1h';
    return `${m / 60}h`;
  }

  function renderHourMove(data) {
    if (!els.hourStats) return;
    const r = getRules();
    const amount = getCalcUsdtAmount();
    const cycleNeedPct = r.buyDrop + r.sellTriggerPct;
    const rangePct = Number(data.rangePct) || 0;
    const changePct = Number(data.changePct) || 0;
    const label = data.label || windowLabel(data.minutes || 60);
    const estCycles =
      cycleNeedPct > 0 ? Math.max(0, Math.floor(rangePct / cycleNeedPct)) : 0;
    const profitOne = amount * (r.netProfitPct / 100);
    const profitEst = profitOne * Math.max(1, estCycles || (rangePct >= r.sellTriggerPct ? 1 : 0));

    let chance = 'LOW';
    let chanceClass = 'down';
    if (rangePct >= cycleNeedPct * 1.5) {
      chance = 'HIGH';
      chanceClass = 'up';
    } else if (rangePct >= cycleNeedPct) {
      chance = 'MEDIUM';
      chanceClass = '';
    }

    els.hourStats.hidden = false;
    if (els.hourHlLabel) els.hourHlLabel.textContent = label;
    if (els.hourHl) {
      els.hourHl.textContent = `${fmt.num(data.high)} / ${fmt.num(data.low)}`;
    }
    if (els.hourChange) {
      els.hourChange.textContent = `${changePct >= 0 ? '+' : ''}${fmt.num(changePct, 3)}%`;
      els.hourChange.className = changePct >= 0 ? 'up' : 'down';
    }
    if (els.hourRange) {
      els.hourRange.textContent = `${fmt.num(rangePct, 3)}%`;
    }
    if (els.hourMinutes) {
      const up = data.upBars ?? data.upMinutes ?? 0;
      const down = data.downBars ?? data.downMinutes ?? 0;
      els.hourMinutes.textContent = `${up} / ${down}`;
    }
    if (els.hourChance) {
      els.hourChance.textContent = chance;
      els.hourChance.className = chanceClass;
    }
    if (els.hourProfit) {
      els.hourProfit.textContent =
        `~${fmt.num(profitOne, 4)} USDT` +
        (estCycles > 1 ? ` ×${estCycles} ≈ ${fmt.num(profitEst, 4)}` : '');
      els.hourProfit.className = 'up';
    }
    if (els.hourHint) {
      els.hourHint.textContent =
        `${data.symbol} · last ${label}: range ${fmt.num(rangePct, 3)}%. ` +
        `Tumhari cycle (−${fmt.num(r.buyDrop, 2)}% buy → +${fmt.num(r.sellTriggerPct, 3)}% sell) ≈ ${fmt.num(cycleNeedPct, 3)}% chahiye. ` +
        (estCycles >= 1
          ? `Is move me ~${estCycles} cycle possible → har cycle ~${fmt.num(profitOne, 4)} USDT net (${fmt.num(amount, 2)} USDT trade).`
          : `Range chhoti — ek full cycle mushkil. Break-even ke liye kam se kam +${fmt.num(r.breakevenPct, 3)}% price chahiye.`);
    }
  }

  async function checkHourMove() {
    const symbol = normalizeSymbol(els.calcSymbol?.value || '');
    if (!symbol) {
      setStatus('Calculator me symbol likho, e.g. ETHUSDT', true);
      return;
    }
    const minutes = Number(els.calcWindow?.value) || 60;
    const label = windowLabel(minutes);
    if (els.calcHourBtn) els.calcHourBtn.disabled = true;
    setStatus(`Loading ${label} move · ${symbol}…`);
    try {
      const res = await fetch(
        `api/market.php?action=move&symbol=${encodeURIComponent(symbol)}&minutes=${minutes}`
      );
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.error || 'Move data failed');
      lastHourMove = data;
      if (els.calcBuy && Number(data.close) > 0) {
        els.calcBuy.value = String(data.close);
        updateSellCalc();
      }
      renderHourMove(data);
      setStatus(`${symbol} ${label} loaded · range ${fmt.num(data.rangePct, 3)}%`);
    } catch (err) {
      setStatus(err.message || 'Move check failed', true);
      if (els.hourStats) els.hourStats.hidden = true;
    } finally {
      if (els.calcHourBtn) els.calcHourBtn.disabled = false;
    }
  }

  async function refreshSuggestions() {
    if (!apiConnected || watchlist.length === 0) {
      suggestions = {};
      if (els.buyHint) {
        els.buyHint.textContent =
          'Card pe USDT amount likho (min ~5), phir Buy BTC dabao.';
      }
      return;
    }

    try {
      const symbols = watchlist.map((w) => w.symbol).join(',');
      const res = await fetch(
        `api/trade.php?action=suggest_many&symbols=${encodeURIComponent(symbols)}`
      );
      const data = await readJson(res);
      if (!res.ok || !data.ok) return;

      usdtFreeBalance = Number(data.usdtFree) || usdtFreeBalance;
      suggestions = data.suggestions || {};
      if (els.usdtFree) els.usdtFree.textContent = fmt.num(usdtFreeBalance, 4);
      if (els.dailyCap) {
        els.dailyCap.textContent = '~5 USDT min';
      }

      // Do NOT overwrite manual Buy USDT input
      const manual = getBuyAmount();
      if (els.buyHint) {
        if (usdtFreeBalance < MIN_BUY_USDT) {
          els.buyHint.textContent = `SELL ONLY mode: wallet free ${fmt.num(usdtFreeBalance, 4)} USDT (< ${MIN_BUY_USDT}). Buy band — sirf sell allowed.`;
          els.buyHint.classList.add('warn');
        } else {
          els.buyHint.textContent = `Manual buy: ${fmt.num(manual || 5, 2)} USDT · Wallet free: ${fmt.num(usdtFreeBalance, 2)} USDT · Sell = all free coins.`;
          els.buyHint.classList.remove('warn');
        }
      }
    } catch {
      // ignore
    }
  }

  function decideSignal(current, basePrice) {
    const { buyDrop, sellTriggerPct } = getRules();
    const changePct = ((current - basePrice) / basePrice) * 100;

    if (changePct >= sellTriggerPct) {
      return { signal: 'SELL', changePct };
    }
    if (buyDrop === 0 ? changePct < 0 : changePct <= -buyDrop) {
      return { signal: 'BUY', changePct };
    }
    return { signal: 'WAIT', changePct };
  }

  function ensureAudio() {
    if (!audioCtx) {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (Ctx) audioCtx = new Ctx();
    }
    if (audioCtx?.state === 'suspended') {
      audioCtx.resume();
    }
  }

  function beep(kind) {
    if (!els.soundToggle.checked) return;
    ensureAudio();
    if (!audioCtx) return;

    const playTone = (freq, startAt, duration = 0.22) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'square';
      osc.frequency.value = freq;
      gain.gain.value = 0.0001;
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      const t = audioCtx.currentTime + startAt;
      gain.gain.exponentialRampToValueAtTime(0.18, t + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.0001, t + duration);
      osc.start(t);
      osc.stop(t + duration + 0.02);
    };

    if (kind === 'BUY') {
      playTone(440, 0);
      playTone(440, 0.28);
    } else {
      playTone(880, 0);
      playTone(1100, 0.28);
    }
  }

  function flashTitle(signal, symbol) {
    const original = document.title;
    document.title = `${signal}: ${symbol}`;
    setTimeout(() => {
      document.title = original;
    }, 2500);
  }

  async function checkApiStatus() {
    try {
      const res = await fetch('api/trade.php?action=status');
      const data = await readJson(res);
      apiConnected = !!(data.ok && data.connected);

      if (!data.configured) {
        els.apiStatus.textContent = 'Keys missing in .env';
        els.apiStatus.className = 'api-status bad';
        setWalletDisplay({});
        if (els.dailyCap) els.dailyCap.textContent = `${PER_TRADE_USDT} USDT`;
        return;
      }

      if (!data.connected) {
        els.apiStatus.textContent = data.error || 'Auth failed';
        els.apiStatus.className = 'api-status bad';
        setWalletDisplay({});
        return;
      }

      setWalletDisplay(data);
      els.apiStatus.textContent = data.canTrade ? 'Connected · Spot trading ON' : 'Connected · trading disabled';
      els.apiStatus.className = data.canTrade ? 'api-status ok' : 'api-status warn';
      if (els.dailyCap) {
        els.dailyCap.textContent = '~5 USDT min';
      }

      // Never overwrite user's manual Buy USDT field
      await refreshSuggestions();
    } catch (err) {
      apiConnected = false;
      els.apiStatus.textContent = err.message || 'Could not reach trade API';
      els.apiStatus.className = 'api-status bad';
    }
  }

  async function placeOrder(side, symbol, opts = {}) {
    const auto = !!opts.auto;
    if (trading) return { ok: false, error: 'Another order in progress' };
    if (!apiConnected) {
      setStatus('Connect API keys in .env first, then click Refresh API.', true);
      return { ok: false, error: 'API not connected' };
    }

    if (side === 'BUY') {
      if (!canBuyNow()) {
        setStatus(
          `SELL ONLY: USDT free ${fmt.num(usdtFreeBalance, 4)} < ${MIN_BUY_USDT}. Buy nahi — sirf Sell all use karo.`,
          true
        );
        return { ok: false, error: 'Sell only mode' };
      }
      const amount = auto ? getBuyAmount() || 6 : getBuyAmount();
      if (!(amount >= MIN_BUY_USDT)) {
        setStatus(`Buy kam se kam ${MIN_BUY_USDT} USDT rakho — warna sell pe NOTIONAL error aata hai.`, true);
        const cardInput = document.querySelector(
          `input[data-buy-usdt][data-symbol="${symbol}"]`
        );
        (cardInput || els.buyAmount)?.focus();
        return { ok: false, error: 'Amount too low' };
      }
      if (amount > usdtFreeBalance + 1e-8) {
        setStatus(
          `Buy amount ${fmt.num(amount, 2)} > wallet free ${fmt.num(usdtFreeBalance, 4)} USDT.`,
          true
        );
        return { ok: false, error: 'Insufficient balance' };
      }

      if (!auto) {
        const nowPx = Number(lastPriceMap[symbol]);
        const previewSell =
          Number.isFinite(nowPx) && nowPx > 0
            ? nowPx * (1 + getRules().sellTriggerPct / 100)
            : null;
        const instantNote = opts.instant
          ? `\nINSTANT BUY — dip wait nahi (abhi market pe).\nEntry ~${fmt.num(nowPx)} → sell target ~${fmt.num(previewSell)} (+${fmt.num(getRules().netProfitPct, 2)}% net)`
          : '';
        if (
          !window.confirm(
            `BUY ${symbol} for ${fmt.num(amount, 2)} USDT?\nWallet free: ${fmt.num(usdtFreeBalance, 4)} USDT${instantNote}`
          )
        ) {
          return { ok: false, error: 'Cancelled' };
        }
      }

      trading = true;
      setStatus(
        `${auto ? 'AUTO ' : opts.instant ? 'INSTANT ' : ''}Sending BUY ${symbol} · ${fmt.num(amount, 2)} USDT…`
      );

      try {
        const res = await fetch('api/trade.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'buy', symbol, quoteOrderQty: amount }),
        });
        const data = await readJson(res);
        if (!res.ok || !data.ok) throw new Error(data.error || 'BUY order failed.');

        const filled = data.order?.executedQty || '';
        const quote = data.order?.cummulativeQuoteQty || amount;
        const filledQty = Number(filled);
        const quoteNum = Number(quote);
        let entry = NaN;
        if (filledQty > 0 && quoteNum > 0) {
          entry = quoteNum / filledQty;
        }
        if (!(entry > 0)) {
          const prices = await fetchPrices([symbol]);
          entry = Number(prices[symbol]);
        }

        const item = watchlist.find((w) => w.symbol === symbol);
        if (item) {
          if (Number.isFinite(entry) && entry > 0) item.basePrice = entry;
          item.holding = true;
          item.lastSignal = 'WAIT';
          item._autoBusy = false;
          item.entryUsdt = amount;
          saveWatchlist();
        }

        const r = getRules();
        const sellAt =
          Number.isFinite(entry) && entry > 0
            ? entry * (1 + r.sellTriggerPct / 100)
            : null;
        const after = amount * (1 + r.netProfitPct / 100);

        setStatus(
          `${auto ? 'AUTO ' : opts.instant ? 'INSTANT ' : ''}BUY filled · ${symbol}` +
            (filled ? ` · qty ${filled}` : '') +
            (quote ? ` · quote ${quote}` : '') +
            (entry > 0 ? ` · entry ${fmt.num(entry)}` : '') +
            (sellAt ? ` · next sell ≥ ${fmt.num(sellAt)}` : '') +
            ` · 1 cycle target ≈ ${fmt.num(after, 4)} USDT`
        );

        // Sync sell calculator to this entry
        if (els.calcBuy && entry > 0) els.calcBuy.value = String(entry);
        if (els.calcUsdt && amount > 0) els.calcUsdt.value = String(amount);
        if (els.calcSymbol) els.calcSymbol.value = symbol;
        updateSellCalc();

        await checkApiStatus();
        await poll();
        await loadHistory();
        await refreshSuggestions();
        await refreshTodayPnL();
        return { ok: true, data };
      } catch (err) {
        const item = watchlist.find((w) => w.symbol === symbol);
        if (item) item._autoBusy = false;
        setStatus(err.message || 'Order failed.', true);
        return { ok: false, error: err.message || 'Order failed' };
      } finally {
        trading = false;
      }
    }

    // SELL = 100% free balance
    if (!auto) {
      if (!window.confirm(`SELL ALL free balance of ${symbol}?`)) {
        return { ok: false, error: 'Cancelled' };
      }
    }

    trading = true;
    setStatus(`${auto ? 'AUTO ' : ''}Sending SELL ALL ${symbol}…`);

    try {
      const res = await fetch('api/trade.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'sell', symbol }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.error || 'SELL order failed.');

      const filled = data.order?.executedQty || data.quantity || '';
      const quote = data.order?.cummulativeQuoteQty || '';

      const item = watchlist.find((w) => w.symbol === symbol);
      if (item) {
        item.holding = false;
        item.lastSignal = 'WAIT';
        item._autoBusy = false;
        const prices = await fetchPrices([symbol]);
        const now = Number(prices[symbol]);
        if (Number.isFinite(now) && now > 0) {
          item.basePrice = now;
        }
        saveWatchlist();
        setStatus(
          `${auto ? 'AUTO ' : ''}SELL filled · ${symbol}` +
            (filled ? ` · qty ${filled}` : '') +
            (quote ? ` · quote ${quote}` : '') +
            ` · next buy at −${fmt.num(getRules().buyDrop, 2)}% from ${fmt.num(item.basePrice)}`
        );
      } else {
        setStatus(
          `${auto ? 'AUTO ' : ''}SELL filled · ${symbol}` +
            (filled ? ` · qty ${filled}` : '') +
            (quote ? ` · quote ${quote}` : '')
        );
      }

      await checkApiStatus();
      if (!auto) await poll();
      await loadHistory();
      await refreshSuggestions();
      await refreshTodayPnL();
      return { ok: true, data };
    } catch (err) {
      const item = watchlist.find((w) => w.symbol === symbol);
      if (item) item._autoBusy = false;
      setStatus(err.message || 'Order failed.', true);
      return { ok: false, error: err.message || 'Order failed' };
    } finally {
      trading = false;
    }
  }

  function updateHistorySymbolOptions(extraSymbols = []) {
    const current = els.histSymbol.value;
    const set = new Set([
      ...watchlist.map((w) => w.symbol),
      ...extraSymbols.map((s) => String(s).toUpperCase()),
    ]);
    const options = ['<option value="">All symbols</option>']
      .concat([...set].sort().map((s) => `<option value="${s}">${s}</option>`));
    els.histSymbol.innerHTML = options.join('');
    if ([...set, ''].includes(current)) {
      els.histSymbol.value = current;
    }
  }

  function renderHistory(report) {
    const summary = report.summary || {};
    els.histSummary.innerHTML = `
      <div class="hist-stat"><span>Total</span><strong>${summary.totalTrades || 0}</strong></div>
      <div class="hist-stat"><span>Buys</span><strong class="down">${summary.buys || 0}</strong></div>
      <div class="hist-stat"><span>Sells</span><strong class="up">${summary.sells || 0}</strong></div>
      <div class="hist-stat"><span>Bought (USDT)</span><strong>${fmt.num(summary.buyQuote, 4)}</strong></div>
      <div class="hist-stat"><span>Sold (USDT)</span><strong>${fmt.num(summary.sellQuote, 4)}</strong></div>
      <div class="hist-stat"><span>Net (USDT)</span><strong class="${
        Number(summary.netQuote) >= 0 ? 'up' : 'down'
      }">${fmt.num(summary.netQuote, 4)}</strong></div>
    `;

    const byDate = report.byDate || [];
    els.histByDate.innerHTML =
      byDate.length === 0
        ? '<p class="empty-note">No date rows.</p>'
        : `<table class="hist-table">
            <thead><tr><th>Date</th><th>Buys</th><th>Sells</th><th>Buy USDT</th><th>Sell USDT</th></tr></thead>
            <tbody>
              ${byDate
                .map(
                  (row) => `<tr>
                    <td>${row.date}</td>
                    <td class="down">${row.buys}</td>
                    <td class="up">${row.sells}</td>
                    <td>${fmt.num(row.buyQuote, 4)}</td>
                    <td>${fmt.num(row.sellQuote, 4)}</td>
                  </tr>`
                )
                .join('')}
            </tbody>
          </table>`;

    const bySymbol = report.bySymbol || [];
    els.histBySymbol.innerHTML =
      bySymbol.length === 0
        ? '<p class="empty-note">No symbol rows.</p>'
        : `<table class="hist-table">
            <thead><tr><th>Symbol</th><th>Buys</th><th>Sells</th><th>Buy qty</th><th>Sell qty</th><th>Buy USDT</th><th>Sell USDT</th></tr></thead>
            <tbody>
              ${bySymbol
                .map(
                  (row) => `<tr>
                    <td>${row.symbol}</td>
                    <td class="down">${row.buys}</td>
                    <td class="up">${row.sells}</td>
                    <td>${fmt.num(row.buyQty)}</td>
                    <td>${fmt.num(row.sellQty)}</td>
                    <td>${fmt.num(row.buyQuote, 4)}</td>
                    <td>${fmt.num(row.sellQuote, 4)}</td>
                  </tr>`
                )
                .join('')}
            </tbody>
          </table>`;

    const trades = report.trades || [];
    updateHistorySymbolOptions(trades.map((t) => t.symbol));
    els.histTrades.innerHTML =
      trades.length === 0
        ? '<p class="empty-note">No trades yet. Place an order or sync from Binance.</p>'
        : `<table class="hist-table">
            <thead>
              <tr>
                <th>Date / time</th>
                <th>Symbol</th>
                <th>Side</th>
                <th>Qty</th>
                <th>Avg price</th>
                <th>Quote USDT</th>
                <th>Order ID</th>
                <th>Source</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              ${trades
                .map((t) => {
                  const side = String(t.side || '').toUpperCase();
                  const symbol = t.symbol || '';
                  const when = new Date(Number(t.time) || 0).toLocaleString();
                  const sellBtn =
                    side === 'BUY' && symbol
                      ? `<button type="button" class="hist-sell-btn" data-hist-sell="${symbol}">Sell all</button>`
                      : '—';
                  return `<tr>
                    <td>${when}</td>
                    <td>${symbol}</td>
                    <td class="${side === 'BUY' ? 'down' : 'up'}">${side}</td>
                    <td>${fmt.num(t.executedQty)}</td>
                    <td>${fmt.num(t.avgPrice)}</td>
                    <td>${fmt.num(t.cummulativeQuoteQty, 4)}</td>
                    <td>${t.orderId ?? '—'}</td>
                    <td>${t.source || '—'}</td>
                    <td>${sellBtn}</td>
                  </tr>`;
                })
                .join('')}
            </tbody>
          </table>`;
  }

  async function loadHistory() {
    const params = new URLSearchParams({ action: 'report' });
    if (els.histSymbol.value) params.set('symbol', els.histSymbol.value);
    if (els.histSide.value) params.set('side', els.histSide.value);
    if (els.histFrom.value) params.set('from', els.histFrom.value);
    if (els.histTo.value) params.set('to', els.histTo.value);

    try {
      const res = await fetch(`api/history.php?${params.toString()}`);
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.error || 'Could not load history.');
      renderHistory(data.report || {});
    } catch (err) {
      els.histTrades.innerHTML = `<p class="empty-note error-note">${err.message}</p>`;
    }
  }

  async function syncHistory() {
    const symbols = [
      ...new Set([
        ...watchlist.map((w) => w.symbol),
        ...(els.histSymbol.value ? [els.histSymbol.value] : []),
      ]),
    ];

    if (symbols.length === 0) {
      setStatus('Add a symbol to watchlist (or pick one) before syncing.', true);
      return;
    }

    els.histSync.disabled = true;
    setStatus(`Syncing Binance trades for ${symbols.join(', ')}…`);

    try {
      const res = await fetch('api/history.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'sync', symbols: symbols.join(','), limit: 100 }),
      });
      const data = await readJson(res);
      if (!res.ok || !data.ok) throw new Error(data.error || 'Sync failed.');

      const imported = data.sync?.imported ?? 0;
      setStatus(`Sync done · ${imported} new fill(s) from Binance`);
      if (data.report) renderHistory(data.report);
      else await loadHistory();
    } catch (err) {
      setStatus(err.message || 'Sync failed.', true);
    } finally {
      els.histSync.disabled = false;
    }
  }

  function renderList(priceMap = lastPriceMap) {
    if (priceMap && Object.keys(priceMap).length) {
      lastPriceMap = priceMap;
    }

    const active = document.activeElement;
    const restore =
      active && active.matches && active.matches('input[data-buy-usdt]')
        ? {
            symbol: active.getAttribute('data-symbol'),
            value: active.value,
            start: active.selectionStart,
            end: active.selectionEnd,
          }
        : null;

    if (watchlist.length === 0) {
      els.watchEmpty.hidden = false;
      els.watchList.innerHTML = '';
      return;
    }

    els.watchEmpty.hidden = true;
    const amt = getBuyAmount() || manualBuyUsdt || 6;

    els.watchList.innerHTML = watchlist
      .map((item) => {
        const current = priceMap[item.symbol];
        const hasPrice = Number.isFinite(current);
        const decision = hasPrice
          ? decideSignal(current, item.basePrice)
          : { signal: item.lastSignal || 'WAIT', changePct: null };
        const signal = decision.signal;
        const changeClass =
          decision.changePct == null ? '' : decision.changePct >= 0 ? 'up' : 'down';

        return `
          <article class="watch-card signal-${signal.toLowerCase()}" data-symbol="${item.symbol}">
            <div class="watch-top">
              <div>
                <h3>${item.symbol}</h3>
                <p class="meta">Base ${fmt.num(item.basePrice)}${
                  item.holding ? ' · HOLDING' : ' · FLAT'
                }</p>
              </div>
              <div class="signal-badge">${signal}</div>
            </div>
            <div class="watch-prices">
              <div>
                <span>Now</span>
                <strong>${hasPrice ? fmt.num(current) : '…'}</strong>
              </div>
              <div>
                <span>Change</span>
                <strong class="${changeClass}">${
                  decision.changePct == null ? '…' : fmt.pct(decision.changePct)
                }</strong>
              </div>
              <div>
                <span>${item.holding ? 'Entry' : 'Buy ≤'}</span>
                <strong>${
                  item.holding
                    ? fmt.num(item.basePrice)
                    : fmt.num(item.basePrice * (1 - getRules().buyDrop / 100))
                }</strong>
              </div>
              <div>
                <span>Sell ≥ (after fees)</span>
                <strong class="sell-target">${fmt.num(
                  item.basePrice * (1 + getRules().sellTriggerPct / 100)
                )}</strong>
              </div>
            </div>
            <p class="fee-line">
              ${
                item.holding
                  ? `HOLDING from ${fmt.num(item.basePrice)} · auto sell ≥ ${fmt.num(
                      item.basePrice * (1 + getRules().sellTriggerPct / 100)
                    )} (+${fmt.num(getRules().netProfitPct, 2)}% net)`
                  : `Net want +${fmt.num(getRules().netProfitPct, 2)}% · fees ${fmt.num(
                      getRules().feePct,
                      2
                    )}%×2 · need price ↑ ${fmt.num(getRules().sellTriggerPct, 3)}% (break-even ${fmt.num(
                      getRules().breakevenPct,
                      3
                    )}%)`
              }
            </p>

            <div class="buy-box${isSellOnlyMode() ? ' sell-only' : ''}${
              item.holding ? ' is-holding' : ''
            }">
              <label class="buy-box-label" for="buy-usdt-${item.symbol}">
                ${
                  isSellOnlyMode()
                    ? `SELL ONLY — USDT free ${fmt.num(usdtFreeBalance, 4)} (< ${MIN_BUY_USDT})`
                    : item.holding
                      ? `Holding — wait sell / Sell all`
                      : `Kitne USDT ka ${assetFromSymbol(item.symbol)}? (Instant = abhi buy)`
                }
              </label>
              <div class="buy-box-row">
                <input
                  id="buy-usdt-${item.symbol}"
                  class="buy-usdt-input"
                  type="number"
                  step="0.01"
                  min="5"
                  value="${amt}"
                  data-buy-usdt
                  data-symbol="${item.symbol}"
                  placeholder="6"
                  ${isSellOnlyMode() || item.holding ? 'disabled' : ''}
                >
                <span class="buy-usdt-unit">USDT</span>
                <button
                  type="button"
                  class="trade-buy trade-instant"
                  data-action="instant-buy"
                  data-symbol="${item.symbol}"
                  ${isSellOnlyMode() || item.holding ? 'disabled' : ''}
                  title="0.1% dip wait ke baghair abhi market buy"
                >
                  Instant buy
                </button>
                <button type="button" class="trade-sell" data-action="sell" data-symbol="${item.symbol}">
                  Sell all
                </button>
              </div>
              <p class="buy-box-hint">
                ${
                  isSellOnlyMode()
                    ? 'Wallet 5 USDT se kam — buy band. Sirf Sell all / auto sell.'
                    : item.holding
                      ? `1 buy done — ab sirf sell. Target ≥ ${fmt.num(
                          item.basePrice * (1 + getRules().sellTriggerPct / 100)
                        )} · Auto sell ON ho to khud bech dega.`
                      : `Instant buy = abhi khareedo (dip optional). Auto = −${fmt.num(
                          getRules().buyDrop,
                          2
                        )}% pe buy, phir +${fmt.num(getRules().netProfitPct, 2)}% net pe sell.`
                }
              </p>
            </div>

            <div class="watch-actions">
              <button type="button" data-action="rebase" data-symbol="${item.symbol}">Set base = now</button>
              <button type="button" data-action="remove" data-symbol="${item.symbol}" class="danger">Remove</button>
            </div>
          </article>
        `;
      })
      .join('');

    if (restore) {
      const el = els.watchList.querySelector(
        `input[data-buy-usdt][data-symbol="${restore.symbol}"]`
      );
      if (el) {
        el.value = restore.value;
        el.focus();
        try {
          el.setSelectionRange(restore.start ?? el.value.length, restore.end ?? el.value.length);
        } catch {
          // ignore
        }
      }
    }
  }

  async function fetchPrices(symbols) {
    if (symbols.length === 0) return {};
    const res = await fetch(
      `api/market.php?action=prices&symbols=${encodeURIComponent(symbols.join(','))}`
    );
    const data = await readJson(res);
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Price fetch failed.');
    }
    return data.prices || {};
  }

  async function poll() {
    if (polling || watchlist.length === 0) {
      if (watchlist.length === 0) {
        els.tickStatus.textContent = 'No symbols';
        renderList();
      }
      return;
    }

    polling = true;
    /** @type {{side:string,symbol:string}[]} */
    const autoJobs = [];

    try {
      const symbols = watchlist.map((w) => w.symbol);
      const prices = await fetchPrices(symbols);

      watchlist.forEach((item) => {
        const current = prices[item.symbol];
        if (!Number.isFinite(current)) return;
        const { signal, changePct } = decideSignal(current, item.basePrice);
        const changed = signal !== item.lastSignal;

        if (signal === 'BUY' || signal === 'SELL') {
          if (changed || item._alertTick == null || item._alertTick >= 1) {
            beep(signal);
            flashTitle(signal, item.symbol);
            item._alertTick = 0;
          } else {
            item._alertTick += 1;
          }
          if (changed) {
            setStatus(
              `${item.symbol}: ${signal} · ${fmt.pct(changePct)} (base ${fmt.num(item.basePrice)})`,
              false
            );
          }
        } else {
          item._alertTick = 0;
        }

        // Browser auto only if Server auto OFF (avoid double orders)
        if (isAutoTradeOn() && !isServerAutoOn() && apiConnected) {
          if (signal === 'BUY' && !item.holding && canBuyNow() && !item._autoBusy) {
            item._autoBusy = true;
            autoJobs.push({ side: 'BUY', symbol: item.symbol });
          } else if (signal === 'BUY' && !item.holding && !canBuyNow() && changed) {
            setStatus(
              `SELL ONLY: USDT < ${MIN_BUY_USDT} — auto buy skip.`,
              true
            );
          } else if (signal === 'SELL' && item.holding && !item._autoBusy) {
            item._autoBusy = true;
            autoJobs.push({ side: 'SELL', symbol: item.symbol });
          }
        }

        item.lastSignal = signal;
      });

      saveWatchlist();
      renderList(prices);
      const autoLabel = isAutoTradeOn() ? ' · AUTO' : '';
      const modeLabel = isSellOnlyMode() ? ' · SELL ONLY' : '';
      els.tickStatus.textContent = `Live${autoLabel}${modeLabel} · ${new Date().toLocaleTimeString()}`;

      // Live today P&L every poll (cheap local calc + optional prices)
      if (Date.now() - lastPnlAt > 4000) {
        await refreshTodayPnL(prices);
      }

      if (Date.now() - lastSuggestAt > 60000) {
        lastSuggestAt = Date.now();
        await refreshSuggestions();
        renderList(prices);
      }

      if (isServerAutoOn() && Date.now() - lastServerPullAt > 20000) {
        lastServerPullAt = Date.now();
        try {
          const res = await fetch('api/watch.php?action=get');
          const data = await readJson(res);
          if (res.ok && data.ok && data.state) {
            applyServerState(data.state, { applyRules: false, replaceItems: true });
            renderList(prices);
          }
        } catch {
          // ignore
        }
      }
    } catch (err) {
      els.tickStatus.textContent = 'Fetch error';
      setStatus(err.message || 'Could not refresh prices.', true);
    } finally {
      polling = false;
    }

    if (autoJobs.length && !autoBusy && !trading) {
      autoBusy = true;
      try {
        for (const job of autoJobs) {
          const item = watchlist.find((w) => w.symbol === job.symbol);
          if (!item) continue;
          if (job.side === 'BUY' && item.holding) {
            item._autoBusy = false;
            continue;
          }
          if (job.side === 'SELL' && !item.holding) {
            item._autoBusy = false;
            continue;
          }
          const result = await placeOrder(job.side, job.symbol, { auto: true });
          if (!result?.ok) {
            const w = watchlist.find((x) => x.symbol === job.symbol);
            if (w) w._autoBusy = false;
          }
        }
      } finally {
        autoBusy = false;
      }
    }
  }

  function startPolling() {
    if (timer) clearInterval(timer);
    poll();
    timer = setInterval(poll, POLL_MS);
  }

  async function addSymbol() {
    const symbol = normalizeSymbol(els.symbol.value);
    if (!symbol) {
      setStatus('Enter a symbol, e.g. BTCUSDT', true);
      return;
    }

    if (watchlist.some((w) => w.symbol === symbol)) {
      setStatus(`${symbol} is already on your watchlist.`, true);
      return;
    }

    els.addBtn.disabled = true;
    setStatus(`Adding ${symbol}…`);

    try {
      let base = Number(els.basePrice.value);
      if (!Number.isFinite(base) || base <= 0) {
        const prices = await fetchPrices([symbol]);
        base = Number(prices[symbol]);
        if (!Number.isFinite(base) || base <= 0) {
          throw new Error('Invalid symbol or price not found.');
        }
      }

      watchlist.unshift({
        symbol,
        basePrice: base,
        addedAt: Date.now(),
        lastSignal: 'WAIT',
        holding: false,
      });
      saveWatchlist();
      els.symbol.value = '';
      els.basePrice.value = '';
      setStatus(`${symbol} added · base ${fmt.num(base)}`);
      ensureAudio();
      lastSuggestAt = 0;
      await refreshSuggestions();
      renderList();
      await poll();
    } catch (err) {
      setStatus(err.message || 'Could not add symbol.', true);
    } finally {
      els.addBtn.disabled = false;
    }
  }

  els.addBtn.addEventListener('click', addSymbol);
  els.refreshApi.addEventListener('click', () => checkApiStatus());
  els.histLoad.addEventListener('click', () => loadHistory());
  els.histSync.addEventListener('click', () => syncHistory());
  els.histTrades.addEventListener('click', async (event) => {
    const btn = event.target.closest('[data-hist-sell]');
    if (!btn) return;
    const symbol = btn.getAttribute('data-hist-sell');
    if (!symbol) return;
    btn.disabled = true;
    try {
      await placeOrder('SELL', symbol);
      await loadHistory();
    } finally {
      btn.disabled = false;
    }
  });
  if (els.autoTrade) {
    els.autoTrade.addEventListener('change', () => {
      if (isAutoTradeOn() && isServerAutoOn() && els.serverAuto) {
        els.serverAuto.checked = false;
      }
      syncAutoBanner();
      scheduleServerSync();
      setStatus(
        isAutoTradeOn()
          ? 'Browser auto ON — tab open rakhna hoga.'
          : 'Browser auto OFF.'
      );
    });
  }

  if (els.serverAuto) {
    els.serverAuto.addEventListener('change', () => {
      if (isServerAutoOn() && els.autoTrade) {
        els.autoTrade.checked = false;
      }
      syncAutoBanner();
      scheduleServerSync();
      setStatus(
        isServerAutoOn()
          ? 'Server auto ON — cPanel cron set karo (CPANEL.md). Tab band OK.'
          : 'Server auto OFF.'
      );
    });
  }

  els.symbol.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      addSymbol();
    }
  });

  els.watchList.addEventListener('click', async (event) => {
    const btn = event.target.closest('button[data-action]');
    if (!btn) return;

    const symbol = btn.getAttribute('data-symbol');
    const action = btn.getAttribute('data-action');
    const item = watchlist.find((w) => w.symbol === symbol);
    if (!item) return;

    if (action === 'buy' || action === 'instant-buy' || action === 'sell') {
      if (action === 'buy' || action === 'instant-buy') {
        if (item.holding) {
          setStatus('Pehle sell karo — ek cycle me sirf 1 buy.', true);
          return;
        }
        const card = btn.closest('.watch-card');
        const cardInput = card?.querySelector('input[data-buy-usdt]');
        if (cardInput) syncBuyAmountInputs(cardInput.value);
        await placeOrder('BUY', symbol, { instant: true });
        return;
      }
      await placeOrder('SELL', symbol);
      return;
    }

    if (action === 'remove') {
      watchlist = watchlist.filter((w) => w.symbol !== symbol);
      saveWatchlist();
      setStatus(`${symbol} removed.`);
      renderList();
      return;
    }

    if (action === 'rebase') {
      try {
        const prices = await fetchPrices([symbol]);
        const now = Number(prices[symbol]);
        if (!Number.isFinite(now) || now <= 0) throw new Error('Price not found.');
        item.basePrice = now;
        item.lastSignal = 'WAIT';
        saveWatchlist();
        setStatus(`${symbol} base set to ${fmt.num(now)}`);
        renderList(prices);
      } catch (err) {
        setStatus(err.message || 'Could not update base price.', true);
      }
    }
  });

  els.watchList.addEventListener('input', (event) => {
    const input = event.target.closest('input[data-buy-usdt]');
    if (!input) return;
    syncBuyAmountInputs(input.value);
  });

  els.buyAmount.addEventListener('input', () => {
    syncBuyAmountInputs(els.buyAmount.value);
    if (lastHourMove) renderHourMove(lastHourMove);
  });

  [els.buyDrop, els.sellUp, els.feePct].filter(Boolean).forEach((input) => {
    input.addEventListener('input', () => {
      updateFeeHint();
      updateSellCalc();
      if (lastHourMove) renderHourMove(lastHourMove);
      scheduleServerSync();
      clearTimeout(input._rulesTimer);
      input._rulesTimer = setTimeout(() => poll(), 250);
    });
    input.addEventListener('change', () => {
      updateFeeHint();
      updateSellCalc();
      if (lastHourMove) renderHourMove(lastHourMove);
      scheduleServerSync();
      poll();
    });
  });
  if (els.buyAmount) {
    els.buyAmount.addEventListener('change', () => scheduleServerSync());
  }
  if (els.calcBuy) {
    els.calcBuy.addEventListener('input', updateSellCalc);
    els.calcBuy.addEventListener('change', updateSellCalc);
  }
  if (els.calcUsdt) {
    els.calcUsdt.addEventListener('input', () => {
      updateSellCalc();
      if (lastHourMove) renderHourMove(lastHourMove);
    });
    els.calcUsdt.addEventListener('change', () => {
      updateSellCalc();
      if (lastHourMove) renderHourMove(lastHourMove);
    });
  }
  if (els.calcHourBtn) {
    els.calcHourBtn.addEventListener('click', () => {
      checkHourMove();
    });
  }
  if (els.calcSymbol) {
    els.calcSymbol.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        checkHourMove();
      }
    });
  }
  updateFeeHint();
  updateSellCalc();

  document.addEventListener(
    'click',
    () => {
      ensureAudio();
    },
    { once: true }
  );

  async function loadSymbols() {
    try {
      const res = await fetch('api/market.php?action=symbols');
      const data = await readJson(res);
      if (!data.ok || !Array.isArray(data.symbols)) return;
      const frag = document.createDocumentFragment();
      data.symbols.forEach((symbol) => {
        const option = document.createElement('option');
        option.value = symbol;
        frag.appendChild(option);
      });
      els.symbolList.appendChild(frag);
    } catch {
      // optional
    }
  }

  loadAutoPref();
  checkApiStatus();
  loadSymbols();
  loadWatchFromServer().then(() => {
    renderList();
    startPolling();
  });
  updateHistorySymbolOptions();
  loadHistory();
  refreshTodayPnL();
})();
