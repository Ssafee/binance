# cPanel deploy — Binance Watch (server auto + 5s loop)

Browser thodi der kholo → watchlist / Server auto ON → band kar do.  
Cron har **1 minute** start hoga, andar **har 5 second** price check (~11 checks / minute).

## JSON files (sab report yahan)

Folder: `data/` (File Manager se dekho)

| File | Kya hai |
|------|---------|
| `watch_state.json` | Watchlist, rules, holding, last run |
| `trades.json` | Saari buys/sells |
| `cron_log.json` | Har minute kitne ticks / actions |

Web se direct open mat karo — `.htaccess` block karta hai. cPanel File Manager se dekho.

## 1. Upload + `.env`

`.env` me pehle se `CRON_SECRET` set hai (local se upload karo).  
Hosting pe ye bhi chahiye:

```
CRON_TICK_SECONDS=5
CRON_LOOP_SECONDS=55
```

Binance API → Spot ON → IP = **server IP** (ya unrestricted).

## 2. Cron (CLI — zaroori)

Web/HTTP cron mat use karo (55s loop timeout ho sakti hai).

```
* * * * * /usr/bin/php /home/YOUR_USER/public_html/binance/cron/auto_trade.php
```

## 3. App flow

1. Site kholo ek baar  
2. Symbols add + rules  
3. **Server auto (cPanel) ON**  
4. **Browser auto OFF**  
5. Tab band — server pe chalta rahega  

## 4. Check

- `data/cron_log.json` → `"ticks": 11` jaisa dikhe  
- `data/trades.json` → fills  
- UI pe Server cron last run time  

Shared host agar 55s process kill kare to `CRON_LOOP_SECONDS=45` try karo.
