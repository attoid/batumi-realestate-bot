<?php
// ====== ТВОЯ БАЗА КВАРТИР ======
$apartments = [
    [
        'этаж' => -2, 'номер' => '009', 'площадь' => 38.0, 'вид' => 'Море',
        'цена_м2' => 1200, 'общая_сумма' => 52800, 'статус' => 'Свободный'
    ],
    [
        'этаж' => -1, 'номер' => '101', 'площадь' => 105.8, 'вид' => 'Море',
        'цена_м2' => 1200, 'общая_сумма' => 155875, 'статус' => 'Свободный'
    ],
    [
        'этаж' => -1, 'номер' => '102', 'площадь' => 48.6, 'вид' => 'Море',
        'цена_м2' => 1200, 'общая_сумма' => 58440, 'статус' => 'Свободный'
    ],
    [
        'этаж' => -1, 'номер' => '104', 'площадь' => 47.6, 'вид' => 'Море',
        'цена_м2' => 1200, 'общая_сумма' => 57120, 'статус' => 'Свободный'
    ],
    [
        'этаж' => 1, 'номер' => '201', 'площадь' => 67.8, 'вид' => 'Море',
        'цена_м2' => 1250, 'общая_сумма' => 84750, 'статус' => 'Свободный'
    ],
    [
        'этаж' => 1, 'номер' => '202', 'площадь' => 45.2, 'вид' => 'Море',
        'цена_м2' => 1250, 'общая_сумма' => 56500, 'статус' => 'Свободный'
    ],
    // ...Добавь остальные квартиры!
];

// ====== ФУНКЦИЯ ФИЛЬТРАЦИИ ======
function searchApartments($params, $apartments) {
    $offers = [];
    foreach ($apartments as $apt) {
        if ($apt['статус'] !== 'Свободный') continue;
        if (isset($params['view']) && mb_strtolower($params['view']) !== mb_strtolower($apt['вид'])) continue;
        if (isset($params['area_min']) && $apt['площадь'] < $params['area_min']) continue;
        if (isset($params['area_max']) && $apt['площадь'] > $params['area_max']) continue;
        $offers[] = $apt;
    }
    return $offers;
}

// ====== ФУНКЦИИ ДЛЯ СОСТОЯНИЯ ПОЛЬЗОВАТЕЛЯ ======
function getUserState($chat_id) {
    $file = __DIR__ . "/user_states/{$chat_id}.json";
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true);
}
function saveUserState($chat_id, $state) {
    if (!file_exists(__DIR__ . '/user_states')) mkdir(__DIR__ . '/user_states');
    file_put_contents(__DIR__ . "/user_states/{$chat_id}.json", json_encode($state));
}

// ====== ОСНОВНОЙ КОД ======
$token = getenv('TELEGRAM_TOKEN');
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    $text = trim($update["message"]["text"]);
    $user_state = getUserState($chat_id);

    if ($text == '/start' || empty($user_state)) {
        $user_state = ['step' => 1];
        saveUserState($chat_id, $user_state);
        $reply = "Привет! Это Сергей Корнаухов, я брокер по недвижимости в Батуми. Давай подберем тебе лучшую квартиру! Для себя или для сдачи?";
    } else {
        switch ($user_state['step']) {
            case 1:
                $user_state['purpose'] = $text;
                $reply = "Какой вид интересует — море или город?";
                $user_state['step'] = 2;
                break;
            case 2:
                $user_state['view'] = $text;
                $reply = "Минимальная площадь? (например: 30)";
                $user_state['step'] = 3;
                break;
            case 3:
                $user_state['area_min'] = (float)$text;
                $reply = "Максимальная площадь?";
                $user_state['step'] = 4;
                break;
            case 4:
                $user_state['area_max'] = (float)$text;
                $offers = searchApartments($user_state, $apartments);
                if (count($offers) === 0) {
                    $reply = "К сожалению, подходящих вариантов нет. Могу подобрать что-то индивидуально, напишите ваши пожелания!";
                } else {
                    $reply = "Вот подходящие варианты:\n";
                    foreach (array_slice($offers, 0, 3) as $of) {
                        $reply .= "Этаж: {$of['этаж']}, №: {$of['номер']}, Площадь: {$of['площадь']} м², Вид: {$of['вид']}, Цена/м²: \${$of['цена_м2']}, Всего: \${$of['общая_сумма']}\n";
                    }
                }
                $user_state = []; // Сбросить состояние для нового запроса
                break;
        }
        saveUserState($chat_id, $user_state);
    }
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text' => $reply
    ]));
}
