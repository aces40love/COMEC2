<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/webhooks.php';

$pdo = app_db();
$limit = max(1, min(100, (int) app_config('WEBHOOK_BATCH_SIZE', 25)));
$processed = 0;
$retried = 0;
$failed = 0;

for ($index = 0; $index < $limit; $index++) {
    $event = app_claim_queued_square_event($pdo);
    if ($event === null) {
        break;
    }

    try {
        $result = app_process_square_event($pdo, $event);
        app_finish_square_event(
            $pdo,
            (string) $event['event_id'],
            (string) $result['status'],
            (string) $result['result']
        );
        $processed++;
    } catch (Throwable $exception) {
        app_retry_square_event($pdo, $event, $exception);
        if ((int) $event['attempts'] >= (int) app_config('WEBHOOK_MAX_ATTEMPTS', 12)) {
            $failed++;
        } else {
            $retried++;
        }
        app_log('error', 'Queued Square webhook processing failed', [
            'event_id' => (string) $event['event_id'],
            'attempt' => (int) $event['attempts'],
            'exception' => get_class($exception),
        ]);
    }
}

$terminalFailed = (int) $pdo->query(
    "SELECT COUNT(*) FROM webhook_events WHERE processing_status = 'failed'"
)->fetchColumn();
fwrite(STDOUT, sprintf(
    "processed=%d retried=%d failed=%d terminal_failed=%d\n",
    $processed,
    $retried,
    $failed,
    $terminalFailed
));
if ($terminalFailed > 0) {
    app_log('critical', 'Square webhook queue contains terminal failures', ['terminal_failed' => $terminalFailed]);
    exit(1);
}
