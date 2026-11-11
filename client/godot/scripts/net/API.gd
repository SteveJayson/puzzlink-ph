# ============================================================
# API.gd  — Autoload Singleton
# All network calls to the PHP backend go through here.
# Never put a coin value or balance in a request body —
# the server calculates everything.
# ============================================================
extends Node

# --- Config ---
const BASE_URL := "https://puzzlink-ph.onrender.com/api"
const TIMEOUT  := 10.0  # seconds

# Emitted on every response so UI can react generically
signal request_failed(endpoint: String, error: String)

# ---- public helpers ----

func device_register(android_id: String, hardware_id: String,
					 device_model: String, os_version: String,
					 app_version: String, firebase_uid: String = "") -> Dictionary:
	var body := {
		"android_id":   android_id,
		"hardware_id":  hardware_id,
		"device_model": device_model,
		"os_version":   os_version,
		"app_version":  app_version,
	}
	if firebase_uid != "":
		body["firebase_uid"] = firebase_uid
	return await http_post("/device/register", body, false)

func get_balance() -> Dictionary:
	return await http_get("/balance")

func complete_level(level_id: int, time_taken_sec: int, ad_doubled: bool) -> Dictionary:
	return await http_post("/level/complete", {
		"level_id":       level_id,
		"time_taken_sec": time_taken_sec,
		"ad_doubled":     ad_doubled,
	})

func claim_ad_reward(ad_type: String, ad_network: String, impression_id: String) -> Dictionary:
	return await http_post("/ad/reward", {
		"ad_type":      ad_type,
		"ad_network":   ad_network,
		"impression_id": impression_id,
	})

func get_levels(page: int = 1, difficulty: String = "", category: String = "") -> Dictionary:
	var query := "?page=%d" % page
	if difficulty != "":
		query += "&difficulty=" + difficulty.uri_encode()
	if category != "":
		query += "&category=" + category.uri_encode()
	return await http_get("/levels" + query)

func request_payout(method: String, number: String, name: String) -> Dictionary:
	return await http_post("/payout/request", {
		"method": method,
		"number": number,
		"name":   name,
	})

func get_payout_status() -> Dictionary:
	return await http_get("/payout/status")

func validate_impression(impression_id: String, ad_type: String, ad_network: String) -> Dictionary:
	return await http_post("/ad/validate", {
		"impression_id": impression_id,
		"ad_type":       ad_type,
		"ad_network":    ad_network,
	})

## Expose URL builder for SyncManager connectivity probe
func _build_url(endpoint: String) -> String:
	return BASE_URL + endpoint

# ---- private transport ----

func http_get(endpoint: String, authenticated: bool = true) -> Dictionary:
	return await http_send("GET", endpoint, {}, authenticated)

func http_post(endpoint: String, body: Dictionary, authenticated: bool = true) -> Dictionary:
	return await http_send("POST", endpoint, body, authenticated)

func http_send(method: String, endpoint: String, body: Dictionary, authenticated: bool) -> Dictionary:
	var http := HTTPRequest.new()
	http.timeout = TIMEOUT
	add_child(http)

	var headers := ["Content-Type: application/json"]
	if authenticated:
		var token := SessionManager.get_token()
		if token == "":
			http.queue_free()
			emit_signal("request_failed", endpoint, "Not authenticated")
			return {"success": false, "error": "Not authenticated"}
		headers.append("Authorization: Bearer " + token)

	var json_body := JSON.stringify(body) if method == "POST" else ""
	var err := http.request(BASE_URL + endpoint,
							headers,
							HTTPClient.METHOD_GET if method == "GET" else HTTPClient.METHOD_POST,
							json_body)
	if err != OK:
		http.queue_free()
		emit_signal("request_failed", endpoint, "HTTPRequest error: %d" % err)
		return {"success": false, "error": "Network error"}

	var result : Array = await http.request_completed
	http.queue_free()

	# result = [result_code, response_code, headers, body_bytes]
	var response_code : int    = result[1]
	var body_bytes    : PackedByteArray = result[3]
	var text          : String = body_bytes.get_string_from_utf8()

	var parsed = JSON.parse_string(text)
	if parsed == null:
		emit_signal("request_failed", endpoint, "Invalid JSON response")
		return {"success": false, "error": "Invalid server response"}

	if response_code >= 400:
		var error_msg : String = parsed.get("error", "Unknown error")
		emit_signal("request_failed", endpoint, error_msg)

	return parsed
