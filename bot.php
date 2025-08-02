<?php

$openai_key = getenv('OPENAI_API_KEY');
$token = getenv('TELEGRAM_TOKEN');

// ====== БАЗА КВАРТИР ======
$apartments = [ /* твой массив как есть */ ];

// Получить историю чата пользователя
function get_chat_history($chat_id) {
    $file = __DIR__ . "/history/{$chat_id}.json";
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true);
}

// Сохранить историю чата пользователя
function save_chat_history($chat_id, $history) {
    if (!file_exists(__DIR__ . '/history')) mkdir(__DIR__ . '/history', 0777, true);
    file_put_contents(__DIR__ . "/history/{$chat_id}.json", json_encode($history, JSON_UNESCAPED_UNICODE));
}

// GPT функция: принимает МАССИВ сообщений!
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

    // 1. Получаем историю
    $history = get_chat_history($chat_id);

    // 2. Формируем system-промпт и (по желанию) добавляем БАЗУ квартир:
    $base_info = "";
    foreach ($apartments as $a) {
        $base_info .= "Этаж: {$a['этаж']}, №: {$a['номер']}, Площадь: {$a['площадь']} м², Вид: {$a['вид']}, Цена/м²: \${$a['цена_м2']}, Всего: \${$a['общая_сумма']}, Статус: {$a['статус']}\n";
    }
    $messages = [
        [
            "role" => "system",
            "content" =>
"Ты бизнес-ассистент Сергея Корнаухова, 29 лет, брокер по недвижимости в Батуми с ноября 2023 года. Всегда отвечай коротко, дружелюбно, не повторяйся. Шути, если пользователь пишет ерунду. Не здоровайся дважды. Спрашивай только то, что нужно для выбора квартиры: площадь, бюджет, район, нужна ли рассрочка, сколько комнат. 
Вот база квартир:\n$base_info"
        ]
    ];

    // 3. Добавляем историю (user/assistant)
    foreach ($history as $msg) {
        $messages[] = $msg;
    }

    // 4. Добавляем новое сообщение пользователя
    $messages[] = ["role" => "user", "content" => $user_message];

    // 5. GPT-запрос
    $answer = ask_gpt($messages, $openai_key);

    // 6. Сохраняем историю
    $history[] = ["role" => "user", "content" => $user_message];
    $history[] = ["role" => "assistant", "content" => $answer];
    save_chat_history($chat_id, $history);

    // 7. Ответ пользователю
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text' => $answer
    ]));
}
