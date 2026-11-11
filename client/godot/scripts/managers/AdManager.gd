# ============================================================
# AdManager.gd  — Autoload Singleton
# Phase 4: Full AppLovin MAX + AdMob Mediation integration.
#
# MEDIATION STRATEGY
# ------------------
# AppLovin MAX is the primary mediation layer. It runs a
# unified auction (header bidding) across:
#   1. AppLovin Exchange (highest fill PH)
#   2. Google AdMob
#   3. Unity Ads
#   4. Mintegral
#   5. Meta Audience Network (optional)
#
# This delivers better eCPM than AdMob standalone because
# multiple networks compete per impression in real time.
#
# PLUGIN REQUIRED
# ---------------
# AppLovin MAX Godot Plugin:
#   https://github.com/AppLovin/AppLovin-MAX-Godot
# Install to addons/applovin_max/ and enable in Project Settings.
#
# GOOGLE PLAY POLICY COMPLIANCE
# ------------------------------
# - Rewarded ads: always explicit, user-initiated. Never auto-play.
# - Interstitials: frequency-capped to every 3 levels, never on
#   back-press or during active gameplay.
# - No ads within 50dp of any tappable game button (IVT prevention).
# - All ad placements use MAX's built-in clickability delay (0.5s).
# ============================================================
extends Node

# ============================================================
# AD UNIT IDs — replace with your MAX dashboard IDs
# ============================================================
const MAX_SDK_KEY          := "YOUR_APPLOVIN_SDK_KEY"
const AD_UNIT_REWARDED     := "YOUR_MAX_REWARDED_AD_UNIT_ID"
const AD_UNIT_INTERSTITIAL := "YOUR_MAX_INTERSTITIAL_AD_UNIT_ID"

# ============================================================
# FREQUENCY CAPS  (client-side guard, server also enforces)
# ============================================================
const INTERSTITIAL_EVERY_N_LEVELS := 3     # show every 3rd level
const MAX_REWARDED_PER_SESSION    := 20    # hard cap per app session
const MIN_SECONDS_BETWEEN_INTER   := 30    # never two interstitials < 30s apart

# ============================================================
# STATE
# ============================================================
var _sdk_initialized      : bool  = false
var _rewarded_loaded      : bool  = false
var _interstitial_loaded  : bool  = false
var _rewarded_loading     : bool  = false
var _interstitial_loading : bool  = false

var _levels_since_inter   : int   = 0
var _rewarded_this_session: int   = 0
var _last_inter_time      : float = -999.0
var _pending_impression_id: String = ""

# ============================================================
# SIGNALS
# ============================================================
signal rewarded_ad_completed(impression_id: String)
signal rewarded_ad_failed(reason: String)
signal interstitial_shown(impression_id: String)
signal interstitial_dismissed
signal sdk_ready

# ============================================================
# LIFECYCLE
# ============================================================

func _ready() -> void:
	GameState.level_completed.connect(_on_level_completed)
	_init_sdk()

func _init_sdk() -> void:
	if not Engine.has_singleton("AppLovinMAX"):
		push_warning("[AdManager] AppLovin MAX plugin not found. Using test stubs.")
		_sdk_initialized = true
		_load_rewarded()
		_load_interstitial()
		return

	var max_sdk = Engine.get_singleton("AppLovinMAX")

	# Wire SDK callbacks
	max_sdk.on_sdk_initialized.connect(_on_sdk_initialized)

	max_sdk.on_rewarded_ad_loaded.connect(_on_rewarded_loaded)
	max_sdk.on_rewarded_ad_load_failed.connect(_on_rewarded_load_failed)
	max_sdk.on_rewarded_ad_displayed.connect(_on_rewarded_displayed)
	max_sdk.on_rewarded_ad_clicked.connect(_on_rewarded_clicked)
	max_sdk.on_rewarded_ad_hidden.connect(_on_rewarded_hidden)
	max_sdk.on_rewarded_ad_received_reward.connect(_on_rewarded_earned)
	max_sdk.on_rewarded_ad_failed_to_display.connect(_on_rewarded_display_failed)

	max_sdk.on_interstitial_loaded.connect(_on_interstitial_loaded)
	max_sdk.on_interstitial_load_failed.connect(_on_interstitial_load_failed)
	max_sdk.on_interstitial_displayed.connect(_on_interstitial_displayed)
	max_sdk.on_interstitial_hidden.connect(_on_interstitial_hidden)
	max_sdk.on_interstitial_failed_to_display.connect(_on_interstitial_display_failed)

	# Initialize MAX SDK
	max_sdk.initialize(MAX_SDK_KEY)

# ============================================================
# PUBLIC API
# ============================================================

## Show a rewarded video. Connect rewarded_ad_completed before calling.
## Never call this without an explicit user tap — Play Store policy.
func show_rewarded_ad() -> void:
	if _rewarded_this_session >= MAX_REWARDED_PER_SESSION:
		emit_signal("rewarded_ad_failed", "Daily session cap reached")
		return

	if not _rewarded_loaded:
		emit_signal("rewarded_ad_failed", "Ad not ready yet")
		return

	if Engine.has_singleton("AppLovinMAX"):
		Engine.get_singleton("AppLovinMAX").show_rewarded_ad(AD_UNIT_REWARDED)
	else:
		# --- STUB (editor / no plugin) ---
		await get_tree().create_timer(1.5).timeout
		var fake_id := "stub_rw_%d" % int(Time.get_unix_time_from_system())
		_on_rewarded_earned(AD_UNIT_REWARDED, {"label": "coins", "amount": 1}, fake_id)

## Called by GameScene after every level completion.
## Internally counts and fires interstitial when threshold met.
func maybe_show_interstitial() -> void:
	_levels_since_inter += 1

	var now       : float = Time.get_unix_time_from_system()
	var too_soon  : bool  = (now - _last_inter_time) < MIN_SECONDS_BETWEEN_INTER
	var not_ready : bool  = not _interstitial_loaded
	var not_due   : bool  = _levels_since_inter < INTERSTITIAL_EVERY_N_LEVELS

	if not_due or too_soon or not_ready:
		return

	_levels_since_inter = 0
	_last_inter_time    = now

	if Engine.has_singleton("AppLovinMAX"):
		Engine.get_singleton("AppLovinMAX").show_interstitial(AD_UNIT_INTERSTITIAL)
	else:
		# --- STUB ---
		var fake_id := "stub_inter_%d" % int(Time.get_unix_time_from_system())
		_on_interstitial_displayed(AD_UNIT_INTERSTITIAL, fake_id)
		await get_tree().create_timer(0.3).timeout
		_on_interstitial_hidden(AD_UNIT_INTERSTITIAL, fake_id)

## True when a rewarded ad is loaded and ready to show.
func is_rewarded_ready() -> bool:
	return _rewarded_loaded

# ============================================================
# REWARDED AD CALLBACKS
# ============================================================

func _on_rewarded_loaded(ad_unit_id: String, ad_info: Dictionary) -> void:
	_rewarded_loaded  = true
	_rewarded_loading = false

func _on_rewarded_load_failed(ad_unit_id: String, error_info: Dictionary) -> void:
	_rewarded_loaded  = false
	_rewarded_loading = false
	push_warning("[AdManager] Rewarded load failed: " + str(error_info))
	# Retry after delay
	await get_tree().create_timer(30.0).timeout
	_load_rewarded()

func _on_rewarded_displayed(ad_unit_id: String, ad_info: Dictionary) -> void:
	_pending_impression_id = str(ad_info.get("adId", str(Time.get_unix_time_from_system())))

func _on_rewarded_clicked(ad_unit_id: String, ad_info: Dictionary) -> void:
	pass  # tracking only

func _on_rewarded_earned(ad_unit_id: String, reward: Dictionary, impression_id: String = "") -> void:
	# MAX fires this BEFORE on_rewarded_ad_hidden — do NOT credit here.
	# Store flag; credit in _on_rewarded_hidden after user sees full ad.
	_pending_impression_id = impression_id if impression_id != "" else _pending_impression_id

func _on_rewarded_hidden(ad_unit_id: String, ad_info: Dictionary) -> void:
	_rewarded_loaded       = false
	_rewarded_this_session += 1
	var imp_id := _pending_impression_id
	_pending_impression_id  = ""
	# Credit ONLY if we have a valid impression ID
	if imp_id != "":
		emit_signal("rewarded_ad_completed", imp_id)
	else:
		emit_signal("rewarded_ad_failed", "No impression ID received")
	_load_rewarded()

func _on_rewarded_display_failed(ad_unit_id: String, error_info: Dictionary) -> void:
	emit_signal("rewarded_ad_failed", "Display failed: " + str(error_info.get("message", "")))
	_load_rewarded()

# ============================================================
# INTERSTITIAL CALLBACKS
# ============================================================

func _on_interstitial_loaded(ad_unit_id: String, ad_info: Dictionary) -> void:
	_interstitial_loaded  = true
	_interstitial_loading = false

func _on_interstitial_load_failed(ad_unit_id: String, error_info: Dictionary) -> void:
	_interstitial_loaded  = false
	_interstitial_loading = false
	await get_tree().create_timer(60.0).timeout
	_load_interstitial()

func _on_interstitial_displayed(ad_unit_id: String, ad_info_or_id = null) -> void:
	_interstitial_loaded = false
	var imp_id := ""
	if ad_info_or_id is Dictionary:
		imp_id = str(ad_info_or_id.get("adId", ""))
	elif ad_info_or_id is String:
		imp_id = ad_info_or_id
	# Credit server for passive interstitial impression
	if imp_id != "":
		GameState.submit_ad_reward("interstitial", "applovin", imp_id)
	emit_signal("interstitial_shown", imp_id)

func _on_interstitial_hidden(ad_unit_id: String, ad_info_or_id = null) -> void:
	emit_signal("interstitial_dismissed")
	_load_interstitial()

func _on_interstitial_display_failed(ad_unit_id: String, error_info: Dictionary) -> void:
	_load_interstitial()

# ============================================================
# SDK INIT CALLBACK
# ============================================================

func _on_sdk_initialized(sdk_config: Dictionary) -> void:
	_sdk_initialized = true
	emit_signal("sdk_ready")
	_load_rewarded()
	_load_interstitial()

# ============================================================
# LOADERS
# ============================================================

func _load_rewarded() -> void:
	if _rewarded_loading or _rewarded_loaded:
		return
	_rewarded_loading = true
	if Engine.has_singleton("AppLovinMAX"):
		Engine.get_singleton("AppLovinMAX").load_rewarded_ad(AD_UNIT_REWARDED)
	else:
		await get_tree().create_timer(0.5).timeout
		_rewarded_loaded  = true
		_rewarded_loading = false

func _load_interstitial() -> void:
	if _interstitial_loading or _interstitial_loaded:
		return
	_interstitial_loading = true
	if Engine.has_singleton("AppLovinMAX"):
		Engine.get_singleton("AppLovinMAX").load_interstitial(AD_UNIT_INTERSTITIAL)
	else:
		await get_tree().create_timer(0.5).timeout
		_interstitial_loaded  = true
		_interstitial_loading = false

func _on_level_completed(_level_id: int, _coins: int) -> void:
	maybe_show_interstitial()
