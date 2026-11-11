# ============================================================
# MainMenu.gd
# ============================================================
extends Control

@onready var label_coins    : Label       = $SafeArea/VBox/Header/LabelCoins
@onready var label_php      : Label       = $SafeArea/VBox/Header/LabelPhp
@onready var badge_cashout  : Label       = $SafeArea/VBox/Header/BadgeCashout
@onready var lbl_offline    : Label       = $SafeArea/VBox/Header/LblOffline
@onready var label_level    : Label       = $SafeArea/VBox/LabelLevel
@onready var label_progress : Label       = $SafeArea/VBox/LabelProgress
@onready var progress_bar   : ProgressBar = $SafeArea/VBox/ProgressBar
@onready var btn_play       : Button      = $SafeArea/VBox/BtnPlay

func _ready() -> void:
	GameState.balance_updated.connect(_refresh_ui)
	SyncManager.connectivity_changed.connect(_on_connectivity_changed)
	btn_play.pressed.connect(_on_play_pressed)
	GameState.refresh_balance()
	GameState.load_level_catalogue()
	_refresh_ui()
	_update_offline_badge()

func _refresh_ui() -> void:
	label_coins.text   = "🪙 %d" % GameState.coins
	label_php.text     = "₱%.2f" % GameState.php_balance
	badge_cashout.visible = GameState.can_cashout
	label_level.text   = "Level %d" % GameState.current_level
	var threshold : float = GameState.threshold_php
	var balance   : float = GameState.php_balance
	progress_bar.value  = clampf(balance / threshold, 0.0, 1.0) * 100.0
	if GameState.can_cashout:
		label_progress.text = "🎉 Ready to cash out ₱%.0f!" % threshold
	else:
		label_progress.text = "₱%.2f / ₱%.0f to cashout" % [balance, threshold]

func _update_offline_badge() -> void:
	var online : bool = SyncManager.is_online()
	lbl_offline.visible = not online
	if not online:
		var pending : int = LocalDB.get_pending_sync_count()
		lbl_offline.text = "📶 Offline" + (" · %d pending" % pending if pending > 0 else "")

func _on_connectivity_changed(_is_online: bool) -> void:
	_update_offline_badge()

func _on_play_pressed() -> void:
	get_tree().change_scene_to_file("res://scenes/game/GameScene.tscn")
