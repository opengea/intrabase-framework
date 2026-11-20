<?php

declare(strict_types=1);

namespace Intrabase\View;

/**
 * What plugins add to the page layout without the kernel knowing them.
 *
 *   - scripts:   HTML (usually <script>) printed at the end of every page,
 *                e.g. an unread-messages poller that feeds window.ibTabBadge.
 *   - userMenu:  entries of the avatar menu (profile, preferences...).
 *   - csp:       extra Content-Security-Policy sources per directive.
 *
 * Register from PluginInterface::register(). Callables are evaluated when the
 * page is rendered, so they can read the session and the active app.
 */
final class LayoutExtensions
{
    /** @var list<callable(): string> */
    private static array $scripts = [];

    /** @var list<array{href: string, icon: string, label: string}> */
    private static array $userMenu = [];

    /** @var array<string, list<string>> */
    private static array $csp = [];

    /**
     * @param callable(): string $render returns trusted HTML
     */
    public static function addScript(callable $render): void
    {
        self::$scripts[] = $render;
    }

    /**
     * @param string $label translation key or text, passed through __()
     */
    public static function addUserMenuItem(string $href, string $icon, string $label): void
    {
        self::$userMenu[] = ['href' => $href, 'icon' => $icon, 'label' => $label];
    }

    /**
     * @param string       $directive e.g. 'frame-src'
     * @param list<string> $sources   http(s) origins or blob:/data:
     */
    public static function addCspSources(string $directive, array $sources): void
    {
        if (!preg_match('/^[a-z-]+-src$/', $directive)) {
            return;
        }
        foreach ($sources as $src) {
            if (is_string($src) && preg_match('#^(https?://[A-Za-z0-9.-]+(:\d+)?|blob:|data:)$#', $src)) {
                self::$csp[$directive][] = $src;
            }
        }
    }

    public static function scriptsHtml(): string
    {
        $html = '';
        foreach (self::$scripts as $render) {
            try {
                $html .= (string) $render() . "\n";
            } catch (\Throwable $e) {
                error_log('[LayoutExtensions] script: ' . $e->getMessage());
            }
        }
        return $html;
    }

    /** @return list<array{href: string, icon: string, label: string}> */
    public static function userMenu(): array
    {
        return self::$userMenu;
    }

    /** @return array<string, list<string>> */
    public static function csp(): array
    {
        return self::$csp;
    }
}
