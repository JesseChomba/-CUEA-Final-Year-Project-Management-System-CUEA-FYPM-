<?php
require_once __DIR__ . '/env.php';
loadEnv();

function smtpRead($socket): string {
    $data = '';
    while (($line = fgets($socket, 515)) !== false) {
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return $data;
}

function smtpCommand($socket, string $command, array $expectedCodes): string {
    fwrite($socket, $command . "\r\n");
    $response = smtpRead($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed: ' . trim($response));
    }
    return $response;
}

function sendSmtpMail(string $toEmail, string $toName, string $subject, string $body): bool {
    $host = envValue('SMTP_HOST', '');
    $port = (int)envValue('SMTP_PORT', '587');
    $encryption = strtolower((string)envValue('SMTP_ENCRYPTION', 'tls'));
    $username = envValue('SMTP_USERNAME', '');
    $password = envValue('SMTP_PASSWORD', '');
    $fromEmail = envValue('SMTP_FROM_EMAIL', $username ?: 'no-reply@localhost');
    $fromName = envValue('SMTP_FROM_NAME', 'CUEA FYPM');

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('SMTP credentials are not configured in .env.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, 8, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: {$errstr}");
    }
    stream_set_timeout($socket, 8);

    smtpRead($socket);
    smtpCommand($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        smtpCommand($socket, 'EHLO localhost', [250]);
    }

    smtpCommand($socket, 'AUTH LOGIN', [334]);
    smtpCommand($socket, base64_encode($username), [334]);
    smtpCommand($socket, base64_encode($password), [235]);
    smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
    smtpCommand($socket, 'DATA', [354]);

    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'To: ' . $toName . ' <' . $toEmail . '>',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8'
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    smtpCommand($socket, $message, [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);

    return true;
}

function sendSystemEmail(string $toEmail, string $toName, string $subject, string $body): bool {
    try {
        return sendSmtpMail($toEmail, $toName, $subject, $body);
    } catch (Throwable $e) {
        error_log('Email send failed: ' . $e->getMessage());
        return false;
    }
}
