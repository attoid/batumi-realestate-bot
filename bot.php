<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
$LEADS_CHAT_ID = "-1002536751047";

// --- Фолбэки, если на сервере нет mbstring ---
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = 'UTF-8') { return strtolower($s); }
}
if (!function_exists('mb_stripos')) {
    function mb_stripos($haystack, $needle, $offset = 0, $enc = 'UTF-8') {
        return stripos($haystack, $needle, $offset);
    }
}

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
}  // ← закрытие if (!empty($apartments))
        } else {  // ← это else от if ($csv_data !== false && $http_code === 200)
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
function get_user_state($chat_id){
    $file = __DIR__."/state/{$chat_id}.json";
    if (!file_exists($file)) return ['state'=>'normal','data'=>[]];
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['state'=>'normal','data'=>[]];
}
function save_user_state($chat_id,$state){
    $dir = __DIR__.'/state';
    if (!is_dir($dir)) @mkdir($dir,0777,true);
    @file_put_contents($dir."/{$chat_id}.json", json_encode($state, JSON_UNESCAPED_UNICODE));
    return true;
}

// ====== ФУНКЦИЯ ОТПРАВКИ СООБЩЕНИЯ ======
function send_telegram_message($token, $chat_id, $text, $reply_markup = null, $parse_mode = 'HTML', $disable_preview = true) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode,
        'disable_web_page_preview' => $disable_preview ? 'true' : 'false'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup, JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $result = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false || $code !== 200) {
        error_log("sendMessage fail: HTTP $code; resp: $result");
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

if (!isset($update["message"])) {
    exit;
}

// --- Разбор входа (ОДИН раз) ---
$chat_id      = $update["message"]["chat"]["id"];
$user_message = trim($update["message"]["text"] ?? "");
$user_name    = $update["message"]["from"]["first_name"] ?? "друг";
$user_id      = $update["message"]["from"]["id"];
$username     = $update["message"]["from"]["username"] ?? "нет_username";
$message_lower = mb_strtolower($user_message);

error_log("Processing message from user $user_name ($user_id): $user_message");

// История/состояние
$history    = get_chat_history($chat_id);
$user_state = get_user_state($chat_id);

// --- /id только в группе ---
if (isset($update["message"]["chat"]["type"]) && in_array($update["message"]["chat"]["type"], ["group","supergroup"])) {
    if ($user_message === '/id') {
        send_telegram_message($token, $chat_id, "ID этой группы: <b>{$chat_id}</b>");
        exit;
    }
}

// --- Дебаг по базе ---
if (!empty($apartments)) {
    error_log("DEBUG: Database loaded successfully - " . count($apartments) . " apartments available");
} else {
    error_log("DEBUG: No apartments loaded from database!");
}

// --- Проверка подписки ---
$last_check  = get_last_subscription_check($chat_id);
$current_time = time();
$channel     = "@smkornaukhovv";
$is_member   = check_subscription($token, $channel, $user_id);

if (!$is_member) {
    if ($current_time - $last_check >= 60) {
        $ok = send_telegram_message($token, $chat_id, "Для продолжения подпишись на канал 👉 @smkornaukhovv, а потом нажми /start");
        if ($ok) save_last_subscription_check($chat_id);
    }
    exit;
}

// --- Приветствие при /start ---
if ($message_lower === '/start') {
    $hello = "Добрый день, {$user_name}! Я AI-бот и помогу подобрать квартиру в Батуми.\n".
             "Скажите, вы ищете квартиру больше для проживания или для сдачи в аренду?";
    send_telegram_message($token, $chat_id, $hello);
    exit;
}

// --- Процесс записи на показ (если уже начат) ---
if ($user_state['state'] !== 'normal') {

    // Шаг 1: время -> просим телефон
    if ($user_state['state'] === 'booking_time') {
        $data = $user_state['data'];
        $data['time'] = $user_message;
        save_user_state($chat_id, ['state' => 'booking_phone', 'data' => $data]);

        $kb = [
            "keyboard" => [[["text" => "Поделиться телефоном (WA)", "request_contact" => true]]],
            "resize_keyboard" => true,
            "one_time_keyboard" => true
        ];
        send_telegram_message($token, $chat_id, "Спасибо! Теперь отправьте номер телефона для WhatsApp (или нажмите кнопку).", $kb, 'HTML');
        exit;
    }

    // Шаг 2: телефон -> отправляем лид в группу
    if ($user_state['state'] === 'booking_phone') {
        $contact_phone = $update["message"]["contact"]["phone_number"] ?? null;
        $phone = $contact_phone ?: $user_message;

        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 9) {
            $kb = [
                "keyboard" => [[["text" => "Поделиться телефоном (WA)", "request_contact" => true]]],
                "resize_keyboard" => true,
                "one_time_keyboard" => true
            ];
            send_telegram_message($token, $chat_id, "Не похоже на номер. Пришлите в формате +9955… или нажмите кнопку.", $kb, 'HTML');
            exit;
        }

        $data = $user_state['data'];
        $data['phone'] = $phone;

        $wa_link = "https://wa.me/" . $digits;
        $tg_link = "tg://user?id={$user_id}";

        $lead_text =
            "🏠 <b>Новая запись на показ</b>\n\n".
            "👤 Клиент: @$username (ID: <code>$user_id</code>)\n".
            "📅 Время: <b>{$data['time']}</b>\n".
            "📱 Телефон (WA): <b>{$phone}</b>\n".
            "🔗 Связь: <a href=\"$tg_link\">TG</a> | <a href=\"$wa_link\">WA</a>";

        send_telegram_message($token, $LEADS_CHAT_ID, $lead_text);

        send_telegram_message($token, $chat_id, "Готово! Я записал вас на показ на {$data['time']} ✅", ["remove_keyboard" => true], 'HTML');

        save_user_state($chat_id, ['state' => 'normal', 'data' => []]);
        exit;
    }

    // Fallback
    send_telegram_message($token, $chat_id, "Что-то пошло не так. Напишите «запись на показ», начнём заново.");
    exit;
}

// --- Быстрые триггеры на старт записи (когда обычный режим) ---
$booking_triggers = [
    'хочу посмотреть','запись на показ','записаться',
    'онлайн показ','онлайн-показ','встреча','назначить показ',
    'посмотреть квартиру','хочу запись','хочу на показ',
    'показать сейчас','покажи варианты','давай посмотрим'
];
$should_start_booking = false;
foreach ($booking_triggers as $kw) {
    if (mb_stripos($message_lower, $kw) !== false) { $should_start_booking = true; break; }
}
if ($should_start_booking) {
    save_user_state($chat_id, ['state' => 'booking_time', 'data' => []]);
    send_telegram_message(
        $token,
        $chat_id,
        "Отлично! Запишу вас на показ.\n\n<u>Шаг 1 из 2</u> — напишите удобные <b>дату и время (Тбилиси)</b>.\nНапример: «13 августа, 15:00»",
        null,
        'HTML'
    );
    exit;
}

// --- Статистика по базе для промпта ---
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

// --- Сводка базы для GPT (не выводим целиком пользователю) ---
$base_info = "";
foreach ($apartments as $a) {
    $base_info .=
        "ЖК: {$a['жк']}, Этаж: {$a['этаж']}, №: {$a['номер']}, ".
        "Площадь: {$a['площадь']} м², Вид: {$a['вид']}, ".
        "Цена/м²: $" . $a['цена_м2'] . ", Всего: $" . $a['общая_сумма'] . ", ".
        "Статус: {$a['статус']}\n";
}


    // ====== SYSTEM PROMPT ======
    $messages = [
        [
            "role" => "system",
            "content" =>
"Ты — AI-брокер по недвижимости в Батуми. Общайся просто и по делу, как живой специалист.

Жёсткие правила:
- Один вопрос за сообщение. Никогда не задавай два подряд.
- Не здороваться повторно (приветствие уже было кодом).
- Делай выводы сам из ответов (цель, бюджет, тип дома, планировка). Не переспрашивай известное.
- После 2–3 коротких уточнений сразу предлагай 1–2 подходящих варианта из базы.
- Если бюджета не хватает — предложи решение: меньший метраж, 2-я линия, рассрочка 0%, дом на финальной стадии.
- В каждом сообщении с вариантами: коротко «почему это вам» + мягкий CTA: «Показать? Напишите: запись на показ».

SPIN-light (как вести диалог):
S — ситуация: один простой вопрос (для жизни или инвестиций).
P — ограничение: деньги/оплата («готовы ли к рассрочке 0%?»).
P/I — тип дома: «готовые или можно новостройку с быстрой сдачей?».
N — выгода: покажи 1–2 лота и предложи запись на показ.

Тон: дружелюбный, уверенный, без канцелярита. Короткие абзацы и фразы. Допускается лёгкая шутка, если собеседник уводит в сторону — но сразу верни к подбору одним вопросом.

Как подбирать:
- Используй сводку базы ниже, но не выводи всю: выбери 1–2 лучших по смыслу.
- Формат лота (очень кратко, в одну-две строки):
  «ЖК {жк}, {площадь} м², этаж {этаж}, вид {вид}, всего \${общая_сумма}. Почему вам: … (1 фраза).»
  Если есть рассрочка/акция — добавь одной фразой.
- Если клиент пишет «спальня и гостиная» — считай это 1BR (~40–50 м²) и не спрашивай метраж.

Триггеры на запись:
- Если клиент пишет что-то вроде «хочу посмотреть», «запись на показ», «покажи варианты», «показать сейчас» — ответь коротко, что готов оформить показ и попроси написать «запись на показ». (Далее запись обрабатывает логика бота.)

Имя пользователя: $user_name.
Краткая статистика: 
$base_stats

База (для выбора вариантов, не перечисляй целиком):
$base_info"

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
