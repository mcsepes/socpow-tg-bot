<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$config = $GLOBALS['config'];

$secretHeader = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secretHeader !== $config['WEBHOOK_SECRET']) {
    http_response_code(403);
    exit('Forbidden');
}

$input  = file_get_contents('php://input');
$update = json_decode($input, true);
if (!is_array($update) || !isset($update['message'])) {
    exit;
}

processMessage($update['message']);

function processMessage(array $message): void
{
    $chatId = (int)$message['chat']['id'];
    $text   = isset($message['text']) ? trim($message['text']) : null;

    if ($text === '/start') {
        registerUser($chatId);
        sendMessage($chatId, $GLOBALS['config']['WELCOME_MESSAGE']);
        return;
    }

    if ($text === '/broadcast' && isAdmin($chatId)) {
        ensurePendingBroadcast($chatId);
        sendMessage($chatId, 'Введите текст рассылки:');
        return;
    }

    if (isAdmin($chatId) && handleBroadcastText($chatId, $text)) {
        return;
    }
}

function registerUser(int $chatId): void
{
    $db = getDb();
    $stmt = $db->prepare('INSERT IGNORE INTO users (chat_id) VALUES (:chat_id)');
    $stmt->execute(['chat_id' => $chatId]);
}

function ensurePendingBroadcast(int $adminId): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id FROM broadcasts WHERE admin_id = :admin_id AND status = "pending_text" ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['admin_id' => $adminId]);
    $row = $stmt->fetch();
    if (!$row) {
        $ins = $db->prepare('INSERT INTO broadcasts (admin_id, updated_at) VALUES (:admin_id, NOW())');
        $ins->execute(['admin_id' => $adminId]);
    }
}

function handleBroadcastText(int $adminId, ?string $text): bool
{
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT id FROM broadcasts
         WHERE admin_id = :admin_id AND status = 'pending_text'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['admin_id' => $adminId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    if ($text === null || $text === '') {
        sendMessage($adminId, 'Пожалуйста, отправьте текстовое сообщение для рассылки.');
        return true;
    }

    $broadcastId = (int)$row['id'];
    $upd = $db->prepare(
        "UPDATE broadcasts
         SET text = :text, status = 'sending', updated_at = NOW()
         WHERE id = :id"
    );
    $upd->execute(['text' => $text, 'id' => $broadcastId]);
    sendMessage($adminId, 'Текст рассылки сохранён. Запуск через cron.');
    return true;
}
