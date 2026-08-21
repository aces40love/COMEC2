<?php

declare(strict_types=1);

function app_claim_email(PDO $pdo): ?array
{
    return app_transaction($pdo, static function (PDO $pdo): ?array {
        $statement = $pdo->query(
            "SELECT * FROM email_outbox WHERE (status = 'pending' AND available_at <= UTC_TIMESTAMP(6)) "
            . "OR (status = 'sending' AND locked_at < UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE) "
            . 'ORDER BY created_at ASC LIMIT 1 FOR UPDATE'
        );
        $email = $statement->fetch();
        if (!$email) {
            return null;
        }

        $update = $pdo->prepare(
            "UPDATE email_outbox SET status = 'sending', attempts = attempts + 1, locked_at = UTC_TIMESTAMP(6), "
            . 'last_error = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ?'
        );
        $update->execute([(int) $email['id']]);
        $email['attempts'] = (int) $email['attempts'] + 1;
        return $email;
    });
}

function app_mark_email_sent(PDO $pdo, int $id): void
{
    $statement = $pdo->prepare(
        "UPDATE email_outbox SET status = 'sent', locked_at = NULL, sent_at = UTC_TIMESTAMP(6), "
        . 'last_error = NULL, updated_at = UTC_TIMESTAMP(6) WHERE id = ?'
    );
    $statement->execute([$id]);
}

function app_retry_email(PDO $pdo, array $email, Throwable $exception): void
{
    $attempts = (int) $email['attempts'];
    $maxAttempts = max(1, (int) app_config('MAIL_MAX_ATTEMPTS', 5));
    $terminal = $attempts >= $maxAttempts;
    $delay = min(3600, 60 * (2 ** min(6, max(0, $attempts - 1))));
    $availableAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . $delay . ' seconds')
        ->format('Y-m-d H:i:s.u');
    $error = substr(get_class($exception) . ': ' . $exception->getMessage(), 0, 1000);

    $statement = $pdo->prepare(
        'UPDATE email_outbox SET status = ?, available_at = ?, locked_at = NULL, last_error = ?, '
        . 'updated_at = UTC_TIMESTAMP(6) WHERE id = ?'
    );
    $statement->execute([$terminal ? 'failed' : 'pending', $availableAt, $error, (int) $email['id']]);
}

function app_scrub_sent_email_content(PDO $pdo): int
{
    $retentionDays = max(1, min(3650, (int) app_config('EMAIL_OUTBOX_RETENTION_DAYS', 90)));
    $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $retentionDays . ' days')
        ->format('Y-m-d H:i:s.u');
    $statement = $pdo->prepare(
        "UPDATE email_outbox SET recipient_email='', subject='', text_body='', html_body='', "
        . 'scrubbed_at=UTC_TIMESTAMP(6), updated_at=UTC_TIMESTAMP(6) '
        . "WHERE status='sent' AND scrubbed_at IS NULL AND sent_at < ? LIMIT 500"
    );
    $statement->execute([$cutoff]);
    return $statement->rowCount();
}

function app_deliver_email(array $email): void
{
    app_require_config(['MAIL_FROM_EMAIL']);
    $recipient = app_valid_mailbox((string) $email['recipient_email'], 'recipient');
    $from = app_valid_mailbox((string) app_config('MAIL_FROM_EMAIL'), 'sender');
    $replyToConfig = trim((string) app_config('MAIL_REPLY_TO', ''));
    $replyTo = $replyToConfig !== '' ? app_valid_mailbox($replyToConfig, 'reply-to') : null;
    $subject = app_clean_mail_header((string) $email['subject']);
    $message = app_build_mime_message(
        $recipient,
        $from,
        (string) app_config('MAIL_FROM_NAME', 'COMEC'),
        $replyTo,
        $subject,
        (string) $email['text_body'],
        (string) $email['html_body']
    );

    $transport = strtolower((string) app_config('MAIL_TRANSPORT', 'mail'));
    if ($transport === 'mail') {
        $headers = implode("\r\n", $message['headers']);
        if (!mail($recipient, app_encode_mail_header($subject), $message['body'], $headers)) {
            throw new RuntimeException('The local mail transport rejected the message.');
        }
        return;
    }
    if ($transport === 'smtp') {
        app_smtp_deliver($from, $recipient, $message['smtp_message']);
        return;
    }
    throw new RuntimeException('MAIL_TRANSPORT must be mail or smtp.');
}

function app_build_mime_message(
    string $recipient,
    string $from,
    string $fromName,
    ?string $replyTo,
    string $subject,
    string $textBody,
    string $htmlBody
): array {
    $fromName = app_clean_mail_header($fromName);
    $boundary = 'comec_' . bin2hex(random_bytes(18));
    $fromHeader = ($fromName !== '' ? app_encode_mail_header($fromName) . ' ' : '') . '<' . $from . '>';
    $domain = substr(strrchr($from, '@') ?: '@comec.org', 1);
    $headers = [
        'From: ' . $fromHeader,
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if ($replyTo !== null) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(app_mail_crlf($textBody)) . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
        . quoted_printable_encode(app_mail_crlf($htmlBody)) . "\r\n"
        . '--' . $boundary . "--\r\n";

    $smtpHeaders = array_merge([
        'To: ' . $recipient,
        'Subject: ' . app_encode_mail_header($subject),
    ], $headers);

    return [
        'headers' => $headers,
        'body' => $body,
        'smtp_message' => implode("\r\n", $smtpHeaders) . "\r\n\r\n" . $body,
    ];
}

function app_smtp_deliver(string $from, string $recipient, string $message): void
{
    app_require_config(['MAIL_SMTP_HOST']);
    $host = trim((string) app_config('MAIL_SMTP_HOST'));
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host) && filter_var($host, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('MAIL_SMTP_HOST is invalid.');
    }
    $port = (int) app_config('MAIL_SMTP_PORT', 587);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('MAIL_SMTP_PORT is invalid.');
    }
    $encryption = strtolower((string) app_config('MAIL_SMTP_ENCRYPTION', 'tls'));
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        throw new RuntimeException('MAIL_SMTP_ENCRYPTION must be tls, ssl, or none.');
    }
    $username = (string) app_config('MAIL_SMTP_USERNAME', '');
    if ($username !== '') {
        if ($encryption === 'none') {
            throw new RuntimeException('SMTP authentication requires TLS or SSL encryption.');
        }
        app_require_config(['MAIL_SMTP_PASSWORD']);
    }
    $timeout = max(3, min(30, (int) app_config('MAIL_SMTP_TIMEOUT', 10)));
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ],
    ]);
    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errorNumber, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not connect to the SMTP server.');
    }

    try {
        stream_set_timeout($socket, $timeout);
        app_smtp_expect($socket, [220]);
        $ehloHost = parse_url((string) app_config('APP_URL'), PHP_URL_HOST) ?: 'comec.org';
        app_smtp_command($socket, 'EHLO ' . $ehloHost, [250]);
        if ($encryption === 'tls') {
            app_smtp_command($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Could not establish SMTP TLS.');
            }
            app_smtp_command($socket, 'EHLO ' . $ehloHost, [250]);
        }

        if ($username !== '') {
            app_smtp_command($socket, 'AUTH LOGIN', [334]);
            app_smtp_command($socket, base64_encode($username), [334]);
            app_smtp_command($socket, base64_encode((string) app_config('MAIL_SMTP_PASSWORD')), [235]);
        }

        app_smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        app_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        app_smtp_command($socket, 'DATA', [354]);
        $dotStuffed = preg_replace('/(^|\r\n)\./', '$1..', app_mail_crlf($message));
        app_smtp_write($socket, rtrim((string) $dotStuffed, "\r\n") . "\r\n.\r\n");
        app_smtp_expect($socket, [250]);
        app_smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function app_smtp_command($socket, string $command, array $expectedCodes): string
{
    app_smtp_write($socket, $command . "\r\n");
    return app_smtp_expect($socket, $expectedCodes);
}

function app_smtp_write($socket, string $data): void
{
    $length = strlen($data);
    $written = 0;
    while ($written < $length) {
        $result = fwrite($socket, substr($data, $written));
        if ($result === false || $result === 0) {
            throw new RuntimeException('SMTP connection write failed.');
        }
        $written += $result;
    }
}

function app_smtp_expect($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 4096);
        if ($line === false) {
            throw new RuntimeException('SMTP connection read failed.');
        }
        $response .= $line;
    } while (strlen($line) >= 4 && $line[3] === '-');

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP server rejected the message with code ' . $code . '.');
    }
    return $response;
}

function app_valid_mailbox(string $email, string $label): string
{
    $email = trim($email);
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || preg_match('/[\r\n]/', $email)) {
        throw new RuntimeException('The configured ' . $label . ' email address is invalid.');
    }
    return $email;
}

function app_clean_mail_header(string $value): string
{
    return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
}

function app_encode_mail_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function app_mail_crlf(string $value): string
{
    return str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $value);
}
