/**
 * Ladder transaction calendar (FullCalendar).
 */
(function () {
  'use strict';

  if (!window.CALENDAR_AUTHED) return;

  var MODE = window.CALENDAR_MODE === 'sim' ? 'sim' : 'live';
  var API = 'api/ladder.php';
  var calendar = null;
  var entries = [];

  function $(id) { return document.getElementById(id); }

  function say(msg, kind) {
    var el = $('cal-status');
    if (!el) return;
    el.textContent = msg || '';
    el.className = 'lad-status' + (kind ? ' is-' + kind : '');
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function num(v) {
    var n = parseFloat(v);
    return isFinite(n) ? n : 0;
  }

  function fmtUsd(v, d) {
    if (v == null || v === '') return '—';
    return num(v).toFixed(d == null ? 2 : d);
  }

  function fmtDateTimeUtc(ms) {
    if (!ms) return '—';
    var d = new Date(num(ms));
    if (isNaN(d.getTime())) return '—';
    return d.toISOString().slice(0, 19).replace('T', ' ') + ' UTC';
  }

  function fmtHold(ms) {
    if (ms <= 0) return '0m';
    var mins = ms / 60000;
    if (mins < 60) return Math.round(mins) + ' min';
    var hrs = mins / 60;
    if (hrs < 48) return hrs.toFixed(1) + ' h';
    return (hrs / 24).toFixed(2) + ' d';
  }

  function isLeftover(row) {
    return row.status === 'OPEN' && String(row.note || '').indexOf('Remainder') >= 0;
  }

  function entryKind(row) {
    if (row.status === 'SOLD') return 'sold';
    if (isLeftover(row)) return 'left';
    return 'hold';
  }

  function eventClass(kind) {
    if (kind === 'sold') return 'cal-ev-sold';
    if (kind === 'left') return 'cal-ev-left';
    return 'cal-ev-hold';
  }

  function entryToEvent(row) {
    var buyAt = num(row.buyAt);
    if (!buyAt) return null;

    var sold = row.status === 'SOLD';
    var endMs = sold ? num(row.sellAt) : Date.now();
    if (!endMs || endMs <= buyAt) {
      endMs = buyAt + 15 * 60000;
    }

    var holdMs = (sold ? num(row.sellAt) : Date.now()) - buyAt;
    var kind = entryKind(row);
    var profit = sold ? num(row.profitUsdt) : num(row.nowProfitUsdt);
    var cost = num(row.costUsdt);

    var title = row.symbol + ' · ' + fmtUsd(cost) + ' USDT';
    if (sold) {
      title += ' · sold ' + (profit >= 0 ? '+' : '') + fmtUsd(profit, 4);
    } else {
      title += ' · hold ' + fmtHold(holdMs);
    }

    return {
      id: String(row.id),
      title: title,
      start: new Date(buyAt),
      end: new Date(endMs),
      classNames: [eventClass(kind)],
      extendedProps: { row: row, kind: kind, holdMs: holdMs }
    };
  }

  function buildEvents(list) {
    return list.map(entryToEvent).filter(Boolean);
  }

  function uniqueSymbols(list) {
    var map = {};
    list.forEach(function (r) { map[r.symbol] = true; });
    return Object.keys(map).sort();
  }

  function renderSymbolFilter(list) {
    var sel = $('cal-symbol');
    if (!sel) return;
    var current = sel.value;
    var symbols = uniqueSymbols(list);
    sel.innerHTML = '<option value="">All</option>' + symbols.map(function (s) {
      return '<option value="' + esc(s) + '">' + esc(s) + '</option>';
    }).join('');
    if (current && symbols.indexOf(current) >= 0) sel.value = current;
  }

  function filteredEntries() {
    var sym = ($('cal-symbol') && $('cal-symbol').value) || '';
    var st = ($('cal-status') && $('cal-status').value) || '';
    return entries.filter(function (r) {
      if (sym && r.symbol !== sym) return false;
      if (st === 'OPEN' && r.status !== 'OPEN') return false;
      if (st === 'SOLD' && r.status !== 'SOLD') return false;
      return true;
    });
  }

  function refreshCalendarEvents() {
    if (!calendar) return;
    calendar.removeAllEvents();
    calendar.addEventSource(buildEvents(filteredEntries()));
  }

  function showDetail(row) {
    var panel = $('cal-detail');
    var body = $('cal-detail-body');
    var title = $('cal-detail-title');
    if (!panel || !body || !title) return;

    var sold = row.status === 'SOLD';
    var leftover = isLeftover(row);
    var holdEnd = sold ? num(row.sellAt) : Date.now();
    var holdMs = holdEnd - num(row.buyAt);
    var status = sold ? 'SOLD' : (leftover ? 'LEFTOVER' : 'HOLDING');
    var pl = sold ? num(row.profitUsdt) : num(row.nowProfitUsdt);
    var plPct = sold ? num(row.profitPct) : num(row.nowProfitPct);
    var plCls = pl >= 0 ? 'cal-up' : 'cal-down';

    title.textContent = row.symbol + ' — ' + status;

    var rows = [
      ['Status', status],
      ['Buy time', fmtDateTimeUtc(row.buyAt)],
      ['Buy price', fmtUsd(row.buyPrice, 4)],
      ['Quantity', row.qty],
      ['Cost', fmtUsd(row.costUsdt) + ' USDT'],
      ['Hold time', fmtHold(holdMs)],
      ['Target price', fmtUsd(row.targetPrice, 4)],
      ['Target value', fmtUsd(row.targetUsdt, 4) + ' USDT']
    ];

    if (sold) {
      rows.push(['Sell time', fmtDateTimeUtc(row.sellAt)]);
      rows.push(['Sell price', fmtUsd(row.sellPrice, 4)]);
      rows.push(['Proceeds', fmtUsd(row.proceedsUsdt, 4) + ' USDT']);
      rows.push(['Profit', (pl >= 0 ? '+' : '') + fmtUsd(pl, 4) + ' USDT (' + plPct.toFixed(3) + '%)', plCls]);
      rows.push(['Reason', row.sellReason || '—']);
    } else {
      rows.push(['Value now', fmtUsd(row.nowValueUsdt, 4) + ' USDT']);
      rows.push(['Unrealized P/L', (pl >= 0 ? '+' : '') + fmtUsd(pl, 4) + ' USDT', plCls]);
    }

    if (row.source) rows.push(['Source', row.source]);
    if (row.note) rows.push(['Note', row.note]);

    body.innerHTML = rows.map(function (pair) {
      var cls = pair[2] ? ' class="' + pair[2] + '"' : '';
      return '<dt>' + esc(pair[0]) + '</dt><dd' + cls + '>' + esc(pair[1]) + '</dd>';
    }).join('');

    panel.hidden = false;
  }

  function hideDetail() {
    var panel = $('cal-detail');
    if (panel) panel.hidden = true;
  }

  async function loadEntries() {
    say('Loading entries…');
    var url = API + '?action=export-entries&mode=' + encodeURIComponent(MODE);
    var res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    var data = await res.json().catch(function () { return null; });
    if (!data) throw new Error('Bad response (HTTP ' + res.status + ')');
    if (data.authRequired) { window.location.reload(); throw new Error('Session expired'); }
    if (!data.ok) throw new Error(data.error || 'Load failed');

    entries = data.rows || [];
    renderSymbolFilter(entries);
    refreshCalendarEvents();
    say(entries.length + ' entr' + (entries.length === 1 ? 'y' : 'ies') + ' loaded.', 'good');
  }

  function initCalendar() {
    var root = $('cal-root');
    if (!root || typeof FullCalendar === 'undefined') return;

    calendar = new FullCalendar.Calendar(root, {
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
      },
      height: 'auto',
      nowIndicator: true,
      slotMinTime: '00:00:00',
      slotMaxTime: '24:00:00',
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
      displayEventTime: true,
      eventClick: function (info) {
        info.jsEvent.preventDefault();
        var row = info.event.extendedProps.row;
        if (row) showDetail(row);
      },
      eventDidMount: function (info) {
        var row = info.event.extendedProps.row;
        if (!row) return;
        var sold = row.status === 'SOLD';
        var tip = row.symbol + '\nBuy: ' + fmtDateTimeUtc(row.buyAt);
        if (sold) {
          tip += '\nSell: ' + fmtDateTimeUtc(row.sellAt);
          tip += '\nHold: ' + fmtHold(info.event.extendedProps.holdMs);
          tip += '\nProfit: ' + fmtUsd(row.profitUsdt, 4) + ' USDT';
        } else {
          tip += '\nStill holding: ' + fmtHold(info.event.extendedProps.holdMs);
        }
        info.el.title = tip;
      }
    });

    calendar.render();
  }

  function wire() {
    if ($('cal-symbol')) $('cal-symbol').addEventListener('change', refreshCalendarEvents);
    if ($('cal-status')) $('cal-status').addEventListener('change', refreshCalendarEvents);
    if ($('cal-reload')) {
      $('cal-reload').addEventListener('click', function () {
        loadEntries().catch(function (e) { say(e.message || 'Reload failed', 'bad'); });
      });
    }
    if ($('cal-detail-close')) $('cal-detail-close').addEventListener('click', hideDetail);
  }

  document.addEventListener('DOMContentLoaded', function () {
    wire();
    initCalendar();
    loadEntries().catch(function (e) { say(e.message || 'Failed to load', 'bad'); });
  });
})();
