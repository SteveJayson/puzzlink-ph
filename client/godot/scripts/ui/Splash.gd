# ============================================================
# Splash.gd — First scene. Handles device registration.
# ============================================================
extends Control

@onready var label_status : Label  = $VBox/LabelStatus
@onready var btn_retry    : Button = $VBox/BtnRetry

func _ready() -> void:
	SessionManager.session_ready.connect(_on_session_ready)
	SessionManager.session_failed.connect(_on_session_failed)
	btn_retry.pressed.connect(_on_retry_pressed)
	label_status.text = "Connecting..."
	SessionManager.ensure_registered()

func _on_session_ready(is_new_user: bool) -> void:
	label_status.text = "Welcome!" if is_new_user else "Welcome back!"
	await get_tree().create_timer(0.8).timeout
	get_tree().change_scene_to_file("res://scenes/ui/MainMenu.tscn")

func _on_session_failed(error: String) -> void:
	label_status.text = "⚠ Connection failed.\nCheck your internet and restart."
	btn_retry.show()

func _on_retry_pressed() -> void:
	btn_retry.hide()
	label_status.text = "Retrying..."
	SessionManager.ensure_registered()
