# ============================================================
# ToastNotification.gd
# Android-style slide-up toast. Add as a CanvasLayer child
# so it always appears on top.
#
# Usage from any scene:
#   Toast.show("₱50.00 cashout submitted! 🎉")
#   Toast.show("No internet — syncing later", Color(0.9, 0.5, 0.1))
# ============================================================
extends CanvasLayer

@onready var panel : PanelContainer = $PanelContainer
@onready var label : Label          = $PanelContainer/Label

var _queue      : Array  = []
var _showing    : bool   = false

const SHOW_DURATION := 2.5   # seconds visible
const SLIDE_TIME    := 0.25

func _ready() -> void:
	panel.modulate.a = 0.0

## Static-style convenience access — call Toast.show() from anywhere
static func show_msg(message: String, color: Color = Color.WHITE) -> void:
	# Traverse up to the autoload instance
	var root := Engine.get_main_loop().root
	var toast = root.get_node_or_null("/root/Toast")
	if toast:
		toast._enqueue(message, color)

func _enqueue(message: String, color: Color) -> void:
	_queue.append({"msg": message, "color": color})
	if not _showing:
		_show_next()

func _show_next() -> void:
	if _queue.is_empty():
		_showing = false
		return
	_showing = true
	var item    : Dictionary = _queue.pop_front()
	label.text  = item["msg"]
	label.add_theme_color_override("font_color", item["color"])

	# Slide up
	var tween := create_tween()
	tween.tween_property(panel, "modulate:a", 1.0, SLIDE_TIME)
	await tween.finished
	await get_tree().create_timer(SHOW_DURATION).timeout

	# Fade out
	var tween2 := create_tween()
	tween2.tween_property(panel, "modulate:a", 0.0, SLIDE_TIME)
	await tween2.finished

	_show_next()
