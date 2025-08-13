import os
import sys
import time
import json
import csv
import random
import logging
from io import StringIO
from datetime import datetime
from typing import Dict, List

import requests
from flask import Flask, request, jsonify

# ======== ЛОГИ ========
logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(message)s"
)
log = logging.getLogger("batumi-realestate-bot")

# ======== ПЕРЕМЕННЫЕ ОКРУЖЕНИЯ ========
TELEGRAM_TOKEN = os.getenv("TELEGRAM_TOKEN")     # задаётся в Render → Environment
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY")     # задаётся в Render → Environment
LEADS_CHAT_ID = os.getenv("LEADS_CHAT_ID", "-1002536751047")   # можно переопределить
CHANNEL_USERNAME = os.getenv("CHANNEL_USERNAME", "@smkornaukhovv")
TELEGRAM_API_URL = f"https://api.telegram.org/bot{TELEGRAM_TOKEN}"

# Жёсткая проверка секретов
missing = [name for name, val in [
    ("TELEGRAM_TOKEN", TELEGRAM_TOKEN),
    ("OPENAI_API_KEY", OPENAI_API_KEY),
] if not val]
if missing:
    log.critical(f"Не заданы переменные окружения: {', '.join(missing)}")
    sys.exit(1)

# ======== OpenAI (новый SDK 1.x) ========
# Требует openai>=1.0 в requirements
try:
    from openai import OpenAI
    _client = OpenAI(api_key=OPENAI_API_KEY)
    def ask_gpt(messages: List[Dict]) -> str:
        try:
            resp = _client.chat.completions.create(
                model="gpt-4o",              # можно заменить на gpt-4o-mini для экономии
                messages=messages,
                max_tokens=400,
                temperature=0.5,
            )
            return resp.choices[0].message.content
        except Exception as e:
            log.error(f"OpenAI API error: {e}")
            return "Извините, возникла техническая проблема. Попробуйте позже."
except Exception as e:
    # Фолбэк: если по какой-то причине openai 1.x не установился
    log.critical(f"Не удалось инициализировать OpenAI SDK: {e}")
    def ask_gpt(messages: List[Dict]) -> str:
        return "AI-возможности временно недоступны. Свяжитесь со мной @smkornaukhovv."

# ======== Flask ========
app = Flask(__name__)

# ======== ПАМЯТЬ (в RAM, сбрасывается при рестарте) ========
chat_histories: Dict[str, List[Dict]] = {}
user_states: Dict[str, Dict] = {}
apartments_cache: List[Dict] = []
cache_time = 0.0
subscription_checks: Dict[str, float] = {}

# ======== ДАННЫЕ ИЗ GOOGLE SHEETS ========
SHEETS_URLS = [
    "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?output=csv",
    "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPз36HHм6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?gid=0&single=true&output=csv"
]

def get_apartments_from_sheets() -> List[Dict]:
    """Кешируем на 15 минут, устойчивый CSV-парсинг."""
    global apartments_cache, cache_time
    if apartments_cache and (time.time() - cache_time) < 900:
        return apartments_cache

    for url in SHEETS_URLS:
        try:
            time.sleep(random.uniform(0.1, 0.4))
            r = requests.get(url, timeout=20, headers={
                "User-Agent": "Mozilla/5.0 (compatible; PropertyBot/1.0)"
            })
            if r.status_code == 200 and len(r.text) > 100:
                apartments = parse_csv_to_apartments(r.text)
                if apartments:
                    apartments_cache = apartments
                    cache_time = time.time()
                    log.info(f"Загружено {len(apartments)} квартир из Google Sheets")
                    return apartments
        except Exception as e:
            log.warning(f"Sheets fetch fail ({url}): {e}")

    log.warning("Не удалось получить данные из Sheets — используем тестовые квартиры.")
    apartments_cache = get_test_apartments()
    cache_time = time.time()
    return apartments_cache

def parse_csv_to_apartments(csv_text: str) -> List[Dict]:
    apartments: List[Dict] = []
    reader = csv.reader(StringIO(csv_text))
    next(reader, None)  # заголовок

    for row in reader:
        try:
            if len(row) < 7:
                continue
            def num(x: str) -> float:
                return float(x.replace("$", "").replace(",", "").strip()) if x else 0.0
            def intnum(x: str) -> int:
                x = x.strip()
                return int(x) if x.isdigit() else 0

            apt = {
                "этаж": intnum(row[0]),
                "номер": intnum(row[1]),
                "площадь": num(row[2]),
                "вид": row[3].strip(),
                "цена_м2": num(row[4]),
                "общая_сумма": num(row[5]),
                "жк": row[6].strip(),
                "статус": "Свободный",
            }
            if apt["номер"] > 0 and apt["площадь"] > 0:
                apartments.append(apt)
        except Exception as e:
            log.error(f"Ошибка парсинга строки {row}: {e}")
    return apartments

def get_test_apartments() -> List[Dict]:
    return [
        {'этаж': 5,  'номер': 319, 'площадь': 35.5, 'вид': 'Море',  'цена_м2': 1520, 'общая_сумма': 54080, 'жк': 'Thalassa Group', 'статус': 'Свободный'},
        {'этаж': 8,  'номер': 412, 'площадь': 29.1, 'вид': 'Город', 'цена_м2': 1520, 'общая_сумма': 44264, 'жк': 'Thalassa Group', 'статус': 'Свободный'},
        {'этаж': 12, 'номер': 514, 'площадь': 21.6, 'вид': 'Море',  'цена_м2': 1520, 'общая_сумма': 32832, 'жк': 'Thalassa Group', 'статус': 'Свободный'},
    ]

# ======== TELEGRAM ========
def send_telegram_message(chat_id: str, text: str, reply_markup=None):
    data = {
        "chat_id": chat_id,
        "text": text,
        "parse_mode": "HTML",
        "disable_web_page_preview": True,
    }
    if reply_markup:
        data["reply_markup"] = json.dumps(reply_markup)
    try:
        r = requests.post(f"{TELEGRAM_API_URL}/sendMessage", data=data, timeout=20)
        return r.json()
    except Exception as e:
        log.error(f"sendMessage error: {e}")

def check_subscription(user_id: int) -> bool:
    try:
        r = requests.get(
            f"{TELEGRAM_API_URL}/getChatMember",
            params={"chat_id": CHANNEL_USERNAME, "user_id": user_id},
            timeout=20
        )
        if r.status_code == 200:
            status = r.json().get("result", {}).get("status", "")
            return status in ["member", "administrator", "creator"]
    except Exception as e:
        log.warning(f"check_subscription error: {e}")
    return True  # при ошибке не блокируем пользователя

# ======== БИЗНЕС-ЛОГИКА ========
BOOKING_TRIGGERS = [
    "хочу посмотреть","запись на показ","записаться",
    "онлайн показ","встреча","назначить показ",
    "посмотреть квартиру","хочу на показ","покажи варианты",
]

def process_message(update: Dict):
    try:
        message = update.get("message", {}) or update.get("edited_message", {})
        chat_id = str(message.get("chat", {}).get("id", ""))
        user_id = message.get("from", {}).get("id", 0)
        username = message.get("from", {}).get("username", "нет_username")
        first_name = message.get("from", {}).get("first_name", "друг")
        text = (message.get("text") or "").strip()

        if not chat_id or not text:
            return

        log.info(f"msg from {first_name}({user_id}): {text}")

        # проверка подписки раз в 60 сек / пользователя
        now = time.time()
        last = subscription_checks.get(chat_id, 0)
        if now - last > 60:
            if not check_subscription(user_id):
                subscription_checks[chat_id] = now
                send_telegram_message(chat_id, "Для продолжения подпишись на канал 👉 @smkornaukhovv, затем нажми /start")
                return

        # /start
        if text.lower() == "/start":
            send_telegram_message(
                chat_id,
                f"Добрый день, {first_name}! Я помогу подобрать квартиру в Батуми.\n"
                f"Скажите, вы ищете квартиру для проживания или для сдачи?"
            )
            return

        # сценарий записи на показ
        state = user_states.get(chat_id, {"state": "normal", "data": {}})

        if state["state"] == "booking_time":
            state["data"]["time"] = text
            state["state"] = "booking_phone"
            user_states[chat_id] = state
            keyboard = {
                "keyboard": [[{"text": "Поделиться телефоном (WA)", "request_contact": True}]],
                "resize_keyboard": True,
                "one_time_keyboard": True
            }
            send_telegram_message(chat_id, "Спасибо! Теперь отправьте номер WhatsApp (+995… или нажмите кнопку).", reply_markup=keyboard)
            return

        if state["state"] == "booking_phone":
            contact = message.get("contact", {})
            phone = contact.get("phone_number") or text
            digits = "".join(ch for ch in phone if ch.isdigit())
            if len(digits) < 9:
                keyboard = {
                    "keyboard": [[{"text": "Поделиться телефоном (WA)", "request_contact": True}]],
                    "resize_keyboard": True,
                    "one_time_keyboard": True
                }
                send_telegram_message(chat_id, "Не похоже на номер. Пришлите формат +9955… или нажмите кнопку.", reply_markup=keyboard)
                return

            wa_link = f"https://wa.me/{digits}"
            tg_link = f"tg://user?id={user_id}"
            lead_text = (
                "🏠 <b>Новая запись на показ</b>\n\n"
                f"👤 Клиент: @{username} (ID: <code>{user_id}</code>)\n"
                f"📅 Время: <b>{state['data'].get('time','не указано')}</b>\n"
                f"📱 Телефон (WA): <b>{phone}</b>\n"
                f'🔗 Связь: <a href="{tg_link}">TG</a> | <a href="{wa_link}">WA</a>'
            )
            send_telegram_message(LEADS_CHAT_ID, lead_text)
            user_states[chat_id] = {"state": "normal", "data": {}}
            send_telegram_message(chat_id, "Готово! Я записал вас на показ ✅", reply_markup={"remove_keyboard": True})
            return

        # триггеры записи
        if any(t in text.lower() for t in BOOKING_TRIGGERS):
            user_states[chat_id] = {"state": "booking_time", "data": {}}
            send_telegram_message(
                chat_id,
                "Отлично! <u>Шаг 1 из 2</u> — напишите удобные <b>дату и время (Тбилиси)</b>.\n"
                "Например: «13 августа, 15:00»"
            )
            return

        # GPT-ответ с инвентарём
        apartments = get_apartments_from_sheets()
        studios = [a for a in apartments if a["площадь"] <= 40]
        studio_min = min((a["общая_сумма"] for a in studios), default=0)
        studio_max = max((a["общая_сумма"] for a in studios), default=0)
        base_stats = f"В базе {len(apartments)} квартир, студий — {len(studios)}, цены: ${studio_min}-{studio_max}"

        base_info = "\n".join(
            f"ЖК: {a['жк']}, Этаж: {a['этаж']}, №: {a['номер']}, Площадь: {a['площадь']} м², Вид: {a['вид']}, Цена: ${a['общая_сумма']}"
            for a in apartments[:20]
        )

        system_prompt = f"""Ты — AI-брокер по недвижимости в Батуми. Общайся просто и по делу.

Правила:
- Один вопрос за сообщение
- Не здоровайся повторно
- После 2-3 уточнений предложи 1-2 варианта
- Если бюджет мал — предложи решение (рассрочка, меньше метраж)
- В конце варианта добавь: "Показать? Напишите: запись на показ"

Имя клиента: {first_name}
Статистика: {base_stats}

База квартир:
{base_info}"""

        history = chat_histories.get(chat_id, [])
        messages = [{"role": "system", "content": system_prompt}] + history[-6:] + [{"role": "user", "content": text}]
        answer = ask_gpt(messages)

        history.extend([{"role": "user", "content": text}, {"role": "assistant", "content": answer}])
        chat_histories[chat_id] = history[-10:]

        send_telegram_message(chat_id, answer)

    except Exception as e:
        log.error(f"process_message error: {e}")

# ======== ROUTES ========
@app.route("/")
def health():
    return f"""
    <h1>Telegram Bot Status</h1>
    <p>OK — {datetime.utcnow()} UTC</p>
    <p>TELEGRAM_TOKEN: ✅ Set</p>
    <p>OPENAI_API_KEY: ✅ Set</p>
    """

@app.route("/webhook", methods=["POST"])
def webhook():
    try:
        update = request.get_json(silent=True) or {}
        log.info(f"update: {update}")
        process_message(update)
        return jsonify({"status": "ok"})
    except Exception as e:
        log.error(f"webhook error: {e}")
        return jsonify({"status": "error"}), 500

@app.route("/set_webhook")
def set_webhook():
    """
    Лучше задать TELEGRAM_WEBHOOK_URL в окружении (например, https://<you>.onrender.com/webhook)
    чтобы не зависеть от заголовков Host.
    """
    webhook_url = os.getenv("TELEGRAM_WEBHOOK_URL")
    if not webhook_url:
        # запасной вариант — строим из Host
        host = request.headers.get("Host", "")
        scheme = "https"
        webhook_url = f"{scheme}://{host}/webhook"
    try:
        r = requests.post(f"{TELEGRAM_API_URL}/setWebhook", data={"url": webhook_url}, timeout=20)
        return jsonify(r.json())
    except Exception as e:
        return jsonify({"error": str(e)}), 500

if __name__ == "__main__":
    port = int(os.getenv("PORT", "10000"))
    app.run(host="0.0.0.0", port=port)
