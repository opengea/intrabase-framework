<?php

declare(strict_types=1);

namespace Intrabase\Domain\Events;

use Intrabase\Core\Application;
use Intrabase\Domain\Contracts\AbstractDomainEvent;
use Intrabase\Domain\Contracts\EventBusInterface;

/**
 * A user's password was changed (by the user, an administrator or a reset link).
 *
 * Plugins that keep their own copy of the credential (e.g. a mailbox with the
 * same password) subscribe to it. The plain password is only kept in memory
 * for the listeners of this request; never log or store the event as is.
 */
final class UserPasswordChanged extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        #[\SensitiveParameter] public readonly string $plainPassword,
    ) {
        parent::__construct();
    }

    /**
     * Dispatch on the application's event bus. Never throws: a failing
     * listener must not undo a password change that is already saved.
     */
    public static function publish(int $userId, string $email, #[\SensitiveParameter] string $plainPassword): void
    {
        try {
            Application::getInstance()->getContainer()->get(EventBusInterface::class)
                ->dispatch(new self($userId, $email, $plainPassword));
        } catch (\Throwable $e) {
            error_log('[UserPasswordChanged] listener failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }
}
