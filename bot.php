<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$openai_key = getenv('OPENAI_API_KEY');
$token = getenv('TELEGRAM_TOKEN');
$admin_chat_id = "7770604629";

// ====== ПРОВЕРКА НА ДУБЛИКАТЫ ЗАПРОСОВ ======
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

// Защита от дублирования
$request_id = $update["update_id"] ?? time();
$lock_file = __DIR__ . "/locks/{$request_id}.lock";
$lock_dir = dirname($lock_file);
if (!file_exists($lock_dir)) {
    mkdir($lock_dir, 0777, true);
}
if (file_exists($lock_file)) {
    error_log("Duplicate request detected: $request_id");
    exit;
}
file_put_contents($lock_file, time());

// ====== ФУНКЦИЯ ПОЛУЧЕНИЯ ДАННЫХ ИЗ GOOGLE SHEETS ======
function get_apartments_from_sheets() {
    // Пробуем разные варианты ссылок
    $sheet_urls = [
        "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?output=csv",
        "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?gid=0&single=true&output=csv"
    ];
    
    $cache_file = __DIR__ . '/cache/apartments.json';
    $cache_time = 900; // Увеличиваем кэш до 15 минут чтобы меньше дергать Google

    // Кэширование
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
        $cached_data = file_get_contents($cache_file);
        $result = json_decode($cached_data, true);
        if (!empty($result)) {
            error_log("Using cached data: " . count($result) . " apartments");
            return $result;
        }
    }

    error_log("Fetching fresh data from Google Sheets");

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
                // Сохраняем в кэш
                $cache_dir = dirname($cache_file);
                if (!file_exists($cache_dir)) {
                    mkdir($cache_dir, 0777, true);
                }
                file_put_contents($cache_file, json_encode($apartments, JSON_UNESCAPED_UNICODE));
                error_log("Data saved to cache");
                return $apartments;
            }
        } else {
            error_log("Failed URL $sheet_url: HTTP $http_code, Error: $error");
        }
    }

    // Если все ссылки не сработали - возвращаем кэш
    if (file_exists($cache_file)) {
        error_log("All URLs failed, returning cached data");
        $cached_data = file_get_contents($cache_file);
        $result = json_decode($cached_data, true);
        return $result ?: [];
    }
    
    error_log("No data available, returning test data");
    // Возвращаем тестовые данные если ничего не работает
    return get_test_apartments();
}

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
    $file = __DIR__ . "/history/{$chat_id}.json";
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    if ($content === false) return [];
    $decoded = json_decode($content, true);
    return $decoded === null ? [] : $decoded;
}

function save_chat_history($chat_id, $history) {
    $dir = __DIR__ . '/history';
    if (!file_exists($dir)) {
        if (!mkdir($dir, 0777, true)) {
            error_log("Failed to create history directory");
            return false;
        }
    }
    $result = file_put_contents($dir . "/{$chat_id}.json", json_encode($history, JSON_UNESCAPED_UNICODE));
    if ($result === false) {
        error_log("Failed to save chat history for chat_id: $chat_id");
        return false;
    }
    return true;
}

// ====== ФУНКЦИИ СОСТОЯНИЯ ПОЛЬЗОВАТЕЛЯ ======
function get_user_state($chat_id) {
    $file = __DIR__ . "/states/{$chat_id}.json";
    if (!file_exists($file)) return ['state' => 'normal', 'data' => []];
    $content = file_get_contents($file);
    if ($content === false) return ['state' => 'normal', 'data' => []];
    $decoded = json_decode($content, true);
    return $decoded === null ? ['state' => 'normal', 'data' => []] : $decoded;
}

function save_user_state($chat_id, $state) {
    $dir = __DIR__ . '/states';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    $result = file_put_contents($dir . "/{$chat_id}.json", json_encode($state, JSON_UNESCAPED_UNICODE));
    if ($result === false) {
        error_log("Failed to save user state for chat_id: $chat_id");
    }
    return $result !== false;
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
        error_log("Failed to send Telegram message: HTTP $http_code, Result: $result");
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
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . "/{$chat_id}.txt", time());
}

// ====== УЛУЧШЕННАЯ ОБРАБОТКА ЗАПИСИ НА ПОКАЗ ======
function handle_booking_process($chat_id, $user_message, $user_state, $user_first_name, $username, $token, $admin_chat_id) {
    $state = $user_state['state'];
    $data = $user_state['data'];

    error_log("Booking process: state=$state, user_message='$user_message'");

    switch ($state) {
        case 'booking_time':
            $data['time'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_phone', 'data' => $data]);
            return "Отлично! Теперь укажите ваш номер телефона для связи:";

        case 'booking_phone':
            // Более гибкая проверка номера телефона
            $phone_clean = preg_replace('/[^\d+]/', '', $user_message);
            if (strlen($phone_clean) < 8) {
                return "Пожалуйста, укажите корректный номер телефона (например: +995599123456 или 599123456):";
            }
            $data['phone'] = $user_message;
            
            // Используем имя из Telegram, если есть, иначе спрашиваем
            if (!empty($user_first_name) && mb_strlen($user_first_name) >= 2) {
                $data['client_name'] = $user_first_name;
                save_user_state($chat_id, ['state' => 'booking_budget', 'data' => $data]);
                return "Отлично, $user_first_name! Какой у вас примерный бюджет на покупку? (например: 50000$)";
            } else {
                save_user_state($chat_id, ['state' => 'booking_name', 'data' => $data]);
                return "Как к вам обращаться? Укажите ваше имя:";
            }

        case 'booking_name':
            if (mb_strlen(trim($user_message)) < 2) {
                return "Пожалуйста, укажите ваше имя:";
            }
            $data['client_name'] = trim($user_message);
            save_user_state($chat_id, ['state' => 'booking_budget', 'data' => $data]);
            return "Отлично! Какой у вас примерный бюджет на покупку? (например: 50000$)";

        case 'booking_budget':
            // Более гибкий парсинг бюджета
            $budget_numbers = preg_replace('/[^\d]/', '', $user_message);
            $budget = intval($budget_numbers);
            
            if ($budget < 5000) {
                return "Пожалуйста, укажите реальный бюджет в долларах (например: 45000 или 50000$):";
            }
            
            $data['budget'] = $budget;
            save_user_state($chat_id, ['state' => 'booking_payment', 'data' => $data]);
            return "Планируете покупать сразу за полную стоимость или рассматриваете рассрочку?";

        case 'booking_payment':
            $data['payment_type'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_timeline', 'data' => $data]);
            return "В какие сроки планируете совершить покупку? (например: в ближайшие 2 месяца)";

        case 'booking_timeline':
            $data['timeline'] = $user_message;
            save_user_state($chat_id, ['state' => 'booking_final', 'data' => $data]);
            return "Есть ли дополнительные пожелания или вопросы по квартире? (можно написать \"нет\")";

        case 'booking_final':
            $data['additional_info'] = $user_message;

            // Формируем подробное сообщение для админа
            $admin_message = "🏠 НОВАЯ ЗАЯВКА НА ПОКАЗ\n\n";
            $admin_message .= "👤 Клиент: " . ($data['client_name'] ?? $user_first_name ?? 'Не указано') . "\n";
            $admin_message .= "📱 Телефон: " . ($data['phone'] ?? 'Не указан') . "\n";
            $admin_message .= "🕐 Время показа: " . ($data['time'] ?? 'Не указано') . "\n";
            $admin_message .= "💰 Бюджет: $" . ($data['budget'] ?? 'Не указан') . "\n";
            $admin_message .= "💳 Способ оплаты: " . ($data['payment_type'] ?? 'Не указано') . "\n";
            $admin_message .= "📅 Сроки покупки: " . ($data['timeline'] ?? 'Не указано') . "\n";
            $admin_message .= "📝 Доп. информация: " . ($data['additional_info'] ?? 'Нет') . "\n";
            $admin_message .= "💬 Telegram: @" . ($username ?? 'нет_username') . " (ID: {$chat_id})\n";
            
            // Добавляем информацию из живой ветки, если есть
            if (!empty($data['motivation'])) {
                $admin_message .= "🎯 Мотивация: " . $data['motivation'] . "\n";
            }
            if (!empty($data['district'])) {
                $admin_message .= "🗺️ Интересующий район: " . $data['district'] . "\n";
            }

            // Отправляем админу
            $admin_result = send_telegram_message($token, $admin_chat_id, $admin_message);
            
            // Логируем результат отправки
            if ($admin_result) {
                error_log("Successfully sent booking to admin: " . json_encode($admin_result));
            } else {
                error_log("Failed to send booking to admin for chat_id: $chat_id");
            }

            // Сбрасываем состояние
            save_user_state($chat_id, ['state' => 'normal', 'data' => []]);

            return "Отлично! ✅ Ваша заявка принята. Сергей свяжется с вами в ближайшее время для организации показа.\n\nСпасибо за обращение! 🏠";
    }
    
    error_log("Unknown booking state: $state");
    return "Что-то пошло не так. Давайте начнем сначала - напишите «запись на показ».";
}

// ====== ПОЛУЧАЕМ КВАРТИРЫ ======
$apartments = get_apartments_from_sheets();

if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $user_message = trim($update["message"]["text"] ?? "");
    $user_first_name = $update["message"]["from"]["first_name"] ?? "";
    $user_id = $update["message"]["from"]["id"];
    $username = $update["message"]["from"]["username"] ?? "нет_username";

    error_log("Processing message from user $user_first_name ($user_id): $user_message");

    // ====== УЛУЧШЕННАЯ "ЖИВАЯ" ВЕТКА ======
    $custom_state_file = __DIR__ . "/custom_state_{$chat_id}.json";
    $custom_state = file_exists($custom_state_file) ? json_decode(file_get_contents($custom_state_file), true) : ["step" => 0, "data" => []];

    // /start всегда сбрасывает живой сценарий
    if (trim(strtolower($user_message)) === '/start') {
        file_put_contents($custom_state_file, json_encode(["step" => 0, "data" => []]));
    }

    // Проверяем, не в процессе ли записи на показ
    $user_state = get_user_state($chat_id);
    
    // Ключевые слова для записи на показ
    $booking_keywords = ['показ', 'запись', 'записаться', 'хочу посмотреть', 'онлайн показ', 'онлайн-показ', 'встретиться', 'встреча'];
    $message_lower = mb_strtolower($user_message);
    
    $is_booking_request = false;
    foreach ($booking_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            $is_booking_request = true;
            break;
        }
    }

    // ОБРАБОТКА ЗАПИСИ НА ПОКАЗ (приоритет!)
    if ($user_state['state'] !== 'normal') {
        error_log("User in booking process, state: " . $user_state['state']);
        $response = handle_booking_process($chat_id, $user_message, $user_state, $user_first_name, $username, $token, $admin_chat_id);
        send_telegram_message($token, $chat_id, $response);
        exit;
    }

    // НАЧАЛО ЗАПИСИ НА ПОКАЗ
    if ($is_booking_request) {
        error_log("Starting booking process for user $user_id");
        // Сохраняем данные из живой ветки, если есть
        $booking_data = [];
        if (!empty($custom_state['data']['motivation'])) {
            $booking_data['motivation'] = $custom_state['data']['motivation'];
        }
        if (!empty($custom_state['data']['district'])) {
            $booking_data['district'] = $custom_state['data']['district'];
        }
        
        // Сбрасываем живую ветку
        file_put_contents($custom_state_file, json_encode(["step" => 0, "data" => []]));
        
        save_user_state($chat_id, ['state' => 'booking_time', 'data' => $booking_data]);
        send_telegram_message($token, $chat_id, "Отлично! Давайте запишем вас на показ квартир. Укажите удобное время (день и время):");
        exit;
    }

    // ЖИВАЯ ВЕТКА (только если НЕ в процессе записи)
    if ($user_state['state'] === 'normal') {
        if ($custom_state["step"] === 0) {
            $custom_state["step"] = 1;
            file_put_contents($custom_state_file, json_encode($custom_state));
            send_telegram_message($token, $chat_id, "Привет, $user_first_name! Подскажи, для чего смотришь недвижимость в Батуми? (Жить, инвестиции, отдых, другое)");
            exit;
        }
        
        if ($custom_state["step"] === 1) {
            $custom_state["data"]["motivation"] = $user_message;
            $custom_state["step"] = 2;
            file_put_contents($custom_state_file, json_encode($custom_state));
            send_telegram_message($token, $chat_id, "Спасибо! А какой район интересен? (Махинджаури, Новый Бульвар, Старый город, или свой вариант)");
            exit;
        }
        
        if ($custom_state["step"] === 2) {
            $custom_state["data"]["district"] = $user_message;
            $custom_state["step"] = 3;
            file_put_contents($custom_state_file, json_encode($custom_state));
            $district = mb_strtolower($custom_state["data"]["district"]);
            $examples = [
                "махинджаури" => "— Студия 29 м² у моря — \$32,800\n— 1+1, 42 м² — \$53,000\n",
                "новый бульвар" => "— Студия 35 м² — \$39,500\n— 1+1, 50 м² — \$56,000\n",
                "старый город" => "— Студия 28 м² — \$44,000\n— 1+1, 41 м² — \$59,500\n"
            ];
            $answer = $examples[$district] ?? "— Студии от \$32,000, 1+1 от \$50,000 (есть во всех районах)";
            send_telegram_message($token, $chat_id, "Вот примеры:\n$answer\n\nХочешь увидеть квартиры по видеосвязи? Напиши «онлайн-показ» — это бесплатно, покажу всё вживую! 📱");
            exit;
        }
        
        if ($custom_state["step"] === 3) {
            if (mb_stripos($user_message, 'показ') !== false || mb_stripos($user_message, 'запис') !== false) {
                // Переходим к записи на показ
                $booking_data = [
                    'motivation' => $custom_state["data"]["motivation"] ?? '',
                    'district' => $custom_state["data"]["district"] ?? ''
                ];
                file_put_contents($custom_state_file, json_encode(["step" => 0, "data" => []]));
                save_user_state($chat_id, ['state' => 'booking_time', 'data' => $booking_data]);
                send_telegram_message($token, $chat_id, "Отлично! Давайте согласуем время для онлайн-показа. Укажите удобное время:");
                exit;
            } else {
                send_telegram_message($token, $chat_id, "Если интересно посмотреть — напиши «онлайн-показ». Или уточни: бюджет, этаж, вид — помогу подобрать под тебя.");
                exit;
            }
        }
    }

    // ====== ПРОВЕРКА ПОДПИСКИ ======
    $last_check = get_last_subscription_check($chat_id);
    $current_time = time();

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

    // ====== ПОЛУЧАЕМ ИСТОРИЮ ЧАТА ======
    $history = get_chat_history($chat_id);
    
    // Очищаем историю при команде /start
    if (trim(strtolower($user_message)) === '/start') {
        $history = [];
        save_chat_history($chat_id, []);
        $is_first_message = true;
        error_log("Chat history cleared for /start command");
    } else {
        // Определяем, является ли это первым обращением (если история пустая)
        $is_first_message = empty($history);
    }

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
    $base_stats = "В базе сейчас " . count($apartments) . " квартир, из них студий — $studio_count, цены студий: от \$studio_min_price до \$studio_max_price.";

    // ====== ФОРМИРУЕМ БАЗУ ДЛЯ ПРОМПТА ======
    $base_info = "";
    foreach ($apartments as $a) {
        $base_info .= "ЖК: {$a['жк']}, Этаж: {$a['этаж']}, №: {$a['номер']}, Площадь: {$a['площадь']} м², Вид: {$a['вид']}, Цена/м²: \${$a['цена_м2']}, Всего: \${$a['общая_сумма']}, Статус: {$a['статус']}\n";
    }

    // ====== УЛУЧШЕННЫЙ SYSTEM PROMPT ======
    $greeting_instruction = $is_first_message ? 
        "ВАЖНО: Это первое сообщение от пользователя. Поздоровайся с ним по имени и СРАЗУ спроси про район интересов." : 
        "КРИТИЧЕСКИ ВАЖНО: Пользователь уже общался с тобой ранее. КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО здороваться повторно! НЕ говори 'Привет', 'Рад тебя видеть' и подобное. Продолжай диалог сразу по существу, отвечая на его вопрос.";

    $messages = [
        [
            "role" => "system",
            "content" =>
"Ты общаешься с пользователем по имени $user_first_name. Всегда обращайся к нему по этому имени — без изменений, переводов и русификаций. 

$greeting_instruction

Ты умный, дерзкий и харизматичный AI-консультант по недвижимости в Батуми. 
Тебя создал Сергей Корнаухов - брокер по недвижимости, предприниматель. Общайся естественно и живо, будь полезным помощником.

ВАЖНО: НИКОГДА не показывай весь список квартир сразу! СНАЧАЛА выясни потребности клиента:
- Какой район интересует?
- Какой бюджет?
- Сколько комнат нужно?
- Рассрочка или сразу?

После каждого ответа показывай 1-3 подходящих варианта.

ВАЖНО: В конце каждого сообщения с вариантами квартир добавляй фразу: 
'Хотите записаться на показ? Просто напишите «хочу посмотреть» или «запись на показ»!'

Твоя специализация — подбор недвижимости по районам:
— Махинджаури: ЖК Thalassa Group, Next Collection, Kolos, A Sector, Mziuri.
— Новый Бульвар: Ande Metropolis, Summer365, Real Palace Blue, Symbol, Artex, SkuLuxe.
— Старый город: Modern Ultra.

**Акционные квартиры (№319, 412, 514) — объясни две опции:**
1. Обычная цена + рассрочка до 18 мес. при 30% взносе:
   — №319: \$67,000  
   — №412: \$55,330  
   — №514: \$40,040
2. Акционная цена только при полной оплате одним платежом (без рассрочки):
   — №319: \$54,080  
   — №412: \$44,264  
   — №514: \$32,832

Твой подход к общению:
— Задавай ТОЛЬКО ОДИН вопрос за раз
— Выясняй потребности ПОЭТАПНО: сначала район, потом бюджет, потом комнаты
— Говори кратко, с энтузиазмом, без повторов
— Будь естественным консультантом, а не допрашивающим роботом

Формат квартир по площади:
— до 37 м² — студия
— 37–55 м² — 1+1
— 55–80 м² — 2+1  
— >80 м² — 3+1

Основные условия:
— первый взнос от 20%, рассрочка без процентов до 10 мес
— акционные варианты — до 18 мес при 30% взносе
— оплата на счёт застройщика
— бронь 2 недели \$100, задаток \$1000 на месяц

Если спросят про дом Thalassa: 'ЖК Thalassa Group — газ, бассейн, спортзал, сдача в этом году, 135 квартир, надёжный застройщик.'

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
