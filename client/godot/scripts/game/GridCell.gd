# ============================================================
# GridCell.gd  — attached to each grid cell in the puzzle
# Lightweight: just holds position metadata and passes
# input up to PuzzleGrid.
# ============================================================
extends Control

# grid_pos is set by PuzzleGrid.load_level()
var grid_pos : Vector2i = Vector2i.ZERO

func _ready() -> void:
	mouse_filter = Control.MOUSE_FILTER_STOP
