<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$openai_key = getenv('OPENAI_API_KEY');
$token = getenv('TELEGRAM_TOKEN');

// ====== БАЗА КВАРТИР ======
$apartments = [
    ['этаж' => 2, 'номер' => 311, 'площадь' => 106.1, 'вид' => 'Море&Горы', 'цена_м2' => 1400, 'общая_сумма' => 148540, 'статус' => 'Свободный'],
    // ... (оставь тут весь свой массив как есть)
];

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
    return json_decode(file_get_contents($file), true);
}
function save_chat_history($chat_id, $history) {
    if (!file_exists(__DIR__ . '/history')) mkdir(__DIR__ . '/history', 0777, true);
    file_put_contents(__DIR__ . "/history/{$chat_id}.json", json_encode($history, JSON_UNESCAPED_UNICODE));
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
    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($result, true);
    return $response['choices'][0]['message']['content'] ?? "Извините, не удалось получить ответ от ИИ.";
}

// ====== ОСНОВНОЙ КОД ======
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $user_message = trim($update["message"]["text"]);
    $user_name = $update["message"]["from"]["first_name"] ?? "";
    $user_id = $update["message"]["from"]["id"];

    // ====== ПРОВЕРКА ПОДПИСКИ ======
    $channel = "@smkornaukhovv";
    $check_url = "https://api.telegram.org/bot$token/getChatMember?chat_id=$channel&user_id=$user_id";
    $check_result = json_decode(file_get_contents($check_url), true);
    $is_member = false;
    if (isset($check_result["result"]["status"])) {
        $status = $check_result["result"]["status"];
        // 'member', 'creator', 'administrator' — подписан
        if (in_array($status, ["member", "administrator", "creator"])) {
            $is_member = true;
        }
    }
    if (!$is_member) {
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            'chat_id' => $chat_id,
            'text' => "Для продолжения подпишись на канал 👉 @smkornaukhovv, а потом нажми /start"
        ]));
        exit;
    }

    // ====== ПРИВЕТСТВИЕ (ТОЛЬКО ПРИ ПЕРВОМ ОБРАЩЕНИИ) ======
    $history = get_chat_history($chat_id);
    if (empty($history)) {
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            'chat_id' => $chat_id,
            'text' => "Привет, $user_name! Рад видеть тебя, сейчас найду лучший вариант квартиры под твои цели. 😉"
        ]));
    }

    // 2. Формируем инфу по базе
    $base_info = "";
    foreach ($apartments as $a) {
        $base_info .= "Этаж: {$a['этаж']}, №: {$a['номер']}, Площадь: {$a['площадь']} м², Вид: {$a['вид']}, Цена/м²: \${$a['цена_м2']}, Всего: \${$a['общая_сумма']}, Статус: {$a['статус']}\n";
    }

    // 3. System-промпт — СТИЛЬ, статистика, БАЗА!
    $messages = [
        [
            "role" => "system",
            "content" =>
"Ты — умный, дерзкий и харизматичный AI-консультант Сергея Корнаухова, 29 лет, брокера по недвижимости в Батуми (опыт с ноября 2023).
Ты ассистент по подбору недвижимости, твоя база — ЖК Thalassa Group: дом с газом, бассейном и спортзалом, всего 135 квартир, сдача в этом году. Подчёркивай преимущества, дай ссылку на видео https://www.youtube.com/watch?v=grVWx8pkhnE&t=235s если интересуются подробностями.
Твои суперспособности:
— мгновенно определять формат квартиры по площади (до 37 м² — студия; 37–55 м² — 1+1; 55–80 м² — 2+1; >80 м² — 3+1), объясняй просто.
— фильтровать свою базу и объяснять выгоды Thalassa.
— кратко, с юмором, остро, дружелюбно, но не повторяйся, не заискивай, не здороваешься дважды.
— если пользователь не знает, что хочет, предлагай 2-3 варианта на выбор и помогай вопросами.
— спрашивай только то, что реально нужно для идеального подбора: площадь, бюджет, рассрочка, сколько комнат, первый взнос, комфортный платёж.
— если пишут ерунду, отшутись и мягко переведи на тему квартир.
— если денег не хватает — предложи рассчитать рассрочку (20% взнос, остальное — до 18 мес.) или ипотеку (формула $P = $S * ($r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1)).
Основные условия:
— первый взнос от 20%, рассрочка без процентов до 10 мес, акционные варианты — до 18 мес при 30% взносе; оплата на счёт застройщика; бронь 2 недели $100, задаток $1000 на месяц.
— Помогаешь с ремонтом, сопровождением, оформлением сделки, ипотекой через BasisBank.
Точка на карте: https://maps.app.goo.gl/MSoSUbvZF8z3c3639?g_st=ipc
Если спросят про дом: 'ЖК Thalassa Group — газ, бассейн, спортзал, сдача в этом году, 135 квартир, надёжный застройщик.' 
Если спрашивают акции, выдай:
— Квартира №319, 67.6м², $800/м², итог $54,080 (без рассрочки), видео: https://www.youtube.com/watch?v=2_DDgp10Ci0
— Квартира №412, 50.3м², $880/м², $44,264 (без рассрочки), видео: https://www.youtube.com/watch?v=QrUalexCMXY
— Квартира №514, 34.2м², $960/м², $32,832 (без рассрочки), видео: https://www.youtube.com/watch?v=EvRhuGlvj08
Для рассрочки и акций — предлагай оба варианта. Объясняй выгоды кратко. Диалог строишь энергично, не отпускаешь пока не выяснишь ключевые параметры!\n"
. $base_stats . "\nВот база квартир:\n" . $base_info
            ]
    ];

    // 4. История чата (user/assistant)
    foreach ($history as $msg) {
        $messages[] = $msg;
    }

    // 5. Добавляем новое сообщение пользователя
    $messages[] = ["role" => "user", "content" => $user_message];

    // 6. GPT-запрос
    $answer = ask_gpt($messages, $openai_key);

    // 7. Сохраняем историю (добавили новые сообщения)
    $history[] = ["role" => "user", "content" => $user_message];
    $history[] = ["role" => "assistant", "content" => $answer];
    save_chat_history($chat_id, $history);

    // 8. Отправляем ответ пользователю
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text' => $answer
    ]));
}
