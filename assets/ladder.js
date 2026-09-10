/**
 * Ladder / simulator front-end — multi-config (one setup per symbol).
 */
(function () {
  'use strict';

  if (!window.LADDER_AUTHED) return;

  var MODE = window.LADDER_MODE === 'sim' ? 'sim' : 'live';
  var IS_SIM = MODE === 'sim';
  var API = 'api/ladder.php';
  var PRICE_KEY = 'ladder_sim_prices';

  var state = {
    page: 1,
    perPage: 10,
    status: '',
    symbolFilter: '',
    payload: null,
    prices: {},
    wsMap: {},
    lastAutoSell: 0,
    busy: false,
    knownSymbols: []
  };

  function $(id) { return document.getElementById(id); }

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function num(value) {
    var n = parseFloat(value);
    return isFinite(n) ? n : 0;
  }

  function trimNum(value, decimals) {
    var n = num(value);
    var s = n.toFixed(decimals);
    return s.indexOf('.') >= 0 ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
  }

  function fmtPrice(value) {
    var n = num(value);
    if (!n) return '—';
    if (n >= 1000) return n.toFixed(2);
    if (n >= 1) return n.toFixed(4);
    return trimNum(n, 8);
  }

  function fmtUsd(value, decimals) {
    if (value === null || value === undefined || value === '') return '—';
    return num(value).toFixed(decimals === undefined ? 2 : decimals);
  }

  function fmtSigned(value, decimals) {
    if (value === null || value === undefined || value === '') return '—';
    var n = num(value);
    return (n >= 0 ? '+' : '') + n.toFixed(decimals === undefined ? 4 : decimals);
  }

  function fmtPct(value) {
    if (value === null || value === undefined || value === '') return '—';
    var n = num(value);
    return (n >= 0 ? '+' : '') + n.toFixed(3) + '%';
  }

  function fmtTime(ms) {
    if (!ms) return 'never';
    return new Date(num(ms)).toLocaleString();
  }

  function say(message, kind) {
    var el = $('lad-status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'lad-status' + (kind ? ' is-' + kind : '');
  }

  function loadLocalPrices() {
    try {
      var raw = localStorage.getItem(PRICE_KEY);
      var parsed = raw ? JSON.parse(raw) : {};
      if (parsed && typeof parsed === 'object') state.prices = parsed;
    } catch (e) { state.prices = {}; }
  }

  function saveLocalPrices() {
    try { localStorage.setItem(PRICE_KEY, JSON.stringify(state.prices)); } catch (e) { /* ignore */ }
  }

  function priceFor(symbol) {
    symbol = String(symbol || '').toUpperCase();
    if (IS_SIM) return num(state.prices[symbol]);
    if (state.wsMap[symbol] > 0) return state.wsMap[symbol];
    if (state.payload && state.payload.prices && state.payload.prices[symbol]) {
      return num(state.payload.prices[symbol]);
    }
    return 0;
  }

  function collectSimPricesFromInputs() {
    if (!IS_SIM) return;
    Array.prototype.forEach.call(document.querySelectorAll('[data-cfg-price]'), function (input) {
      var symbol = input.getAttribute('data-cfg-price');
      var v = parseFloat(input.value);
      if (symbol && isFinite(v) && v > 0) state.prices[symbol] = v;
    });
    saveLocalPrices();
  }

  function baseParams() {
    collectSimPricesFromInputs();
    var params = {
      mode: MODE,
      page: state.page,
      perPage: state.perPage,
      status: state.status,
      symbolFilter: state.symbolFilter
    };
    if (IS_SIM) params.prices = state.prices;
    return params;
  }

  async function apiGet(action) {
    var params = baseParams();
    params.action = action;
    var qs = Object.keys(params)
      .filter(function (k) {
        var v = params[k];
        return v !== '' && v !== null && typeof v !== 'object';
      })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
      .join('&');

    // prices map as repeated query is awkward — use POST for sim state with body
    if (IS_SIM) {
      return apiPost(action, {});
    }

    var res = await fetch(API + '?' + qs, { headers: { Accept: 'application/json' } });
    var data = await res.json().catch(function () { return null; });
    if (!data) throw new Error('Bad response from server (HTTP ' + res.status + ')');
    if (data.authRequired) { window.location.reload(); throw new Error('Session expired'); }
    if (!data.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function apiPost(action, body) {
    var payload = Object.assign(baseParams(), body || {}, { action: action });
    var res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload)
    });
    var data = await res.json().catch(function () { return null; });
    if (!data) throw new Error('Bad response from server (HTTP ' + res.status + ')');
    if (data.authRequired) { window.location.reload(); throw new Error('Session expired'); }
    if (!data.ok) {
      var err = new Error(data.error || 'Request failed');
      err.data = data;
      throw err;
    }
    return data;
  }

  function applyPayload(data) {
    state.payload = data;
    if (data.prices && typeof data.prices === 'object') {
      Object.keys(data.prices).forEach(function (sym) {
        if (!IS_SIM || !state.prices[sym]) state.prices[sym] = num(data.prices[sym]);
      });
    }
    if (data.page) {
      state.page = data.page.page;
      state.perPage = data.page.perPage;
    }
    renderConfigs(data.configs || []);
    renderSymbolFilter(data.configs || [], data.dashboard);
    renderAll();
    if (!IS_SIM) connectSockets(data.configs || []);
  }

  function renderConfigs(configs) {
    var tbody = $('lad-config-rows');
    if (!tbody) return;

    $('lad-config-summary').textContent = configs.length
      ? (configs.length + ' coin(s): ' + configs.map(function (c) { return c.symbol; }).join(', '))
      : 'No configs yet';

    if (!configs.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="lad-empty">No configurations yet. Add one below.</td></tr>';
      return;
    }

    tbody.innerHTML = configs.map(function (cfg) {
      var price = priceFor(cfg.symbol);
      var priceCell = IS_SIM
        ? '<input class="lad-price-input" data-cfg-price="' + esc(cfg.symbol) + '" type="number" step="any" min="0" value="' +
          (price > 0 ? esc(trimNum(price, 8)) : '') + '" placeholder="test price">'
        : fmtPrice(price);

      return '<tr>' +
        '<td><strong>' + esc(cfg.symbol) + '</strong></td>' +
        '<td>' + fmtUsd(cfg.dailyUsdt) + '</td>' +
        '<td>' + esc(cfg.feePct) + '</td>' +
        '<td>' + esc(cfg.netProfitPct) + '</td>' +
        '<td>' + (cfg.lastBuyDate ? esc(cfg.lastBuyDate) + ' UTC' : '—') + '</td>' +
        (IS_SIM ? '<td>' + priceCell + '</td>' : '') +
        '<td class="lad-col-act">' +
          '<button type="button" class="lad-row-btn" data-cfg-buy="' + esc(cfg.id) + '">Buy</button> ' +
          '<button type="button" class="lad-row-btn ghost-btn" data-cfg-edit="' + esc(cfg.id) + '">Edit</button> ' +
          '<button type="button" class="lad-row-btn lad-row-btn-del" data-cfg-del="' + esc(cfg.id) + '">✕</button>' +
        '</td></tr>';
    }).join('');

    Array.prototype.forEach.call(tbody.querySelectorAll('[data-cfg-buy]'), function (btn) {
      btn.addEventListener('click', withBusy(function () { return buyConfig(btn.getAttribute('data-cfg-buy')); }));
    });
    Array.prototype.forEach.call(tbody.querySelectorAll('[data-cfg-edit]'), function (btn) {
      btn.addEventListener('click', function () { editConfig(btn.getAttribute('data-cfg-edit')); });
    });
    Array.prototype.forEach.call(tbody.querySelectorAll('[data-cfg-del]'), function (btn) {
      btn.addEventListener('click', withBusy(function () { return deleteConfig(btn.getAttribute('data-cfg-del')); }));
    });
    Array.prototype.forEach.call(tbody.querySelectorAll('[data-cfg-price]'), function (input) {
      input.addEventListener('change', function () {
        var symbol = input.getAttribute('data-cfg-price');
        var v = parseFloat(input.value);
        if (symbol && isFinite(v) && v > 0) {
          state.prices[symbol] = v;
          saveLocalPrices();
          renderAll();
        }
      });
    });
  }

  function renderSymbolFilter(configs, dash) {
    var sel = $('flt-symbol');
    if (!sel) return;
    var current = state.symbolFilter;
    var symbols = {};
    (configs || []).forEach(function (c) { symbols[c.symbol] = true; });
    if (dash && dash.symbols) dash.symbols.forEach(function (s) { symbols[s] = true; });
    var list = Object.keys(symbols).sort();
    sel.innerHTML = '<option value="">All</option>' + list.map(function (s) {
      return '<option value="' + esc(s) + '"' + (s === current ? ' selected' : '') + '>' + esc(s) + '</option>';
    }).join('');
  }

  function clearConfigForm() {
    $('cfg-id').value = '';
    $('cfg-symbol').value = '';
    $('cfg-daily').value = '';
    $('cfg-fee').value = '0.1';
    $('cfg-profit').value = '0.3';
    if ($('cfg-form-title')) $('cfg-form-title').textContent = 'Add configuration';
  }

  function editConfig(id) {
    var configs = (state.payload && state.payload.configs) || [];
    var cfg = configs.filter(function (c) { return String(c.id) === String(id); })[0];
    if (!cfg) return;
    $('cfg-id').value = cfg.id;
    $('cfg-symbol').value = cfg.symbol;
    $('cfg-daily').value = cfg.dailyUsdt;
    $('cfg-fee').value = cfg.feePct;
    $('cfg-profit').value = cfg.netProfitPct;
    if ($('cfg-form-title')) $('cfg-form-title').textContent = 'Edit ' + cfg.symbol;
    $('cfg-symbol').focus();
  }

  function renderAll() {
    var data = state.payload;
    if (!data) return;
    var dash = data.dashboard || {};

    $('lad-open-count').textContent = dash.openCount === undefined ? '—' : dash.openCount;
    $('lad-matured').textContent = dash.maturedCount === undefined ? '—' : dash.maturedCount;
    $('lad-lastrun').textContent = data.lastRunAt ? fmtTime(data.lastRunAt) : 'never';
    if (dash.nextBuyAtUtc) {
      $('lad-lastrun').title = 'Next daily buy: ' + dash.nextBuyAtUtc;
    }

    if (IS_SIM) {
      $('lad-cash').textContent = fmtUsd(data.simCash) + ' USDT';
      if ($('lad-price')) $('lad-price').textContent = Object.keys(state.prices).length
        ? Object.keys(state.prices).map(function (s) { return s + ' ' + fmtPrice(state.prices[s]); }).join(' · ')
        : 'set test prices';
    } else {
      var apiEl = $('lad-api-status');
      if (apiEl) {
        if (data.apiConnected === true) {
          apiEl.textContent = 'Connected';
          apiEl.className = 'lad-value lad-up';
        } else {
          apiEl.textContent = 'Not connected';
          apiEl.className = 'lad-value lad-down';
          apiEl.title = data.balanceError || '';
        }
      }
      $('lad-cash').textContent = data.usdtFree != null ? fmtUsd(data.usdtFree) + ' USDT' : '—';
    }

    $('dash-invested-open').textContent = fmtUsd(dash.investedOpen) + ' USDT';
    $('dash-invested-total').textContent = 'all time: ' + fmtUsd(dash.investedTotal) + ' USDT';
    $('dash-open-value').textContent = dash.openValue != null ? fmtUsd(dash.openValue) + ' USDT' : '—';
    $('dash-open-qty').textContent = (dash.configs || 0) + ' config(s)';

    var unreal = num(dash.unrealized);
    var unrealEl = $('dash-unrealized');
    unrealEl.textContent = dash.unrealized == null ? '—' : fmtSigned(unreal) + ' USDT';
    unrealEl.className = unreal >= 0 ? 'lad-up' : 'lad-down';
    $('dash-unrealized-pct').textContent = fmtPct(dash.unrealizedPct);

    var realized = num(dash.realizedProfit);
    var realEl = $('dash-realized');
    realEl.textContent = fmtSigned(realized) + ' USDT';
    realEl.className = realized >= 0 ? 'lad-up' : 'lad-down';
    $('dash-sold-count').textContent = (dash.soldCount || 0) + ' sold';

    var total = num(dash.totalProfit);
    var totalEl = $('dash-total');
    totalEl.textContent = fmtSigned(total) + ' USDT';
    totalEl.className = total >= 0 ? 'lad-up' : 'lad-down';
    $('dash-avg-hold').textContent = dash.avgHoldDays == null ? 'no sales yet' : 'avg hold ' + dash.avgHoldDays + ' days';
    $('dash-best').textContent = dash.bestProfit == null ? '—' : fmtSigned(dash.bestProfit) + ' USDT';
    $('dash-worst').textContent = dash.worstProfit == null ? '—' : 'worst ' + fmtSigned(dash.worstProfit) + ' USDT';

    var rows = ((data.page && data.page.rows) || []).map(function (row) {
      return reprice(row, priceFor(row.symbol));
    });
    renderRows(rows);
    renderByDay(dash.byDay || []);
    renderLog(data.lastRunLog || []);
    renderPager(data.page);
  }

  function reprice(row, price) {
    var copy = Object.assign({}, row);
    if (copy.status !== 'OPEN' || !price) return copy;
    var keep = 1 - num(copy.feePct) / 100;
    var spot = num(copy.qty) * price;
    var value = spot * keep;
    copy.nowSpotUsdt = spot;
    copy.nowValueUsdt = value;
    copy.nowProfitUsdt = value - num(copy.costUsdt);
    copy.nowProfitPct = num(copy.costUsdt) > 0 ? (copy.nowProfitUsdt / num(copy.costUsdt)) * 100 : null;
    copy.matured = num(copy.targetPrice) > 0 && price >= num(copy.targetPrice);
    copy.toTargetPct = num(copy.targetPrice) > 0 ? ((num(copy.targetPrice) - price) / price) * 100 : null;
    return copy;
  }

  function renderRows(rows) {
    var tbody = $('lad-rows');
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="10" class="lad-empty">' +
        (state.status || state.symbolFilter ? 'No entries match this filter.' : 'No entries yet. Add a config and buy.') +
        '</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      var isSold = row.status === 'SOLD';
      var matured = !!row.matured;
      var price = priceFor(row.symbol);
      var cls = isSold ? 'is-sold' : (matured ? 'is-matured' : '');
      var leftover = !isSold && String(row.note || '').indexOf('Remainder') >= 0;
      var pill = isSold
        ? '<span class="lad-pill lad-pill-sold">SOLD</span>'
        : leftover
          ? '<span class="lad-pill lad-pill-open">LEFTOVER</span>'
          : (matured ? '<span class="lad-pill lad-pill-ready">READY</span>' : '<span class="lad-pill lad-pill-open">HOLDING</span>');

      var nowCell = '—';
      var plCell = '—';
      var valueCell = '—';
      if (isSold) {
        nowCell = fmtPrice(row.sellPrice) + '<br><small>' + fmtUsd(row.proceedsUsdt, 4) + ' USDT</small>';
        valueCell = fmtUsd(row.proceedsUsdt, 4);
        plCell = '<span class="' + (num(row.profitUsdt) >= 0 ? 'lad-up' : 'lad-down') + '">' +
          fmtSigned(row.profitUsdt) + '<br><small>' + fmtPct(row.profitPct) + '</small></span>';
      } else if (price) {
        nowCell = fmtPrice(price) + '<br><small>' +
          (matured ? 'target reached' : (row.toTargetPct != null ? fmtPct(row.toTargetPct) + ' to go' : '')) +
          '</small>';
        valueCell = '<strong>' + fmtUsd(row.nowSpotUsdt, 4) + '</strong>';
        plCell = '<span class="' + (num(row.nowProfitUsdt) >= 0 ? 'lad-up' : 'lad-down') + '">' +
          fmtSigned(row.nowProfitUsdt) + '<br><small>' + fmtPct(row.nowProfitPct) + '</small></span>';
      }

      var action = isSold
        ? '<small>' + esc(row.sellReason || '') + '</small>'
        : '<button type="button" class="lad-row-btn' + (matured ? ' lad-row-btn-ready' : '') +
          '" data-sell="' + esc(row.id) + '">' + (matured ? 'Sell now' : 'Sell') + '</button>' +
          (IS_SIM ? ' <button type="button" class="lad-row-btn lad-row-btn-del" data-del="' + esc(row.id) + '">✕</button>' : '');

      return '<tr class="' + cls + '">' +
        '<td>' + esc(row.buyDate) + '<br><small>' + esc(row.symbol) +
          (leftover ? ' · leftover' : '') + '</small></td>' +
        '<td>' + fmtPrice(row.buyPrice) + '</td>' +
        '<td>' + trimNum(row.qty, 8) + '</td>' +
        '<td>' + fmtUsd(row.costUsdt) + '</td>' +
        '<td>' + valueCell + '</td>' +
        '<td>' + fmtPrice(row.targetPrice) + '<br><small>' + fmtUsd(row.targetUsdt, 4) + ' USDT</small></td>' +
        '<td>' + nowCell + '</td>' +
        '<td>' + plCell + '</td>' +
        '<td>' + pill + (row.holdDays != null ? '<br><small>' + row.holdDays + 'd</small>' : '') + '</td>' +
        '<td class="lad-col-act">' + action + '</td></tr>';
    }).join('');

    Array.prototype.forEach.call(tbody.querySelectorAll('[data-sell]'), function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-sell');
        var row = rows.filter(function (r) { return String(r.id) === id; })[0];
        sellEntry(id, row);
      });
    });
    Array.prototype.forEach.call(tbody.querySelectorAll('[data-del]'), function (btn) {
      btn.addEventListener('click', function () { deleteEntry(btn.getAttribute('data-del')); });
    });
  }

  function renderByDay(days) {
    var tbody = $('lad-byday');
    if (!days.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="lad-empty">No buys yet.</td></tr>';
      return;
    }
    tbody.innerHTML = days.map(function (day) {
      return '<tr><td>' + esc(day.date) + '</td><td>' + day.buys + '</td><td>' + fmtUsd(day.invested) +
        '</td><td>' + day.openCount + '</td><td>' + day.sold + '</td><td class="' +
        (num(day.profit) >= 0 ? 'lad-up' : 'lad-down') + '">' +
        (day.sold ? fmtSigned(day.profit) : '—') + '</td></tr>';
    }).join('');
  }

  function renderLog(log) {
    var ul = $('lad-log');
    if (!log.length) {
      ul.innerHTML = '<li>Nothing yet — the cron writes here every minute.</li>';
      return;
    }
    ul.innerHTML = log.map(function (row) {
      var when = row.t ? new Date(num(row.t)).toLocaleTimeString() : '';
      return '<li>' + esc(when) + ' — ' + esc(row.msg || '') + '</li>';
    }).join('');
  }

  function renderPager(page) {
    if (!page) return;
    $('pg-info').textContent = 'Page ' + page.page + ' of ' + page.pages + ' · ' + page.total + ' entries';
    $('pg-prev').disabled = page.page <= 1;
    $('pg-next').disabled = page.page >= page.pages;
  }

  function connectSockets(configs) {
    (configs || []).forEach(function (cfg) {
      var symbol = cfg.symbol;
      if (!symbol || state.wsMap['__sock_' + symbol]) return;
      try {
        var ws = new WebSocket('wss://stream.binance.com:9443/ws/' + symbol.toLowerCase() + '@ticker');
        state.wsMap['__sock_' + symbol] = ws;
        ws.onmessage = function (event) {
          var data = JSON.parse(event.data);
          var last = parseFloat(data.c);
          if (isFinite(last) && last > 0) {
            state.wsMap[symbol] = last;
            renderAll();
            renderConfigs((state.payload && state.payload.configs) || []);
          }
        };
      } catch (e) { /* ignore */ }
    });
  }

  function withBusy(fn) {
    return async function () {
      if (state.busy) return;
      state.busy = true;
      try { await fn(); }
      catch (err) { say(err.message || String(err), 'bad'); }
      finally { state.busy = false; }
    };
  }

  async function reload(message) {
    var data = await apiGet('state');
    applyPayload(data);
    if (message) say(message, 'good');
  }

  var doSaveConfig = withBusy(async function () {
    var symbol = ($('cfg-symbol').value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    $('cfg-symbol').value = symbol;
    if (!symbol) {
      say('Enter a symbol first, e.g. ETHUSDT.', 'bad');
      return;
    }
    var body = {
      config: {
        id: $('cfg-id').value || undefined,
        symbol: symbol,
        dailyUsdt: $('cfg-daily').value,
        feePct: $('cfg-fee').value,
        netProfitPct: $('cfg-profit').value,
        autoBuyEnabled: true,
        autoSellEnabled: true
      }
    };
    if ($('cfg-sim-start')) body.simStartUsdt = $('cfg-sim-start').value;

    say('Saving…');
    var data = await apiPost('config-save', body);
    applyPayload(data);
    clearConfigForm();
    var warnings = data.warnings || [];
    $('lad-config-warnings').innerHTML = warnings.map(function (w) {
      return '<p class="lad-alert lad-alert-warn">' + esc(w) + '</p>';
    }).join('');
    say(data.message || ('Saved ' + symbol), warnings.length ? 'bad' : 'good');
  });

  async function buyConfig(id) {
    var configs = (state.payload && state.payload.configs) || [];
    var cfg = configs.filter(function (c) { return String(c.id) === String(id); })[0];
    if (!cfg) return;

    if (IS_SIM) {
      collectSimPricesFromInputs();
      if (!(priceFor(cfg.symbol) > 0)) {
        say('Enter a Test price for ' + cfg.symbol + ' in the config table.', 'bad');
        return;
      }
    } else if (!window.confirm('Place a REAL market buy of ' + fmtUsd(cfg.dailyUsdt) + ' USDT on ' + cfg.symbol + '?')) {
      return;
    }

    var body = { configId: id };
    var amount = parseFloat(($('sim-amount') || {}).value);
    if (isFinite(amount) && amount > 0) body.usdt = amount;
    say('Buying ' + cfg.symbol + '…');
    var data = await apiPost('buy-now', body);
    applyPayload(data);
    say(data.message || 'Bought.', 'good');
  }

  async function deleteConfig(id) {
    var configs = (state.payload && state.payload.configs) || [];
    var cfg = configs.filter(function (c) { return String(c.id) === String(id); })[0];
    var openForSymbol = 0;
    if (cfg && cfg.symbol) {
      var sym = cfg.symbol;
      openForSymbol = ((state.payload && state.payload.page && state.payload.page.rows) || [])
        .filter(function (e) { return e.status === 'OPEN' && e.symbol === sym; }).length;
    }
    var msg = openForSymbol > 0
      ? 'Remove ' + (cfg ? cfg.symbol : 'this') + ' config?\n\n'
        + openForSymbol + ' open holding(s) will stay in the table.\n'
        + 'No more daily buys for this coin — cron still sells when target is hit.'
      : 'Remove this configuration?';
    if (!window.confirm(msg)) return;

    var body = { id: id };
    if (cfg && cfg.symbol) body.symbol = cfg.symbol;
    var data = await apiPost('config-delete', body);
    applyPayload(data);
    say(data.message || 'Removed.', 'good');
  }

  async function sellEntry(id, row) {
    if (state.busy) return;
    if (IS_SIM) {
      collectSimPricesFromInputs();
      if (!(priceFor(row.symbol) > 0)) {
        say('Enter a Test price for ' + row.symbol + '.', 'bad');
        return;
      }
    }

    var matured = row && row.matured;
    var force = false;
    if (!matured) {
      if (!window.confirm('This entry has NOT reached its profit target.\nSell anyway?')) return;
      force = true;
    } else if (!IS_SIM && !window.confirm('Sell this entry on Binance now?')) {
      return;
    }

    state.busy = true;
    try {
      say('Selling…');
      var data = await apiPost('sell', { id: id, force: force, symbol: row.symbol });
      applyPayload(data);
      say(data.message || 'Sold.', 'good');
    } catch (err) {
      say(err.message, 'bad');
    } finally {
      state.busy = false;
    }
  }

  var doSellMatured = withBusy(async function () {
    if (IS_SIM) collectSimPricesFromInputs();
    else if (!window.confirm('Sell every matured entry on Binance now?')) return;
    say('Selling matured entries…');
    var data = await apiPost('sell-matured', {});
    applyPayload(data);
    say(data.message || 'Done.', 'good');
  });

  var doRun = withBusy(async function () {
    if (IS_SIM) collectSimPricesFromInputs();
    say('Running automation pass…');
    var data = await apiPost('run', {});
    applyPayload(data);
    say(data.message || 'Pass done.', 'good');
  });

  var doReset = withBusy(async function () {
    if (!window.confirm('Clear all simulated entries? Configs stay.')) return;
    var data = await apiPost('reset', {});
    applyPayload(data);
    say(data.message || 'Reset.', 'good');
  });

  async function deleteEntry(id) {
    if (state.busy || !window.confirm('Remove this simulated entry?')) return;
    state.busy = true;
    try {
      var data = await apiPost('delete-entry', { id: id });
      applyPayload(data);
      say('Entry removed.', 'good');
    } catch (err) {
      say(err.message, 'bad');
    } finally {
      state.busy = false;
    }
  }

  function wire() {
    loadLocalPrices();
    if ($('cfg-sim-start') && state.payload) {
      // filled on first reload
    }

    $('cfg-save').addEventListener('click', doSaveConfig);
    if ($('cfg-clear')) $('cfg-clear').addEventListener('click', clearConfigForm);
    $('act-sell-matured').addEventListener('click', doSellMatured);
    $('act-run').addEventListener('click', doRun);
    $('act-refresh').addEventListener('click', withBusy(function () { return reload('Refreshed.'); }));
    if ($('act-reset')) $('act-reset').addEventListener('click', doReset);
    if ($('act-buy')) {
      // Top buy button buys the first config / currently edited symbol
      $('act-buy').addEventListener('click', withBusy(async function () {
        var configs = (state.payload && state.payload.configs) || [];
        if (!configs.length) {
          say('Add a configuration first.', 'bad');
          return;
        }
        var id = $('cfg-id').value || configs[0].id;
        await buyConfig(id);
      }));
    }

    $('flt-status').addEventListener('change', withBusy(function () {
      state.status = $('flt-status').value;
      state.page = 1;
      return reload();
    }));
    if ($('flt-symbol')) {
      $('flt-symbol').addEventListener('change', withBusy(function () {
        state.symbolFilter = $('flt-symbol').value;
        state.page = 1;
        return reload();
      }));
    }
    $('flt-perpage').addEventListener('change', withBusy(function () {
      state.perPage = parseInt($('flt-perpage').value, 10) || 10;
      state.page = 1;
      return reload();
    }));
    $('pg-prev').addEventListener('click', withBusy(function () {
      state.page = Math.max(1, state.page - 1);
      return reload();
    }));
    $('pg-next').addEventListener('click', withBusy(function () {
      state.page += 1;
      return reload();
    }));

    loadSymbols();
  }

  async function loadSymbols() {
    try {
      var res = await fetch('api/market.php?action=symbols');
      var data = await res.json();
      if (!data || !data.ok) return;
      state.knownSymbols = data.symbols || [];
      var list = $('lad-symbol-list');
      if (list) {
        list.innerHTML = state.knownSymbols.slice(0, 800).map(function (s) {
          return '<option value="' + esc(s) + '"></option>';
        }).join('');
      }
    } catch (e) { /* optional */ }
  }

  wire();
  reload().then(function () {
    if ($('cfg-sim-start') && state.payload) {
      $('cfg-sim-start').value = state.payload.simStartUsdt || 100;
    }
  }).catch(function (err) { say(err.message || String(err), 'bad'); });

  setInterval(function () {
    if (!state.busy) reload().catch(function () { /* ignore */ });
  }, 30000);
})();
