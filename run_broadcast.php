<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$db = getDb();
$config = $GLOBALS['config'];

// Лимит сообщений за один запуск
$maxPerRun = null;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $maxPerRun = (int)$argv[1];
} elseif (isset($config['MAX_MESSAGES_PER_RUN'])) {
    $maxPerRun = (int)$config['MAX_MESSAGES_PER_RUN'];
}
if ($maxPerRun !== null && $maxPerRun <= 0) {
    $maxPerRun = null; // 0 и отрицательные — без ограничений
}

$remaining = $maxPerRun;

// Возвращаем "зависшие" рассылки в статус 'sending'
$db->exec(
    "UPDATE broadcasts
        SET status = 'sending'
      WHERE status = 'processing'
        AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
);

// Получаем все рассылки в статусе 'sending'
$stmt = $db->query("SELECT id, admin_id, text FROM broadcasts WHERE status = 'sending'");
$broadcasts = $stmt->fetchAll();

foreach ($broadcasts as $b) {
    if ($remaining !== null && $remaining <= 0) {
        break;
    }
    // Атомарно захватываем рассылку
    $u = $db->prepare("UPDATE broadcasts SET status = 'processing' WHERE id = :id AND status = 'sending'");
    $u->execute(['id' => $b['id']]);
    if ($u->rowCount() === 0) {
        continue; // уже кто-то взял или изменилось
    }

    try {
        $limitHit = processBroadcast((int)$b['id'], (int)$b['admin_id'], $b['text'], $remaining);
    } catch (\Throwable $e) {
        // Возвращаем статус для повторной попытки
        $db->prepare("UPDATE broadcasts SET status = 'sending' WHERE id = :id")
            ->execute(['id' => $b['id']]);
        sendMessage((int)$b['admin_id'], 'Ошибка при обработке рассылки #' . $b['id'] . ': ' . $e->getMessage());
    }

    if ($limitHit && $remaining !== null && $remaining <= 0) {
        break;
    }
}

function processBroadcast(int $broadcastId, int $adminId, string $text, ?int &$remaining): bool
{
    $db = getDb();
    $config = $GLOBALS['config'];

    // Выбираем rate-limit
    $limit      = $config['RATE_LIMIT']['default'];
    $batchSize  = $limit['batch_size'];
    $delayMs    = $limit['delay_ms'];
    $msgDelayMs = $limit['msg_delay_ms'] ?? 40;
    $batchNum    = 0;
    $sentAll     = 0;
    $failedAll   = 0;
    $limitReached = false;
    $completed    = false;

    while (true) {
        $stmt = $db->prepare(
            "SELECT u.id, u.chat_id
             FROM users u
             LEFT JOIN broadcast_attempts ba ON ba.user_id = u.id AND ba.broadcast_id = :bid
             WHERE ba.user_id IS NULL
             ORDER BY u.id
             LIMIT :lim"
        );
        $stmt->bindValue(':bid', $broadcastId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $chunk = $stmt->fetchAll();
        if (!$chunk) {
            $completed = true;
            break;
        }
        $batchNum++;
        $sent = $failed = 0;

        foreach ($chunk as $u) {
            $attempts  = 0;
            $success   = false;
            $lastError = '';

            while ($attempts < 3 && !$success) {
                $attempts++;
                $res = sendMessage((int)$u['chat_id'], $text);
                if ($res['ok']) {
                    $success = true;
                } else {
                    $lastError = $res['description'] ?? 'Unknown error';
                    if (($res['error_code'] ?? 0) === 429) {
                        $retry = (int)($res['parameters']['retry_after'] ?? 1);
                        sleep($retry);
                    } else {
                        usleep(500_000 * $attempts); // бэкофф
                    }
                }
                usleep($msgDelayMs * 1000);
            }

            // Сохраняем попытку
            $db->prepare(
                "INSERT INTO broadcast_attempts
                 (broadcast_id, user_id, attempts, status, last_error)
                 VALUES (:bid, :uid, :att, :st, :err)
                 ON DUPLICATE KEY UPDATE
                    attempts   = VALUES(attempts),
                    status     = VALUES(status),
                    last_error = VALUES(last_error),
                    updated_at = NOW()"
            )->execute([
                'bid' => $broadcastId,
                'uid' => $u['id'],
                'att' => $attempts,
                'st'  => $success ? 'sent' : 'failed',
                'err' => $lastError,
            ]);

            if ($success) {
                $sent++;
                if ($remaining !== null) {
                    $remaining--;
                }
            } else {
                $failed++;
            }

            if ($remaining !== null && $remaining <= 0) {
                $limitReached = true;
                break;
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

        if ($limitReached) {
            break;
        }

        // Пауза между батчами
        usleep($delayMs * 1000);
    }

    if ($completed) {
        // Финальный отчёт и завершение
        sendMessage($adminId, "Рассылка #{$broadcastId} завершена. Всего отправлено: {$sentAll}, не удалось: {$failedAll}.");
        $db->prepare("UPDATE broadcasts SET status = 'completed' WHERE id = :id")
            ->execute(['id' => $broadcastId]);
        return false;
    }

    // Прерываемся из-за лимита
    $db->prepare("UPDATE broadcasts SET status = 'sending' WHERE id = :id")
        ->execute(['id' => $broadcastId]);
    sendMessage($adminId, "Достигнут лимит отправки. Рассылка будет продолжена позже. Отправлено в этом запуске: {$sentAll}.");
    return true;
}
