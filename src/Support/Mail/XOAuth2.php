<?php

declare(strict_types=1);

namespace Intrabase\Support\Mail;

/**
 * The SASL XOAUTH2 initial response, shared by IMAP and SMTP.
 *
 *   base64("user=" <address> ^A "auth=Bearer " <access token> ^A ^A)
 *
 * Google and Microsoft both define it this way (developers.google.com/gmail/imap/xoauth2-protocol,
 * learn.microsoft.com/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth).
 *
 * On failure both servers answer the initial response with a CONTINUATION
 * carrying a base64 JSON error ({"status":"401",…}) instead of a final NO;
 * the client has to send an empty line to get the tagged NO. {@see errorDetail()}
 * turns that challenge into something worth logging.
 */
final class XOAuth2
{
    private function __construct()
    {
    }

    public static function initialResponse(string $user, string $accessToken): string
    {
        return base64_encode('user=' . $user . "\x01auth=Bearer " . $accessToken . "\x01\x01");
    }

    /**
     * The JSON a server puts in its failure challenge ("+ eyJzdGF0dXMiOi…"),
     * decoded to a short line; '' when the challenge is not one.
     */
    public static function errorDetail(string $challenge): string
    {
        $challenge = trim($challenge);
        if (str_starts_with($challenge, '+')) {
            $challenge = trim(substr($challenge, 1));
        }
        if (str_starts_with($challenge, '334')) {
            $challenge = trim(substr($challenge, 3));
        }
        if ($challenge === '') {
            return '';
        }
        $json = base64_decode($challenge, true);
        if ($json === false) {
            return '';
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return trim($json);
        }
        $bits = [];
        foreach (['status', 'schemes', 'scope'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                $bits[] = $k . '=' . $data[$k];
            }
        }
        return implode(' ', $bits);
    }
}
