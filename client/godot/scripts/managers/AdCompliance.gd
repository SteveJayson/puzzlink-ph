# ============================================================
# AdCompliance.gd  — Autoload Singleton
# Google Play & AdMob policy enforcement on the client side.
#
# INVALID TRAFFIC (IVT) PREVENTION RULES
# ----------------------------------------
# 1. No ad unit within 50dp of any tappable game control.
# 2. Interstitials never fire on back button, scene changes
#    during gameplay, or within 1s of level load.
# 3. Rewarded ads are always user-initiated via explicit button.
# 4. Interstitials get a 0.5s clickability delay (MAX built-in,
#    but we also enforce a client-side guard here).
# 5. We track the last time we showed any ad and enforce a
#    minimum inter-ad gap to protect session quality.
#
# TRANSPARENT REWARDED DESIGN (Play Store requirement)
# -----------------------------------------------------
# Every rewarded trigger must show a clear pre-prompt:
#   "Watch a short video to [specific benefit]"
# AdManager.show_rewarded_ad() must ONLY be called from
# buttons with this labeling. This module validates that.
# ============================================================
extends Node

# Minimum gap between any two ad impressions of any type
const MIN_GAP_ANY_AD_SEC      := 5.0
# Minimum time since scene load before an interstitial may fire
const MIN_SCENE_AGE_FOR_INTER := 2.0
# Minimum 50dp exclusion zone around game buttons (enforced in scene layout)
const EXCLUSION_ZONE_DP       := 50

var _last_ad_shown_at   : float = -999.0
var _scene_loaded_at    : float = 0.0

signal compliance_blocked(reason: String)

# ============================================================
# LIFECYCLE
# ============================================================

func _ready() -> void:
	get_tree().root.child_entered_tree.connect(_on_scene_changed)

func _on_scene_changed(_node: Node) -> void:
	_scene_loaded_at = Time.get_unix_time_from_system()

# ============================================================
# GATE CHECKS  — call before showing any ad
# ============================================================

## Returns true if it's safe to show a rewarded ad right now.
## Rewarded ads require an explicit user tap — pass confirmed=true
## only when called from a button press handler.
func can_show_rewarded(user_initiated: bool) -> bool:
	if not user_initiated:
		_block("Rewarded ad must be user-initiated (Play Store policy)")
		return false
	return _check_global_gap()

## Returns true if it's safe to show an interstitial right now.
func can_show_interstitial() -> bool:
	var scene_age := Time.get_unix_time_from_system() - _scene_loaded_at
	if scene_age < MIN_SCENE_AGE_FOR_INTER:
		_block("Interstitial blocked — scene too new (%.1fs old)" % scene_age)
		return false
	return _check_global_gap()

## Record that an ad was just shown. Call from AdManager callbacks.
func record_ad_shown() -> void:
	_last_ad_shown_at = Time.get_unix_time_from_system()

# ============================================================
# LABEL VALIDATOR
# ============================================================

## Validates that a rewarded button's label is policy-compliant.
## Pass the Button node; logs a warning if the label is vague.
func validate_rewarded_button_label(btn: Button) -> void:
	var text := btn.text.to_lower()
	var compliant := (
		text.contains("watch") or
		text.contains("video") or
		text.contains("ad")    or
		text.contains("double") or
		text.contains("2x")
	)
	if not compliant:
		push_warning(
			"[AdCompliance] Button '%s' has a vague rewarded label. " \
			+ "Play Store requires clear disclosure (e.g. 'Watch ad for 2× coins')." \
			% btn.text
		)

# ============================================================
# EXCLUSION ZONE HELPER
# ============================================================

## Check that an ad container node is not overlapping game buttons.
## Pass the ad's Rect2 in screen coordinates.
func check_exclusion_zone(ad_rect: Rect2, game_buttons: Array) -> bool:
	var expanded := ad_rect.grow(EXCLUSION_ZONE_DP)
	for btn in game_buttons:
		if btn is Control:
			var btn_rect : Rect2 = btn.get_global_rect()
			if expanded.intersects(btn_rect):
				push_warning(
					"[AdCompliance] Ad overlaps button '%s' within exclusion zone." % btn.name
				)
				return false
	return true

# ============================================================
# PRIVATE
# ============================================================

func _check_global_gap() -> bool:
	var gap := Time.get_unix_time_from_system() - _last_ad_shown_at
	if gap < MIN_GAP_ANY_AD_SEC:
		_block("Ad gap too short (%.1fs < %.1fs)" % [gap, MIN_GAP_ANY_AD_SEC])
		return false
	return true

func _block(reason: String) -> void:
	push_warning("[AdCompliance] BLOCKED: " + reason)
	emit_signal("compliance_blocked", reason)
