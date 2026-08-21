<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mailer.php';

$pdo = app_db();
$limit = max(1, min(100, (int) app_config('MAIL_BATCH_SIZE', 25)));
$sent = 0;
$retried = 0;
$failed = 0;

for ($index = 0; $index < $limit; $index++) {
    $email = app_claim_email($pdo);
    if ($email === null) {
        break;
    }

    try {
        app_deliver_email($email);
        app_mark_email_sent($pdo, (int) $email['id']);
        $sent++;
    } catch (Throwable $exception) {
        app_retry_email($pdo, $email, $exception);
        if ((int) $email['attempts'] >= (int) app_config('MAIL_MAX_ATTEMPTS', 5)) {
            $failed++;
        } else {
            $retried++;
        }
        app_log('error', 'Queued confirmation email delivery failed', [
            'outbox_id' => (int) $email['id'],
            'attempt' => (int) $email['attempts'],
            'exception' => get_class($exception),
        ]);
    }
}

$scrubbed = app_scrub_sent_email_content($pdo);
$terminalFailed = (int) $pdo->query("SELECT COUNT(*) FROM email_outbox WHERE status = 'failed'")->fetchColumn();
fwrite(STDOUT, sprintf(
    "sent=%d retried=%d failed=%d scrubbed=%d terminal_failed=%d\n",
    $sent,
    $retried,
    $failed,
    $scrubbed,
    $terminalFailed
));
if ($terminalFailed > 0) {
    app_log('critical', 'Email outbox contains terminal failures', ['terminal_failed' => $terminalFailed]);
    exit(1);
}
