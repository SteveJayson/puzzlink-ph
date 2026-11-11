# ============================================================
# CoinCounter.gd  — reusable component
# Animated coin counter that rolls up to a new value
# instead of jumping. Attach to a Label node.
#
# Usage in a parent script:
#   $CoinCounter.animate_to(new_coin_value)
# ============================================================
extends Label

var _target_value  : int   = 0
var _display_value : float = 0.0
var _animating     : bool  = false

const ROLL_SPEED := 800.0   # coins per second

func _ready() -> void:
	text = "🪙 0"

func _process(delta: float) -> void:
	if not _animating:
		return
	var diff := _target_value - _display_value
	if abs(diff) < 1.0:
		_display_value = _target_value
		_animating     = false
	else:
		# Accelerate toward target
		_display_value += diff * delta * 5.0
	text = "🪙 %d" % int(_display_value)

func set_value(value: int) -> void:
	_target_value  = value
	_display_value = float(value)
	_animating     = false
	text           = "🪙 %d" % value

func animate_to(new_value: int) -> void:
	_target_value = new_value
	_animating    = true
