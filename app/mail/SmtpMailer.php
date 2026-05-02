<?php

class SmtpMailer
{
    private array $settings;
    private $socket = null;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): void
    {
        $host = trim((string)$this->settings['host']);
        $port = (int)$this->settings['port'];
        $encryption = strtolower((string)$this->settings['encryption']);
        $username = (string)$this->settings['username'];
        $password = (string)$this->settings['password_plain'];
        $fromEmail = trim((string)$this->settings['from_email']);
        $fromName = trim((string)$this->settings['from_name']) ?: 'TimePoint';

        if ($host === '' || $port <= 0 || $fromEmail === '') {
            throw new RuntimeException('SMTP ist nicht vollstaendig konfiguriert.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $this->socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$this->socket) {
            throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, 20);
        $this->expect([220]);
        $this->command('EHLO ' . $this->localHostName(), [250]);

        if ($encryption === 'starttls' || $encryption === 'tls') {
            $this->command('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS konnte nicht aktiviert werden.');
            }
            $this->command('EHLO ' . $this->localHostName(), [250]);
        }

        if ($username !== '' || $password !== '') {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334]);
            $this->command(base64_encode($password), [235]);
        }

        $this->command('MAIL FROM:<' . $fromEmail . '>', [250]);
        $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
        $this->command('DATA', [354]);
        $this->write($this->dotStuff($this->buildMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody, $textBody, $attachments)) . "\r\n.");
        $this->expect([250]);
        $this->command('QUIT', [221]);
        fclose($this->socket);
    }

    private function buildMessage(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody, array $attachments): string
    {
        $alternativeBoundary = 'tp_alt_' . bin2hex(random_bytes(12));
        $mixedBoundary = 'tp_mix_' . bin2hex(random_bytes(12));
        $textBody = $textBody !== '' ? $textBody : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->formatAddress($fromEmail, $fromName),
            'To: ' . $this->formatAddress($toEmail, $toName),
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
        ];

        $alternativePart = "--{$alternativeBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $this->normalizeBody($textBody)
            . "\r\n--{$alternativeBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $this->normalizeBody($htmlBody)
            . "\r\n--{$alternativeBoundary}--";

        if (!$attachments) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"';
            return implode("\r\n", $headers) . "\r\n\r\n" . $alternativePart;
        }

        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';
        $message = implode("\r\n", $headers)
            . "\r\n\r\n--{$mixedBoundary}\r\nContent-Type: multipart/alternative; boundary=\"{$alternativeBoundary}\"\r\n\r\n"
            . $alternativePart;

        foreach ($attachments as $attachment) {
            $filename = $this->sanitizeFilename((string)($attachment['filename'] ?? 'attachment.bin'));
            $contentType = trim((string)($attachment['content_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream';
            $content = (string)($attachment['content'] ?? '');

            $message .= "\r\n--{$mixedBoundary}\r\n"
                . "Content-Type: {$contentType}; name=\"" . addcslashes($filename, "\\\"") . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"" . addcslashes($filename, "\\\"") . "\"\r\n\r\n"
                . rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
        }

        return $message . "\r\n--{$mixedBoundary}--";
    }

    private function formatAddress(string $email, string $name): string
    {
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function normalizeBody(string $body): string
    {
        return str_replace(["\r\n", "\r", "\n"], "\r\n", $body);
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim(str_replace(["\r", "\n", '/', '\\'], '_', $filename));

        return $filename !== '' ? $filename : 'attachment.bin';
    }

    private function dotStuff(string $message): string
    {
        return preg_replace('/^\./m', '..', $message);
    }

    private function command(string $command, array $expectedCodes): string
    {
        $this->write($command);
        return $this->expect($expectedCodes);
    }

    private function write(string $line): void
    {
        fwrite($this->socket, $line . "\r\n");
    }

    private function expect(array $expectedCodes): string
    {
        $response = '';
        do {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                throw new RuntimeException('Keine Antwort vom SMTP-Server.');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP-Fehler: ' . trim($response));
        }

        return $response;
    }

    private function localHostName(): string
    {
        return $_SERVER['SERVER_NAME'] ?? 'localhost';
    }
}
