<?php

declare(strict_types=1);

namespace Intrabase\View;

use Intrabase\Database\Connection;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Support\UserAppAccess;

/**
 * Default home page: a grid with the active applications the user can open.
 *
 * Replace it with your own class through config('dashboard_class'); it must
 * take (Session, Connection) and expose render(): string.
 */
class Dashboard
{
    public function __construct(
        private Session $session,
        private Connection $db,
    ) {
    }

    public function render(): string
    {
        $apps = $this->apps();
        $user = $this->session->user() ?? [];
        $name = (string) ($user['name'] ?? $user['email'] ?? '');

        $html = '<div class="ib-dashboard">';
        $html .= '<h1 class="ib-dashboard__title">' . Output::e(__('Welcome') . ($name !== '' ? ', ' . $name : '')) . '</h1>';

        if ($apps === []) {
            $html .= '<p class="ib-dashboard__empty">' . Output::e(__('No applications available yet.')) . '</p>';
            return $html . '</div>';
        }

        $html .= '<div class="ib-dashboard__apps">';
        foreach ($apps as $app) {
            $icon = preg_replace('/[^a-z0-9 -]/', '', (string) ($app['icon'] ?? '')) ?: 'fa-solid fa-cube';
            $html .= '<a class="ib-dashboard__app" href="/' . Output::e((string) $app['keyname']) . '">'
                . '<i class="' . Output::e($icon) . '" aria-hidden="true"></i>'
                . '<span>' . Output::e(__((string) $app['name'])) . '</span>'
                . '</a>';
        }
        $html .= '</div></div>';

        return $html;
    }

    /**
     * Active applications shown in the app bar, minus those the user has no access to.
     *
     * @return list<array<string, mixed>>
     */
    private function apps(): array
    {
        try {
            $rows = $this->db->pdo()->query(
                "SELECT keyname, name, icon FROM ib_apps
                  WHERE is_active = 1 AND display_location <> 'hidden'
                  ORDER BY sort_order, name"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            try {
                if (UserAppAccess::currentLevel($this->db, $this->session, (string) $row['keyname']) === 'none') {
                    continue;
                }
            } catch (\Throwable) {
                // No per-person access table yet: show the app.
            }
            $out[] = $row;
        }
        return $out;
    }
}
