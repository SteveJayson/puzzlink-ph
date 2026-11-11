# ============================================================
# SyncManager.gd  — Autoload Singleton
# Monitors network connectivity and drains the offline
# sync queue whenever a connection is available.
#
# Flow:
#   Offline puzzle solved
#     → GameState calls SyncManager.queue_level_complete()
#     → Stored in LocalDB sync_queue
#   Connection restored
#     → SyncManager._poll_connectivity() fires
#     → Drains queue in order, retrying up to 5 times
#     → Confirmed items update GameState balance
# ============================================================
extends Node

const POLL_INTERVAL_SEC := 15.0   # check connectivity every N seconds
const MAX_RETRIES       := 5

var _is_online      : bool = false
var _polling_timer  : float = 0.0
var _is_syncing     : bool = false

signal connectivity_changed(is_online: bool)
signal sync_started
signal sync_completed(synced_count: int, failed_count: int)

# ---- lifecycle ----

func _ready() -> void:
	LocalDB.sync_queue_changed.connect(_on_queue_changed)
	_check_connectivity()

func _process(delta: float) -> void:
	_polling_timer += delta
	if _polling_timer >= POLL_INTERVAL_SEC:
		_polling_timer = 0.0
		_check_connectivity()

# ---- public ----

## Called by GameState when a level is solved offline
func queue_level_complete(level_id: int, time_taken: int) -> void:
	LocalDB.enqueue_sync("level_complete", {
		"level_id":       level_id,
		"time_taken_sec": time_taken,
		"ad_doubled":     false,
		"queued_at":      int(Time.get_unix_time_from_system()),
	})
	if _is_online and not _is_syncing:
		drain_queue()

## Called by GameState when an ad reward is earned offline (rare but possible)
func queue_ad_reward(ad_type: String, ad_network: String, impression_id: String) -> void:
	LocalDB.enqueue_sync("ad_reward", {
		"ad_type":       ad_type,
		"ad_network":    ad_network,
		"impression_id": impression_id,
	})

## Force a sync attempt (e.g., user pulls to refresh)
func drain_queue() -> void:
	if _is_syncing:
		return
	var items : Array = LocalDB.get_pending_sync_items()
	if items.is_empty():
		return

	_is_syncing = true
	emit_signal("sync_started")

	var synced := 0
	var failed := 0

	for item in items:
		var event_type : String     = str(item.get("event_type", ""))
		var payload    : Dictionary = JSON.parse_string(str(item.get("payload", "{}"))) or {}
		var item_id    : int        = int(item.get("id", 0))

		var success := await _dispatch(event_type, payload)

		if success:
			LocalDB.remove_sync_item(item_id)
			synced += 1
		else:
			LocalDB.increment_retry(item_id)
			failed += 1

	_is_syncing = false
	emit_signal("sync_completed", synced, failed)

	# Refresh balance after a successful drain
	if synced > 0:
		GameState.refresh_balance()

# ---- private ----

func _check_connectivity() -> void:
	# Godot 4 doesn't have a native connectivity API; we probe with a lightweight
	# HEAD request to our server. On Android, OS.get_name() == "Android" and
	# the system handles permission automatically for INTERNET.
	var http := HTTPRequest.new()
	http.timeout = 5.0
	add_child(http)
	var err := http.request(
		API._build_url("/balance"),   # see API.gd helper below
		["Authorization: Bearer " + SessionManager.get_token()],
		HTTPClient.METHOD_HEAD, ""
	)
	if err != OK:
		http.queue_free()
		_set_online(false)
		return

	var result : Array = await http.request_completed
	http.queue_free()

	var code : int = result[1]
	# 200/401 both mean server is reachable; 0/negative means no connection
	_set_online(code > 0)

func _set_online(online: bool) -> void:
	if online == _is_online:
		return
	_is_online = online
	emit_signal("connectivity_changed", online)
	if online and not _is_syncing:
		drain_queue()

func _dispatch(event_type: String, payload: Dictionary) -> bool:
	match event_type:
		"level_complete":
			var result := await API.complete_level(
				int(payload.get("level_id", 0)),
				int(payload.get("time_taken_sec", 0)),
				bool(payload.get("ad_doubled", false))
			)
			return result.get("success", false)

		"ad_reward":
			var result := await API.claim_ad_reward(
				str(payload.get("ad_type", "")),
				str(payload.get("ad_network", "")),
				str(payload.get("impression_id", ""))
			)
			# 409 (already credited) is also a success for sync purposes
			return result.get("success", false) or \
			       result.get("error", "") == "Impression already credited"
	return false

func _on_queue_changed(pending_count: int) -> void:
	# Optionally show a subtle "pending sync" indicator in UI
	pass

func is_online() -> bool:
	return _is_online

# ---- Toast feedback (called after drain_queue completes) ----
# Connected in _ready after Toast autoload is available
func _notify_sync_result(synced: int, failed: int) -> void:
	if synced > 0:
		ToastNotification.show_msg("✓ %d offline reward(s) synced!" % synced, Color(0.2, 0.9, 0.4))
	if failed > 0:
		ToastNotification.show_msg("⚠ %d reward(s) failed to sync. Will retry." % failed, Color(1.0, 0.6, 0.1))
