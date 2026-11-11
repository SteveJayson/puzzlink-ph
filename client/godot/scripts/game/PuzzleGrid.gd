# ============================================================
# PuzzleGrid.gd  — attached to res://scenes/game/GameScene.tscn
# 
# Full word-search grid with:
#  - 8-directional word detection (H, V, diagonal)
#  - Touch drag selection with live visual feedback
#  - Persistent found-word highlights
#  - Invalid selection shake animation
#  - Configurable grid size (auto-fit to screen width)
# ============================================================
extends GridContainer

# --- Signals ---
signal word_found(word: String)
signal puzzle_solved(level_id: int, time_elapsed_sec: int)

# --- Colours ---
const COLOR_IDLE     := Color(1.00, 1.00, 1.00)   # white
const COLOR_DRAG     := Color(0.40, 0.75, 1.00)   # sky blue
const COLOR_FOUND    := Color(0.35, 0.85, 0.50)   # green
const COLOR_ERROR    := Color(1.00, 0.35, 0.35)   # red flash

# --- State ---
var _level_data     : Dictionary = {}
var _grid_letters   : Array      = []   # Array[Array[String]]  [row][col]
var _solution_words : Array      = []   # Array[String]
var _word_positions : Dictionary = {}   # word -> Array[Vector2i]
var _found_words    : Array      = []   # Array[String]
var _found_cells    : Array      = []   # Array[Vector2i] with confirmed highlights

var _selected_cells : Array      = []   # Array[Vector2i] current drag
var _is_dragging    : bool       = false
var _start_time     : float      = 0.0
var _rows           : int        = 0
var _cols           : int        = 0

const CELL_SCENE := preload("res://scenes/game/GridCell.tscn")

# ============================================================
# PUBLIC
# ============================================================

func load_level(level_data: Dictionary) -> void:
	_level_data     = level_data
	_found_words    = []
	_found_cells    = []
	_selected_cells = []
	_is_dragging    = false
	_start_time     = Time.get_unix_time_from_system()

	var grid_data : Dictionary = level_data.get("grid_data", level_data)
	_grid_letters   = grid_data.get("grid",  [])
	_solution_words = grid_data.get("words", [])

	_rows = _grid_letters.size()
	_cols = _grid_letters[0].size() if _rows > 0 else 0

	# Pre-compute word positions for all 8 directions
	_word_positions = _build_word_map()

	columns = _cols

	# Clear and rebuild cells
	for child in get_children():
		child.queue_free()

	for r in range(_rows):
		for c in range(_cols):
			var cell : Control = CELL_SCENE.instantiate()
			cell.set_meta("grid_pos", Vector2i(r, c))
			cell.get_node("Label").text = (_grid_letters[r][c] as String).to_upper()
			_set_cell_color(cell, COLOR_IDLE)
			# Connect input at the PuzzleGrid level using a touch-area overlay
			cell.gui_input.connect(_on_cell_input.bind(r, c))
			add_child(cell)

# ============================================================
# TOUCH INPUT
# ============================================================

func _on_cell_input(event: InputEvent, row: int, col: int) -> void:
	var pos := Vector2i(row, col)

	if event is InputEventScreenTouch:
		if event.pressed:
			_is_dragging    = true
			_selected_cells = [pos]
			_refresh_drag_highlight()
		elif _is_dragging:
			_is_dragging = false
			_check_selection()

	elif event is InputEventScreenDrag and _is_dragging:
		# Only append if it extends a valid straight line from start
		if not _selected_cells.has(pos) and _is_valid_extension(pos):
			_selected_cells.append(pos)
			_refresh_drag_highlight()

func _is_valid_extension(new_pos: Vector2i) -> bool:
	# A valid selection is always a straight line in one of 8 directions.
	# Enforce this from the start of the drag.
	if _selected_cells.size() < 2:
		return true   # any second cell is acceptable
	var origin    := _selected_cells[0]
	var second    := _selected_cells[1]
	var dir       := (second - origin).sign()
	var expected  := _selected_cells[-1] + dir
	return new_pos == expected

# ============================================================
# WORD CHECKING
# ============================================================

func _check_selection() -> void:
	if _selected_cells.size() < 2:
		_clear_drag_highlight()
		_selected_cells = []
		return

	var built_word := ""
	for pos in _selected_cells:
		built_word += _grid_letters[pos.x][pos.y]
	built_word = built_word.to_upper()

	var matched_word := ""

	# Check forward
	for w in _solution_words:
		if w.to_upper() == built_word and not _found_words.has(w):
			matched_word = w
			break

	# Check reverse
	if matched_word == "":
		var rev_word := ""
		for i in range(_selected_cells.size() - 1, -1, -1):
			rev_word += _grid_letters[_selected_cells[i].x][_selected_cells[i].y]
		rev_word = rev_word.to_upper()
		for w in _solution_words:
			if w.to_upper() == rev_word and not _found_words.has(w):
				matched_word = w
				break

	if matched_word != "":
		_found_words.append(matched_word)
		_found_cells.append_array(_selected_cells.duplicate())
		_persist_found_highlight(_selected_cells)
		_selected_cells = []
		emit_signal("word_found", matched_word)

		if _found_words.size() == _solution_words.size():
			var elapsed := int(Time.get_unix_time_from_system() - _start_time)
			emit_signal("puzzle_solved", int(_level_data.get("id", 0)), elapsed)
	else:
		# Flash red then clear
		_flash_error(_selected_cells.duplicate())
		_selected_cells = []

# ============================================================
# VISUAL HELPERS
# ============================================================

func _refresh_drag_highlight() -> void:
	for child in get_children():
		var pos : Vector2i = child.get_meta("grid_pos")
		if _found_cells.has(pos):
			_set_cell_color(child, COLOR_FOUND)
		elif _selected_cells.has(pos):
			_set_cell_color(child, COLOR_DRAG)
		else:
			_set_cell_color(child, COLOR_IDLE)

func _clear_drag_highlight() -> void:
	for child in get_children():
		var pos : Vector2i = child.get_meta("grid_pos")
		if _found_cells.has(pos):
			_set_cell_color(child, COLOR_FOUND)
		else:
			_set_cell_color(child, COLOR_IDLE)

func _persist_found_highlight(cells: Array) -> void:
	for child in get_children():
		var pos : Vector2i = child.get_meta("grid_pos")
		if cells.has(pos):
			_set_cell_color(child, COLOR_FOUND)

func _flash_error(cells: Array) -> void:
	# Flash cells red, then restore
	for child in get_children():
		var pos : Vector2i = child.get_meta("grid_pos")
		if cells.has(pos):
			_set_cell_color(child, COLOR_ERROR)
	await get_tree().create_timer(0.25).timeout
	for child in get_children():
		var pos : Vector2i = child.get_meta("grid_pos")
		if cells.has(pos):
			_set_cell_color(child, COLOR_IDLE)

func _set_cell_color(cell: Control, color: Color) -> void:
	var panel := cell.get_node_or_null("Panel")
	if panel:
		panel.self_modulate = color

# ============================================================
# WORD MAP  (pre-computed for instant lookup)
# ============================================================

func _build_word_map() -> Dictionary:
	var directions := [
		Vector2i(0, 1),   # right
		Vector2i(0, -1),  # left
		Vector2i(1, 0),   # down
		Vector2i(-1, 0),  # up
		Vector2i(1, 1),   # down-right
		Vector2i(1, -1),  # down-left
		Vector2i(-1, 1),  # up-right
		Vector2i(-1, -1), # up-left
	]
	var word_map := {}

	for r in range(_rows):
		for c in range(_cols):
			for dir in directions:
				for word in _solution_words:
					var uword : String = word.to_upper()
					var cells  : Array = []
					var valid  := true
					for i in range(uword.length()):
						var nr := r + dir.x * i
						var nc := c + dir.y * i
						if nr < 0 or nr >= _rows or nc < 0 or nc >= _cols:
							valid = false
							break
						if (_grid_letters[nr][nc] as String).to_upper() != str(uword[i]):
							valid = false
							break
						cells.append(Vector2i(nr, nc))
					if valid and cells.size() == uword.length():
						word_map[uword] = cells

	return word_map
