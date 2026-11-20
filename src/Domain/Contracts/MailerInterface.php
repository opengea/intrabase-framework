<?php

declare(strict_types=1);

namespace Intrabase\Domain\Contracts;

/**
 * MailerInterface — outbound transactional email boundary
 *
 * Application code (controllers, use cases) MUST depend on this interface
 * instead of a concrete Mailer. This decouples business logic from the
 * transport (SMTP, sendmail, SendGrid, MailHog in tests, etc.).
 *
 * Concrete implementations live in Infrastructure:
 *   - Intrabase\Support\Mailer           (legacy, SMTP via stream sockets)
 *   - Intrabase\Infrastructure\Mail\*    (future adapters)
 *   - Intrabase\Tests\Fakes\InMemoryMailer (records messages; unit tests)
 *
 * @package Intrabase\Domain\Contracts
 */
interface MailerInterface
{
    /**
     * Send an email synchronously.
     *
     * @param  string   $toEmail   Primary recipient
     * @param  string   $toName    Display name (may be empty)
     * @param  string   $subject   Already-decoded subject
     * @param  string   $htmlBody  HTML part (may be empty)
     * @param  string   $textBody  Plain-text part; derived from $htmlBody if empty
     * @param  int|null $smtpId    Pick a specific SMTP account from ib_sys_smtp
     * @return bool                True on success, false on failure (error_log-ed)
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        ?int   $smtpId = null,
    ): bool;
}
