# ============================================================
# GameScene.gd  — attached to res://scenes/game/GameScene.tscn
# 
# Orchestrates a single puzzle session:
#  1. Loads level data from GameState (cached locally)
#  2. Drives PuzzleGrid
#  3. Runs the countdown timer
#  4. Shows the LevelComplete overlay
#  5. Triggers the rewarded-ad double-coins flow
# ============================================================
extends Control

# --- Node refs (set in Godot scene editor) ---
@onready var grid           : GridContainer = $SafeArea/VBox/GridWrapper/PuzzleGrid
@onready var label_level    : Label         = $SafeArea/Header/LabelLevel
@onready var label_timer    : Label         = $SafeArea/Header/LabelTimer
@onready var label_coins    : Label         = $SafeArea/Header/LabelCoins
@onready var word_list      : VBoxContainer = $SafeArea/VBox/WordList
@onready var overlay_complete : Control     = $OverlayComplete
@onready var overlay_loading  : Control     = $OverlayLoading
@onready var lbl_earned     : Label         = $OverlayComplete/VBox/LblEarned
@onready var lbl_total      : Label         = $OverlayComplete/VBox/LblTotal
@onready var btn_next       : Button        = $OverlayComplete/VBox/BtnNext
@onready var btn_watch_ad   : Button        = $OverlayComplete/VBox/BtnWatchAd
@onready var lbl_offline    : Label         = $SafeArea/Header/LblOffline
@onready var timer_bar      : ProgressBar   = $SafeArea/VBox/TimerBar

# --- Config ---
const LEVEL_TIME_SEC  := 60     # puzzle time limit per round
const BONUS_TIME_SEC  := 15     # extra time awarded per found word (easy mode)

# --- State ---
var _level_id        : int   = 0
var _time_remaining  : float = LEVEL_TIME_SEC
var _timer_running   : bool  = false
var _coins_earned    : int   = 0
var _ad_doubled      : bool  = false
var _time_taken      : int   = 0
var _level_data      : Dictionary = {}

# ============================================================
# LIFECYCLE
# ============================================================

func _ready() -> void:
	overlay_complete.hide()
	overlay_loading.show()

	GameState.level_catalogue_ready.connect(_on_catalogue_ready, CONNECT_ONE_SHOT)
	SyncManager.connectivity_changed.connect(_on_connectivity_changed)

	btn_next.pressed.connect(_on_next_pressed)
	btn_watch_ad.pressed.connect(_on_watch_ad_pressed)

	AdManager.rewarded_ad_completed.connect(_on_rewarded_earned)
	AdManager.rewarded_ad_failed.connect(_on_rewarded_failed)

	# Start label refresh
	label_coins.text = "🪙 %d" % GameState.coins
	_update_offline_badge()

	if GameState.is_catalogue_ready():
		_load_current_level()
	else:
		GameState.load_level_catalogue()

func _process(delta: float) -> void:
	if not _timer_running:
		return
	_time_remaining -= delta
	_time_remaining  = maxf(_time_remaining, 0.0)

	label_timer.text = "%d" % ceili(_time_remaining)
	timer_bar.value  = (_time_remaining / LEVEL_TIME_SEC) * 100.0

	# Color warning when time is low
	if _time_remaining <= 10.0:
		label_timer.add_theme_color_override("font_color", Color(1, 0.3, 0.3))
	else:
		label_timer.remove_theme_color_override("font_color")

	if _time_remaining <= 0.0:
		_timer_running = false
		_on_time_expired()

# ============================================================
# LEVEL LOADING
# ============================================================

func _on_catalogue_ready() -> void:
	overlay_loading.hide()
	_load_current_level()

func _load_current_level() -> void:
	var level_number : int = GameState.current_level
	_level_data = GameState.get_level_data(level_number)

	if _level_data.is_empty():
		# Fallback: generate a placeholder puzzle until server data loads
		_level_data = _generate_fallback_level(level_number)

	_level_id       = int(_level_data.get("id", level_number))
	_time_remaining = LEVEL_TIME_SEC
	_timer_running  = false
	_coins_earned   = 0
	_ad_doubled     = false
	overlay_complete.hide()

	label_level.text = "Level %d" % level_number
	label_coins.text = "🪙 %d" % GameState.coins

	# Build word list sidebar
	for child in word_list.get_children():
		child.queue_free()
	var grid_data : Dictionary = _level_data.get("grid_data", _level_data)
	for w in grid_data.get("words", []):
		var lbl := Label.new()
		lbl.text = w.to_upper()
		lbl.name = "word_" + w
		lbl.add_theme_font_size_override("font_size", 14)
		word_list.add_child(lbl)

	# Connect grid signals
	grid.word_found.connect(_on_word_found, CONNECT_ONE_SHOT if grid.word_found.get_connections().size() > 0 else 0)
	grid.puzzle_solved.connect(_on_puzzle_solved, CONNECT_ONE_SHOT if grid.puzzle_solved.get_connections().size() > 0 else 0)
	# Reconnect cleanly
	if grid.word_found.is_connected(_on_word_found):
		grid.word_found.disconnect(_on_word_found)
	if grid.puzzle_solved.is_connected(_on_puzzle_solved):
		grid.puzzle_solved.disconnect(_on_puzzle_solved)
	grid.word_found.connect(_on_word_found)
	grid.puzzle_solved.connect(_on_puzzle_solved)

	grid.load_level(_level_data)

	# Short countdown before timer starts
	await get_tree().create_timer(0.5).timeout
	_timer_running = true

# ============================================================
# GAME EVENTS
# ============================================================

func _on_word_found(word: String) -> void:
	# Strike-through the word in the list
	var lbl := word_list.get_node_or_null("word_" + word)
	if lbl:
		lbl.add_theme_color_override("font_color", Color(0.35, 0.85, 0.50))
		lbl.text = "✓ " + word.to_upper()

	# Bump timer slightly (encourages continued play)
	_time_remaining = minf(_time_remaining + 3.0, float(LEVEL_TIME_SEC))

func _on_puzzle_solved(level_id: int, time_elapsed_sec: int) -> void:
	_timer_running = false
	_time_taken    = time_elapsed_sec

	# Retrieve expected coin reward from level data
	var base_reward : int = int(_level_data.get("coin_reward", 20))
	_coins_earned = base_reward

	lbl_earned.text = "🪙 +%d coins" % _coins_earned
	lbl_total.text  = "Balance: 🪙 %d  (₱%.2f)" % [GameState.coins, GameState.php_balance]
	btn_watch_ad.show()
	overlay_complete.show()

	# Trigger interstitial on every 3rd level
	AdManager.maybe_show_interstitial()

func _on_time_expired() -> void:
	# Time ran out — partial credit (half coins, still triggers ad flow)
	_time_taken   = LEVEL_TIME_SEC
	_coins_earned = int(_level_data.get("coin_reward", 20)) / 2
	lbl_earned.text = "⏰ Time's up! +%d coins" % _coins_earned
	lbl_total.text  = "Balance: 🪙 %d  (₱%.2f)" % [GameState.coins, GameState.php_balance]
	btn_watch_ad.show()
	overlay_complete.show()
	AdManager.maybe_show_interstitial()

# ============================================================
# LEVEL COMPLETE OVERLAY ACTIONS
# ============================================================

func _on_watch_ad_pressed() -> void:
	# Policy gate: rewarded must be user-initiated — this IS a button press, so pass true
	if not AdCompliance.can_show_rewarded(true):
		ToastNotification.show_msg("Ad not available right now", Color(1, 0.6, 0.2))
		return
	if not AdManager.is_rewarded_ready():
		ToastNotification.show_msg("Loading ad, try again in a moment...", Color(1, 0.8, 0.3))
		return
	btn_watch_ad.disabled = true
	btn_watch_ad.text     = "Loading ad..."
	AdCompliance.validate_rewarded_button_label(btn_watch_ad)
	AdManager.show_rewarded_ad()

func _on_rewarded_earned(impression_id: String) -> void:
	AdCompliance.record_ad_shown()
	# Validate impression with server before granting reward
	var validation := await API.validate_impression(impression_id, "rewarded", "applovin")
	var is_valid   := validation.get("success", false) and validation.get("data", {}).get("validated", false)
	if not is_valid:
		var reason : String = validation.get("data", {}).get("reason", "validation_failed")
		if reason == "daily_cap_reached":
			ToastNotification.show_msg("Daily ad reward limit reached 🔒", Color(1, 0.6, 0.2))
		else:
			ToastNotification.show_msg("Couldn't verify ad. Reward skipped.", Color(0.9, 0.3, 0.3))
		btn_watch_ad.disabled = false
		btn_watch_ad.text     = "Watch Ad (2× coins)"
		return
	_ad_doubled   = true
	_coins_earned *= 2
	btn_watch_ad.hide()
	lbl_earned.text = "🪙 +%d coins (2×!)" % _coins_earned
	_finalize_level()

func _on_rewarded_failed() -> void:
	btn_watch_ad.disabled = false
	btn_watch_ad.text     = "Watch Ad (2× coins)"
	# Optional: show a toast "No ad available right now"

func _on_next_pressed() -> void:
	if not _ad_doubled:
		_finalize_level()
	else:
		_go_to_next_level()

func _finalize_level() -> void:
	btn_next.disabled = true
	# Submit to server (or queue offline)
	await GameState.submit_level_complete(_level_id, _time_taken, _ad_doubled)
	# Update label with confirmed balance
	lbl_total.text = "Balance: 🪙 %d  (₱%.2f)" % [GameState.coins, GameState.php_balance]
	btn_next.disabled = false
	btn_next.text     = "Next Level →"
	# After finalize, Next leads to next level
	btn_watch_ad.hide()

func _go_to_next_level() -> void:
	_load_current_level()

# ============================================================
# CONNECTIVITY
# ============================================================

func _update_offline_badge() -> void:
	if lbl_offline:
		lbl_offline.visible = not SyncManager.is_online()

func _on_connectivity_changed(_is_online: bool) -> void:
	_update_offline_badge()
	label_coins.text = "🪙 %d" % GameState.coins

# ============================================================
# FALLBACK PUZZLE (when server data not yet cached)
# ============================================================

func _generate_fallback_level(level_number: int) -> Dictionary:
	# Simple 7×7 word search with everyday Filipino-English words
	# Used only on very first launch before server data is cached.
	return {
		"id":          level_number,
		"level_number": level_number,
		"coin_reward": 20,
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
