# ============================================================
# ToastNotification.gd  — Autoload Singleton (Toast)
# Prints toast messages to the Godot Output panel in editor.
# On device, prints to logcat. Visual overlay can be added later.
# Usage: Toast.show_msg("Your message")
# ============================================================
extends Node

static func show_msg(message: String, color: Color = Color.WHITE) -> void:
	# In editor: prints to Output panel
	# On Android: visible in adb logcat
	print("[Toast] ", message)
