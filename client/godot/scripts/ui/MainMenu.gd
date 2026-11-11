# ============================================================
# MainMenu.gd  — attached to res://scenes/ui/MainMenu.tscn
#
# Tab layout:
#   [Play]  [Earn]  [Wallet]
#
# Displays live coin balance, cashout progress, and
# a prominent GCash/Maya reward badge.
# ============================================================
extends Control

# --- Tabs ---
@onready var tab_container   : TabContainer = $SafeArea/TabContainer
@onready var page_play       : Control      = $SafeArea/TabContainer/Play
@onready var page_earn       : Control      = $SafeArea/TabContainer/Earn
@onready var page_wallet     : Control      = $SafeArea/TabContainer/Wallet

# --- Header (visible on all tabs) ---
@onready var label_coins     : Label        = $SafeArea/Header/LabelCoins
@onready var label_php       : Label        = $SafeArea/Header/LabelPhp
@onready var badge_cashout   : Control      = $SafeArea/Header/BadgeCashout
@onready var lbl_offline     : Label        = $SafeArea/Header/LblOffline

# --- Play tab ---
@onready var btn_play        : Button       = $SafeArea/TabContainer/Play/VBox/BtnPlay
@onready var label_level     : Label        = $SafeArea/TabContainer/Play/VBox/LabelLevel
@onready var label_progress  : Label        = $SafeArea/TabContainer/Play/VBox/LabelProgress
@onready var progress_bar    : ProgressBar  = $SafeArea/TabContainer/Play/VBox/ProgressBar

func _ready() -> void:
	GameState.balance_updated.connect(_refresh_header)
	SyncManager.connectivity_changed.connect(_on_connectivity_changed)

	btn_play.pressed.connect(_on_play_pressed)

	# Kick off balance + catalogue load
	GameState.refresh_balance()
	GameState.load_level_catalogue()

	_refresh_header()
	_refresh_play_tab()
	_update_offline_badge()

# ============================================================
# HEADER
# ============================================================

func _refresh_header() -> void:
	label_coins.text = "🪙 %d" % GameState.coins
	label_php.text   = "₱%.2f" % GameState.php_balance

	# Show glowing cashout badge when eligible
	badge_cashout.visible = GameState.can_cashout

func _update_offline_badge() -> void:
	lbl_offline.visible = not SyncManager.is_online()
	if not SyncManager.is_online():
		var pending := LocalDB.get_pending_sync_count()
		lbl_offline.text = "📶 Offline" + (" · %d pending" % pending if pending > 0 else "")

func _on_connectivity_changed(_is_online: bool) -> void:
	_update_offline_badge()

# ============================================================
# PLAY TAB
# ============================================================

func _refresh_play_tab() -> void:
	label_level.text = "Level %d" % GameState.current_level

	var threshold : float = GameState.threshold_php
	var balance   : float = GameState.php_balance
	var pct       : float = clampf(balance / threshold, 0.0, 1.0)
	progress_bar.value  = pct * 100.0

	if GameState.can_cashout:
		label_progress.text = "🎉 Ready to cash out ₱%.0f!" % threshold
		label_progress.add_theme_color_override("font_color", Color(0.2, 0.8, 0.3))
	else:
		label_progress.text = "₱%.2f / ₱%.0f to cashout" % [balance, threshold]
		label_progress.remove_theme_color_override("font_color")

func _on_play_pressed() -> void:
	get_tree().change_scene_to_file("res://scenes/game/GameScene.tscn")
