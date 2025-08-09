<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "TEST: PHP started\n";

file_put_contents(__DIR__.'/test.log', date('c')." BOT.PHP ЗАПУСТИЛСЯ\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "Бот работает!";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$openai_key = getenv('OPENAI_API_KEY');
$token = getenv('TELEGRAM_TOKEN');
$admin_chat_id = "7770604629";

if (!$openai_key) {
    error_log("CRITICAL ERROR: OPENAI_API_KEY not set");
    die("Configuration error");
}
if (!$token) {
    error_log("CRITICAL ERROR: TELEGRAM_TOKEN not set");
    die("Configuration error");
}

// ====== ХРАНИЛИЩА В ПАМЯТИ ВМЕСТО ФАЙЛОВ ======
$chat_histories = [];
$user_states = [];
$apartments_cache = null;
$cache_time = 0;

// ====== ПОЛУЧЕНИЕ ВХОДЯЩИХ ДАННЫХ ======
$content = file_get_contents("php://input");
if ($content === false) {
    error_log("Failed to read input");
    exit;
}

$update = json_decode($content, true);
if ($update === null) {
    error_log("Failed to decode JSON input");
    exit;
}

// ====== ФУНКЦИЯ ПОЛУЧЕНИЯ ДАННЫХ ИЗ GOOGLE SHEETS ======
function get_apartments_from_sheets() {
    // Пробуем разные варианты ссылок
    $sheet_urls = [
        "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?output=csv",
        "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?gid=0&single=true&output=csv"
    ];
    
   global $apartments_cache, $cache_time;

// Кеширование в памяти
if ($apartments_cache !== null && (time() - $cache_time) < 900) {
    error_log("Using cached data: " . count($apartments_cache) . " apartments");
    return $apartments_cache;
}

    // Пробуем разные ссылки
    foreach ($sheet_urls as $sheet_url) {
        error_log("Trying URL: $sheet_url");
        
        // Добавляем случайную задержку чтобы не попасть в rate limit
        usleep(rand(100000, 500000)); // 0.1-0.5 секунды
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $sheet_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PropertyBot/1.0)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/csv,text/plain,*/*',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive'
        ]);
        
        $csv_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        error_log("Response: HTTP $http_code, Size: " . strlen($csv_data) . " bytes");
        
        if ($csv_data !== false && $http_code === 200 && strlen($csv_data) > 100) {
            error_log("Successfully fetched data from: $sheet_url");
            
            // Парсим CSV
            $apartments = parse_csv_to_apartments($csv_data);
            error_log("Parsed " . count($apartments) . " apartments from CSV");
            
            if (!empty($apartments)) {

// Сохраняем в память
    $apartments_cache = $apartments;
    $cache_time = time();
    error_log("Data saved to memory cache");
    return $apartments;
            }
        } else {
            error_log("Failed URL $sheet_url: HTTP $http_code, Error: $error");
        }
    } // закрытие foreach
    
    error_log("No data available, returning test data");
    return get_test_apartments();
} // закрытие функции

// ====== ТЕСТОВЫЕ ДАННЫЕ НА СЛУЧАЙ ПРОБЛЕМ С GOOGLE SHEETS ======
function get_test_apartments() {
    return [
        [
            'этаж' => 5,
            'номер' => 319,
            'площадь' => 35.5,
            'вид' => 'Море',
            'цена_м2' => 1520,
            'общая_сумма' => 54080,
            'жк' => 'Thalassa Group',
            'статус' => 'Свободный'
        ],
        [
            'этаж' => 8,
            'номер' => 412,
            'площадь' => 29.1,
            'вид' => 'Город',
            'цена_м2' => 1520,
            'общая_сумма' => 44264,
            'жк' => 'Thalassa Group',
            'статус' => 'Свободный'
        ],
        [
            'этаж' => 12,
            'номер' => 514,
            'площадь' => 21.6,
            'вид' => 'Море',
            'цена_м2' => 1520,
            'общая_сумма' => 32832,
            'жк' => 'Thalassa Group',
            'статус' => 'Свободный'
        ]
    ];
}


// ====== ФУНКЦИЯ ПАРСИНГА CSV ======
function parse_csv_to_apartments($csv_data) {
    $lines = explode("\n", trim($csv_data));
    $apartments = [];
    
    error_log("CSV has " . count($lines) . " lines");
    
    // Выводим первые 3 строки для отладки
    for ($debug_i = 0; $debug_i < min(3, count($lines)); $debug_i++) {
        error_log("Line $debug_i: " . $lines[$debug_i]);
    }
    
    // Пропускаем заголовки (первую строку)
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        
        $data = str_getcsv($line);
        error_log("Line $i parsed into " . count($data) . " columns: " . implode(" | ", $data));

        // Должно быть хотя бы 7 колонок: этаж, номер, площадь, вид, цена, сумма, жк
        if (count($data) < 7) {
            error_log("Skipping line $i - not enough columns");
            continue;
        }

        $apartment = [
            'этаж' => (int)$data[0],
            'номер' => (int)$data[1],
            'площадь' => (float)str_replace([",", "$"], "", $data[2]),
            'вид' => trim($data[3]),
            'цена_м2' => (float)str_replace([",", "$"], "", $data[4]),
            'общая_сумма' => (float)str_replace([",", "$"], "", $data[5]),
            'жк' => trim($data[6]),
            'статус' => 'Свободный'
        ];
        
        if ($apartment['номер'] > 0 && $apartment['площадь'] > 0 && $apartment['общая_сумма'] > 0) {
            $apartments[] = $apartment;
            error_log("Added apartment: " . json_encode($apartment));
        } else {
            error_log("Skipped invalid apartment: " . json_encode($apartment));
        }
    }
    
    error_log("Total apartments parsed: " . count($apartments));
    return $apartments;
}

// ====== ФУНКЦИИ ИСТОРИИ ЧАТА ======
function get_chat_history($chat_id) {
    global $chat_histories;
    return $chat_histories[$chat_id] ?? [];
}

function save_chat_history($chat_id, $history) {
    global $chat_histories;
    $chat_histories[$chat_id] = $history;
    return true;
}

// ====== ФУНКЦИИ СОСТОЯНИЯ ПОЛЬЗОВАТЕЛЯ ======
function get_user_state($chat_id) {
    global $user_states;
    return $user_states[$chat_id] ?? ['state' => 'normal', 'data' => []];
}

function save_user_state($chat_id, $state) {
    global $user_states;
    $user_states[$chat_id] = $state;
    return true;
}

// ====== ФУНКЦИЯ ОТПРАВКИ СООБЩЕНИЯ ======
function send_telegram_message($token, $chat_id, $text) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $http_code !== 200) {
        error_log("Failed to send Telegram message: HTTP $http_code");
        return false;
    }

    return json_decode($result, true);
}

// ====== ФУНКЦИЯ ПРОВЕРКИ ПОДПИСКИ ======
function check_subscription($token, $channel, $user_id) {
    $url = "https://api.telegram.org/bot$token/getChatMember";
    $data = [
        'chat_id' => $channel,
        'user_id' => $user_id
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $http_code !== 200) {
        error_log("Failed to check subscription: HTTP $http_code");
        return true; // В случае ошибки API пропускаем проверку
    }

    $response = json_decode($result, true);
    if (!isset($response["result"]["status"])) {
        return true; // В случае неожиданного ответа пропускаем проверку
    }

    $status = $response["result"]["status"];
    return in_array($status, ["member", "administrator", "creator"]);
}

// ====== GPT ФУНКЦИЯ ======
function ask_gpt($messages, $openai_key) {
    $data = [
        "model" => "gpt-4o",
        "messages" => $messages,
        "max_tokens" => 400,
        "temperature" => 0.5
    ];

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $openai_key"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $http_code !== 200) {
        error_log("OpenAI API error: HTTP $http_code, Response: $result");
        return "Извините, сейчас возникли технические проблемы. Попробуйте позже или напишите напрямую @smkornaukhovv";
    }

    $response = json_decode($result, true);
    if (!isset($response['choices'][0]['message']['content'])) {
        error_log("Invalid OpenAI response structure: " . $result);
        return "Извините, не удалось получить ответ от ИИ. Попробуйте еще раз или напишите @smkornaukhovv";
    }

    return $response['choices'][0]['message']['content'];
}

// ====== ФУНКЦИЯ ПРОВЕРКИ ПОСЛЕДНЕГО СООБЩЕНИЯ О ПОДПИСКЕ ======
function get_last_subscription_check($chat_id) {
    $file = __DIR__ . "/subscription_checks/{$chat_id}.txt";
    if (!file_exists($file)) return 0;
    $time = file_get_contents($file);
    return $time ? (int)$time : 0;
}

function save_last_subscription_check($chat_id) {
    $dir = __DIR__ . '/subscription_checks';
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            error_log("Failed to create directory: $dir");
            return false;
        }
    }
    file_put_contents($dir . "/{$chat_id}.txt", time());
}

// ====== ОБРАБОТКА ЗАПИСИ НА ПОКАЗ ======
function handle_booking_process($chat_id, $user_message, $user_state, $user_name, $token, $admin_chat_id) {
    $state = $user_state['state'];
    $data = $user_state['data'];

    switch ($state) {
        case 'booking_time':
            $data['time'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_phone', 'data' => $data]);
            return "Отлично! Теперь укажите ваш номер телефона (пример: +9955...):";

        case 'booking_phone':
            $phone = preg_replace('/\D+/', '', $user_message);
            if (strlen($phone) < 9) {
                return "Похоже, номер некорректный. Пожалуйста, отправьте номер ещё раз, например: +995599000000";
            }
            $data['phone'] = $user_message;
            $data['client_name'] = $user_name;  // АВТОМАТИЧЕСКИ БЕРЕМ ИМЯ
            save_user_state($chat_id, ['state' => 'booking_budget', 'data' => $data]);  // СРАЗУ ПЕРЕХОДИМ К БЮДЖЕТУ
            return "Какой у вас бюджет на покупку? (укажите сумму в долларах)";

        // БЛОК booking_name УДАЛЕН - больше не нужен!

        case 'booking_budget':
            $budget = preg_replace('/[^\d]/', '', $user_message);
            if (intval($budget) < 10000) {
                return "Пожалуйста, укажите реальный бюджет (например: 45000).";
            }
            $data['budget'] = $budget;
            save_user_state($chat_id, ['state' => 'booking_payment', 'data' => $data]);
            return "Планируете покупать сразу за полную стоимость или в рассрочку?";

        case 'booking_payment':
            $valid = false;
            $lower = mb_strtolower($user_message);
            foreach (['рассрочка', 'полная', 'сразу', 'в рассрочку', 'оплата'] as $w) {
                if (mb_stripos($lower, $w) !== false) $valid = true;
            }
            if (!$valid) {
                return "Пожалуйста, напишите: \"сразу\" или \"рассрочка\"";
            }
            $data['payment_type'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_timeline', 'data' => $data]);
            return "Когда планируете выйти на сделку? (например: в течение месяца, через 3 месяца и т.д.)";

        case 'booking_timeline':
            if (mb_strlen($user_message) < 2) {
                return "Пожалуйста, укажите примерный срок, когда хотите купить квартиру.";
            }
            $data['timeline'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_decision_maker', 'data' => $data]);
            return "Кто принимает окончательное решение о покупке? (вы лично, с супругом/супругой, с родителями и т.д.)";

        case 'booking_decision_maker':
            if (mb_strlen($user_message) < 2) {
                return "Пожалуйста, укажите, кто принимает решение о покупке.";
            }
            $data['decision_maker'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_purpose', 'data' => $data]);
            return "С какой целью покупаете недвижимость? (для проживания, инвестиций, сдачи в аренду и т.д.)";

        case 'booking_purpose':
            if (mb_strlen($user_message) < 2) {
                return "Пожалуйста, укажите цель покупки (жить, сдавать, инвестировать и т.д.)";
            }
            $data['purpose'] = $user_message;

            // Отправляем данные админу
            $admin_message = "🏠 НОВАЯ ЗАЯВКА НА ПОКАЗ\n\n";
            $admin_message .= "👤 Клиент: {$data['client_name']}\n";
            $admin_message .= "📱 Телефон: {$data['phone']}\n";
            $admin_message .= "🕐 Время показа: {$data['time']}\n";
            if (!empty($data['apartment'])) {
                $admin_message .= "🏢 Квартира: {$data['apartment']}\n";
            }
            $admin_message .= "💰 Бюджет: {$data['budget']}\n";
            $admin_message .= "💳 Оплата: {$data['payment_type']}\n";
            $admin_message .= "📅 Сделка: {$data['timeline']}\n";
            $admin_message .= "👥 ЛПР: {$data['decision_maker']}\n";
            $admin_message .= "🎯 Цель: {$data['purpose']}\n";
            $admin_message .= "💬 Telegram: @{$user_name} (ID: {$chat_id})";

            send_telegram_message($token, $admin_chat_id, $admin_message);

            // Сбрасываем состояние
            save_user_state($chat_id, ['state' => 'normal', 'data' => []]);

            return "Отлично! Ваша заявка принята. Сергей свяжется с вами лично для уточнения всех деталей показа. Спасибо за обращение! 🏠";
    }
    return "Что-то пошло не так. Попробуйте еще раз.";
}
// ====== ПОЛУЧАЕМ КВАРТИРЫ ======
$apartments = get_apartments_from_sheets();

if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $user_message = trim($update["message"]["text"] ?? "");
    $user_name = $update["message"]["from"]["first_name"] ?? "друг";
    $user_id = $update["message"]["from"]["id"];
    $username = $update["message"]["from"]["username"] ?? "нет_username";

    error_log("Processing message from user $user_name ($user_id): $user_message");

    // ====== ПОЛУЧАЕМ СОСТОЯНИЕ ПОЛЬЗОВАТЕЛЯ ======
    $user_state = get_user_state($chat_id);

        // ====== ПРОВЕРКА НА КОМАНДЫ ЗАПИСИ НА ПОКАЗ ======
    $booking_keywords = ['показ', 'запись', 'записаться', 'хочу посмотреть', 'онлайн показ', 'онлайн-показ', 'встретиться', 'встреча'];
    $message_lower = mb_strtolower($user_message);

    // ====== ДЕБАГ - только в логи, не пользователю ======
    if (!empty($apartments)) {
        error_log("DEBUG: Database loaded successfully - " . count($apartments) . " apartments available");
    } else {
        error_log("DEBUG: No apartments loaded from database!");
    }

    // Проверяем, не отправляли ли мы уже сообщение о подписке в последние 60 секунд
    $last_check = get_last_subscription_check($chat_id);
    $current_time = time();

    // ====== ПРОВЕРКА ПОДПИСКИ ======
    $channel = "@smkornaukhovv";
    $is_member = check_subscription($token, $channel, $user_id);

    if (!$is_member) {
        // Проверяем, не спамили ли мы уже
        if ($current_time - $last_check < 60) {
            exit;
        }
        $success = send_telegram_message($token, $chat_id, "Для продолжения подпишись на канал 👉 @smkornaukhovv, а потом нажми /start");
        if ($success) {
            save_last_subscription_check($chat_id);
        }
        exit;
    }
    
    // ====== ОБРАБОТКА ЗАПИСИ НА ПОКАЗ ======
    if ($user_state['state'] !== 'normal') {
        $response = handle_booking_process($chat_id, $user_message, $user_state, $username, $token, $admin_chat_id);
        send_telegram_message($token, $chat_id, $response);
        exit;
    }

    // ====== ПОЛУЧАЕМ ИСТОРИЮ ЧАТА ======
    $history = get_chat_history($chat_id);
    
    // Здороваемся только при команде /start
    $is_first_message = (trim(strtolower($user_message)) === '/start');

    // Сохраняем данные для отладки
    file_put_contents(__DIR__.'/parse_debug.log', print_r($apartments, true));

    // ====== СТАТИСТИКА ПО БАЗЕ ======
    $studio_count = 0;
    $studio_min_price = null;
    $studio_max_price = null;
    foreach ($apartments as $a) {
        if ($a['площадь'] <= 40) {
            $studio_count++;
            if (is_null($studio_min_price) || $a['общая_сумма'] < $studio_min_price) $studio_min_price = $a['общая_сумма'];
            if (is_null($studio_max_price) || $a['общая_сумма'] > $studio_max_price) $studio_max_price = $a['общая_сумма'];
        }
    }
    $base_stats = "В базе сейчас " . count($apartments) . " квартир, из них студий — $studio_count, цены студий: от \$$studio_min_price до \$$studio_max_price.";

    // ====== СФОРМИРУЙ БАЗУ ДЛЯ ПРОМПТА ======
    $base_info = "";
    foreach ($apartments as $a) {
        $base_info .= "ЖК: {$a['жк']}, Этаж: {$a['этаж']}, №: {$a['номер']}, Площадь: {$a['площадь']} м², Вид: {$a['вид']}, Цена/м²: \${$a['цена_м2']}, Всего: \${$a['общая_сумма']}, Статус: {$a['статус']}\n";
    }

    // ====== SYSTEM PROMPT ======
    $greeting_instruction = ($user_message === '/start') ? 
    "ВАЖНО: Пользователь написал /start. Поздоровайся с ним по имени и спроси про район." : 
    "СТРОГО ЗАПРЕЩЕНО здороваться! Отвечай только на вопрос пользователя по существу.";

    $messages = [
        [
            "role" => "system",
            "content" =>
"Ты общаешься с пользователем по имени $user_name. Всегда обращайся к нему по этому имени — без изменений, переводов и русификаций. 

$greeting_instruction
Системный промпт (готов к вставке)
Ты — AI-продавец недвижимости в Батуми. Цель: довести клиента до показа и сделки.

Тон
Энергичный, уверенный, дружелюбный. Короткие фразы. Никакой воды. 1 вопрос за раз.

Память сессии (важно)
Запомни ответы клиента и не повторяй уже заданные вопросы. Если клиент дал часть параметров — используй их сразу.

Мини-воронка
Выясни: район → бюджет → планировка (студия/1+1/2+1/3+1) → оплата (сразу/рассрочка).

После каждого полученного параметра предложи 1–2 подходящих варианта.

Создай срочность (лимит, спрос, выгода).

Закрой на действие: запись на показ (онлайн/офлайн) и номер телефона.

Классификация по площади
до 37 м² — студия

37–55 м² — 1+1

55–80 м² — 2+1

80 м² — 3+1

Условия
первый взнос от 20%, рассрочка 0% до 10 мес

акционные — до 18 мес при 30% взносе

оплата на счёт застройщика

бронь 2 недели $100, задаток $1000 на месяц

Акции (конкретно проговаривай 2 опции)
Квартиры №319, 412, 514:

Обычная цена + рассрочка до 18 мес (30% взнос):
— №319: $67 000, — №412: $55 330, — №514: $40 040

Акционная цена при полной оплате одним платежом:
— №319: $54 080, — №412: $44 264, — №514: $32 832

Локации (подбор по районам)
Махинджаури: Thalassa Group, Next Collection, Kolos, A Sector, Mziuri

Новый бульвар: Ande Metropolis, Summer365, Real Palace Blue, Symbol, Artex, SkuLuxe

Старый город: Modern Ultra

Если спрашивают про Thalassa: «ЖК Thalassa Group — газ, бассейн, спортзал, сдача в этом году, 135 квартир, надёжный застройщик.»

Формат выдачи варианта (строго 1–2 за раз)
Вариант: ЖК {жк}, этаж {этаж}, №{номер}, {площадь} м² ({тип}), вид: {вид}.
Цена: ${общая_сумма} (≈ ${цена_м2}/м²).
Оплата: {сразу/рассрочка}; бронь $100, задаток $1000.
Почему сейчас: {аргумент срочности: ограниченный сток, цена до конца недели, высокий спрос и т.п.}
CTA: Хотите записаться на показ? Напишите «хочу посмотреть» или «запись на показ».

Правила реакции
Клиент назвал район → сразу 1–2 лучших варианта в этом районе + 1 уточнение (бюджет или планировка).

Клиент назвал бюджет → варианты в бюджете (1–2) + уточнение (район или планировка).

Клиент задаёт вопрос → ответ кратко и по делу → затем логичный следующий шаг (вариант/уточнение/CTA).

Если данных мало → задай только один самый полезный вопрос.

Никогда не показывай «весь список».

В каждом сообщении с вариантами добавляй CTA о записи на показ.

Работа с возражениями (коротко)
Дорого: предложи акционную цену/меньшую площадь/другой вид, упомяни рассрочку.

Думаю: ограниченность и выгода сейчас; предложи бронь $100 на 2 недели.

Не уверен в застройщике: факты о доме/застройщике (Thalassa и т.д.), оплата на счёт застройщика.

Шаблон финального закрытия
«Могу записать на показ (онлайн/офлайн). Когда удобно — сегодня или завтра? Оставьте номер телефона

$base_stats

База квартир (используй только после выяснения потребностей):
$base_info
"
        ]
    ];

    // ====== ИСТОРИЯ ЧАТА ======
    foreach ($history as $msg) {
        $messages[] = $msg;
    }

    // ====== ДОБАВЛЯЕМ НОВОЕ СООБЩЕНИЕ ======
    $messages[] = ["role" => "user", "content" => $user_message];

    error_log("Sending request to GPT with " . count($messages) . " messages");
    error_log("Last user message: " . $user_message);

    // ====== GPT-ЗАПРОС ======
    $answer = ask_gpt($messages, $openai_key);
    
    error_log("GPT response: " . substr($answer, 0, 100) . "...");

    // ====== СОХРАНЯЕМ ИСТОРИЮ ======
    $history[] = ["role" => "user", "content" => $user_message];
    $history[] = ["role" => "assistant", "content" => $answer];
    save_chat_history($chat_id, $history);

    // ====== ОТПРАВЛЯЕМ ОТВЕТ ======
    $telegram_result = send_telegram_message($token, $chat_id, $answer);
    error_log("Telegram send result: " . ($telegram_result ? "SUCCESS" : "FAILED"));
}

// Очищаем старые lock файлы (старше 1 минуты)
$lock_dir = __DIR__ . '/locks';
if (is_dir($lock_dir)) {
    $files = glob($lock_dir . '/*.lock');
    foreach ($files as $file) {
        if (filemtime($file) < time() - 60) {
            unlink($file);
        }
    }
}
?>
