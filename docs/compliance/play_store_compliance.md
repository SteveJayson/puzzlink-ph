# Google Play Store Compliance Checklist
## PuzzLink PH — Pre-Submission Verification

Work through every item before submitting to Play Console.
A single policy violation can delay launch by weeks or trigger account suspension.

---

## Section 1: Play Console Setup

### App Information
- [ ] **Package name**: `com.puzzlinkph.game` (choose carefully — cannot be changed after publish)
- [ ] **Category**: Puzzle → Word Games
- [ ] **Content Rating**: Complete IARC questionnaire
  - Contains ads: **Yes**
  - Contains in-app purchases: **No** (initially)
  - Real money gambling: **No**
  - Expected rating: **Everyone (E)**

### Data Safety Form (Play Console → Data Safety)

Fill in every field accurately. Misrepresentation is a policy violation.

| Data Type | Collected | Shared | Purpose |
|---|---|---|---|
| Device or other IDs (Android ID) | ✅ Yes | ❌ No | Fraud prevention, account linking |
| IP Address | ✅ Yes | ❌ No | Fraud prevention |
| Name | ✅ Yes (optional) | ✅ Xendit (payment) | Financial transactions |
| Phone number | ✅ Yes (optional) | ✅ Xendit (payment) | Financial transactions |
| Advertising ID (GAID) | ✅ Yes | ✅ Ad partners | Advertising |
| In-app messages / Game activity | ✅ Yes | ❌ No | App functionality |

- [ ] All data encrypted in transit: **Yes** (HTTPS/TLS)
- [ ] User can request data deletion: **Yes** (via email, link in app)

---

## Section 2: Ads Policy Compliance

### Financial Features Declaration
- [ ] In Play Console → App Content → Financial Features:
  - Earning real money rewards: **Yes — declare this clearly**
  - This triggers enhanced review. Provide honest description.

### AdMob / MAX Policy Rules

**Interstitial Ads — Hard Rules (violations = account ban):**
- [ ] Never show between levels in rapid succession (enforced: every 3 levels minimum)
- [ ] Never show when user presses Back button
- [ ] Never show during app load / splash screen
- [ ] Never trigger from a countdown timer
- [ ] Never place close button in a position that overlaps game UI
- [ ] Add a brief delay (0.5s) before the interstitial becomes clickable (MAX default: enabled)

**Rewarded Ads — Hard Rules:**
- [ ] Player must explicitly tap a clearly labeled button
- [ ] Button text must state the specific reward: `"Watch a short video for 2× coins"`
- [ ] Never auto-start a rewarded ad
- [ ] Never show a rewarded ad while another UI element is loading

**Invalid Traffic (IVT) Prevention:**
- [ ] No ad unit within 50dp of any tappable game element
- [ ] No ads visible during the puzzle interaction area
- [ ] No fullscreen interstitials that cover a "play" button placement

---

## Section 3: Reward-to-Cash Apps — Special Requirements

Apps that convert in-app rewards to real money face extra scrutiny.

### Required Disclosures (in app store listing AND in-app):
- [ ] **Minimum cashout threshold** clearly stated in store description
- [ ] **Estimated earnings** are realistic — do NOT use misleading claims like "Earn ₱1,000/day"
- [ ] **Payout timeline** disclosed: "1–3 business days via GCash/Maya"
- [ ] **No guarantee of earnings** — include disclaimer that earnings vary

### Recommended In-App Disclosures:
Add a short "How it Works" screen (shown on first launch):
```
💰 How Earnings Work
─────────────────────
• Earn coins by solving puzzles and watching ads
• Coins convert to cash: 1,000 coins = ₱1.00
• First cashout minimum: ₱50 via GCash or Maya
• Earnings depend on ad availability and engagement
• Payouts processed within 1–3 business days
```

---

## Section 4: Store Listing — App Store Optimization (ASO)

### Title (max 30 chars)
```
PuzzLink PH - Earn GCash
```

### Short Description (max 80 chars)
```
Solve word puzzles. Earn real cash rewards. Cash out to GCash or Maya!
```

### Full Description (max 4,000 chars)
```
🧩 PuzzLink PH — The Word Puzzle Game That PAYS!

Solve fun word-search puzzles and earn real cash you can send directly 
to your GCash or Maya wallet. No gimmicks — real payouts, real players.

HOW IT WORKS
─────────────
✅ Solve word puzzles to earn coins
✅ Watch short video ads to double your rewards
✅ Complete survey offers for bigger bonuses
✅ Cash out starting from just ₱50 to GCash/Maya

THE PUZZLES
────────────
• 500+ word-search puzzles across easy, medium, and hard levels
• New puzzles added regularly
• Fast 30–60 second rounds — perfect for commute, breaks, or waiting

REAL REWARDS
─────────────
• 1st cashout: ₱50 minimum
• Subsequent cashouts: ₱300 minimum
• Direct disbursement to GCash or Maya
• Typical processing time: 1–3 business days

📱 Designed for budget Android phones (Realme, Samsung A-series, Infinix)
🌐 Works offline — sync earnings when back online

Earnings depend on ad availability and task completion. 
This is not a get-rich-quick app — it's a fun puzzle game with real bonuses.

By downloading, you agree to our Privacy Policy and Terms of Service.
Privacy Policy: [YOUR_PRIVACY_POLICY_URL]
```

### Keywords (use in description naturally)
```
GCash puzzle, earn GCash, word puzzle Philippines, libre pera, 
puzzle game earn money, word search Philippines, Maya cash out
```

### Graphic Assets Needed
- [ ] App icon: 512×512 PNG, no alpha
- [ ] Feature graphic: 1024×500 PNG — show GCash logo + coin + puzzle grid
- [ ] Screenshots (min 2, recommended 8): portrait 1080×1920
  - [ ] SS1: Puzzle gameplay with coin counter
  - [ ] SS2: "You solved it! +40 coins" level complete overlay
  - [ ] SS3: Wallet screen showing ₱50 cashout available
  - [ ] SS4: GCash/Maya payout form
  - [ ] SS5: "Real payout proof" mockup (discretionary)

---

## Section 5: Technical Pre-Launch Checklist

### APK / AAB Requirements
- [ ] Target SDK: Android 14 (API 34) minimum for new apps (Nov 2024+)
- [ ] Minimum SDK: Android 10 (API 29) — covers 95%+ of PH market
- [ ] App signed with release keystore (keep keystore file backed up — if lost, app cannot be updated)
- [ ] ProGuard/R8 enabled for release builds
- [ ] Internet permission declared in `AndroidManifest.xml`
- [ ] No DEBUG flags in release build

### Godot Android Export Checklist
- [ ] Keystore path configured in Godot Export → Android
- [ ] Package name matches Play Console entry exactly
- [ ] Version code incremented for each upload
- [ ] Export as AAB (Android App Bundle), not APK — required for new Play Store apps
- [ ] All textures use ETC2/ASTC compression (Godot setting: `rendering/textures/vram_compression/import_etc2_astc=true`)
- [ ] AdMob/MAX App ID added to `res/values/strings.xml` via the plugin's setup

### Pre-Launch Report
After uploading AAB, in Play Console → Android vitals → Pre-launch report:
- [ ] No crashes on emulated test devices
- [ ] No ANRs (Application Not Responding)
- [ ] All permissions granted correctly

---

## Section 6: Soft Launch Timeline

| Week | Milestone |
|---|---|
| Week 1 | Deploy backend to production server. Run payout dry run with 5 internal testers. |
| Week 2 | Open internal test track. 10–20 testers. Fix any critical bugs. |
| Week 3–4 | Closed testing track. 50–200 testers. PH Facebook group recruitment. |
| Week 5 | Review Play Console pre-launch report. Resolve any policy flags. |
| Week 6 | Submit for production review. Expect 3–7 day Google review time. |
| Week 7 | Public launch 🚀 |

---

## Section 7: app-ads.txt (Required for AdMob)

Host this file at `https://YOUR_DOMAIN/app-ads.txt` (not in the game server — in a web root):

```
google.com, pub-XXXXXXXXXXXXXXXX, DIRECT, f08c47fec0942fa0
applovin.com, XXXXXXXX, DIRECT
```

Replace IDs with your actual AdMob publisher ID and AppLovin account ID.
Google will crawl this URL to verify ad network authorization.
