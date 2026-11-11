# PuzzLink PH — QA & Device Test Plan

## 1. Target Test Devices (Philippines Market)

Test on at least one device from each tier before submission.

| Tier | Device | RAM | Android | Chipset |
|---|---|---|---|---|
| Budget | Realme C53 | 4 GB | 13 | Unisoc T612 |
| Budget | Infinix Hot 30i | 4 GB | 13 | Helio G85 |
| Mid | Samsung Galaxy A14 | 4 GB | 13 | Helio G80 |
| Mid | Redmi 12 | 6 GB | 13 | Snapdragon 685 |
| Upper-mid | Samsung Galaxy A34 | 6 GB | 14 | Helio G99 |

**Minimum target spec:** 2 GB RAM, Android 10, 32 GB storage, 4G capable.

---

## 2. Performance Benchmarks

Run these measurements on the Realme C53 (worst-case device):

| Metric | Acceptable | Target |
|---|---|---|
| Cold launch time | < 4s | < 2.5s |
| Level load time | < 1.5s | < 0.8s |
| Puzzle scene FPS | ≥ 30 | 60 |
| APK size | < 50 MB | < 30 MB |
| RAM usage (gameplay) | < 180 MB | < 120 MB |

**Tools:** Android Studio Profiler, `adb shell dumpsys meminfo`, `systrace`.

---

## 3. Network Resilience Test Cases

### Test Matrix

| Scenario | Expected Behavior |
|---|---|
| Full 4G (normal play) | All API calls succeed; coins credited immediately |
| Weak signal (1 bar) | Level completes locally; queued in `sync_queue` |
| Airplane mode (fully offline) | Puzzles still playable; badge shows "Offline · N pending" |
| Reconnect after offline session | `SyncManager` auto-drains queue; toast confirms sync |
| Server 500 error | Client shows retry option; no duplicate credits on retry |
| Server 429 (rate limit) | Client backs off gracefully; no crash |
| Mid-ad network drop | Ad fails silently; "No ad available" shown; puzzle proceeds |

### How to Test Offline Sync
1. Enable Airplane Mode on device
2. Complete 3 puzzles
3. Re-enable WiFi
4. Verify: 3 `level_complete` events appear in `transactions` table
5. Verify: `sync_queue` table is empty after drain
6. Verify: balance reflects server-confirmed values (not optimistic estimate)

---

## 4. End-to-End Payout Dry Run

Run this sequence before opening beta to real users.

### Pre-conditions
- Backend deployed to staging server
- Xendit sandbox key configured
- Two real GCash numbers available (tester's phones)

### Steps

| Step | Action | Expected Result |
|---|---|---|
| 1 | Register fresh device account | UUID created, balance = 0 |
| 2 | Complete 50 levels + watch 20 rewarded ads | Balance ≥ ₱1.50 |
| 3 | Add offerwall tasks via admin_adjustment to reach ₱50 | Balance = ₱50.00 |
| 4 | Submit payout: GCash 09171234567, name "Test User" | Status = pending |
| 5 | Approve in admin dashboard | Xendit disbursement sent |
| 6 | Confirm GCash receipt on tester's phone | ₱50.00 received |
| 7 | Verify payout_requests.status = completed | Webhook processed |
| 8 | Verify balance deducted correctly | Balance = ₱0.00 |
| 9 | Submit second payout (should fail — below ₱300) | 402 response |
| 10 | Test rejection flow: submit and reject from admin | Coins refunded |

### Edge Cases to Verify
- [ ] Submit payout with `+639171234567` format — should normalize and succeed
- [ ] Submit payout while already pending — should return 409
- [ ] Submit payout from banned account — should return 403
- [ ] Xendit sandbox failure (use invalid number) — coins should refund automatically

---

## 5. Ad Compliance Test Cases

| Test | Pass Criteria |
|---|---|
| Rewarded ad button tap | Pre-prompt visible before ad loads |
| Rewarded ad button label | Contains "Watch", "video", or "2×" — no vague labels |
| Interstitial timing | Never fires within 2s of level load |
| Interstitial frequency | Does not fire more than once every 3 levels |
| Ad gap | At least 5s between any two ad impressions |
| Accidental click test | Tapping game grid area does NOT click any ad |
| Back button | Pressing back during interstitial dismisses it; no second ad |
| No ad during puzzle | Interstitials only show on level-complete overlay |

**Use Google Ad Inspector** (enable via `adb shell setprop debug.admob.test_device_id YOUR_DEVICE_ID`) to verify ad unit IDs and test mode.

---

## 6. Security Checklist

- [ ] JWT secret is 64+ random characters in production `.env`
- [ ] Database user has only SELECT/INSERT/UPDATE/DELETE (no DROP/ALTER)
- [ ] Admin panel is behind HTTPS with a strong password
- [ ] Admin panel is not accessible via `/api/` path (`.htaccess` blocks it)
- [ ] Offerwall secrets are set for all 5 networks in `.env`
- [ ] Xendit webhook token is set and validated
- [ ] No PHP error output in production (`display_errors = Off`)
- [ ] SQL log does not appear in API responses
- [ ] Rate limiter is active and returns 429 at threshold

---

## 7. Beta Tester Recruitment Checklist

- [ ] Create closed testing track in Play Console
- [ ] Generate 20–50 invite codes or invite by email
- [ ] Target: mix of Realme/Samsung/Infinix users on Globe and Smart
- [ ] Distribute via PH Facebook gaming groups
- [ ] Collect feedback via Google Form (linked in app store listing)
- [ ] Run beta for minimum 14 days before production rollout
