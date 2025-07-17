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
            $limitHit = processBroadcast((int)$b['id'], (int)$b['admin_id'], $b['text'], $remaining, $b['max_recipients']);
        } catch (Throwable $e) {
            $db->prepare("UPDATE broadcasts SET status = 'sending', updated_at = NOW() WHERE id = :id")
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
            SET status = 'sending', updated_at = NOW()
          WHERE status = 'processing'
            AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
}

function fetchSendingBroadcasts(PDO $db): array
{
    $stmt = $db->query("SELECT id, admin_id, text, max_recipients FROM broadcasts WHERE status = 'sending'");
    return $stmt->fetchAll();
}

function captureBroadcast(PDO $db, int $id): bool
{
    $u = $db->prepare("UPDATE broadcasts SET status = 'processing', updated_at = NOW() WHERE id = :id AND status = 'sending'");
    $u->execute(['id' => $id]);
    return $u->rowCount() > 0;
}

function processBroadcast(
    int $broadcastId,
    int $adminId,
    string $text,
    ?int &$remaining,
    ?int $maxRecipients
): bool
{
    $db = getDb();
    $config     = $GLOBALS['config'];
    $limit      = $config['RATE_LIMIT']['default'];
    $batchSize  = $limit['batch_size'];
    $delayMs    = $limit['delay_ms'];
    $msgDelayMs = $limit['msg_delay_ms'] ?? 40;

    $startTime = getBroadcastStartTime($db, $broadcastId);
    if ($startTime === null) {
        $startTime = time();
    }
    $stats     = getBroadcastStats($db, $broadcastId);
    $batchNum  = 0;
    $sentAll   = $stats['sent'];
    $failedAll = $stats['failed'];
    $totalRecipients = getTotalRecipients($db, $maxRecipients);
    $limitReached = false;
    $completed    = false;

    $attempted = getBroadcastAttemptCount($db, $broadcastId);
    if ($maxRecipients !== null) {
        $remainingForBroadcast = $maxRecipients - $attempted;
        if ($remainingForBroadcast <= 0) {
            finalizeBroadcast($db, $broadcastId);
            sendFinalReport(
                $adminId,
                $broadcastId,
                $sentAll,
                $failedAll,
                $totalRecipients,
                $startTime
            );
            return false;
        }
    } else {
        $remainingForBroadcast = null;
    }

    while (true) {
        $chunkLimit = $batchSize;
        if ($remaining !== null) {
            $chunkLimit = min($chunkLimit, $remaining);
        }
        if ($remainingForBroadcast !== null) {
            $chunkLimit = min($chunkLimit, $remainingForBroadcast);
        }

        $chunk = fetchUsersChunk($db, $broadcastId, $chunkLimit);
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
            } else {
                $failed++;
            }
            if ($remaining !== null) {
                $remaining--;
            }
            if ($remainingForBroadcast !== null) {
                $remainingForBroadcast--;
            }
            if (($remaining !== null && $remaining <= 0) || ($remainingForBroadcast !== null && $remainingForBroadcast <= 0)) {
                $limitReached = true;
                break;
            }
        }

        $sentAll   += $sent;
        $failedAll += $failed;
        updateBroadcastTimestamp($db, $broadcastId);
        reportBatch(
            $adminId,
            $broadcastId,
            $batchNum,
            $sent,
            count($chunk),
            $failed,
            $sentAll,
            $failedAll,
            $totalRecipients,
            $startTime,
            $delayMs
        );

        if ($limitReached) {
            break;
        }
        usleep($delayMs * 1000);
    }

    if (
        $completed
        || (
            $maxRecipients !== null
            && $remainingForBroadcast !== null
            && $remainingForBroadcast <= 0
        )
    ) {
        finalizeBroadcast($db, $broadcastId);
        sendFinalReport(
            $adminId,
            $broadcastId,
            $sentAll,
            $failedAll,
            $totalRecipients,
            $startTime
        );
        return false;
    }

    $db->prepare("UPDATE broadcasts SET status = 'sending', updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $broadcastId]);
    $header = buildBroadcastHeader(
        $broadcastId,
        $totalRecipients,
        $sentAll,
        $failedAll,
        time() - $startTime,
        false
    );
    sendMessage(
        $adminId,
        $header . PHP_EOL
        . "Достигнут лимит отправки за один запуск. Отправлено в этом запуске: {$sentAll}." . PHP_EOL
        . "Рассылка будет продолжена позже."
    );
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

function getBroadcastAttemptCount(PDO $db, int $broadcastId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM broadcast_attempts WHERE broadcast_id = :id');
    $stmt->execute(['id' => $broadcastId]);
    return (int)$stmt->fetchColumn();
}

function getBroadcastStats(PDO $db, int $broadcastId): array
{
    $stmt = $db->prepare(
        "SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM broadcast_attempts WHERE broadcast_id = :id"
    );
    $stmt->execute(['id' => $broadcastId]);
    $row = $stmt->fetch();
    return [
        'sent'   => $row ? (int)$row['sent'] : 0,
        'failed' => $row ? (int)$row['failed'] : 0,
    ];
}

function getBroadcastStartTime(PDO $db, int $broadcastId): ?int
{
    $stmt = $db->prepare(
        'SELECT MIN(created_at) AS started FROM broadcast_attempts WHERE broadcast_id = :id'
    );
    $stmt->execute(['id' => $broadcastId]);
    $row = $stmt->fetch();
    if ($row && $row['started'] !== null) {
        return (int)strtotime($row['started']);
    }
    return null;
}

function getSubscribersCount(): int
{
    $db = getDb();
    $stmt = $db->query('SELECT COUNT(*) FROM users');
    return (int)$stmt->fetchColumn();
}

function getTotalRecipients(PDO $db, ?int $maxRecipients): int
{
    $total = getSubscribersCount();
    if ($maxRecipients !== null && $maxRecipients < $total) {
        return $maxRecipients;
    }
    return $total;
}

function buildBroadcastHeader(
    int $broadcastId,
    int $totalRecipients,
    int $sent,
    int $failed,
    int $duration,
    bool $final
): string {
    $timeText = $final ? "Время выполнения: {$duration} сек." : "Длится {$duration} сек.";
    return "[Рассылка #{$broadcastId}] [Всего {$totalRecipients}, отправлено {$sent}, не удалось {$failed}] [{$timeText}]";
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
         (broadcast_id, user_id, attempts, status, last_error, updated_at)
         VALUES (:bid, :uid, :att, :st, :err, NOW())
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

function reportBatch(
    int $adminId,
    int $broadcastId,
    int $batchNum,
    int $sent,
    int $total,
    int $failed,
    int $sentAll,
    int $failedAll,
    int $totalRecipients,
    int $startTime,
    int $delayMs
): void {
    $header = buildBroadcastHeader(
        $broadcastId,
        $totalRecipients,
        $sentAll,
        $failedAll,
        time() - $startTime,
        false
    );
    sendMessage(
        $adminId,
        $header . PHP_EOL
        . "Batch #{$batchNum}: отправлено {$sent} из {$total}, {$failed} — не удалось." . PHP_EOL
        . "Жду {$delayMs} мс."
    );
}

function finalizeBroadcast(PDO $db, int $broadcastId): void
{
    $db->prepare("UPDATE broadcasts SET status = 'completed', updated_at = NOW() WHERE id = :id")
        ->execute(['id' => $broadcastId]);
}

function sendFinalReport(
    int $adminId,
    int $broadcastId,
    int $sentAll,
    int $failedAll,
    int $totalRecipients,
    int $startTime
): void {
    $db = getDb();
    $stats = getBroadcastStats($db, $broadcastId);
    $totalSent = $stats['sent'] ?? $sentAll;
    $totalFailed = $stats['failed'] ?? $failedAll;

    $header = buildBroadcastHeader(
        $broadcastId,
        $totalRecipients,
        $totalSent,
        $totalFailed,
        time() - $startTime,
        true
    );

    sendMessage(
        $adminId,
        $header . PHP_EOL . 'Рассылка завершена.'
    );
}
