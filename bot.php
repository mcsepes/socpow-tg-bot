<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$config = $GLOBALS['config'];

$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secretHeader !== $config['WEBHOOK_SECRET']) {
    http_response_code(403);
    exit('Forbidden');
}

$input = file_get_contents('php://input');
$update = json_decode($input, true);
if (!is_array($update) || !isset($update['message'])) {
    exit;
}

$message = $update['message'];
$chatId  = (int)$message['chat']['id'];
$text    = isset($message['text']) ? trim($message['text']) : null;

$db = getDb();

// Обработка команды /start
if ($text === '/start') {
    $stmt = $db->prepare('INSERT IGNORE INTO users (chat_id) VALUES (:chat_id)');
    $stmt->execute(['chat_id' => $chatId]);
    sendMessage($chatId, "Добро пожаловать! Вы успешно подписались на рассылку.");
    exit;
}

// Если админ начал /broadcast
if ($text === '/broadcast' && isAdmin($chatId)) {
    $stmt = $db->prepare('INSERT INTO broadcasts (admin_id) VALUES (:admin_id)');
    $stmt->execute(['admin_id' => $chatId]);
    sendMessage($chatId, "Введите текст рассылки:");
    exit;
}

// Если админ вводит текст рассылки для последнего pending_text
if (isAdmin($chatId)) {
    $stmt = $db->prepare("
        SELECT id FROM broadcasts
        WHERE admin_id = :admin_id AND status = 'pending_text'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute(['admin_id' => $chatId]);
    $row = $stmt->fetch();
    if ($row) {
        if ($text === null || $text === '') {
            sendMessage($chatId, 'Пожалуйста, отправьте текстовое сообщение для рассылки.');
            exit;
        }
        $broadcastId = (int)$row['id'];
        $upd = $db->prepare("
            UPDATE broadcasts
            SET text = :text, status = 'sending'
            WHERE id = :id
        ");
        $upd->execute(['text' => $text, 'id' => $broadcastId]);
        sendMessage($chatId, "Текст рассылки сохранён. Запуск через cron.");
        exit;
    }
}

// Иначе — ничего не делаем
