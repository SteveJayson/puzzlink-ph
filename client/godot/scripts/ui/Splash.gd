# ============================================================
# Splash.gd  — attached to res://scenes/ui/Splash.tscn
# First scene loaded. Handles device registration, then
# transitions to the main menu.
# ============================================================
extends Control

@onready var label_status : Label = $LabelStatus
@onready var anim         : AnimationPlayer = $AnimationPlayer

func _ready() -> void:
	SessionManager.session_ready.connect(_on_session_ready)
	SessionManager.session_failed.connect(_on_session_failed)
	label_status.text = "Connecting..."
	SessionManager.ensure_registered()

func _on_session_ready(is_new_user: bool) -> void:
	label_status.text = "Welcome!" if is_new_user else "Welcome back!"
	await get_tree().create_timer(0.8).timeout
	get_tree().change_scene_to_file("res://scenes/ui/MainMenu.tscn")

func _on_session_failed(error: String) -> void:
	label_status.text = "⚠ Connection failed.\nCheck your internet and restart."
	# Show retry button
	$BtnRetry.show()

func _on_retry_pressed() -> void:
	$BtnRetry.hide()
	label_status.text = "Retrying..."
	SessionManager.ensure_registered()
