# ============================================================
# SessionManager.gd  — Autoload Singleton
# Handles device registration on first launch, JWT storage,
# and local secure preferences.
# ============================================================
extends Node

const PREF_TOKEN := "auth_token"
const PREF_UUID  := "user_uuid"

var _token : String = ""
var _uuid  : String = ""

signal session_ready(is_new_user: bool)
signal session_failed(error: String)

# ---- lifecycle ----

func _ready() -> void:
	_load_stored_session()

func _load_stored_session() -> void:
	# Load from encrypted config — Godot's ConfigFile with an encryption key
	# is the lightweight substitute for Android EncryptedSharedPreferences.
	var cfg := ConfigFile.new()
	var path := "user://session.cfg"
	var key  := _device_key()

	if cfg.load_encrypted_pass(path, key) == OK:
		_token = cfg.get_value("auth", PREF_TOKEN, "")
		_uuid  = cfg.get_value("auth", PREF_UUID,  "")

func save_session(token: String, uuid: String) -> void:
	_token = token
	_uuid  = uuid
	var cfg  := ConfigFile.new()
	var path := "user://session.cfg"
	var key  := _device_key()
	cfg.set_value("auth", PREF_TOKEN, token)
	cfg.set_value("auth", PREF_UUID,  uuid)
	cfg.save_encrypted_pass(path, key)

func get_token() -> String:
	return _token

func get_uuid() -> String:
	return _uuid

func is_authenticated() -> bool:
	return _token != ""

func clear_session() -> void:
	_token = ""
	_uuid  = ""
	var path := "user://session.cfg"
	if FileAccess.file_exists(path):
		DirAccess.remove_absolute(path)

# ---- device registration (called from Splash scene) ----

func ensure_registered() -> void:
	if is_authenticated():
		emit_signal("session_ready", false)
		return

	# Collect device info via Android JNI calls
	var android_id   : String = _get_android_id()
	var device_model : String = OS.get_model_name()
	var os_version   : String = str(Engine.get_version_info().get("major", 0))
	var app_version  : String = ProjectSettings.get_setting("application/config/version", "1.0.0")

	var result : Dictionary = await API.device_register(
		android_id, "", device_model, os_version, app_version
	)

	if result.get("success", false):
		var data : Dictionary = result.get("data", {})
		save_session(data.get("token", ""), data.get("uuid", ""))
		emit_signal("session_ready", data.get("is_new_user", false))
	else:
		emit_signal("session_failed", result.get("error", "Registration failed"))

# ---- helpers ----

func _get_android_id() -> String:
	# On Android: use JNI to call Settings.Secure.getString(context, "android_id")
	# Falls back to a generated stable ID on other platforms (for testing in editor)
	if OS.get_name() == "Android":
		# GDNative/GDExtension plugin required for real Android ID access.
		# Placeholder — replace with your android plugin call:
		# return AndroidRuntime.call("getAndroidId")
		return OS.get_unique_id()
	# Editor / PC testing fallback
	return OS.get_unique_id()

func _device_key() -> String:
	# Derives an encryption key from hardware identifiers.
	# Not cryptographically robust but prevents casual file inspection.
	return ("puzzlink_" + OS.get_unique_id()).sha256_text().substr(0, 32)
