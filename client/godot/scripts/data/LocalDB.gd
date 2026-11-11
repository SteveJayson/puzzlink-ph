# ============================================================
# LocalDB.gd  — Autoload Singleton
# SQLite-backed local cache for offline puzzle solving,
# pending sync queue, and level progress.
#
# Uses Godot SQLite plugin: godot-sqlite by 2shady4u
# https://github.com/2shady4u/godot-sqlite
# (Add to addons/ and enable in Project Settings)
#
# Without the plugin, falls back to ConfigFile for critical
# data so the game still runs in the editor/emulator.
# ============================================================
extends Node

const DB_PATH   := "user://puzzlink_local.db"
const DB_KEY    := "puzzlink_local_v1"   # bumping this wipes and rebuilds the DB

var _db  = null          # SQLite instance (null until plugin confirmed)
var _cfg : ConfigFile    # fallback when plugin absent

signal sync_queue_changed(pending_count: int)

# ============================================================
# LIFECYCLE
# ============================================================

func _ready() -> void:
	if Engine.has_singleton("SQLite"):
		_init_sqlite()
	else:
		push_warning("[LocalDB] SQLite plugin not found — using ConfigFile fallback")
		_init_configfile()

func _init_sqlite() -> void:
	_db = Engine.get_singleton("SQLite")
	_db.path            = DB_PATH
	_db.verbosity_level = 0   # silent
	if not _db.open_db():
		push_error("[LocalDB] Failed to open SQLite DB")
		_db = null
		_init_configfile()
		return
	_create_tables()

func _init_configfile() -> void:
	_cfg = ConfigFile.new()
	_cfg.load("user://local_cache.cfg")

func _create_tables() -> void:
	# Level cache — downloaded from server
	_db.create_table("levels", {
		"id":           {"data_type": "int",  "primary_key": true},
		"level_number": {"data_type": "int",  "not_null": true},
		"difficulty":   {"data_type": "text", "not_null": true},
		"category":     {"data_type": "text", "not_null": true},
		"coin_reward":  {"data_type": "int",  "not_null": true},
		"grid_data":    {"data_type": "text", "not_null": true},   # JSON string
		"cached_at":    {"data_type": "int",  "not_null": true},   # Unix timestamp
	})

	# Completed levels — local record
	_db.create_table("completed_levels", {
		"level_id":      {"data_type": "int",  "primary_key": true},
		"completed_at":  {"data_type": "int",  "not_null": true},
		"time_taken_sec":{"data_type": "int",  "not_null": true},
		"coins_earned":  {"data_type": "int",  "not_null": true},
		"synced":        {"data_type": "int",  "not_null": true},   # 0 = pending sync
	})

	# Pending sync queue — offline events waiting for server confirmation
	_db.create_table("sync_queue", {
		"id":            {"data_type": "int",  "primary_key": true, "auto_increment": true},
		"event_type":    {"data_type": "text", "not_null": true},   # "level_complete", "ad_reward"
		"payload":       {"data_type": "text", "not_null": true},   # JSON string
		"created_at":    {"data_type": "int",  "not_null": true},
		"retry_count":   {"data_type": "int",  "not_null": true},
	})

	# Local settings / preferences
	_db.create_table("settings", {
		"key":   {"data_type": "text", "primary_key": true},
		"value": {"data_type": "text", "not_null": true},
	})

# ============================================================
# LEVEL CACHE
# ============================================================

func cache_levels(levels: Array) -> void:
	if _db == null:
		_cfg.set_value("cache", "levels", JSON.stringify(levels))
		_cfg.save("user://local_cache.cfg")
		return
	var now := int(Time.get_unix_time_from_system())
	for level in levels:
		var row := {
			"id":           int(level.get("id", 0)),
			"level_number": int(level.get("level_number", 0)),
			"difficulty":   str(level.get("difficulty", "easy")),
			"category":     str(level.get("category", "general")),
			"coin_reward":  int(level.get("coin_reward", 20)),
			"grid_data":    JSON.stringify(level.get("grid_data", {})),
			"cached_at":    now,
		}
		# Upsert
		_db.insert_row("levels", row)

func get_cached_level(level_number: int) -> Dictionary:
	if _db == null:
		var arr = _cfg.get_value("cache", "levels", [])
		if arr is Array:
			for l in arr:
				if l.get("level_number", 0) == level_number:
					return l
		return {}
	var rows : Array = _db.select_rows("levels",
		"level_number = %d" % level_number, ["*"])
	if rows.is_empty():
		return {}
	var row := rows[0]
	row["grid_data"] = JSON.parse_string(row["grid_data"])
	return row

func get_cached_level_count() -> int:
	if _db == null:
		return (_cfg.get_value("cache", "levels", []) as Array).size()
	var rows : Array = _db.select_rows("levels", "", ["COUNT(*) as cnt"])
	return int(rows[0].get("cnt", 0)) if not rows.is_empty() else 0

func is_cache_stale() -> bool:
	# Cache is stale if it's older than 24h or has fewer than 50 levels
	if _db == null:
		return get_cached_level_count() < 10
	var rows := _db.select_rows("levels", "", ["MIN(cached_at) as oldest"])
	if rows.is_empty() or rows[0].get("oldest") == null:
		return true
	var age := int(Time.get_unix_time_from_system()) - int(rows[0]["oldest"])
	return age > 86400 or get_cached_level_count() < 50

# ============================================================
# COMPLETED LEVELS
# ============================================================

func mark_level_completed(level_id: int, time_taken: int, coins: int, synced: bool) -> void:
	if _db == null:
		var completed = _cfg.get_value("progress", "completed", {})
		completed[str(level_id)] = {"coins": coins, "time": time_taken, "synced": synced}
		_cfg.set_value("progress", "completed", completed)
		_cfg.save("user://local_cache.cfg")
		return
	_db.insert_row("completed_levels", {
		"level_id":       level_id,
		"completed_at":   int(Time.get_unix_time_from_system()),
		"time_taken_sec": time_taken,
		"coins_earned":   coins,
		"synced":         1 if synced else 0,
	})

func is_level_completed(level_id: int) -> bool:
	if _db == null:
		return _cfg.get_value("progress", "completed", {}).has(str(level_id))
	var rows := _db.select_rows("completed_levels", "level_id = %d" % level_id, ["level_id"])
	return not rows.is_empty()

func get_highest_completed_level() -> int:
	if _db == null:
		var completed = _cfg.get_value("progress", "completed", {})
		var max_id := 0
		for k in completed.keys():
			max_id = max(max_id, int(k))
		return max_id
	var rows := _db.select_rows("completed_levels", "", ["MAX(level_id) as max_id"])
	return int(rows[0].get("max_id", 0)) if not rows.is_empty() else 0

# ============================================================
# OFFLINE SYNC QUEUE
# ============================================================

func enqueue_sync(event_type: String, payload: Dictionary) -> void:
	var row := {
		"event_type":  event_type,
		"payload":     JSON.stringify(payload),
		"created_at":  int(Time.get_unix_time_from_system()),
		"retry_count": 0,
	}
	if _db == null:
		var queue : Array = _cfg.get_value("sync", "queue", [])
		queue.append(row)
		_cfg.set_value("sync", "queue", queue)
		_cfg.save("user://local_cache.cfg")
	else:
		_db.insert_row("sync_queue", row)
	emit_signal("sync_queue_changed", get_pending_sync_count())

func get_pending_sync_items() -> Array:
	if _db == null:
		return _cfg.get_value("sync", "queue", [])
	return _db.select_rows("sync_queue", "retry_count < 5", ["*"])

func remove_sync_item(item_id: int) -> void:
	if _db == null:
		return   # ConfigFile fallback doesn't support indexed removal cleanly
	_db.delete_rows("sync_queue", "id = %d" % item_id)
	emit_signal("sync_queue_changed", get_pending_sync_count())

func increment_retry(item_id: int) -> void:
	if _db == null:
		return
	var rows := _db.select_rows("sync_queue", "id = %d" % item_id, ["retry_count"])
	if rows.is_empty():
		return
	var new_count := int(rows[0]["retry_count"]) + 1
	_db.update_rows("sync_queue", "id = %d" % item_id, {"retry_count": new_count})

func get_pending_sync_count() -> int:
	if _db == null:
		return (_cfg.get_value("sync", "queue", []) as Array).size()
	var rows := _db.select_rows("sync_queue", "retry_count < 5", ["COUNT(*) as cnt"])
	return int(rows[0].get("cnt", 0)) if not rows.is_empty() else 0

# ============================================================
# SETTINGS / PREFERENCES
# ============================================================

func get_setting(key: String, default_value: String = "") -> String:
	if _db == null:
		return _cfg.get_value("settings", key, default_value)
	var rows := _db.select_rows("settings", "key = '%s'" % key.replace("'","''"), ["value"])
	return str(rows[0]["value"]) if not rows.is_empty() else default_value

func save_setting(key: String, value: String) -> void:
	if _db == null:
		_cfg.set_value("settings", key, value)
		_cfg.save("user://local_cache.cfg")
		return
	_db.insert_row("settings", {"key": key, "value": value})
