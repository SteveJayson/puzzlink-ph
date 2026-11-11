# ============================================================
# GameScene.gd
# ============================================================
extends Control

@onready var grid            : GridContainer = $SafeArea/VBox/GridWrapper/PuzzleGrid
@onready var label_level     : Label         = $SafeArea/VBox/Header/LabelLevel
@onready var label_timer     : Label         = $SafeArea/VBox/Header/LabelTimer
@onready var label_coins     : Label         = $SafeArea/VBox/Header/LabelCoins
@onready var lbl_offline     : Label         = $SafeArea/VBox/Header/LblOffline
@onready var timer_bar       : ProgressBar   = $SafeArea/VBox/TimerBar
@onready var word_list       : VBoxContainer = $SafeArea/VBox/WordList
@onready var overlay_loading : ColorRect     = $OverlayLoading
@onready var overlay_complete: ColorRect     = $OverlayComplete
@onready var lbl_earned      : Label         = $OverlayComplete/VBox/LblEarned
@onready var lbl_total       : Label         = $OverlayComplete/VBox/LblTotal
@onready var btn_watch_ad    : Button        = $OverlayComplete/VBox/BtnWatchAd
@onready var btn_next        : Button        = $OverlayComplete/VBox/BtnNext

const LEVEL_TIME_SEC := 60

var _level_id       : int        = 0
var _time_remaining : float      = LEVEL_TIME_SEC
var _timer_running  : bool       = false
var _coins_earned   : int        = 0
var _ad_doubled     : bool       = false
var _time_taken     : int        = 0
var _level_data     : Dictionary = {}

func _ready() -> void:
	overlay_complete.hide()
	overlay_loading.show()

	GameState.level_catalogue_ready.connect(_on_catalogue_ready, CONNECT_ONE_SHOT)
	SyncManager.connectivity_changed.connect(_on_connectivity_changed)
	btn_next.pressed.connect(_on_next_pressed)
	btn_watch_ad.pressed.connect(_on_watch_ad_pressed)
	AdManager.rewarded_ad_completed.connect(_on_rewarded_earned)
	AdManager.rewarded_ad_failed.connect(_on_rewarded_failed)

	label_coins.text = "🪙 %d" % GameState.coins
	_update_offline_badge()

	if GameState.is_catalogue_ready():
		_load_current_level()
	else:
		GameState.load_level_catalogue()

func _process(delta: float) -> void:
	if not _timer_running:
		return
	_time_remaining = maxf(_time_remaining - delta, 0.0)
	label_timer.text = "%d" % ceili(_time_remaining)
	timer_bar.value  = (_time_remaining / LEVEL_TIME_SEC) * 100.0
	if _time_remaining <= 10.0:
		label_timer.add_theme_color_override("font_color", Color(1, 0.3, 0.3))
	else:
		label_timer.remove_theme_color_override("font_color")
	if _time_remaining <= 0.0:
		_timer_running = false
		_on_time_expired()

func _on_catalogue_ready() -> void:
	overlay_loading.hide()
	_load_current_level()

func _load_current_level() -> void:
	var level_number : int = GameState.current_level
	_level_data = GameState.get_level_data(level_number)
	if _level_data.is_empty():
		_level_data = _fallback_level(level_number)

	_level_id       = int(_level_data.get("id", level_number))
	_time_remaining = LEVEL_TIME_SEC
	_timer_running  = false
	_coins_earned   = 0
	_ad_doubled     = false
	overlay_complete.hide()
	label_level.text = "Level %d" % level_number
	label_coins.text = "🪙 %d" % GameState.coins

	for child in word_list.get_children():
		child.queue_free()
	var gd : Dictionary = _level_data.get("grid_data", _level_data) as Dictionary
	for w in gd.get("words", []):
		var lbl := Label.new()
		lbl.text = str(w).to_upper()
		lbl.name = "word_" + str(w)
		lbl.add_theme_font_size_override("font_size", 14)
		word_list.add_child(lbl)

	if grid.word_found.is_connected(_on_word_found):
		grid.word_found.disconnect(_on_word_found)
	if grid.puzzle_solved.is_connected(_on_puzzle_solved):
		grid.puzzle_solved.disconnect(_on_puzzle_solved)
	grid.word_found.connect(_on_word_found)
	grid.puzzle_solved.connect(_on_puzzle_solved)
	grid.load_level(_level_data)

	await get_tree().create_timer(0.5).timeout
	_timer_running = true

func _on_word_found(word: String) -> void:
	var lbl := word_list.get_node_or_null("word_" + word)
	if lbl:
		lbl.add_theme_color_override("font_color", Color(0.35, 0.85, 0.50))
		lbl.text = "✓ " + word.to_upper()
	_time_remaining = minf(_time_remaining + 3.0, float(LEVEL_TIME_SEC))

func _on_puzzle_solved(level_id: int, time_elapsed_sec: int) -> void:
	_timer_running = false
	_time_taken    = time_elapsed_sec
	_coins_earned  = int(_level_data.get("coin_reward", 20))
	lbl_earned.text = "🪙 +%d coins" % _coins_earned
	lbl_total.text  = "Balance: 🪙 %d  (₱%.2f)" % [GameState.coins, GameState.php_balance]
	btn_watch_ad.show()
	overlay_complete.show()
	AdManager.maybe_show_interstitial()

func _on_time_expired() -> void:
	_time_taken   = LEVEL_TIME_SEC
	_coins_earned = int(_level_data.get("coin_reward", 20)) / 2
	lbl_earned.text = "⏰ Time's up! +%d coins" % _coins_earned
	lbl_total.text  = "Balance: 🪙 %d  (₱%.2f)" % [GameState.coins, GameState.php_balance]
	btn_watch_ad.show()
	overlay_complete.show()
	AdManager.maybe_show_interstitial()

func _on_watch_ad_pressed() -> void:
	if not AdCompliance.can_show_rewarded(true):
		Toast.show_msg("Ad not available right now", Color(1, 0.6, 0.2))
		return
	if not AdManager.is_rewarded_ready():
		Toast.show_msg("Loading ad, try again in a moment...", Color(1, 0.8, 0.3))
		return
	btn_watch_ad.disabled = true
	btn_watch_ad.text     = "Loading ad..."
	AdManager.show_rewarded_ad()

func _on_rewarded_earned(impression_id: String) -> void:
	AdCompliance.record_ad_shown()
	_ad_doubled   = true
	_coins_earned *= 2
	btn_watch_ad.hide()
	lbl_earned.text = "🪙 +%d coins (2×!)" % _coins_earned
	_finalize_level()

func _on_rewarded_failed(_reason: String) -> void:
	btn_watch_ad.disabled = false
	btn_watch_ad.text     = "Watch Ad (2× coins)"

func _on_next_pressed() -> void:
	if not _ad_doubled:
		_finalize_level()
	else:
		_load_current_level()

func _finalize_level() -> void:
	btn_next.disabled = true
	await GameState.submit_level_complete(_level_id, _time_taken, _ad_doubled)
	lbl_total.text    = "Balance: 🪙 %d  (₱%.2f)" % [GameState.coins, GameState.php_balance]
	btn_next.disabled = false
	btn_next.text     = "Next Level →"
	btn_watch_ad.hide()

func _update_offline_badge() -> void:
	if lbl_offline:
		lbl_offline.visible = not SyncManager.is_online()

func _on_connectivity_changed(_is_online: bool) -> void:
	_update_offline_badge()
	label_coins.text = "🪙 %d" % GameState.coins

func _fallback_level(level_number: int) -> Dictionary:
	return {
		"id": level_number, "level_number": level_number, "coin_reward": 20,
		"grid_data": {
			"words": ["GCASH", "COINS", "WIN"],
			"grid": [
				["G","C","A","S","H","X","Z"],
				["C","O","I","N","S","Y","Q"],
				["W","I","N","P","T","A","B"],
				["F","R","E","E","L","M","K"],
				["J","V","U","O","D","N","H"],
				["P","L","A","Y","E","R","S"],
				["X","Q","B","Z","M","K","W"],
			]
		}
	}
