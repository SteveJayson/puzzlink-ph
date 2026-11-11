# ============================================================
# WalletScreen.gd
# Shows balance, cashout progress bar, and handles payout form.
# ============================================================
extends Control

@onready var label_coins     : Label      = $VBox/LabelCoins
@onready var label_php       : Label      = $VBox/LabelPhp
@onready var progress_bar    : ProgressBar= $VBox/ProgressBar
@onready var label_progress  : Label      = $VBox/LabelProgress
@onready var btn_cashout     : Button     = $VBox/BtnCashout
@onready var payout_form     : Control   = $PayoutForm
@onready var input_method    : OptionButton = $PayoutForm/InputMethod
@onready var input_number    : LineEdit   = $PayoutForm/InputNumber
@onready var input_name      : LineEdit   = $PayoutForm/InputName
@onready var btn_submit      : Button     = $PayoutForm/BtnSubmit
@onready var label_status    : Label      = $PayoutForm/LabelStatus

func _ready() -> void:
	payout_form.hide()
	GameState.balance_updated.connect(_refresh_ui)
	btn_cashout.pressed.connect(_on_cashout_pressed)
	btn_submit.pressed.connect(_on_submit_pressed)
	# Populate method options
	input_method.add_item("GCash", 0)
	input_method.add_item("Maya",  1)
	GameState.refresh_balance()

func _refresh_ui() -> void:
	label_coins.text    = "🪙 %d coins" % GameState.coins
	label_php.text      = "₱%.2f" % GameState.php_balance
	btn_cashout.disabled = not GameState.can_cashout

	var threshold : float = GameState.threshold_php
	var balance   : float = GameState.php_balance
	var pct       : float = clampf(balance / threshold, 0.0, 1.0)
	progress_bar.value  = pct * 100.0

	if GameState.can_cashout:
		label_progress.text = "Ready to cash out ✓"
	else:
		label_progress.text = "₱%.2f more to reach ₱%.2f cashout" % [
			GameState.remaining_php, threshold
		]

func _on_cashout_pressed() -> void:
	payout_form.show()
	label_status.text = ""

func _on_submit_pressed() -> void:
	var method  : String = "gcash" if input_method.selected == 0 else "maya"
	var number  : String = input_number.text.strip_edges()
	var name_str: String = input_name.text.strip_edges()

	if number.length() != 11 or not number.begins_with("09"):
		label_status.text = "⚠ Enter a valid 09XXXXXXXXX number"
		return
	if name_str.length() < 2:
		label_status.text = "⚠ Enter your account name"
		return

	btn_submit.disabled = true
	label_status.text   = "Submitting..."

	var result : Dictionary = await API.request_payout(method, number, name_str)
	btn_submit.disabled = false

	if result.get("success", false):
		var data := result.get("data", {})
		label_status.text = data.get("message", "Payout submitted!")
		payout_form.hide()
		GameState.refresh_balance()
	else:
		label_status.text = "❌ " + result.get("error", "Failed to submit")
