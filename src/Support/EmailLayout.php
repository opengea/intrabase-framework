<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;

/**
 * EmailLayout — shared HTML shell for transactional email.
 *
 * Lifted verbatim out of NotificationEngine::wrapEmailLayout() so that other
 * senders (the planner reminder mails raised by CronRunner, and whatever comes
 * next) render inside the very same shell without duplicating the markup.
 * NotificationEngine now delegates here, so its output must stay byte-identical
 * to what it produced before the extraction — do not "improve" the markup here
 * without re-checking the emails NotificationEngine sends.
 *
 * Everything is static and takes the Connection as an argument: the callers are
 * a stateless engine and a cron task, neither of which wants another service to
 * wire up.
 *
 * @package Intrabase\Support
 */
final class EmailLayout
{
    /**
     * Wrap an email body in the responsive HTML layout.
     *
     * If ib_config.email_layout_html is set, that global layout wins and the
     * body is substituted into its {{{body}}} placeholder. Otherwise the
     * built-in layout is rendered, themed by the optional template row
     * ($tpl: bg_color, text_color, link_color, font_family, body_width_px,
     * header_html, footer_html).
     *
     * @param array<string,mixed>|null $tpl Optional ib_lib_email_templates row
     */
    public static function wrap(
        ?Connection $db,
        string $body,
        string $subject,
        ?array $tpl = null,
    ): string {
        $bgColor    = $tpl['bg_color']     ?? '#ffffff';
        $textColor  = $tpl['text_color']   ?? '#1c1c1c';
        $linkColor  = $tpl['link_color']   ?? '#003ba1';
        $fontFamily = $tpl['font_family']  ?? 'Tahoma, Arial, Helvetica, sans-serif';
        $width      = (int)($tpl['body_width_px'] ?? 600);
        $header     = $tpl['header_html']  ?? '';
        $footer     = $tpl['footer_html']  ?? '';

        // Try to load global layout from config
        $layoutHtml = null;
        if ($db !== null) {
            try {
                $layoutHtml = $db->fetchValue(
                    "SELECT config_value FROM ib_config WHERE config_key = 'email_layout_html'"
                );
            } catch (\Throwable) {
                $layoutHtml = null;
            }
        }

        if ($layoutHtml) {
            // If there's a global layout, substitute {{{body}}} in it
            return str_replace('{{{body}}}', $body, (string)$layoutHtml);
        }

        $appName = self::appConfig($db)['app_name'] ?? 'Intrabase';
        $year    = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$subject}</title>
<style>
  body { margin:0; padding:0; background:#f4f4f4; font-family:{$fontFamily}; }
  .wrapper { width:100%; background:#f4f4f4; padding:20px 0; }
  .container { max-width:{$width}px; margin:0 auto; background:{$bgColor}; border-radius:8px; overflow:hidden; }
  .header { background:#1a1a2e; padding:24px 32px; text-align:center; }
  .header span { color:#ffffff; font-size:20px; font-weight:bold; letter-spacing:1px; }
  .content { padding:32px; color:{$textColor}; font-size:15px; line-height:1.6; }
  .content a { color:{$linkColor}; }
  .footer { background:#f8f8f8; padding:16px 32px; text-align:center; color:#888; font-size:12px; border-top:1px solid #e0e0e0; }
</style>
</head>
<body>
<div class="wrapper">
  <div class="container">
    <div class="header">{$header}<span>{$appName}</span></div>
    <div class="content">{$body}</div>
    <div class="footer">{$footer}&copy; {$year} {$appName}. Tots els drets reservats.</div>
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * app_name / app_url from ib_config. Empty array when unreadable, so every
     * caller has to supply its own default (as they already did).
     *
     * @return array<string,string>
     */
    public static function appConfig(?Connection $db): array
    {
        if ($db === null) {
            return [];
        }
        try {
            $rows = $db->fetchAll(
                "SELECT config_key, config_value FROM ib_config WHERE config_key IN ('app_name','app_url')"
            );
            $config = [];
            foreach ($rows as $r) {
                $config[$r['config_key']] = $r['config_value'];
            }
            return $config;
        } catch (\Throwable) {
            return [];
        }
    }
}
