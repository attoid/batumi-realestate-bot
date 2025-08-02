<?php
$token = '8242406499:AAHpvI2DwNc1qMhw4qMb6NJ_vjebU1j230k';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $text = trim($update["message"]["text"] ?? '');

    // /start или любое приветствие — показываем кнопки
    if ($text == '/start' || $text == '/старт') {
        $reply = "Здравствуйте! 👋\n\nЯ — Сергей Корнаухов, ваш агент по недвижимости в Батуми.\n\nВыберите, что вас интересует:";
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
    }
}

// Ответ на нажатие кнопок
if (isset($update["callback_query"])) {
    $chat_id = $update["callback_query"]["message"]["chat"]["id"];
    $data = $update["callback_query"]["data"];

    // Логика ответов на кнопки
    if ($data == 'type_apartment') {
        $text = "Вы выбрали: Квартира.\n\nНапишите, какой район, площадь или бюджет интересует — подберу варианты.";
    } elseif ($data == 'type_aparthotel') {
        $text = "Вы выбрали: Апартаменты.\n\nГотов рассказать про лучшие предложения!";
    } elseif ($data == 'installment') {
        $text = "Рассрочка: расскажу все нюансы. Напишите желаемый первый взнос или срок.";
    } elseif ($data == 'investment') {
        $text = "Инвестиции: могу подобрать объекты с высокой доходностью.";
    } else {
        $text = "Выберите интересующий пункт:";
    }

    // Ответ пользователю
    $params = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query($params));
}
?>
