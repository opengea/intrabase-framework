<?php

declare(strict_types=1);

namespace Intrabase\Domain\Events;

use Intrabase\Core\Application;
use Intrabase\Domain\Contracts\AbstractDomainEvent;
use Intrabase\Domain\Contracts\EventBusInterface;

/**
 * A user just proved their password at the login form.
 *
 * It is the only moment the plain password is known, so plugins that keep a
 * copy of the same credential elsewhere (e.g. a mailbox account still
 * without one) can fill it in. Never log or store the event as is.
 */
final class UserLoggedInWithPassword extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        #[\SensitiveParameter] public readonly string $plainPassword,
    ) {
        parent::__construct();
    }

    /** Dispatch on the application's event bus. Never throws: the login goes on. */
    public static function publish(int $userId, string $email, #[\SensitiveParameter] string $plainPassword): void
    {
        try {
            Application::getInstance()->getContainer()->get(EventBusInterface::class)
                ->dispatch(new self($userId, $email, $plainPassword));
        } catch (\Throwable $e) {
            error_log('[UserLoggedInWithPassword] listener failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }
}
