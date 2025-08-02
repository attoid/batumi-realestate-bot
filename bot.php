<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$token = getenv('TELEGRAM_TOKEN');
$openai_key = getenv('OPENAI_API_KEY');

$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Функция общения с OpenAI с логированием ответа
function ask_gpt($prompt, $openai_key) {
    $data = [
        "model" => "gpt-3.5-turbo",
        "messages" => [
            ["role" => "system", "content" => "Ты профессиональный агент по недвижимости в Батуми. Отвечай просто, конкретно, по делу, как опытный консультант."],
            ["role" => "user", "content" => $prompt]
        ],
        "max_tokens" => 400,
        "temperature" => 0.4
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

    // Логируем ответ OpenAI для отладки
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $result . PHP_EOL, FILE_APPEND);

    curl_close($ch);
    $response = json_decode($result, true);
    return $response['choices'][0]['message']['content'] ?? "Извините, не удалось получить ответ от ИИ.";
}

// ОБРАБОТКА СООБЩЕНИЙ
if(isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $text = trim($update["message"]["text"]);

    if ($text == '/start') {
        $reply = "Здравствуйте! Я — Сергей Корнаухов, ваш агент по недвижимости в Батуми. Задайте вопрос или выберите интересующее направление:";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Квартира', 'callback_data' => 'type_apartment'],
                    ['text' => 'Апартаменты', 'callback_data' => 'type_aparthotel'],
                ],
                [
                    ['text' => 'Рассрочка', 'callback_data' => 'installment'],
                    ['text' => 'Инвестиции', 'callback_data' => 'investment'],
                ]
            ]
        ];
        $data = [
            'chat_id' => $chat_id,
            'text' => $reply,
            'reply_markup' => json_encode($keyboard)
        ];
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query($data));
    } else {
        $answer = ask_gpt($text, $openai_key);
        $data = [
            'chat_id' => $chat_id,
            'text' => $answer
        ];
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query($data));
    }
}

// ОБРАБОТКА КНОПОК
if (isset($update["callback_query"])) {
    $chat_id = $update["callback_query"]["message"]["chat"]["id"];
    $data = $update["callback_query"]["data"];
    if ($data == 'type_apartment') {
        $text = "Вы выбрали: Квартира. Расскажите, какой район или бюджет интересует?";
    } elseif ($data == 'type_aparthotel') {
        $text = "Вы выбрали: Апартаменты. Готов рассказать про лучшие предложения!";
    } elseif ($data == 'installment') {
        $text = "Рассрочка: расскажу все нюансы, напишите желаемый первый взнос или срок.";
    } elseif ($data == 'investment') {
        $text = "Инвестиции: могу подобрать объекты с высокой доходностью.";
    } else {
        $text = "Выберите интересующий пункт:";
    }
    $params = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query($params));
}
?>
