<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$config = $GLOBALS['config'];

$token  = $config['BOT_TOKEN'];
$url    = urlencode($config['WEBHOOK_URL']);
$secret = urlencode($config['WEBHOOK_SECRET']);

$apiUrl = "https://api.telegram.org/bot{$token}/setWebhook?url={$url}&secret_token={$secret}";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$error    = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo "Ошибка при установке webhook: {$error}\n";
} else {
    echo "Ответ Telegram: {$response}\n";
}
