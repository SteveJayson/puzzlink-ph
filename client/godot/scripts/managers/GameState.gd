# ============================================================
# GameState.gd  — Autoload Singleton
# ============================================================
extends Node

var coins         : int   = 0
var php_balance   : float = 0.0
var can_cashout   : bool  = false
var cashout_tier  : int   = 1
var threshold_php : float = 50.0
var remaining_php : float = 50.0

var current_level           : int  = 1
var levels_completed_today  : int  = 0
var total_levels_completed  : int  = 0

var _level_cache  : Array = []
var _cache_loaded : bool  = false

signal balance_updated
signal level_completed(level_id: int, coins_earned: int)
signal level_catalogue_ready
signal payout_requested(payout_id: int)
signal offline_queued(pending_count: int)

func _ready() -> void:
	total_levels_completed = LocalDB.get_highest_completed_level()
	current_level          = total_levels_completed + 1
	var today := Time.get_date_string_from_system()
	if LocalDB.get_setting("last_played_date") == today:
		levels_completed_today = int(LocalDB.get_setting("levels_today", "0"))

# ============================================================
# BALANCE
# ============================================================

func refresh_balance() -> void:
	if not SyncManager.is_online():
		return
	var result : Dictionary = await API.get_balance()
	if result.get("success", false):
		var d : Dictionary = result.get("data", {}) as Dictionary
		_apply_balance(d)

func _apply_balance(d: Dictionary) -> void:
	coins       = int(d.get("coins", coins))
	php_balance = float(d.get("php_equivalent", php_balance))
	var cashout : Dictionary = d.get("cashout", {}) as Dictionary
	can_cashout   = bool(cashout.get("can_cashout", false))
	cashout_tier  = int(cashout.get("tier", 1))
	threshold_php = float(cashout.get("threshold_php", 50.0))
	remaining_php = float(cashout.get("remaining_php", 50.0))
	emit_signal("balance_updated")

# ============================================================
# LEVEL COMPLETION
# ============================================================

func submit_level_complete(level_id: int, time_taken: int, ad_doubled: bool) -> void:
	var level_data  : Dictionary = LocalDB.get_cached_level(level_id)
	var base_reward : int        = int(level_data.get("coin_reward", 20))
	var estimated   : int        = base_reward * (2 if ad_doubled else 1)

	LocalDB.mark_level_completed(level_id, time_taken, estimated, false)
	_advance_progression(level_id, estimated)

	if SyncManager.is_online():
		var result : Dictionary = await API.complete_level(level_id, time_taken, ad_doubled)
		if result.get("success", false):
			var d : Dictionary = result.get("data", {}) as Dictionary
			_apply_balance(d)
			var actual := int(d.get("coins_earned", estimated))
			emit_signal("level_completed", level_id, actual)
		else:
			emit_signal("level_completed", level_id, 0)
	else:
		SyncManager.queue_level_complete(level_id, time_taken)
		coins       += estimated
		php_balance  = float(coins) / 1000.0
		emit_signal("level_completed", level_id, estimated)
		emit_signal("balance_updated")
		emit_signal("offline_queued", LocalDB.get_pending_sync_count())

func _advance_progression(level_id: int, _coins_earned: int) -> void:
	total_levels_completed  = max(total_levels_completed, level_id)
	current_level           = total_levels_completed + 1
	levels_completed_today += 1
	var today := Time.get_date_string_from_system()
	LocalDB.save_setting("last_played_date", today)
	LocalDB.save_setting("levels_today", str(levels_completed_today))

# ============================================================
# AD REWARDS
# ============================================================

func submit_ad_reward(ad_type: String, ad_network: String, impression_id: String) -> int:
	if SyncManager.is_online():
		var result : Dictionary = await API.claim_ad_reward(ad_type, ad_network, impression_id)
		if result.get("success", false):
			var d   : Dictionary = result.get("data", {}) as Dictionary
			var bal : Dictionary = d.get("balance", {}) as Dictionary
			coins       = int(bal.get("coins", coins))
			php_balance = float(bal.get("php_equivalent", php_balance))
			emit_signal("balance_updated")
			return int(d.get("coins_earned", 0))
	else:
		SyncManager.queue_ad_reward(ad_type, ad_network, impression_id)
	return 0

# ============================================================
# LEVEL CATALOGUE
# ============================================================

func load_level_catalogue() -> void:
	if not LocalDB.is_cache_stale():
		_cache_loaded = true
		emit_signal("level_catalogue_ready")
		return

	if not SyncManager.is_online():
		_cache_loaded = true
		emit_signal("level_catalogue_ready")
		return

	var page       := 1
	var all_levels : Array = []
	while true:
		var result : Dictionary = await API.get_levels(page)
		if not result.get("success", false):
			break
		var data       : Dictionary = result.get("data", {}) as Dictionary
		var levels     : Array      = data.get("levels", []) as Array
		all_levels.append_array(levels)
		var pagination : Dictionary = data.get("pagination", {}) as Dictionary
		if page >= int(pagination.get("total_pages", 1)):
			break
		page += 1

	if not all_levels.is_empty():
		LocalDB.cache_levels(all_levels)

	_cache_loaded = true
	emit_signal("level_catalogue_ready")

func get_level_data(level_number: int) -> Dictionary:
	return LocalDB.get_cached_level(level_number)

func is_catalogue_ready() -> bool:
	return _cache_loaded
