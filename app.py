import os
import logging
import json
import requests
from datetime import datetime
from flask import Flask, request, jsonify
import openai
from typing import Dict, List, Optional
import time
import random

# Настройка логирования
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

app = Flask(__name__)

# Получаем переменные окружения
TELEGRAM_TOKEN = os.environ.get('TELEGRAM_TOKEN')
OPENAI_API_KEY = os.environ.get('OPENAI_API_KEY')
ADMIN_CHAT_ID = "7770604629"
LEADS_CHAT_ID = "-1002536751047"
CHANNEL_USERNAME = "@smkornaukhovv"

# Проверка переменных
if not TELEGRAM_TOKEN:
    logger.error("TELEGRAM_TOKEN not set!")
if not OPENAI_API_KEY:
    logger.error("OPENAI_API_KEY not set!")
else:
    openai.api_key = OPENAI_API_KEY

# URL для Telegram API
TELEGRAM_API_URL = f"https://api.telegram.org/bot{TELEGRAM_TOKEN}"

# Хранилище данных в памяти
chat_histories = {}
user_states = {}
apartments_cache = None
cache_time = 0
subscription_checks = {}

# ====== ПОЛУЧЕНИЕ ДАННЫХ ИЗ GOOGLE SHEETS ======
def get_apartments_from_sheets():
    global apartments_cache, cache_time
    
    # Кеширование на 15 минут
    if apartments_cache and (time.time() - cache_time) < 900:
        logger.info(f"Using cached data: {len(apartments_cache)} apartments")
        return apartments_cache
    
    sheet_urls = [
        "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?output=csv",
        "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?gid=0&single=true&output=csv"
    ]
    
    for sheet_url in sheet_urls:
        try:
            # Небольшая задержка чтобы не попасть в rate limit
            time.sleep(random.uniform(0.1, 0.5))
            
            response = requests.get(sheet_url, timeout=20, headers={
                'User-Agent': 'Mozilla/5.0 (compatible; PropertyBot/1.0)',
                'Accept': 'text/csv,text/plain,*/*'
            })
            
            if response.status_code == 200 and len(response.text) > 100:
                logger.info(f"Successfully fetched data from Google Sheets")
                apartments = parse_csv_to_apartments(response.text)
                
                if apartments:
                    apartments_cache = apartments
                    cache_time = time.time()
                    return apartments
                    
        except Exception as e:
            logger.error(f"Failed to fetch from {sheet_url}: {e}")
    
    # Возвращаем тестовые данные если не удалось получить
    logger.warning("Using test data")
    return get_test_apartments()

def parse_csv_to_apartments(csv_data: str) -> List[Dict]:
    apartments = []
    lines = csv_data.strip().split('\n')
    
    # Пропускаем заголовок
    for i, line in enumerate(lines[1:], 1):
        if not line.strip():
            continue
            
        try:
            # Простой парсинг CSV
            parts = line.split(',')
            if len(parts) >= 7:
                apartment = {
                    'этаж': int(parts[0]) if parts[0].isdigit() else 0,
                    'номер': int(parts[1]) if parts[1].isdigit() else 0,
                    'площадь': float(parts[2].replace('$', '').replace(',', '')) if parts[2] else 0,
                    'вид': parts[3].strip(),
                    'цена_м2': float(parts[4].replace('$', '').replace(',', '')) if parts[4] else 0,
                    'общая_сумма': float(parts[5].replace('$', '').replace(',', '')) if parts[5] else 0,
                    'жк': parts[6].strip() if len(parts) > 6 else 'Thalassa Group',
                    'статус': 'Свободный'
                }
                
                if apartment['номер'] > 0 and apartment['площадь'] > 0:
                    apartments.append(apartment)
                    
        except Exception as e:
            logger.error(f"Error parsing line {i}: {e}")
            continue
    
    logger.info(f"Parsed {len(apartments)} apartments")
    return apartments

def get_test_apartments():
    return [
        {
            'этаж': 5,
            'номер': 319,
            'площадь': 35.5,
            'вид': 'Море',
            'цена_м2': 1520,
            'общая_сумма': 54080,
            'жк': 'Thalassa Group',
            'статус': 'Свободный'
        },
        {
            'этаж': 8,
            'номер': 412,
            'площадь': 29.1,
            'вид': 'Город',
            'цена_м2': 1520,
            'общая_сумма': 44264,
            'жк': 'Thalassa Group',
            'статус': 'Свободный'
        },
        {
            'этаж': 12,
            'номер': 514,
            'площадь': 21.6,
            'вид': 'Море',
            'цена_м2': 1520,
            'общая_сумма': 32832,
            'жк': 'Thalassa Group',
            'статус': 'Свободный'
        }
    ]

# ====== TELEGRAM ФУНКЦИИ ======
def send_telegram_message(chat_id: str, text: str, reply_markup=None, parse_mode='HTML'):
    data = {
        'chat_id': chat_id,
        'text': text,
        'parse_mode': parse_mode,
        'disable_web_page_preview': True
    }
    if reply_markup:
        data['reply_markup'] = json.dumps(reply_markup)
    
    try:
        response = requests.post(f"{TELEGRAM_API_URL}/sendMessage", data=data)
        return response.json()
    except Exception as e:
        logger.error(f"Failed to send message: {e}")
        return None

def check_subscription(user_id: int) -> bool:
    try:
        response = requests.get(f"{TELEGRAM_API_URL}/getChatMember", params={
            'chat_id': CHANNEL_USERNAME,
            'user_id': user_id
        })
        if response.status_code == 200:
            data = response.json()
            status = data.get('result', {}).get('status', '')
            return status in ['member', 'administrator', 'creator']
    except Exception as e:
        logger.error(f"Failed to check subscription: {e}")
        return True  # При ошибке пропускаем проверку
    return False

# ====== GPT ФУНКЦИЯ ======
def ask_gpt(messages: List[Dict]) -> str:
    try:
        client = openai.OpenAI(api_key=OPENAI_API_KEY)
        response = client.chat.completions.create(
            model="gpt-4o",
            messages=messages,
            max_tokens=400,
            temperature=0.5
        )
        return response.choices[0].message.content
    except Exception as e:
        logger.error(f"OpenAI API error: {e}")
        return "Извините, возникла техническая проблема. Попробуйте позже или напишите @smkornaukhovv"

# ====== ОБРАБОТКА СООБЩЕНИЙ ======
def process_message(update: Dict):
    try:
        # Извлекаем данные из update
        message = update.get('message', {})
        chat_id = str(message.get('chat', {}).get('id', ''))
        user_id = message.get('from', {}).get('id', 0)
        username = message.get('from', {}).get('username', 'нет_username')
        first_name = message.get('from', {}).get('first_name', 'друг')
        text = message.get('text', '').strip()
        
        if not chat_id or not text:
            return
        
        logger.info(f"Message from {first_name} ({user_id}): {text}")
        
        # Проверка подписки
        global subscription_checks
        last_check = subscription_checks.get(chat_id, 0)
        current_time = time.time()
        
        if not check_subscription(user_id):
            if current_time - last_check >= 60:
                send_telegram_message(
                    chat_id, 
                    "Для продолжения подпишись на канал 👉 @smkornaukhovv, а потом нажми /start"
                )
                subscription_checks[chat_id] = current_time
            return
        
        # Команда /start
        if text.lower() == '/start':
            send_telegram_message(
                chat_id,
                f"Добрый день, {first_name}! Я AI-бот и помогу подобрать квартиру в Батуми.\n"
                "Скажите, вы ищете квартиру больше для проживания или для сдачи в аренду?"
            )
            return
        
        # Получаем состояние пользователя
        user_state = user_states.get(chat_id, {'state': 'normal', 'data': {}})
        
        # Обработка записи на показ
        if user_state['state'] == 'booking_time':
            user_state['data']['time'] = text
            user_state['state'] = 'booking_phone'
            user_states[chat_id] = user_state
            
            keyboard = {
                "keyboard": [[{"text": "Поделиться телефоном (WA)", "request_contact": True}]],
                "resize_keyboard": True,
                "one_time_keyboard": True
            }
            send_telegram_message(
                chat_id,
                "Спасибо! Теперь отправьте номер телефона для WhatsApp (или нажмите кнопку).",
                reply_markup=keyboard
            )
            return
            
        elif user_state['state'] == 'booking_phone':
            # Обработка контакта или текста с номером
            contact = message.get('contact', {})
            phone = contact.get('phone_number', '') or text
            
            # Проверка номера
            digits = ''.join(filter(str.isdigit, phone))
            if len(digits) < 9:
                keyboard = {
                    "keyboard": [[{"text": "Поделиться телефоном (WA)", "request_contact": True}]],
                    "resize_keyboard": True,
                    "one_time_keyboard": True
                }
                send_telegram_message(
                    chat_id,
                    "Не похоже на номер. Пришлите в формате +9955… или нажмите кнопку.",
                    reply_markup=keyboard
                )
                return
            
            # Отправляем лид в группу
            wa_link = f"https://wa.me/{digits}"
            tg_link = f"tg://user?id={user_id}"
            
            lead_text = (
                "🏠 <b>Новая запись на показ</b>\n\n"
                f"👤 Клиент: @{username} (ID: <code>{user_id}</code>)\n"
                f"📅 Время: <b>{user_state['data'].get('time', 'не указано')}</b>\n"
                f"📱 Телефон (WA): <b>{phone}</b>\n"
                f'🔗 Связь: <a href="{tg_link}">TG</a> | <a href="{wa_link}">WA</a>'
            )
            
            send_telegram_message(LEADS_CHAT_ID, lead_text)
            
            # Сбрасываем состояние
            user_states[chat_id] = {'state': 'normal', 'data': {}}
            
            send_telegram_message(
                chat_id,
                f"Готово! Я записал вас на показ на {user_state['data'].get('time', 'указанное время')} ✅",
                reply_markup={"remove_keyboard": True}
            )
            return
        
        # Проверка триггеров для записи на показ
        booking_triggers = [
            'хочу посмотреть', 'запись на показ', 'записаться',
            'онлайн показ', 'встреча', 'назначить показ',
            'посмотреть квартиру', 'хочу на показ', 'покажи варианты'
        ]
        
        if any(trigger in text.lower() for trigger in booking_triggers):
            user_states[chat_id] = {'state': 'booking_time', 'data': {}}
            send_telegram_message(
                chat_id,
                "Отлично! Запишу вас на показ.\n\n"
                "<u>Шаг 1 из 2</u> — напишите удобные <b>дату и время (Тбилиси)</b>.\n"
                "Например: «13 августа, 15:00»"
            )
            return
        
        # GPT обработка
        apartments = get_apartments_from_sheets()
        
        # Подготовка статистики для GPT
        studio_count = sum(1 for a in apartments if a['площадь'] <= 40)
        studio_prices = [a['общая_сумма'] for a in apartments if a['площадь'] <= 40]
        studio_min = min(studio_prices) if studio_prices else 0
        studio_max = max(studio_prices) if studio_prices else 0
        
        base_stats = f"В базе {len(apartments)} квартир, студий — {studio_count}, цены: ${studio_min}-${studio_max}"
        
        # База для GPT
        base_info = "\n".join([
            f"ЖК: {a['жк']}, Этаж: {a['этаж']}, №: {a['номер']}, "
            f"Площадь: {a['площадь']} м², Вид: {a['вид']}, "
            f"Цена: ${a['общая_сумма']}"
            for a in apartments[:20]  # Ограничиваем для экономии токенов
        ])
        
        # История чата
        history = chat_histories.get(chat_id, [])
        
        # Системный промпт
        system_prompt = f"""Ты — AI-брокер по недвижимости в Батуми. Общайся просто и по делу.

Правила:
- Один вопрос за сообщение
- Не здоровайся повторно
- После 2-3 уточнений предлагай 1-2 варианта
- Если бюджет мал — предложи решение (рассрочка, меньше метраж)
- При показе вариантов добавь: "Показать? Напишите: запись на показ"

Имя клиента: {first_name}
Статистика: {base_stats}

База квартир:
{base_info}"""
        
        # Формируем messages для GPT
        messages = [{"role": "system", "content": system_prompt}]
        messages.extend(history[-6:])  # Последние 6 сообщений
        messages.append({"role": "user", "content": text})
        
        # Получаем ответ от GPT
        answer = ask_gpt(messages)
        
        # Сохраняем историю
        history.append({"role": "user", "content": text})
        history.append({"role": "assistant", "content": answer})
        chat_histories[chat_id] = history[-10:]  # Храним последние 10
        
        # Отправляем ответ
        send_telegram_message(chat_id, answer)
        
    except Exception as e:
        logger.error(f"Error processing message: {e}")

# ====== FLASK ROUTES ======
@app.route('/')
def index():
    return f"""
    <h1>Telegram Bot Status</h1>
    <p>Bot is running!</p>
    <p>TELEGRAM_TOKEN: {'✅ Set' if TELEGRAM_TOKEN else '❌ Not set'}</p>
    <p>OPENAI_API_KEY: {'✅ Set' if OPENAI_API_KEY else '❌ Not set'}</p>
    <p>Time: {datetime.now()}</p>
    """

@app.route('/webhook', methods=['POST'])
def webhook():
    try:
        update = request.get_json()
        logger.info(f"Received update: {update}")
        
        # Обрабатываем в фоне
        process_message(update)
        
        return jsonify({'status': 'ok'})
    except Exception as e:
        logger.error(f"Webhook error: {e}")
        return jsonify({'status': 'error'}), 500

@app.route('/set_webhook')
def set_webhook():
    # Получаем URL приложения из заголовков
    host = request.headers.get('Host', 'batumi-realestate-bot.onrender.com')
    webhook_url = f"https://{host}/webhook"
    
    try:
        response = requests.post(
            f"{TELEGRAM_API_URL}/setWebhook",
            data={'url': webhook_url}
        )
        return jsonify(response.json())
    except Exception as e:
        return jsonify({'error': str(e)}), 500

if __name__ == '__main__':
    port = int(os.environ.get('PORT', 10000))
    app.run(host='0.0.0.0', port=port)
