(function () {
  'use strict';

  var API = 'api/market.php';
  var chartEl = document.getElementById('graph-chart');
  var volumeEl = document.getElementById('graph-volume');
  var priceChart = null;
  var volumeChart = null;
  var candleSeries = null;
  var volumeSeries = null;

  function $(id) { return document.getElementById(id); }

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(value) {
    var n = parseFloat(value);
    return isFinite(n) ? n : 0;
  }

  function fmtPrice(value) {
    var n = num(value);
    if (!n) return '—';
    if (n >= 1000) return n.toFixed(2);
    if (n >= 1) return n.toFixed(4);
    return n.toFixed(8).replace(/0+$/, '').replace(/\.$/, '');
  }

  function fmtPct(value) {
    var n = num(value);
    return (n >= 0 ? '+' : '') + n.toFixed(2) + '%';
  }

  function fmtVol(value) {
    var n = num(value);
    if (n >= 1e6) return (n / 1e6).toFixed(2) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(2) + 'K';
    return n.toFixed(2);
  }

  function say(message, isError) {
    var el = $('graph-status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'graph-status' + (isError ? ' is-error' : '');
  }

  function normalizeSymbol(raw) {
    return String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  }

  function ensureCharts() {
    if (priceChart || typeof LightweightCharts === 'undefined') {
      return;
    }

    var common = {
      layout: {
        background: { color: '#0b1220' },
        textColor: '#9aa4b2',
      },
      grid: {
        vertLines: { color: 'rgba(255,255,255,0.04)' },
        horzLines: { color: 'rgba(255,255,255,0.04)' },
      },
      rightPriceScale: { borderColor: 'rgba(255,255,255,0.08)' },
      timeScale: { borderColor: 'rgba(255,255,255,0.08)' },
      crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
    };

    priceChart = LightweightCharts.createChart(chartEl, Object.assign({}, common, {
      width: chartEl.clientWidth,
      height: chartEl.clientHeight,
    }));
    candleSeries = priceChart.addCandlestickSeries({
      upColor: '#0f7a5a',
      downColor: '#b03030',
      borderUpColor: '#0f7a5a',
      borderDownColor: '#b03030',
      wickUpColor: '#0f7a5a',
      wickDownColor: '#b03030',
    });

    volumeChart = LightweightCharts.createChart(volumeEl, Object.assign({}, common, {
      width: volumeEl.clientWidth,
      height: volumeEl.clientHeight,
    }));
    volumeSeries = volumeChart.addHistogramSeries({
      priceFormat: { type: 'volume' },
      priceScaleId: '',
    });
    volumeChart.priceScale('').applyOptions({
      scaleMargins: { top: 0.1, bottom: 0 },
    });

    priceChart.timeScale().subscribeVisibleLogicalRangeChange(function (range) {
      if (range && volumeChart) {
        volumeChart.timeScale().setVisibleLogicalRange(range);
      }
    });

    window.addEventListener('resize', function () {
      if (priceChart) {
        priceChart.applyOptions({ width: chartEl.clientWidth, height: chartEl.clientHeight });
      }
      if (volumeChart) {
        volumeChart.applyOptions({ width: volumeEl.clientWidth, height: volumeEl.clientHeight });
      }
    });
  }

  function renderStats(data) {
    $('graph-stats').hidden = false;
    $('stat-symbol').textContent = data.symbol;
    $('stat-period').textContent = data.from + ' → ' + data.to;
    $('stat-close').textContent = fmtPrice(data.close) + ' USDT';
    var changeEl = $('stat-change');
    changeEl.textContent = fmtPct(data.changePct);
    changeEl.className = 'graph-stat-value ' + (num(data.changePct) >= 0 ? 'is-up' : 'is-down');
    $('stat-high').textContent = fmtPrice(data.high);
    $('stat-low').textContent = fmtPrice(data.low);
  }

  function renderTable(candles) {
    var tbody = $('graph-rows');
    if (!candles.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="graph-empty">No data.</td></tr>';
      return;
    }

    var rows = candles.slice().reverse();
    tbody.innerHTML = rows.map(function (c) {
      var cls = num(c.changePct) >= 0 ? 'is-up' : 'is-down';
      return '<tr>' +
        '<td>' + esc(c.date) + '</td>' +
        '<td>' + fmtPrice(c.open) + '</td>' +
        '<td>' + fmtPrice(c.high) + '</td>' +
        '<td>' + fmtPrice(c.low) + '</td>' +
        '<td>' + fmtPrice(c.close) + '</td>' +
        '<td class="' + cls + '">' + fmtPct(c.changePct) + '</td>' +
        '<td>' + fmtVol(c.volume) + '</td>' +
        '</tr>';
    }).join('');
  }

  function renderCharts(candles) {
    ensureCharts();
    if (!candleSeries || !volumeSeries) {
      say('Chart library failed to load.', true);
      return;
    }

    var ohlc = candles.map(function (c) {
      return {
        time: c.date,
        open: num(c.open),
        high: num(c.high),
        low: num(c.low),
        close: num(c.close),
      };
    });

    var vol = candles.map(function (c) {
      return {
        time: c.date,
        value: num(c.volume),
        color: num(c.close) >= num(c.open) ? 'rgba(15,122,90,0.55)' : 'rgba(176,48,48,0.55)',
      };
    });

    candleSeries.setData(ohlc);
    volumeSeries.setData(vol);
    priceChart.timeScale().fitContent();
    volumeChart.timeScale().fitContent();
  }

  async function loadSymbols() {
    try {
      var res = await fetch(API + '?action=symbols');
      var data = await res.json();
      if (!data.ok || !Array.isArray(data.symbols)) return;
      var list = $('graph-symbol-list');
      list.innerHTML = data.symbols.slice(0, 200).map(function (sym) {
        return '<option value="' + esc(sym) + '"></option>';
      }).join('');
    } catch (e) { /* ignore */ }
  }

  async function loadChart() {
    var symbol = normalizeSymbol($('graph-symbol').value);
    $('graph-symbol').value = symbol;
    var days = parseInt($('graph-days').value, 10) || 30;

    if (!symbol) {
      say('Enter a symbol, e.g. ETHUSDT.', true);
      return;
    }

    say('Loading ' + symbol + '…');
    try {
      var url = API + '?action=history&symbol=' + encodeURIComponent(symbol) + '&days=' + days;
      var res = await fetch(url);
      var data = await res.json();
      if (!data.ok) {
        throw new Error(data.error || 'Failed to load chart data');
      }

      renderStats(data);
      renderCharts(data.candles || []);
      renderTable(data.candles || []);
      say('Loaded ' + data.days + ' daily candles for ' + data.symbol + '.', false);

      if (window.history && window.history.replaceState) {
        var qs = '?symbol=' + encodeURIComponent(symbol) + '&days=' + days;
        window.history.replaceState(null, '', 'graph.php' + qs);
      }
    } catch (err) {
      say(err.message || String(err), true);
    }
  }

  $('graph-load').addEventListener('click', loadChart);
  $('graph-symbol').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') loadChart();
  });
  $('graph-days').addEventListener('change', loadChart);

  loadSymbols();
  loadChart();
})();
