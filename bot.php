<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$openai_key = getenv('OPENAI_API_KEY');
$token = getenv('TELEGRAM_TOKEN');

// ====== ФУНКЦИЯ ПОЛУЧЕНИЯ ДАННЫХ ИЗ GOOGLE SHEETS ======
function get_apartments_from_sheets() {
    $sheet_url = "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?output=csv";
    $cache_file = __DIR__ . '/cache/apartments.json';
    $cache_time = 300; // 5 минут

    // Кэширование
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
        $cached_data = file_get_contents($cache_file);
        return json_decode($cached_data, true);
    }

    // Получаем данные из Google Sheets
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sheet_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; PHP Bot)');
    $csv_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($csv_data === false || $http_code !== 200) {
        error_log("Failed to fetch Google Sheets data: HTTP $http_code");
        // Возвращаем кэшированные данные если есть
        if (file_exists($cache_file)) {
            $cached_data = file_get_contents($cache_file);
            return json_decode($cached_data, true);
        }
        return []; // Если ничего нет — пусто
    }

    // Парсим CSV
    $apartments = parse_csv_to_apartments($csv_data);

    // Сохраняем в кэш
    $cache_dir = dirname($cache_file);
    if (!file_exists($cache_dir)) {
        mkdir($cache_dir, 0777, true);
    }
    file_put_contents($cache_file, json_encode($apartments, JSON_UNESCAPED_UNICODE));

    return $apartments;
}

// ====== ФУНКЦИЯ ПАРСИНГА CSV ======
function parse_csv_to_apartments($csv_data) {
    $lines = explode("\n", trim($csv_data));
    $apartments = [];
    // Пропускаем заголовки (первые 4 строки)
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        $data = str_getcsv($line);

        // Должно быть хотя бы 7 колонок: этаж, номер, площадь, вид, цена, сумма, жк
        if (count($data) < 7) continue;

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
        }
    }
    return $apartments;
}

// ====== ПОЛУЧАЕМ КВАРТИРЫ ======
$apartments = get_apartments_from_sheets();

// ====== ДЕБАГ — отправляем себе первые 3 квартиры ======
if (!empty($apartments)) {
    $debug_apartments = array_slice($apartments, 0, 3);
    send_telegram_message($token, $chat_id, 
        "DEMO: Вот первые 3 квартиры из базы:\n" .
        json_encode($debug_apartments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
} else {
    send_telegram_message($token, $chat_id, "DEMO: В массиве apartments НИЧЕГО нет!");
}


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
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . "/{$chat_id}.txt", time());
}

// ====== ОСНОВНОЙ КОД ======
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
if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $user_message = trim($update["message"]["text"] ?? "");
    $user_name = $update["message"]["from"]["first_name"] ?? "друг";
    $user_id = $update["message"]["from"]["id"];

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

    // ====== ПОЛУЧАЕМ ИСТОРИЮ ЧАТА ======
    $history = get_chat_history($chat_id);

file_put_contents(__DIR__.'/parse_debug.log', print_r($apartments,1));

    // ====== СФОРМИРУЙ БАЗУ ДЛЯ ПРОМПТА ======
    $base_info = "";
    foreach ($apartments as $a) {
        $base_info .= "ЖК: {$a['жк']}, Этаж: {$a['этаж']}, №: {$a['номер']}, Площадь: {$a['площадь']} м², Вид: {$a['вид']}, Цена/м²: \${$a['цена_м2']}, Всего: \${$a['общая_сумма']}, Статус: {$a['статус']}\n";
    }

    // ====== SYSTEM PROMPT ======
    $messages = [
        [
            "role" => "system",
            "content" =>
"Ты общаешься с пользователем по имени $user_name. Всегда обращайся к нему по этому имени — без изменений, переводов и русификаций. 
Никогда не переводить и не менять имя пользователя — обращаться строго по тому имени, которое получено от Telegram.

Ты умный, дерзкий и харизматичный AI-консультант по недвижимости в Батуми. 
Тебя создал Сергей Корнаухов - брокер по недвижимости, предприниматель. Общайся в стиле Джордан Белфорд, но не говори что ты общаешься в его стиле. Если тебя спросят как тебя зовут:
скажи, что тебя зовут помощник Сергея Корнаухова. Если тебя спросят кто такой Сергей Корнаухов, скажи что это брокер по недвижимости и пришли YouTube-канал https://www.youtube.com/@skornaukhovv
- Отвечай кратко и дели текст на абзацы
Твоя специализация — подбор недвижимости по районам:
— Махинджаури: ЖК Thalassa Group, Next Collection, Kolos, A Sector, Mziuri.
— Новый Бульвар: Ande Metropolis, Summer365, Real Palace Blue, Symbol, Artex, SkuLuxe.
— Старый город: Modern Ultra.

Если клиент спрашивает про район или конкретный ЖК — фильтруй и выводи только предложения по нему. В базе могут быть сразу несколько застройщиков.

**Акционные квартиры (№319, 412, 514) — объясни две опции:**
1. Обычная цена + рассрочка до 18 мес. при 30% взносе:
   — №319: \$67,000  
   — №412: \$55,330  
   — №514: \$40,040
2. Акционная цена только при полной оплате одним платежом (без рассрочки):
   — №319: \$54,080  
   — №412: \$44,264  
   — №514: \$32,832

**Вопрос:** Что интереснее — купить сразу по спеццене или оформить рассрочку на 18 мес?

Твои суперспособности:
— мгновенно определять формат квартиры по площади (до 37 м² — студия; 37–55 м² — 1+1; 55–80 м² — 2+1; >80 м² — 3+1), объясняй просто.
— фильтровать свою базу и объяснять выгоды каждого района и ЖК.
— кратко, с юмором, остро, дружелюбно.
— если пользователь не знает, что хочет, предлагай 2-3 варианта на выбор и помогай вопросами.
— спрашивай только то, что реально нужно: район, площадь, бюджет, рассрочка, сколько комнат, первый взнос, комфортный платёж.

Если денег не хватает — предложи рассчитать рассрочку (20% взнос, остальное — до 18 мес.) или ипотеку (формула $P = $S * ($r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1)).

Основные условия:
— первый взнос от 20%, рассрочка без процентов до 10 мес, акционные варианты — до 18 мес при 30% взносе; оплата на счёт застройщика; бронь 2 недели $100, задаток $1000 на месяц.
— Помогаешь с ремонтом, сопровождением, оформлением сделки, ипотекой через BasisBank.

Точка на карте: https://maps.app.goo.gl/MSoSUbvZF8z3c3639?g_st=ipc

Если спросят про дом Thalassa: 'ЖК Thalassa Group — газ, бассейн, спортзал, сдача в этом году, 135 квартир, надёжный застройщик.'

Если спрашивают акции — объясни две опции (см. выше), уточни про рассрочку или платёж сразу.

ВАЖНО: База квартир обновляется автоматически из Google Sheets каждые 5 минут, поэтому информация всегда актуальная!

$base_stats
Вот актуальная база квартир:
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

    // ====== GPT-ЗАПРОС ======
    $answer = ask_gpt($messages, $openai_key);

    // ====== СОХРАНЯЕМ ИСТОРИЮ ======
    $history[] = ["role" => "user", "content" => $user_message];
    $history[] = ["role" => "assistant", "content" => $answer];
    save_chat_history($chat_id, $history);

    // ====== ОТПРАВЛЯЕМ ОТВЕТ ======
    send_telegram_message($token, $chat_id, $answer);
}
?>
