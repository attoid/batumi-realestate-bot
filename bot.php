<?php
file_put_contents(__DIR__.'/test.log', date('c')." BOT.PHP ЗАПУСТИЛСЯ\n", FILE_APPEND);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "Бот работает!";
    exit;
}
echo "POST-запрос!";
?>
