<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$GLOBALS['config'] = $config;

/**
 * Получаем PDO соединение
 */
function getDb(): \PDO
{
    static $pdo;
    if ($pdo === null) {
        $db = $GLOBALS['config']['DB'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['HOST'], $db['PORT'], $db['NAME'], $db['CHARSET']
        );
        $pdo = new \PDO($dsn, $db['USER'], $db['PASSWORD'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/**
 * Отправка сообщения через Telegram Bot API
 */
function sendMessage(int $chatId, string $text, ?string $parseMode = null): array
{
    $token = $GLOBALS['config']['BOT_TOKEN'];
    $url   = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = [
        'chat_id' => $chatId,
        'text'    => $text,
    ];
    if ($parseMode !== null) {
        $payload['parse_mode'] = $parseMode;
    }
    $payload = json_encode($payload);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,       true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error_code' => 0, 'description' => $err];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error_code' => 0, 'description' => 'Invalid response'];
    }
    $data['description'] = $data['description'] ?? null;
    $data['error_code']  = $data['error_code'] ?? 0;
    $data['parameters']  = $data['parameters'] ?? [];
    return $data;
}

/**
 * Проверка, является ли пользователь админом
 */
function isAdmin(int $chatId): bool
{
    return in_array($chatId, $GLOBALS['config']['ADMINS'], true);
}
