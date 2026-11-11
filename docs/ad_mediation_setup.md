# Ad Mediation Setup — PuzzLink PH

## Platform: AppLovin MAX

AppLovin MAX runs a unified auction across all configured networks. For Philippine Tier 3 traffic, this consistently delivers 20–40% higher eCPM than AdMob standalone because multiple networks compete per impression.

---

## Step 1: Create Your MAX Account

1. Go to [dash.applovin.com](https://dash.applovin.com)
2. Create an account → Add App → Enter your Play Store bundle ID
3. Note your **SDK Key** (goes in `AdManager.gd` → `MAX_SDK_KEY`)

---

## Step 2: Create Ad Units

In the MAX dashboard, under **Monetize → Ad Units**:

| Ad Format | Placement Name | Copy the Ad Unit ID into |
|---|---|---|
| Rewarded | `PuzzLink_Rewarded` | `AdManager.AD_UNIT_REWARDED` |
| Interstitial | `PuzzLink_Inter` | `AdManager.AD_UNIT_INTERSTITIAL` |

---

## Step 3: Mediation Waterfall (Networks to Add)

Add these networks under each ad unit's **Waterfall** tab.
Order reflects expected eCPM for PH traffic (highest fill first):

### Rewarded Video Waterfall

| Priority | Network | Why |
|---|---|---|
| 1 | **AppLovin Exchange** | Built-in; best fill rate globally |
| 2 | **Google AdMob** | Largest demand pool; good PH fill |
| 3 | **Unity Ads** | Strong gaming vertical; good eCPM PH |
| 4 | **Mintegral** | Strong Asia-Pacific fill for Tier 3 |
| 5 | **Meta Audience Network** | Optional; requires Facebook SDK |

### Interstitial Waterfall

Same order. Mintegral and Unity typically have stronger interstitial fill than rewarded for PH.

> **Note:** MAX uses **header bidding** (unified auction), so the waterfall order is a tiebreaker for equal bids — all networks bid simultaneously.

---

## Step 4: Configure AdMob Mediation Adapter

In your Android `build.gradle`:

```gradle
dependencies {
    implementation 'com.applovin:applovin-sdk:+'
    implementation 'com.applovin.mediation:google-adapter:+'       // AdMob
    implementation 'com.applovin.mediation:unity-ads-adapter:+'    // Unity
    implementation 'com.applovin.mediation:mintegral-adapter:+'    // Mintegral
}
```

---

## Step 5: Server-Side Verification (SSV) — Rewarded Ads

SSV ensures coins are only credited when AppLovin's servers confirm the ad was fully watched.

1. In MAX Dashboard → Ad Units → `PuzzLink_Rewarded` → **Server-Side Verification**
2. Set **Callback URL**: `https://YOUR_SERVER/api/ad/ssv?user_id={USER_ID}&ad_unit_id={AD_UNIT_ID}&event_id={EVENT_ID}&event_token={EVENT_TOKEN}&revenue={REVENUE}&reward_amount={REWARD_AMOUNT}`
3. Set **SSV Key** in your server `.env` as `APPLOVIN_SSV_KEY`
4. In your Godot client, call `setUserId(SessionManager.get_uuid())` on the MAX SDK before loading ads

The flow:
```
User watches ad → AppLovin SDK → AppLovin servers → POST /api/ad/ssv (server credits coins)
                                                  ↓
                             Client POSTs /api/ad/validate (confirms server recorded it)
                                                  ↓
                                        UI shows reward
```

---

## Step 6: Offerwall S2S Postback URLs

Configure these in each offerwall network's publisher dashboard:

| Network | Postback URL |
|---|---|
| **CPX Research** | `https://YOUR_SERVER/api/offerwall/callback?network=cpx&ext_user_id={EXT_USER_ID}&status={STATUS}&trans_id={TRANS_ID}&payout={PAYOUT}&hash={HASH}` |
| **Tapjoy** | `https://YOUR_SERVER/api/offerwall/callback?network=tapjoy&snuid={SNUID}&currency={CURRENCY}&id={ID}&verifier={VERIFIER}` |
| **Wannads** | `https://YOUR_SERVER/api/offerwall/callback?network=wannads&user_id={USER_ID}&transaction_id={TRANSACTION_ID}&reward={REWARD}&signature={SIGNATURE}` |
| **IronSource** | `https://YOUR_SERVER/api/offerwall/callback?network=ironsource&userId={USER_ID}&offerId={OFFER_ID}&transId={TRANSACTION_ID}&reward={REWARD}&signature={SIGNATURE}` |

Add the HMAC/secret keys to `.env` for each network.

---

## Step 7: Google Play Policy Checklist

Before submitting to the Play Store:

- [ ] Rewarded ad button clearly says "Watch video for 2× coins" (not just "Get reward")
- [ ] No ad unit placed within 50dp of any game button (checked by `AdCompliance.gd`)
- [ ] Interstitials never fire on back-press or during active puzzle solving
- [ ] Interstitial frequency cap: maximum 1 per 3 levels (`AdManager.INTERSTITIAL_EVERY_N_LEVELS`)
- [ ] Minimum 30 seconds between consecutive interstitials (`AdManager.MIN_SECONDS_BETWEEN_INTER`)
- [ ] AdMob app-ads.txt published at `https://YOUR_DOMAIN/app-ads.txt`
- [ ] UMP (User Messaging Platform) consent flow for EEA users (use MAX's built-in UMP SDK)

---

## Expected eCPM Ranges (Philippines, Nov 2026)

| Ad Type | Low | Mid | High |
|---|---|---|---|
| Rewarded Video | $1.50 | $2.50 | $4.00 |
| Interstitial | $0.80 | $1.20 | $2.00 |
| Offerwall (CPX survey) | $0.50/task | $2.00/task | $20.00/task |

With MAX mediation, expect ~25% uplift over AdMob standalone for PH traffic.
