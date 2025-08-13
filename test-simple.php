<?php
echo "Test 1: PHP works<br>";

echo "Test 2: ENV check<br>";
echo "TELEGRAM_TOKEN via ENV: " . (isset($_ENV['TELEGRAM_TOKEN']) ? 'FOUND' : 'NOT FOUND') . "<br>";
echo "OPENAI_API_KEY via ENV: " . (isset($_ENV['OPENAI_API_KEY']) ? 'FOUND' : 'NOT FOUND') . "<br>";

echo "Test 3: getenv check<br>";
echo "TELEGRAM_TOKEN via getenv: " . (getenv('TELEGRAM_TOKEN') ? 'FOUND' : 'NOT FOUND') . "<br>";
echo "OPENAI_API_KEY via getenv: " . (getenv('OPENAI_API_KEY') ? 'FOUND' : 'NOT FOUND') . "<br>";

echo "Test 4: Request method<br>";
echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "<br>";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "Test 5: GET request detected - Bot should work!<br>";
}

echo "<br>All tests completed!";
?>
