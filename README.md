# PuzzLink PH

Casual word/grid puzzle game with real GCash/Maya cash rewards.
Target: Android (Philippines market) — Godot 4 client + PHP/MySQL backend.

---

## Project Structure

```
game/
├── backend/
│   ├── index.php                   # Front controller / router
│   ├── .htaccess                   # Rewrites + directory protection
│   ├── .env.example                # Environment variable template
│   ├── api/
│   │   ├── register.php            # POST /api/register (username/password)
│   │   ├── login.php               # POST /api/login
│   │   ├── device_register.php     # POST /api/device/register (anonymous device auth)
│   │   ├── level_complete.php      # POST /api/level/complete
│   │   ├── levels.php              # GET  /api/levels
│   │   ├── ad_reward.php           # POST /api/ad/reward
│   │   ├── offerwall_callback.php  # POST /api/offerwall/callback (server-to-server)
│   │   ├── balance.php             # GET  /api/balance
│   │   ├── payout_request.php      # POST /api/payout/request
│   │   └── payout_status.php       # GET  /api/payout/status
│   ├── config/
│   │   ├── db.php                  # PDO singleton
│   │   └── constants.php           # Economy config (coin rates, caps, thresholds)
│   ├── middleware/
│   │   └── auth.php                # JWT issue + validation
│   └── helpers/
│       ├── response.php            # json_ok / json_error
│       └── rate_limit.php          # Per-user/IP rate limiting
├── database/
│   ├── schema.sql                  # Initial schema (run first)
│   └── migration_v2.sql            # Phase 2 additions (device fingerprints, etc.)
├── client/
│   └── godot/
│       ├── project.godot
│       ├── scripts/
│       │   ├── net/
│       │   │   └── API.gd              # All HTTP calls
│       │   ├── managers/
│       │   │   ├── SessionManager.gd   # Device auth + JWT storage
│       │   │   ├── GameState.gd        # In-memory state + server sync
│       │   │   └── AdManager.gd        # AdMob/MAX hooks (Phase 3)
│       │   ├── game/
│       │   │   └── PuzzleGrid.gd       # Grid render + tap/drag input
│       │   └── ui/
│       │       ├── Splash.gd           # First-launch registration
│       │       └── WalletScreen.gd     # Balance + cashout form
│       ├── scenes/                 # .tscn files (create in Godot editor)
│       │   ├── ui/  Splash, MainMenu, WalletScreen, LevelSelect
│       │   └── game/  GameScene, GridCell
│       └── assets/  fonts, sounds, themes
└── README.md
```

---

## Database Setup

```sql
-- Step 1: Initial schema
SOURCE database/schema.sql;

-- Step 2: Phase 2 additions
SOURCE database/migration_v2.sql;

-- Step 3: Create app user
CREATE USER 'puzzlink_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON puzzlink_ph.* TO 'puzzlink_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## Backend Setup

```bash
cp backend/.env.example backend/.env
# Edit .env with real DB credentials and secrets
```

Requirements: PHP 8.1+, MySQL 8.0+, Apache with mod_rewrite.

---

## Client Setup (Godot)

1. Download [Godot 4.x](https://godotengine.org/) (standard, not .NET)
2. Open Godot → Import → select `client/godot/project.godot`
3. Set `BASE_URL` in `scripts/net/API.gd` to your server URL
4. Build scenes in the editor to match the script `@onready` paths
5. For Android export: install Android SDK, follow [Godot Android export docs](https://docs.godotengine.org/en/stable/tutorials/export/exporting_for_android.html)

---

## API Reference

All protected endpoints require `Authorization: Bearer <token>`.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/register` | No | Create account (username + password) |
| POST | `/api/login` | No | Get JWT |
| POST | `/api/device/register` | No | Anonymous device-based account (app first launch) |
| GET  | `/api/balance` | Yes | Balance + cashout eligibility |
| GET  | `/api/levels` | Yes | Paginated puzzle catalogue |
| POST | `/api/level/complete` | Yes | Credit coins for completing a puzzle |
| POST | `/api/ad/reward` | Yes | Credit coins for watching an ad |
| POST | `/api/offerwall/callback` | HMAC | S2S callback from ad network |
| POST | `/api/payout/request` | Yes | Request GCash/Maya cashout |
| GET  | `/api/payout/status` | Yes | Cashout history |

---

## Coin Economy

| Event | Coins | ₱ |
|-------|-------|---|
| Solve puzzle (base) | 20 | ₱0.020 |
| Solve puzzle (2x) | 40 | ₱0.040 |
| Rewarded video | 50 | ₱0.050 |
| Interstitial ad | 10 | ₱0.010 |
| Offerwall $0.25 task | ~14,500 | ₱14.50 |

**1,000 coins = ₱1.00 — Payout tiers: ₱50 (first), ₱300 (subsequent)**

---

## Security Principles

- Coin amounts are always **calculated server-side** — client only sends event IDs
- Idempotency keys prevent double-crediting from retries or replays
- Offerwall callbacks verified via HMAC before any coins are awarded
- `FOR UPDATE` row lock in payout prevents race conditions
- Device fingerprint table supports multi-account detection without hard-blocking
- All queries use PDO prepared statements

---

## Phase 3 Checklist

- [ ] Design puzzle level `.tscn` scenes in Godot editor
- [ ] Integrate [Poing AdMob plugin](https://github.com/poingstudios/godot-admob-plugin) — replace stubs in `AdManager.gd`
- [ ] Wire real AdMob Ad Unit IDs in `AdManager.gd`
- [ ] Register with Tapjoy / Adjoe / IronSource; set HMAC secrets in `.env`
- [ ] Add puzzle levels to `puzzle_levels` table (or build a CMS endpoint)
- [ ] Deploy backend to Render / Railway / VPS
- [ ] Generate signed APK and submit to Google Play internal testing
