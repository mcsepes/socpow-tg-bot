<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$db = getDb();
$config = $GLOBALS['config'];

// Получаем все рассылки в статусе 'sending'
$stmt = $db->query("SELECT id, admin_id, text FROM broadcasts WHERE status = 'sending'");
$broadcasts = $stmt->fetchAll();

foreach ($broadcasts as $b) {
    // Атомарно захватываем рассылку
    $u = $db->prepare("UPDATE broadcasts SET status = 'processing' WHERE id = :id AND status = 'sending'");
    $u->execute(['id' => $b['id']]);
    if ($u->rowCount() === 0) {
        continue; // уже кто-то взял или изменилось
    }

    processBroadcast((int)$b['id'], (int)$b['admin_id'], $b['text']);
}

function processBroadcast(int $broadcastId, int $adminId, string $text): void
{
    $db = getDb();
    $config = $GLOBALS['config'];

    // Загружаем всех подписчиков
    $users = $db->query("SELECT id, chat_id FROM users")->fetchAll();

    // Выбираем rate-limit
    $limit = $config['RATE_LIMIT']['default'];
    $batchSize = $limit['batch_size'];
    $delayMs   = $limit['delay_ms'];

    $total = count($users);
    $batchNum = 0;
    $sentAll = 0;
    $failedAll = 0;

    foreach (array_chunk($users, $batchSize) as $chunk) {
        $batchNum++;
        $sent = $failed = 0;

        foreach ($chunk as $u) {
            $attempts = 0;
            $success = false;
            $lastError = '';

            while ($attempts < 3 && !$success) {
                $attempts++;
                if (sendMessage((int)$u['chat_id'], $text)) {
                    $success = true;
                } else {
                    $lastError = "Attempt $attempts failed";
                    usleep(500_000 * $attempts); // экспоненциальный бэкофф
                }
            }

            // Сохраняем попытку
            $db->prepare("
                INSERT INTO broadcast_attempts
                (broadcast_id, user_id, attempts, status, last_error)
                VALUES (:bid, :uid, :att, :st, :err)
            ")->execute([
                'bid' => $broadcastId,
                'uid' => $u['id'],
                'att' => $attempts,
                'st'  => $success ? 'sent' : 'failed',
                'err' => $lastError,
            ]);

            if ($success) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $sentAll   += $sent;
        $failedAll += $failed;

        // Обновляем время изменения рассылки
        $db->prepare(
            'UPDATE broadcasts SET updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $broadcastId]);

        // Отчёт по батчу админу
        sendMessage($adminId, "Batch #{$batchNum}: отправлено {$sent} из " . count($chunk) . ", {$failed} — не удалось.");

        // Пауза между батчами
        usleep($delayMs * 1000);
    }

    // Финальный отчёт и завершение
    sendMessage($adminId, "Рассылка #{$broadcastId} завершена. Всего отправлено: {$sentAll}, не удалось: {$failedAll}.");
    $db->prepare("UPDATE broadcasts SET status = 'completed' WHERE id = :id")
        ->execute(['id' => $broadcastId]);
}
