<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;
use Intrabase\Domain\Contracts\MailerInterface;
use Intrabase\Support\Mail\XOAuth2;
use PDO;

/**
 * Mailer
 *
 * Simple email sender using PHPMailer-compatible SMTP flow.
 * Priority order:
 *   1. SMTP account from ib_sys_smtp with function_type='system' and is_default=1
 *   2. PHP mail() fallback
 *
 * Uses PHP's stream_socket_client for SMTP (no external library required).
 *
 * Implements MailerInterface so application code can depend on the
 * contract rather than this concrete class.
 */
final class Mailer implements MailerInterface
{
    private ?array $smtpConfig = null;
    private bool   $smtpLoaded = false;

    public function __construct(
        private readonly ?Connection $db = null,
    ) {}

    /**
     * Display name for the From: of a mail account's outgoing messages.
     *
     * `from_name` when the user set one. Otherwise the account's `name`,
     * which is a LABEL for the account list — and not always fit to be shown
     * to recipients: linking a Gmail/Outlook/iCloud account labels it
     * "jane.example (gmail.com)", and that went out as the sender's name
     * (spam filters score it as a display name impersonating another domain,
     * and it lands in spam). That automatic label, or a label that is just an address, gives
     * no display name: the bare address reads better than a made-up one.
     */
    public static function senderName(array $account): string
    {
        $from = trim((string)($account['from_name'] ?? ''));
        if ($from !== '') {
            return $from;
        }
        $label = trim((string)($account['name'] ?? $account['account_name'] ?? ''));
        if ($label === '' || str_contains($label, '@')
            || preg_match('/\s\([a-z0-9.-]+\.[a-z]{2,}\)$/i', $label)) {
            return '';
        }
        return $label;
    }

    /**
     * Envia des d'una bústia de l'app Correu (`ib_mail_accounts`): From, usuari i contrasenya (o
     * XOAUTH2) de la bústia. Per als correus que han de sortir de la persona que fa l'acció, com
     * la invitació d'un usuari (12/11/2026). Retorna false si la bústia no té SMTP o falla l'enviament.
     */
    public function sendFromAccount(array $account, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $host = trim((string) (($account['smtp_host'] ?? '') ?: ($account['imap_host'] ?? '')));
        if ($host === '' || trim((string) ($account['email'] ?? '')) === '') {
            return false;
        }
        $token = '';
        if (($account['auth_type'] ?? 'password') !== 'password' && $this->db !== null) {
            try {
                $token = (string) ((new Mail\MailAccountOAuth($this->db))->hydrate($account)['smtp_oauth_token'] ?? '');
            } catch (\Throwable) {
                $token = '';
            }
        }
        $smtp = [
            'smtp_host'           => $host,
            'smtp_port'           => (int) (($account['smtp_port'] ?? 0) ?: 587),
            'smtp_user'           => (string) (($account['smtp_user'] ?? '') ?: ($account['imap_user'] ?? '')),
            'smtp_password'       => self::decryptAccountPassword((string) (($account['smtp_password'] ?? '') ?: ($account['imap_password'] ?? ''))),
            'smtp_password_plain' => true,
            'smtp_secure'         => (string) (($account['smtp_encryption'] ?? '') ?: 'tls'),
            'smtp_auth'           => 1,
            'from_email'          => (string) $account['email'],
            'from_name'           => self::senderName($account),
            'oauth_token'         => $token,
        ];
        if ($textBody === '') {
            $textBody = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/h\d)[^>]*>#i', "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
        }
        return $this->sendSmtp($smtp, $toEmail, $toName, $subject, $htmlBody, $textBody);
    }

    /** Contrasenya d'una bústia de l'app Correu (AES-256-GCM amb la clau de config/app.php, com MailApi/TicketApi). */
    public static function decryptAccountPassword(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $config = is_file($base . '/config/app.php') ? (array) (require $base . '/config/app.php') : [];
        $key = (string) ($config['encryption_key'] ?? $config['app_key'] ?? 'intrabase_default_key');
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 28) {
            return $encrypted;
        }
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        return $plain !== false ? $plain : $encrypted;
    }

    /**
     * Send an email.
     *
     * $attachments (optional, backwards-compatible extra param over
     * MailerInterface): list of ['name' => string, 'mime' => string,
     * 'content' => string(raw bytes)] — sent base64-encoded in a
     * multipart/mixed wrapper around the usual multipart/alternative body.
     *
     * @param array<int,array{name?:string,mime?:string,content?:string}> $attachments
     * @param string[] $cc  Adreces en còpia (capçalera Cc + RCPT TO), opcional
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        ?int   $smtpId   = null,
        array  $attachments = [],
        array  $cc = [],
    ): bool {
        if ($textBody === '') {
            $textBody = strip_tags($htmlBody);
        }

        // Còpies (Cc): adreces vàlides, sense duplicats ni el destinatari principal
        $cc = array_values(array_unique(array_filter(
            array_map(fn($a) => strtolower(trim((string) $a)), $cc),
            fn($a) => $a !== '' && $a !== strtolower(trim($toEmail)) && filter_var($a, FILTER_VALIDATE_EMAIL)
        )));

        $smtp = $this->loadSmtpConfig($smtpId);

        if ($smtp !== null) {
            return $this->sendSmtp($smtp, $toEmail, $toName, $subject, $htmlBody, $textBody, $attachments, $cc);
        }

        return $this->sendPhpMail($toEmail, $toName, $subject, $htmlBody, $textBody, $attachments, $cc);
    }

    /**
     * Queue an email in ib_sys_email_queue for background processing.
     */
    public function queue(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody        = '',
        int    $priority        = 3,
        ?string $relatedModule  = null,
        ?int    $relatedId      = null,
        ?int    $smtpId         = null,
    ): bool {
        if ($this->db === null) {
            return false;
        }
        try {
            $smtp       = $this->loadSmtpConfig($smtpId);
            $fromEmail  = $smtp ? $smtp['from_email'] : '';
            $fromName   = $smtp ? ($smtp['from_name'] ?? 'Intrabase') : 'Intrabase';

            $this->db->insert('ib_sys_email_queue', [
                'to_email'       => $toEmail,
                'to_name'        => $toName,
                'from_email'     => $fromEmail,
                'from_name'      => $fromName,
                'subject'        => $subject,
                'body_html'      => $htmlBody,
                'body_text'      => $textBody ?: strip_tags($htmlBody),
                'smtp_id'        => $smtpId,
                'priority'       => $priority,
                'status'         => 'queued',
                'related_module' => $relatedModule,
                'related_id'     => $relatedId,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[Mailer] Queue error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process the email queue — sends up to $limit pending emails.
     * Returns number of emails processed.
     */
    public function processQueue(int $limit = 50): int
    {
        if ($this->db === null) {
            return 0;
        }

        $pending = $this->db->fetchAll(
            "SELECT * FROM ib_sys_email_queue
              WHERE status = 'queued' AND attempts < 3
              ORDER BY priority DESC, created_at ASC
              LIMIT :limit",
            ['limit' => $limit]
        );

        $processed = 0;
        foreach ($pending as $item) {
            $id = (int) $item['id'];

            // Mark as sending
            $this->db->execute(
                "UPDATE ib_sys_email_queue
                    SET status = 'sending', last_attempt_at = NOW(), attempts = attempts + 1
                  WHERE id = :id",
                ['id' => $id]
            );

            $smtpId = !empty($item['smtp_id']) ? (int)$item['smtp_id'] : null;
            $smtp   = $this->loadSmtpConfig($smtpId);

            $ok = $smtp !== null
                ? $this->sendSmtp($smtp, $item['to_email'], $item['to_name'] ?? '', $item['subject'], $item['body_html'] ?? '', $item['body_text'] ?? '')
                : $this->sendPhpMail($item['to_email'], $item['to_name'] ?? '', $item['subject'], $item['body_html'] ?? '', $item['body_text'] ?? '');

            if ($ok) {
                $this->db->execute(
                    "UPDATE ib_sys_email_queue SET status = 'sent', sent_at = NOW() WHERE id = :id",
                    ['id' => $id]
                );
            } else {
                // If max attempts reached, mark as failed
                $attempts = (int)$item['attempts'] + 1;
                $newStatus = $attempts >= 3 ? 'failed' : 'queued';
                $this->db->execute(
                    "UPDATE ib_sys_email_queue SET status = :s, error_message = 'Delivery failed' WHERE id = :id",
                    ['s' => $newStatus, 'id' => $id]
                );
            }

            $processed++;
        }

        return $processed;
    }

    // -------------------------------------------------------------------------
    // SMTP sender
    // -------------------------------------------------------------------------

    private function sendSmtp(
        array $smtp,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $attachments = [],
        array $cc = []
    ): bool {
        // Tenant sense correu sortint (demo): no surt res, es diu que sí i queda al log
        if (OutboundMailGuard::blocked($this->db)) {
            OutboundMailGuard::logBlocked('Mailer', array_merge([$toEmail], $cc), $subject);
            return true;
        }
        $host       = (string)($smtp['smtp_host'] ?? '');
        $port       = (int)($smtp['smtp_port']    ?? 587);
        $user       = (string)($smtp['smtp_user'] ?? '');
        $pass       = !empty($smtp['smtp_password_plain'])
            ? (string)($smtp['smtp_password'] ?? '')
            : $this->decryptSmtpPassword((string)($smtp['smtp_password'] ?? ''));
        $fromEmail  = (string)($smtp['from_email'] ?? $user);
        $fromName   = (string)($smtp['from_name']  ?? 'Intrabase');
        $encryption = strtolower((string)($smtp['smtp_secure'] ?? 'tls'));
        $useAuth    = (bool)($smtp['smtp_auth'] ?? true);

        $boundary = 'ib_' . bin2hex(random_bytes(8));

        $to   = $toName !== '' ? '"' . $this->encodeHeader($toName) . '" <' . $toEmail . '>' : $toEmail;
        $from = $fromName !== '' ? '"' . $this->encodeHeader($fromName) . '" <' . $fromEmail . '>' : $fromEmail;
        $date = date('r');
        $msgId = '<' . uniqid('ib', true) . '@' . gethostname() . '>';

        $alt  = "--{$boundary}\r\n";
        // Base64 amb CTE declarat: 7 bits nets i cap línia de més de 998 caràcters
        // (un data: URI de 2.400 caràcters al cos invalidava la signatura DKIM).
        $alt .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $alt .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $alt .= chunk_split(base64_encode($textBody), 76, "\r\n");
        $alt .= "--{$boundary}\r\n";
        $alt .= "Content-Type: text/html; charset=UTF-8\r\n";
        $alt .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $alt .= chunk_split(base64_encode($htmlBody), 76, "\r\n");
        $alt .= "--{$boundary}--\r\n";

        $headers  = "Date: {$date}\r\n";
        $headers .= "From: {$from}\r\n";
        $headers .= "To: {$to}\r\n";
        if ($cc !== []) {
            $headers .= "Cc: " . implode(', ', $cc) . "\r\n";
        }
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Message-ID: {$msgId}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        if ($attachments === []) {
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $body = $alt;
        } else {
            $mixBoundary = 'ibm_' . bin2hex(random_bytes(8));
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"\r\n";
            $body  = "--{$mixBoundary}\r\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $body .= $alt . "\r\n";
            $body .= $this->attachmentParts($attachments, $mixBoundary);
            $body .= "--{$mixBoundary}--\r\n";
        }
        $headers .= "X-Mailer: Intrabase/1.0\r\n";

        $message = $headers . "\r\n" . $body;

        try {
            $errNo  = 0;
            $errStr = '';
            $timeout = 15;

            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ]);

            if ($encryption === 'ssl') {
                $socket = @stream_socket_client(
                    "ssl://{$host}:{$port}", $errNo, $errStr, $timeout,
                    STREAM_CLIENT_CONNECT, $ctx
                );
            } else {
                $socket = @stream_socket_client(
                    "tcp://{$host}:{$port}", $errNo, $errStr, $timeout
                );
            }

            if (!$socket) {
                error_log("[Mailer] SMTP connect failed to {$host}:{$port} — {$errStr}");
                return false;
            }

            stream_set_timeout($socket, $timeout);

            $this->smtpRead($socket); // Server greeting

            $this->smtpWrite($socket, "EHLO " . gethostname());
            $this->smtpRead($socket);

            // STARTTLS for TLS
            if ($encryption === 'tls') {
                $this->smtpWrite($socket, "STARTTLS");
                $this->smtpRead($socket);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    error_log("[Mailer] STARTTLS failed for {$host}");
                    return false;
                }
                $this->smtpWrite($socket, "EHLO " . gethostname());
                $this->smtpRead($socket);
            }

            // AUTH XOAUTH2 (an OAuth sender: Microsoft 365 / Outlook), else AUTH LOGIN
            $oauthToken = (string)($smtp['oauth_token'] ?? '');
            if ($useAuth && $oauthToken !== '') {
                [$authOk, $authResp] = self::smtpAuthXoauth2($socket, $user, $oauthToken);
                if (!$authOk) {
                    fclose($socket);
                    error_log("[Mailer] SMTP AUTH XOAUTH2 failed for {$user}: {$authResp}");
                    return false;
                }
            } elseif ($useAuth && $user !== '' && $pass !== '') {
                $this->smtpWrite($socket, "AUTH LOGIN");
                $this->smtpRead($socket);
                $this->smtpWrite($socket, base64_encode($user));
                $this->smtpRead($socket);
                $this->smtpWrite($socket, base64_encode($pass));
                $authResp = $this->smtpRead($socket);
                if (!str_starts_with(trim($authResp), '235')) {
                    fclose($socket);
                    error_log("[Mailer] SMTP AUTH failed for {$user}: {$authResp}");
                    return false;
                }
            }

            $this->smtpWrite($socket, "MAIL FROM:<{$fromEmail}>");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, "RCPT TO:<{$toEmail}>");
            $this->smtpRead($socket);
            foreach ($cc as $ccAddr) {
                $this->smtpWrite($socket, "RCPT TO:<{$ccAddr}>");
                $this->smtpRead($socket);
            }
            $this->smtpWrite($socket, "DATA");
            $this->smtpRead($socket);
            $this->smtpWrite($socket, $message . "\r\n.");
            $dataResp = $this->smtpRead($socket);
            $this->smtpWrite($socket, "QUIT");
            fclose($socket);

            $ok = str_starts_with(trim($dataResp), '250');
            if (!$ok) {
                error_log("[Mailer] SMTP DATA rejected: {$dataResp}");
            }
            return $ok;

        } catch (\Throwable $e) {
            error_log("[Mailer] SMTP exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * SMTP AUTH XOAUTH2 on an open, already-EHLO'd (and STARTTLS'd) socket.
     *
     * The one implementation every SMTP client of the app uses for OAuth
     * accounts — the composer (MailApiController::smtpTransmit), tickets
     * (TicketApiController::smtpSendReply), scheduled sends and ticket
     * replies from cron (CronRunner) and this class.
     *
     * Exchange Online answers a rejected token with "334 <base64 JSON>"
     * instead of a final error; the client must send an empty line to get the
     * 535. Returns [accepted, server reply (with the decoded JSON detail)].
     *
     * @param resource $socket
     * @return array{0: bool, 1: string}
     */
    public static function smtpAuthXoauth2($socket, string $user, string $accessToken): array
    {
        fwrite($socket, 'AUTH XOAUTH2 ' . XOAuth2::initialResponse($user, $accessToken) . "\r\n");
        $resp = self::readSmtpReply($socket);
        if (str_starts_with($resp, '334')) {
            $detail = XOAuth2::errorDetail($resp);
            fwrite($socket, "\r\n");
            $resp = self::readSmtpReply($socket) . ($detail !== '' ? ' (' . $detail . ')' : '');
        }
        return [str_starts_with($resp, '235'), $resp];
    }

    /**
     * One SMTP reply, multi-line ones included ("250-…" lines up to "250 …").
     *
     * @param resource $socket
     */
    public static function readSmtpReply($socket): string
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    }

    private function smtpWrite($socket, string $data): void
    {
        fwrite($socket, $data . "\r\n");
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    // -------------------------------------------------------------------------
    // PHP mail() fallback
    // -------------------------------------------------------------------------

    private function sendPhpMail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $attachments = [],
        array $cc = []
    ): bool {
        if (OutboundMailGuard::blocked($this->db)) {
            OutboundMailGuard::logBlocked('Mailer/mail()', array_merge([$toEmail], $cc), $subject);
            return true;
        }
        $boundary = 'ib_' . bin2hex(random_bytes(8));
        $to       = $toName !== '' ? "{$toName} <{$toEmail}>" : $toEmail;
        $subj     = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $alt  = "--{$boundary}\r\n";
        // Base64 amb CTE declarat: 7 bits nets i cap línia de més de 998 caràcters
        // (un data: URI de 2.400 caràcters al cos invalidava la signatura DKIM).
        $alt .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $alt .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $alt .= chunk_split(base64_encode($textBody), 76, "\r\n");
        $alt .= "--{$boundary}\r\n";
        $alt .= "Content-Type: text/html; charset=UTF-8\r\n";
        $alt .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $alt .= chunk_split(base64_encode($htmlBody), 76, "\r\n");
        $alt .= "--{$boundary}--";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "From: Intrabase <noreply@" . gethostname() . ">\r\n";
        if ($cc !== []) {
            $headers .= "Cc: " . implode(', ', $cc) . "\r\n";
        }

        if ($attachments === []) {
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
            $body = $alt;
        } else {
            $mixBoundary = 'ibm_' . bin2hex(random_bytes(8));
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$mixBoundary}\"\r\n";
            $body  = "--{$mixBoundary}\r\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
            $body .= $alt . "\r\n\r\n";
            $body .= $this->attachmentParts($attachments, $mixBoundary);
            $body .= "--{$mixBoundary}--";
        }

        return @mail($to, $subj, $body, $headers);
    }

    /**
     * Render base64 attachment MIME parts for a multipart/mixed body.
     *
     * @param array<int,array{name?:string,mime?:string,content?:string}> $attachments
     */
    private function attachmentParts(array $attachments, string $boundary): string
    {
        $out = '';
        foreach ($attachments as $att) {
            $name    = str_replace(['"', "\r", "\n"], '', (string)($att['name'] ?? 'attachment'));
            $mime    = str_replace(["\r", "\n"], '', (string)($att['mime'] ?? 'application/octet-stream'));
            $content = (string)($att['content'] ?? '');
            $out .= "--{$boundary}\r\n";
            $out .= "Content-Type: {$mime}; name=\"{$name}\"\r\n";
            $out .= "Content-Transfer-Encoding: base64\r\n";
            $out .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n";
            $out .= chunk_split(base64_encode($content), 76, "\r\n");
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Config loaders
    // -------------------------------------------------------------------------

    private function loadSmtpConfig(?int $smtpId = null): ?array
    {
        // If requesting a specific SMTP account by ID, always load fresh
        if ($smtpId !== null) {
            if ($this->db === null) {
                return null;
            }
            try {
                $pdo  = $this->db->pdo();
                $stmt = $pdo->prepare(
                    "SELECT smtp_host, smtp_port, smtp_user, smtp_password, smtp_secure, smtp_auth,
                            from_email, from_name, reply_to
                       FROM ib_sys_smtp
                      WHERE id = :id AND status = 1
                      LIMIT 1"
                );
                $stmt->execute([':id' => $smtpId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (\Throwable) {
                return null;
            }
        }

        if ($this->smtpLoaded) {
            return $this->smtpConfig;
        }
        $this->smtpLoaded = true;

        if ($this->db === null) {
            return null;
        }

        try {
            $pdo  = $this->db->pdo();
            $stmt = $pdo->prepare(
                "SELECT smtp_host, smtp_port, smtp_user, smtp_password, smtp_secure, smtp_auth,
                        from_email, from_name, reply_to
                   FROM ib_sys_smtp
                  WHERE function_type = 'system' AND is_default = 1 AND status = 1
                  LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->smtpConfig = $row ?: null;
        } catch (\Throwable) {
            $this->smtpConfig = null;
        }

        return $this->smtpConfig;
    }

    private function decryptSmtpPassword(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        $secret = defined('APP_SECRET') ? APP_SECRET : ($_ENV['APP_SECRET'] ?? '');
        if ($secret === '' || !str_contains($encrypted, ':')) {
            return $encrypted; // stored as-is (not encrypted yet)
        }
        try {
            $parts  = explode(':', $encrypted, 2);
            $iv     = base64_decode($parts[0]);
            $cipher = base64_decode($parts[1]);
            $key    = substr(hash('sha256', $secret, true), 0, 32);
            $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $plain !== false ? $plain : $encrypted;
        } catch (\Throwable) {
            return $encrypted;
        }
    }

    private function encodeHeader(string $str): string
    {
        if (preg_match('/[^\x20-\x7E]/', $str)) {
            return '=?UTF-8?B?' . base64_encode($str) . '?=';
        }
        return $str;
    }
}
