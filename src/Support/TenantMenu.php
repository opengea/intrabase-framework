<?php
declare(strict_types=1);
namespace Intrabase\Support;

use Intrabase\Security\Session;

/**
 * TenantMenu — ítems que el tenant afegeix al desplegable de l'avatar.
 * Hook `<data>/avatar_menu.php` → callable(array $ibUser, ?array $activeEntity): array d'ítems
 * {label, icon?, href?, js?}. `js` és el nom d'una funció global que intrabase.js crida en clicar.
 */
final class TenantMenu
{
    public const HOOK_FILE = 'avatar_menu.php';

    public static function items(Session $session, ?array $activeEntity): array
    {
        $fn = TenantHook::resolve(self::HOOK_FILE);
        if ($fn === null) return [];
        try {
            return self::normalize($fn($session->user() ?? [], $activeEntity));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Pur: descarta el que no és segur o no es pot pintar. */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $it) {
            if (!is_array($it)) continue;
            $label = trim((string)($it['label'] ?? ''));
            $href  = trim((string)($it['href'] ?? ''));
            $js    = trim((string)($it['js'] ?? ''));
            if ($label === '') continue;
            if ($js !== '' && !preg_match('/^[A-Za-z_]\w*$/', $js)) $js = '';
            if ($href !== '' && !preg_match('#^(/|https://)#', $href)) $href = '';
            if ($href === '' && $js === '') continue;
            $icon = trim((string)($it['icon'] ?? ''));
            $out[] = ['label' => $label, 'icon' => $icon !== '' ? $icon : 'fa-solid fa-circle', 'href' => $href, 'js' => $js];
        }
        return $out;
    }
}
