<?php

declare(strict_types=1);

namespace Intrabase\Tests\Fakes;

use Intrabase\Domain\Contracts\MailerInterface;

/**
 * InMemoryMailer — test double for MailerInterface.
 *
 * Records every call to send() as a SentMessage object; never touches
 * the network. Inspect $sent (or the helpers below) from the test.
 *
 * @package Intrabase\Tests\Fakes
 */
final class InMemoryMailer implements MailerInterface
{
    /** @var array<int, array{
     *   toEmail: string, toName: string, subject: string,
     *   htmlBody: string, textBody: string, smtpId: int|null
     * }>
     */
    public array $sent = [];

    public bool $willFail = false;

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        ?int   $smtpId = null,
    ): bool {
        $this->sent[] = [
            'toEmail'  => $toEmail,
            'toName'   => $toName,
            'subject'  => $subject,
            'htmlBody' => $htmlBody,
            'textBody' => $textBody !== '' ? $textBody : strip_tags($htmlBody),
            'smtpId'   => $smtpId,
        ];
        return !$this->willFail;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    public function clear(): void
    {
        $this->sent = [];
    }
}
