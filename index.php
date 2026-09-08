<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Binance Watch — Spot Buy / Sell</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@500;600&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css?v=24">
</head>
<body>
  <div class="bg-grid" aria-hidden="true"></div>

  <main class="wrap">
    <header class="brand">
      <div class="brand-mark">
        <span class="brand-dot" aria-hidden="true"></span>
        <span class="brand-label">BINANCE WATCH</span>
      </div>
      <h1>Buy dip · Sell target</h1>
      <p>cPanel pe <strong>Server auto</strong> ON + cron → tab band bhi chalega. Local pe tab sirf UI ke liye.</p>
      <p class="brand-links">
        <a href="ladder.php">Daily ladder (buy daily, sell in profit)</a> ·
        <a href="simulate.php">Simulator</a>
      </p>
    </header>

    <section class="api-bar" aria-label="API status">
      <div>
        <p class="api-label">Spot API</p>
        <p id="api-status" class="api-status">Checking…</p>
      </div>
      <div>
        <p class="api-label">Wallet USDT</p>
        <p id="usdt-total" class="api-value">—</p>
      </div>
      <div>
        <p class="api-label">USDT free</p>
        <p id="usdt-free" class="api-value">—</p>
      </div>
      <div>
        <p class="api-label">USDT locked</p>
        <p id="usdt-locked" class="api-value">—</p>
      </div>
      <div>
        <p class="api-label">BTC wallet</p>
        <p id="btc-wallet" class="api-value">—</p>
      </div>
      <div>
        <p class="api-label">Min order</p>
        <p id="daily-cap" class="api-value">~5 USDT</p>
      </div>
      <div>
        <p class="api-label">Server cron</p>
        <p id="server-cron-status" class="api-value">—</p>
      </div>
      <button id="refresh-api" type="button" class="ghost-btn">Refresh API</button>
    </section>

    <section class="search-panel add-panel" aria-label="Add symbol">
      <div class="field">
        <label for="symbol">Symbol</label>
        <input
          id="symbol"
          type="text"
          list="symbol-list"
          placeholder="BTCUSDT"
          value="BTCUSDT"
          autocomplete="off"
          spellcheck="false"
          maxlength="20"
        >
        <datalist id="symbol-list"></datalist>
      </div>
      <div class="field field-narrow">
        <label for="base-price">Base price (optional)</label>
        <input id="base-price" type="number" step="any" min="0" placeholder="Auto = current">
      </div>
      <button id="add-btn" type="button">Add to watchlist</button>
    </section>

    <div class="rules-bar">
      <label class="rule">
        Buy when
        <input id="buy-drop" type="number" step="0.01" min="0" value="0.1" title="Percent below base">
        % below
      </label>
      <label class="rule">
        Fee / side
        <input id="fee-pct" type="number" step="0.01" min="0" value="0.1" title="Binance fee each side, usually 0.1%">
        %
      </label>
      <label class="rule">
        Net profit
        <input id="sell-up" type="number" step="0.01" min="0" value="0.3" title="Profit AFTER buy+sell fees">
        % after fees
      </label>
      <p id="fee-hint" class="buy-hint">Fees ~0.1%×2. Sell trigger includes fees so you aim for net +0.3%.</p>
      <label class="rule">
        Buy USDT
        <input id="buy-amount" type="number" step="0.01" min="5" value="6" placeholder="6" title="USDT per auto/manual buy">
        USDT
      </label>
      <p id="buy-hint" class="buy-hint">Cycle: −0.1% → buy 6 USDT → +0.3% net → sell all → repeat.</p>
      <label class="rule check auto-check">
        <input id="auto-trade" type="checkbox" checked>
        Browser auto
      </label>
      <label class="rule check auto-check">
        <input id="server-auto" type="checkbox">
        Server auto (cPanel)
      </label>
      <label class="rule check">
        <input id="sound-toggle" type="checkbox" checked>
        Sound alert
      </label>
      <p id="tick-status" class="tick-status">Waiting…</p>
    </div>

    <section class="sell-calc" aria-label="Sell price calculator">
      <h2 class="sell-calc-title">Sell calculator</h2>
      <p class="sell-calc-lead">Buy rate + kitne USDT khareedoge — 1 cycle ke baad kitne USDT banenge, woh dikhega.</p>
      <div class="sell-calc-row">
        <label class="rule" for="calc-buy">
          Buy / current
          <input id="calc-buy" type="number" step="any" min="0" placeholder="e.g. 1900">
        </label>
        <label class="rule" for="calc-usdt">
          Buy USDT
          <input id="calc-usdt" type="number" step="0.01" min="5" value="6" placeholder="6">
        </label>
        <div class="sell-calc-out">
          <div class="sell-calc-item">
            <span>Break-even sell</span>
            <strong id="calc-breakeven">—</strong>
          </div>
          <div class="sell-calc-item target">
            <span>Sell for +<span id="calc-net-label">0.3</span>% net</span>
            <strong id="calc-target">—</strong>
          </div>
          <div class="sell-calc-item target">
            <span>After 1 cycle</span>
            <strong id="calc-after">—</strong>
          </div>
          <div class="sell-calc-item">
            <span>Faida (1 cycle)</span>
            <strong id="calc-profit" class="up">—</strong>
          </div>
        </div>
      </div>
      <p id="calc-hint" class="buy-hint">Example: 6 USDT buy → +0.3% net → ~6.018 USDT.</p>

      <div class="hour-move">
        <h3 class="hour-move-title">Move &amp; chance</h3>
        <p class="sell-calc-lead">Time window choose karo (15m–24h) — up/down, range, aur buy→sell faida.</p>
        <div class="sell-calc-row">
          <label class="rule" for="calc-symbol">
            Symbol
            <input id="calc-symbol" type="text" list="symbol-list" placeholder="ETHUSDT" autocomplete="off">
          </label>
          <label class="rule" for="calc-window">
            Window
            <select id="calc-window">
              <option value="15">15 min</option>
              <option value="30">30 min</option>
              <option value="60" selected>1 hour</option>
              <option value="120">2 hours</option>
              <option value="240">4 hours</option>
              <option value="360">6 hours</option>
              <option value="720">12 hours</option>
              <option value="1440">24 hours</option>
            </select>
          </label>
          <button id="calc-hour-btn" type="button" class="ghost-btn">Check</button>
        </div>
        <div id="hour-stats" class="hour-stats" hidden>
          <div class="sell-calc-out">
            <div class="sell-calc-item">
              <span><span id="hour-hl-label">Window</span> high / low</span>
              <strong id="hour-hl">—</strong>
            </div>
            <div class="sell-calc-item">
              <span>Change</span>
              <strong id="hour-change">—</strong>
            </div>
            <div class="sell-calc-item">
              <span>Range (high−low)</span>
              <strong id="hour-range">—</strong>
            </div>
            <div class="sell-calc-item">
              <span>Bars ↑ / ↓</span>
              <strong id="hour-minutes">—</strong>
            </div>
            <div class="sell-calc-item target">
              <span>Chance (your rules)</span>
              <strong id="hour-chance">—</strong>
            </div>
            <div class="sell-calc-item target">
              <span>Est. faida / cycle</span>
              <strong id="hour-profit">—</strong>
            </div>
          </div>
          <p id="hour-hint" class="buy-hint"></p>
        </div>
      </div>
    </section>

    <p id="auto-banner" class="auto-banner" hidden>AUTO ON — browser tab must stay open for browser auto.</p>
    <p id="server-banner" class="auto-banner server-banner" hidden>SERVER AUTO ON — cPanel cron har minute chalega, tab band bhi OK. Browser auto OFF rakho taake double buy na ho.</p>

    <section id="pnl-panel" class="pnl-panel" aria-label="Today profit and loss">
      <div class="pnl-head">
        <h2>Today’s P&amp;L</h2>
        <p id="pnl-date" class="pnl-date">Loading…</p>
      </div>
      <div class="pnl-grid">
        <article class="pnl-card">
          <span>Realized</span>
          <strong id="pnl-realized">—</strong>
        </article>
        <article class="pnl-card">
          <span>Unrealized</span>
          <strong id="pnl-unrealized">—</strong>
        </article>
        <article class="pnl-card pnl-total">
          <span>Total today</span>
          <strong id="pnl-total">—</strong>
        </article>
        <article class="pnl-card">
          <span>Bought today</span>
          <strong id="pnl-bought">—</strong>
        </article>
        <article class="pnl-card">
          <span>Sold today</span>
          <strong id="pnl-sold">—</strong>
        </article>
        <article class="pnl-card">
          <span>Trades</span>
          <strong id="pnl-trades">—</strong>
        </article>
      </div>
      <div id="pnl-symbols" class="pnl-symbols"></div>
    </section>

    <p id="status" class="status" role="status" aria-live="polite"></p>

    <section class="watch-section">
      <div class="watch-head">
        <h2>Your watchlist</h2>
        <p class="lead">Har symbol: <strong>Instant buy</strong> abhi, ya Auto −0.1% pe buy → <strong>sell +0.3% net</strong> → dubara. Holding me dusra buy nahi.</p>
      </div>
      <div id="watch-empty" class="watch-empty">No symbols yet. Add one above to start watching.</div>
      <div id="watch-list" class="watch-list" aria-live="polite"></div>
    </section>

    <section class="history-section" aria-label="Trade history report">
      <div class="watch-head">
        <h2>Trade history & report</h2>
        <p class="lead">What you bought and sold — filter by date and symbol. Sync pulls fills from Binance.</p>
      </div>

      <div class="history-filters">
        <div class="field">
          <label for="hist-symbol">Symbol</label>
          <select id="hist-symbol">
            <option value="">All symbols</option>
          </select>
        </div>
        <div class="field">
          <label for="hist-side">Side</label>
          <select id="hist-side">
            <option value="">All</option>
            <option value="BUY">BUY</option>
            <option value="SELL">SELL</option>
          </select>
        </div>
        <div class="field">
          <label for="hist-from">From</label>
          <input id="hist-from" type="date">
        </div>
        <div class="field">
          <label for="hist-to">To</label>
          <input id="hist-to" type="date">
        </div>
        <button id="hist-load" type="button" class="ghost-btn">Apply filters</button>
        <button id="hist-sync" type="button">Sync from Binance</button>
      </div>

      <div id="hist-summary" class="hist-summary"></div>

      <div class="hist-split">
        <div class="panel">
          <h3>By date</h3>
          <div id="hist-by-date" class="hist-table-wrap"></div>
        </div>
        <div class="panel">
          <h3>By symbol</h3>
          <div id="hist-by-symbol" class="hist-table-wrap"></div>
        </div>
      </div>

      <div class="panel hist-detail-panel">
        <h3>Detailed trades</h3>
        <div id="hist-trades" class="hist-table-wrap"></div>
      </div>
    </section>
  </main>

  <script src="assets/app.js?v=24"></script>
</body>
</html>
