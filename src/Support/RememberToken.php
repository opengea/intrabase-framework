<?php

declare(strict_types=1);

namespace Intrabase\Support;

/**
 * The native apps' «ib_remember» cookie is stored as «h1:» + SHA-256 of the
 * token, never the token itself, like the trusted devices: a read of
 * ib_remember_tokens does not give anyone a working cookie. Whoever reads the
 * cookie back looks it up with stored($cookie). The prefix keeps a stolen
 * stored value from ever being a valid cookie (cookies are 64 hex characters).
 */
final class RememberToken
{
    public const PREFIX = 'h1:';

    public static function stored(string $token): string
    {
        return self::PREFIX . hash('sha256', $token);
    }
}
