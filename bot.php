<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$openai_key = getenv('OPENAI_API_KEY');
$token = getenv('TELEGRAM_TOKEN');

// ====== ФУНКЦИЯ ПОЛУЧЕНИЯ ДАННЫХ ИЗ GOOGLE SHEETS ======
function get_apartments_from_sheets() {
    // Ваша ссылка преобразованная в CSV формат
    $sheet_url = "https://docs.google.com/spreadsheets/d/e/2PACX-1vSiwJ2LTzkOdpQNfqNBnxz0SGcHPz36HHm6voblS_2SdAK2H5oO1-xbZt1yF3-Y-YlPiKIN5CAxZpVh/pub?output=csv";

    // Кэширование на 5 минут для оптимизации
    $cache_file = __DIR__ . '/cache/apartments.json';
    $cache_time = 300; // 5 минут

    // Проверяем кэш
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
        return []; // Возвращаем пустой массив, если ничего не доступно
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
    for ($i = 4; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if (empty($line)) continue;
        $data = str_getcsv($line);

        // Адаптировано: поддержка новой колонки "ЖК"
        if (count($data) < 7) continue; // Нужно хотя бы 7 колонок: этаж, номер, площадь, вид, цена, сумма, жк

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
// ... (без изменений, см. твой код выше)

// ====== ОСНОВНОЙ КОД ======
// ... (всё по твоей схеме, только в формировании базы выводим ЖК)

$base_info = "";
foreach ($apartments as $a) {
    $base_info .= "ЖК: {$a['жк']}, Этаж: {$a['этаж']}, №: {$a['номер']}, Площадь: {$a['площадь']} м², Вид: {$a['вид']}, Цена/м²: \${$a['цена_м2']}, Всего: \${$a['общая_сумма']}, Статус: {$a['статус']}\n";
}


    // ====== SYSTEM PROMPT (ОБНОВЛЕННЫЙ) ======
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

Если клиент спрашивает про район, расскажи чем районы отличаются и предложи варианты по этим объектам. По Махинджаури — акцент на Thalassa Group.

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
