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
        registerUser($message);
        sendMessage($chatId, $GLOBALS['config']['WELCOME_MESSAGE']);
        return;
    }

    if (preg_match('~^/send\s+(\S+)~', (string)$text, $m) && isAdmin($chatId)) {
        $targetId = findUserChatId($m[1]);
        if ($targetId === null) {
            sendMessage($chatId, 'Пользователь не найден.');
        } else {
            ensurePendingDirect($chatId, $targetId);
            sendMessage($chatId, 'Введите текст сообщения:');
        }
        return;
    }

    if (preg_match('/^\/broadcast(?:\s+(\d+))?$/', (string)$text, $m) && isAdmin($chatId)) {
        $limit = isset($m[1]) ? (int)$m[1] : null;
        ensurePendingBroadcast($chatId, $limit);
        sendMessage($chatId, 'Введите текст рассылки:');
        return;
    }

    if ($text === '/subscribers' && isAdmin($chatId)) {
        $count = getSubscribersCount();
        sendMessage($chatId, 'Количество подписчиков: ' . $count);
        return;
    }

    if (isAdmin($chatId) && handleDirectText($chatId, $text)) {
        return;
    }

    if (isAdmin($chatId) && handleBroadcastText($chatId, $text)) {
        return;
    }
}

function registerUser(array $message): void
{
    $chatId  = (int)$message['chat']['id'];
    $userId  = isset($message['from']['id']) ? (int)$message['from']['id'] : $chatId;
    $username = $message['from']['username'] ?? null;

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO users (chat_id, user_id, username)
         VALUES (:chat_id, :user_id, :username)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), username = VALUES(username)'
    );
    $stmt->execute([
        'chat_id'  => $chatId,
        'user_id'  => $userId,
        'username' => $username,
    ]);
}

function ensurePendingBroadcast(int $adminId, ?int $max): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id FROM broadcasts WHERE admin_id = :admin_id AND status = "pending_text" ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['admin_id' => $adminId]);
    $row = $stmt->fetch();
    if (!$row) {
        $ins = $db->prepare('INSERT INTO broadcasts (admin_id, max_recipients, updated_at) VALUES (:admin_id, :max, NOW())');
        $ins->execute(['admin_id' => $adminId, 'max' => $max]);
    } elseif ($max !== null) {
        $upd = $db->prepare('UPDATE broadcasts SET max_recipients = :max WHERE id = :id');
        $upd->execute(['max' => $max, 'id' => $row['id']]);
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

function findUserChatId(string $ident): ?int
{
    $db = getDb();
    $ident = ltrim($ident, '@');
    $num   = ctype_digit($ident) ? (int)$ident : -1;
    $stmt = $db->prepare(
        'SELECT chat_id FROM users WHERE username = :u OR chat_id = :id OR user_id = :id LIMIT 1'
    );
    $stmt->execute(['u' => $ident, 'id' => $num]);
    $row = $stmt->fetch();
    return $row ? (int)$row['chat_id'] : null;
}

function ensurePendingDirect(int $adminId, int $chatId): void
{
    $db = getDb();
    $db->prepare('DELETE FROM direct_messages WHERE admin_id = :aid AND status = "pending_text"')
        ->execute(['aid' => $adminId]);
    $db->prepare('INSERT INTO direct_messages (admin_id, chat_id, updated_at) VALUES (:aid, :cid, NOW())')
        ->execute(['aid' => $adminId, 'cid' => $chatId]);
}

function handleDirectText(int $adminId, ?string $text): bool
{
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT id, chat_id FROM direct_messages
         WHERE admin_id = :aid AND status = 'pending_text'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['aid' => $adminId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    if ($text === null || $text === '') {
        sendMessage($adminId, 'Пожалуйста, отправьте текстовое сообщение.');
        return true;
    }

    $dmId  = (int)$row['id'];
    $cid   = (int)$row['chat_id'];
    $res   = sendMessage($cid, $text);
    $db->prepare(
        "UPDATE direct_messages
         SET text = :text, status = 'sent', updated_at = NOW()
         WHERE id = :id"
    )->execute(['text' => $text, 'id' => $dmId]);

    if ($res['ok']) {
        sendMessage($adminId, 'Сообщение отправлено.');
    } else {
        sendMessage($adminId, 'Ошибка отправки: ' . $res['description']);
    }
    return true;
}

function getSubscribersCount(): int
{
    $db = getDb();
    $stmt = $db->query('SELECT COUNT(*) FROM users');
    return (int)$stmt->fetchColumn();
}
