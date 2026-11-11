# PuzzLink PH — Launch Growth Strategy
## Philippines Market: Organic Acquisition & Retention

---

## Phase A: Soft Launch (Weeks 1–4, Closed Beta)

### Facebook Community Seeding

Target groups (search these on Facebook):
- "GCash Earning Apps Philippines" — 500K+ members
- "Libre Pera Apps PH" — 200K+ members  
- "Legit Earning Apps Philippines 2026" — 300K+ members
- "Puzzle Games Philippines"
- Local buy-and-sell / community groups (Caloocan, Cebu, Davao, etc.)

**Post Template (for beta testers):**
```
🧩 Looking for beta testers for PuzzLink PH!

Solve word puzzles and earn real GCash/Maya cash. 
First cashout at just ₱50!

What I need from you:
✅ Play 30 minutes/day for 2 weeks
✅ Report any bugs or glitches
✅ Give honest feedback

Comment "BETA" or DM me for the Google Play test link.

This is a REAL app with REAL payouts — I'll share proof during the test!
```

### TikTok Content Strategy (Weeks 1–6)

TikTok is the highest-ROI organic channel for earning apps in the PH.
Target: 3 videos/week minimum.

**Video formats that convert:**
1. **Payout Proof** — Screen record the GCash notification: "I just received ₱50 from PuzzLink!"
2. **Gameplay + commentary** — "Watch me solve this puzzle in 30 seconds and earn coins"
3. **Tutorial** — "How to reach ₱50 cashout in 3 days"
4. **Comparison** — "PuzzLink vs other GCash earning apps — which is more legit?"

**Hashtags:**
```
#GCashEarning #LibrePera #EarnMoneyPH #PuzzLinkPH
#GCashApp #WordPuzzle #EarningApps2026 #PagkakakitaanPH
```

**Hook formulas** (first 3 seconds determine watch rate):
- "I earned ₱50 just by playing a word game..."
- "This app actually pays? I tried it for 7 days..."
- "Kung gusto mong kumita ng libre, panoorin mo ito..."

---

## Phase B: Public Launch (Week 7+)

### App Store Optimization (ASO) — Ongoing

**A/B Test these elements in Play Console:**
1. Icon design — test coin design vs puzzle grid design
2. Feature graphic — "Real GCash payouts" text overlay vs clean gameplay screenshot
3. Short description — test emoji-heavy vs clean text
4. First screenshot — test payout screen vs gameplay screen

**Review acquisition:**
- Prompt for review ONLY after a successful cashout (highest positive sentiment moment)
- Use Godot's Android plugin to call the In-App Review API
- Never offer rewards for reviews — Play Store policy violation

### Referral Program (Phase 2 feature — plan now)

Add to database (future):
```sql
ALTER TABLE users ADD COLUMN referred_by INT UNSIGNED DEFAULT NULL;
-- When referee reaches first cashout, referrer gets +500 coins
```

Referral message template for sharing:
```
Sulit na sulit! 🎉 Na-cashout na ko ng ₱50 sa PuzzLink PH!
Solve lang ng word puzzles tapos may GCash ka na!
Download here: [PLAY_STORE_LINK]
Use my code: [USERNAME] para may bonus kayo pareho!
```

---

## Phase C: Retention (Ongoing)

### Daily Engagement Loop
- Push notification at 9 AM: "🧩 Your daily puzzles are ready! Earn coins today."
- Push notification at 7 PM: "⏰ You're ₱[X] away from your ₱[threshold] cashout!"
- Push notification on cashout completion: "💸 ₱50 is on its way to your GCash!"

### Key Retention Metrics to Track (in admin dashboard or analytics)
| Metric | Target |
|---|---|
| Day 1 retention | > 50% |
| Day 7 retention | > 25% |
| Day 30 retention | > 10% |
| Avg session/day | > 2 |
| % reaching ₱50 | > 15% (of all installs) |
| % re-engaging after cashout | > 60% |

The last metric is critical — users who cash out once are your highest LTV segment.
If 60%+ continue after Tier 1, Tier 2 (₱300) is achievable, delivering ~6× the revenue margin.

---

## Profitability Modeling (Reality Check)

### Conservative Scenario (1,000 DAU)

| Revenue Source | Per User/Day | Total/Day |
|---|---|---|
| Rewarded ads (10 views × $0.003) | $0.03 | $30 |
| Interstitials (5 views × $0.001) | $0.005 | $5 |
| Offerwall (10% completion × $0.50 avg) | $0.05 | $50 |
| **Total Revenue** | **$0.085** | **$85/day** |

| Payout Cost | Per User | Frequency |
|---|---|---|
| Tier 1 (₱50 / ~$0.86) | $0.86 | Once per user |
| Tier 2 (₱300 / ~$5.17) | $5.17 | Monthly for active users |

**Break-even:** A user must generate ~$0.86 in ad/offerwall revenue before Tier 1 payout.
At $0.085/day LTV, break-even is ~10 days of engagement. Tier 2 requires ~60 days.

**Key lever:** Offerwall drives 59% of revenue at 10% completion rate. 
Improving completion to 15% adds $25/day without any extra users.
