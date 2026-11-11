# ============================================================
# OfferwallScreen.gd  — the "Earn" tab in MainMenu
#
# Hosts a WebView panel showing the offerwall iframe/webview
# from Tapjoy / Adjoe / IronSource, plus a list of
# featured high-value tasks shown natively for context.
#
# On Android: use the Godot WebView plugin
#   https://github.com/kidovate/godot-webview
#   OR open the offer URL in the system browser and
#   rely on server-side S2S postback for coin crediting.
# ============================================================
extends Control

@onready var webview_container : Control = $VBox/WebViewContainer
@onready var fallback_list     : VBoxContainer = $VBox/FallbackList
@onready var label_pending     : Label   = $VBox/LabelPending
@onready var btn_refresh       : Button  = $VBox/BtnRefresh
@onready var label_earn_tip    : Label   = $VBox/LabelTip

# Offerwall URL — replace with your actual network's URL
# Most networks provide a URL like:
# https://offers.tapjoy.com/api/v2/pubs/<pub_id>/offerwall?user_id={UUID}
const OFFERWALL_URL_TEMPLATE := "https://offers.example.com/offerwall?user_id={uuid}&platform=android"

# High-value tasks shown natively when WebView not available
const FEATURED_TASKS := [
	{"title": "Install & Open any game",         "reward": "₱3.00",   "type": "app_install"},
	{"title": "Complete a short survey",          "reward": "₱5.00–₱20.00", "type": "survey"},
	{"title": "Sign up for a free service",       "reward": "₱8.00",   "type": "signup"},
	{"title": "Watch 5 video ads",               "reward": "₱0.50",   "type": "video_series"},
	{"title": "Play a sponsored game to level 5","reward": "₱15.00",  "type": "game_promo"},
]

func _ready() -> void:
	btn_refresh.pressed.connect(_on_refresh_pressed)
	GameState.balance_updated.connect(_refresh_pending_label)
	_load_offerwall()
	_build_featured_list()
	_refresh_pending_label()

	label_earn_tip.text = "💡 Tip: Completing offerwall tasks is the fastest way to reach your cashout goal!"

func _load_offerwall() -> void:
	var uuid := SessionManager.get_uuid()
	var url  := OFFERWALL_URL_TEMPLATE.replace("{uuid}", uuid)

	# Attempt WebView plugin
	if Engine.has_singleton("WebView"):
		fallback_list.hide()
		var wv = Engine.get_singleton("WebView")
		wv.loadUrl(url)
		# TODO: Attach wv to webview_container node using GDExtension plugin API
	else:
		# WebView plugin not installed — show native fallback
		webview_container.hide()
		fallback_list.show()

func _build_featured_list() -> void:
	for child in fallback_list.get_children():
		child.queue_free()

	for task in FEATURED_TASKS:
		var row := HBoxContainer.new()

		var lbl_title := Label.new()
		lbl_title.text = task["title"]
		lbl_title.size_flags_horizontal = Control.SIZE_EXPAND_FILL
		lbl_title.add_theme_font_size_override("font_size", 13)

		var lbl_reward := Label.new()
		lbl_reward.text = task["reward"]
		lbl_reward.add_theme_color_override("font_color", Color(0.2, 0.75, 0.3))
		lbl_reward.add_theme_font_size_override("font_size", 13)

		row.add_child(lbl_title)
		row.add_child(lbl_reward)
		fallback_list.add_child(row)

		# Separator
		var sep := HSeparator.new()
		fallback_list.add_child(sep)

func _refresh_pending_label() -> void:
	var pending := LocalDB.get_pending_sync_count()
	if pending > 0:
		label_pending.text    = "⏳ %d reward(s) pending sync" % pending
		label_pending.visible = true
	else:
		label_pending.visible = false

func _on_refresh_pressed() -> void:
	_load_offerwall()
	GameState.refresh_balance()
