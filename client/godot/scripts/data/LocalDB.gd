# ============================================================
# LocalDB.gd  — Autoload Singleton
# ConfigFile-based local cache. No plugins needed.
# ============================================================
extends Node

const CACHE_PATH := "user://local_cache.cfg"

var _cfg : ConfigFile = ConfigFile.new()

signal sync_queue_changed(pending_count: int)

func _ready() -> void:
	_cfg.load(CACHE_PATH)

func _save() -> void:
	_cfg.save(CACHE_PATH)

func _get_cfg() -> ConfigFile:
	if _cfg == null:
		_cfg = ConfigFile.new()
		_cfg.load(CACHE_PATH)
	return _cfg

# ============================================================
# LEVEL CACHE
# ============================================================

func cache_levels(levels: Array) -> void:
	_get_cfg().set_value("cache", "levels", JSON.stringify(levels))
	_get_cfg().set_value("cache", "cached_at", int(Time.get_unix_time_from_system()))
	_save()

func get_cached_level(level_number: int) -> Dictionary:
	var raw : String = _get_cfg().get_value("cache", "levels", "[]")
	var arr = JSON.parse_string(raw)
	if arr is Array:
		for item in arr:
			if item is Dictionary:
				var d : Dictionary = item as Dictionary
				if int(d.get("level_number", -1)) == level_number:
					return d
	return {}

func get_cached_level_count() -> int:
	var raw : String = _get_cfg().get_value("cache", "levels", "[]")
	var arr = JSON.parse_string(raw)
	if arr is Array:
		return (arr as Array).size()
	return 0

func is_cache_stale() -> bool:
	var cached_at : int = int(_get_cfg().get_value("cache", "cached_at", 0))
	var age       : int = int(Time.get_unix_time_from_system()) - cached_at
	return age > 86400 or get_cached_level_count() < 10

# ============================================================
# COMPLETED LEVELS
# ============================================================

func mark_level_completed(level_id: int, time_taken: int, coins: int, synced: bool) -> void:
	var raw : String = _get_cfg().get_value("progress", "completed", "{}")
	var parsed = JSON.parse_string(raw)
	var d : Dictionary = {}
	if parsed is Dictionary:
		d = parsed as Dictionary
	d[str(level_id)] = {"coins": coins, "time": time_taken, "synced": synced}
	_get_cfg().set_value("progress", "completed", JSON.stringify(d))
	_save()

func is_level_completed(level_id: int) -> bool:
	var raw : String = _get_cfg().get_value("progress", "completed", "{}")
	var parsed = JSON.parse_string(raw)
	if parsed is Dictionary:
		var d : Dictionary = parsed as Dictionary
		return d.has(str(level_id))
	return false

func get_highest_completed_level() -> int:
	var raw : String = _get_cfg().get_value("progress", "completed", "{}")
	var parsed = JSON.parse_string(raw)
	if not (parsed is Dictionary):
		return 0
	var d : Dictionary = parsed as Dictionary
	var max_id : int = 0
	for k in d.keys():
		var n : int = int(str(k))
		if n > max_id:
			max_id = n
	return max_id

# ============================================================
# OFFLINE SYNC QUEUE
# ============================================================

func enqueue_sync(event_type: String, payload: Dictionary) -> void:
	var raw : String = _get_cfg().get_value("sync", "queue", "[]")
	var parsed = JSON.parse_string(raw)
	var q : Array = []
	if parsed is Array:
		q = parsed as Array
	q.append({
		"event_type":  event_type,
		"payload":     JSON.stringify(payload),
		"created_at":  int(Time.get_unix_time_from_system()),
		"retry_count": 0,
	})
	_get_cfg().set_value("sync", "queue", JSON.stringify(q))
	_save()
	emit_signal("sync_queue_changed", get_pending_sync_count())

func get_pending_sync_items() -> Array:
	var raw : String = _get_cfg().get_value("sync", "queue", "[]")
	var parsed = JSON.parse_string(raw)
	if parsed is Array:
		return parsed as Array
	return []

func remove_sync_item(item_index: int) -> void:
	var raw : String = _get_cfg().get_value("sync", "queue", "[]")
	var parsed = JSON.parse_string(raw)
	if not (parsed is Array):
		return
	var q : Array = parsed as Array
	if item_index >= 0 and item_index < q.size():
		q.remove_at(item_index)
	_get_cfg().set_value("sync", "queue", JSON.stringify(q))
	_save()
	emit_signal("sync_queue_changed", get_pending_sync_count())

func increment_retry(item_index: int) -> void:
	var raw : String = _get_cfg().get_value("sync", "queue", "[]")
	var parsed = JSON.parse_string(raw)
	if not (parsed is Array):
		return
	var q : Array = parsed as Array
	if item_index >= 0 and item_index < q.size():
		if q[item_index] is Dictionary:
			var item : Dictionary = q[item_index] as Dictionary
			item["retry_count"] = int(item.get("retry_count", 0)) + 1
			q[item_index] = item
	_get_cfg().set_value("sync", "queue", JSON.stringify(q))
	_save()

func get_pending_sync_count() -> int:
	var items : Array = get_pending_sync_items()
	var count : int = 0
	for item in items:
		if item is Dictionary:
			var d : Dictionary = item as Dictionary
			if int(d.get("retry_count", 0)) < 5:
				count += 1
	return count

# ============================================================
# SETTINGS
# ============================================================

func get_setting(key: String, default_value: String = "") -> String:
	return str(_get_cfg().get_value("settings", key, default_value))

func save_setting(key: String, value: String) -> void:
	_get_cfg().set_value("settings", key, value)
	_save()
