<?php
declare(strict_types=1);
require __DIR__ . '/common.php';

$config = $GLOBALS['config'];
$db     = getDb();

$maxPerRun = parseMaxPerRun($argv, $config);
runBroadcasts($maxPerRun);

function parseMaxPerRun(array $argv, array $config): ?int
{
    $max = null;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $max = (int)$argv[1];
    } elseif (isset($config['MAX_MESSAGES_PER_RUN'])) {
        $max = (int)$config['MAX_MESSAGES_PER_RUN'];
    }
    return ($max !== null && $max > 0) ? $max : ($max === 0 ? null : $max);
}

function runBroadcasts(?int $maxPerRun): void
{
    $db = getDb();
    $remaining = $maxPerRun;

    restoreStalledBroadcasts($db);
    $broadcasts = fetchSendingBroadcasts($db);

    foreach ($broadcasts as $b) {
        if ($remaining !== null && $remaining <= 0) {
            break;
        }
        if (!captureBroadcast($db, (int)$b['id'])) {
            continue;
        }
        try {
            $limitHit = processBroadcast((int)$b['id'], (int)$b['admin_id'], $b['text'], $remaining);
        } catch (Throwable $e) {
            $db->prepare("UPDATE broadcasts SET status = 'sending' WHERE id = :id")
                ->execute(['id' => $b['id']]);
            sendMessage((int)$b['admin_id'], 'Ошибка при обработке рассылки #' . $b['id'] . ': ' . $e->getMessage());
            continue;
        }
        if ($limitHit && $remaining !== null && $remaining <= 0) {
            break;
        }
    }
}

function restoreStalledBroadcasts(PDO $db): void
{
    $db->exec(
        "UPDATE broadcasts
            SET status = 'sending'
          WHERE status = 'processing'
            AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
}

function fetchSendingBroadcasts(PDO $db): array
{
    $stmt = $db->query("SELECT id, admin_id, text FROM broadcasts WHERE status = 'sending'");
    return $stmt->fetchAll();
}

function captureBroadcast(PDO $db, int $id): bool
{
    $u = $db->prepare("UPDATE broadcasts SET status = 'processing' WHERE id = :id AND status = 'sending'");
    $u->execute(['id' => $id]);
    return $u->rowCount() > 0;
}

function processBroadcast(int $broadcastId, int $adminId, string $text, ?int &$remaining): bool
{
    $db = getDb();
    $config     = $GLOBALS['config'];
    $limit      = $config['RATE_LIMIT']['default'];
    $batchSize  = $limit['batch_size'];
    $delayMs    = $limit['delay_ms'];
    $msgDelayMs = $limit['msg_delay_ms'] ?? 40;

    $batchNum     = 0;
    $sentAll      = 0;
    $failedAll    = 0;
    $limitReached = false;
    $completed    = false;

    while (true) {
        $chunk = fetchUsersChunk($db, $broadcastId, $batchSize);
        if (!$chunk) {
            $completed = true;
            break;
        }
        $batchNum++;
        $sent = $failed = 0;
        foreach ($chunk as $u) {
            ['success' => $success, 'attempts' => $attempts, 'error' => $err] = sendMessageWithRetry((int)$u['chat_id'], $text, $msgDelayMs);
            saveAttempt($db, $broadcastId, (int)$u['id'], $attempts, $success, $err);
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
        updateBroadcastTimestamp($db, $broadcastId);
        reportBatch($adminId, $batchNum, $sent, count($chunk), $failed);

        if ($limitReached) {
            break;
        }
        usleep($delayMs * 1000);
    }

    if ($completed) {
        finalizeBroadcast($db, $broadcastId);
        sendFinalReport($adminId, $broadcastId, $sentAll, $failedAll);
        return false;
    }

    $db->prepare("UPDATE broadcasts SET status = 'sending' WHERE id = :id")
        ->execute(['id' => $broadcastId]);
    sendMessage($adminId, "Достигнут лимит отправки. Рассылка будет продолжена позже. Отправлено в этом запуске: {$sentAll}.");
    return true;
}

function fetchUsersChunk(PDO $db, int $broadcastId, int $limit): array
{
    $stmt = $db->prepare(
        "SELECT u.id, u.chat_id
         FROM users u
         LEFT JOIN broadcast_attempts ba ON ba.user_id = u.id AND ba.broadcast_id = :bid
         WHERE ba.user_id IS NULL
         ORDER BY u.id
         LIMIT :lim"
    );
    $stmt->bindValue(':bid', $broadcastId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function sendMessageWithRetry(int $chatId, string $text, int $msgDelayMs): array
{
    $attempts  = 0;
    $success   = false;
    $lastError = '';
    while ($attempts < 3 && !$success) {
        $attempts++;
        $res = sendMessage($chatId, $text);
        if ($res['ok']) {
            $success = true;
        } else {
            $lastError = $res['description'] ?? 'Unknown error';
            if (($res['error_code'] ?? 0) === 429) {
                $retry = (int)($res['parameters']['retry_after'] ?? 1);
                sleep($retry);
            } else {
                usleep(500_000 * $attempts);
            }
        }
        usleep($msgDelayMs * 1000);
    }
    return ['success' => $success, 'attempts' => $attempts, 'error' => $lastError];
}

function saveAttempt(PDO $db, int $broadcastId, int $userId, int $attempts, bool $success, string $error): void
{
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
        'uid' => $userId,
        'att' => $attempts,
        'st'  => $success ? 'sent' : 'failed',
        'err' => $error,
    ]);
}

function updateBroadcastTimestamp(PDO $db, int $broadcastId): void
{
    $db->prepare('UPDATE broadcasts SET updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $broadcastId]);
}

function reportBatch(int $adminId, int $batchNum, int $sent, int $total, int $failed): void
{
    sendMessage($adminId, "Batch #{$batchNum}: отправлено {$sent} из {$total}, {$failed} — не удалось.");
}

function finalizeBroadcast(PDO $db, int $broadcastId): void
{
    $db->prepare("UPDATE broadcasts SET status = 'completed' WHERE id = :id")
        ->execute(['id' => $broadcastId]);
}

function sendFinalReport(int $adminId, int $broadcastId, int $sentAll, int $failedAll): void
{
    sendMessage($adminId, "Рассылка #{$broadcastId} завершена. Всего отправлено: {$sentAll}, не удалось: {$failedAll}.");
}
