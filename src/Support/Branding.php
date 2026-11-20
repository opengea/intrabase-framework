<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Core\Application;

/**
 * Branding
 *
 * Per-tenant brand assets. A tenant can set `ib_tenants.logo` (web path to an
 * SVG/PNG served by this install, e.g. /uploads/branding/acme.svg); every
 * piece of interface CHROME (login box, boot splash, sidebar, app header,
 * favicon) resolves its mark through here so it follows the tenant, while
 * PRODUCT contexts (About dialog, trial signup, mail provider tiles) keep the
 * Intrabase mark.
 *
 * Contract: the chrome flattens/inverts the logo with CSS
 * (`brightness(0) invert(1)` on dark surfaces, `filter:none` on light), so a
 * tenant file must be the DARK version of the mark — same convention as the
 * default logo.svg.
 */
final class Branding
{
    public const DEFAULT_LOGO = '/assets/img/logo.svg';

    /**
     * Web path of the brand logo used by the app CHROME (header, sidebars,
     * boot splash, favicon and e-mail conventions): the tenant logo if set,
     * Intrabase's otherwise. With `brand_login_only = 1` in the tenant's
     * `ib_config` the tenant mark is shown on the login-family cards only
     * (see loginLogo()) and the interface keeps the Intrabase logo.
     */
    public static function logo(): string
    {
        return self::loginOnly() ? self::DEFAULT_LOGO : self::tenantLogo();
    }

    /**
     * Capçalera de les targetes d'entrada (login, contrasenya, verificació…): sempre el logo
     * d'Intrabase i, si la intranet té marca pròpia, el logo i el nom del client a sota, petits
     * Va dins de `.ib-login-box__logo`.
     */
    public static function loginMarkHtml(): string
    {
        $ib = '<img src="' . self::DEFAULT_LOGO . '" alt="Intrabase" class="ib-login-box__logo-img">';
        $logo = self::tenantLogo();
        $name = self::name();
        $hasLogo = $logo !== self::DEFAULT_LOGO;
        if (!$hasLogo && $name === 'Intrabase') {
            return $ib;
        }
        // Intranet amb marca pròpia: el client a dalt i Intrabase a sota, discret (12/11/2026).
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $wide = $hasLogo && self::logoAspect() >= 1.4;
        return '<div class="ib-login-box__tenant' . ($wide ? ' ib-login-box__tenant--wide' : '') . '">'
             . ($hasLogo ? '<img src="' . $e($logo) . '" alt="" class="ib-login-box__tenant-img">' : '')
             . '<span class="ib-login-box__tenant-name">' . $e($name) . '</span>'
             . '</div>'
             . '<div class="ib-login-box__powered"><img src="' . self::DEFAULT_LOGO . '" alt="Intrabase" class="ib-login-box__powered-img"><span>' . $e(self::loginHost() ?: 'Intrabase') . '</span></div>';
    }

    /** Adreça de la intranet on s'entra (sense port), o '' si no és un nom de host vàlid. */
    public static function loginHost(): string
    {
        $h = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
        return preg_match('/^[a-z0-9.-]{3,253}$/', $h) ? $h : '';
    }

    /** La intranet té marca pròpia (logo o nom), i per tant la targeta ja porta l'adreça a sota. */
    public static function hasTenantMark(): bool
    {
        return self::tenantLogo() !== self::DEFAULT_LOGO || self::name() !== 'Intrabase';
    }

    /** Web path of the mark on the login-family cards: always the tenant logo when set. */
    public static function loginLogo(): string
    {
        return self::tenantLogo();
    }

    private static function tenantLogo(): string
    {
        $tenant = Application::getInstance()->getTenantConfig();
        $logo   = trim((string) ($tenant['logo'] ?? ''));
        return $logo !== '' ? $logo : self::DEFAULT_LOGO;
    }

    /** `ib_config.brand_login_only` of the tenant DB, read once per request; false when unreadable. */
    private static function loginOnly(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = false;
        try {
            $db = Application::getInstance()->getDb();
            if ($db !== null) {
                $row = $db->fetchOne("SELECT config_value FROM ib_config WHERE config_key = 'brand_login_only' LIMIT 1");
                $cached = $row !== null && in_array(strtolower(trim((string) ($row['config_value'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
            }
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Width/height of the brand logo, 1.0 when unknown. Read from the SVG's
     * viewBox (or width/height attributes) or, for bitmaps, getimagesize().
     * The login-family cards size the mark by WIDTH (48px, a square slot):
     * a wide wordmark (e.g. 367x194) came out 25px tall. Anything wider
     * than 1.4:1 gets the `--wide` modifier and a wider slot instead.
     */
    public static function logoAspect(): float
    {
        $file = self::resolveLogoFile(self::loginLogo());
        if ($file === null) return 1.0;
        try {
            if (preg_match('/\.svg$/i', $file)) {
                $head = (string) file_get_contents($file, false, null, 0, 4096);
                if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $head, $m)
                    || (preg_match('/\swidth\s*=\s*["\']([\d.]+)/i', $head, $w)
                        && preg_match('/\sheight\s*=\s*["\']([\d.]+)/i', $head, $h)
                        && ($m = [null, $w[1], $h[1]]))) {
                    $wv = (float) $m[1]; $hv = (float) $m[2];
                    return ($wv > 0 && $hv > 0) ? $wv / $hv : 1.0;
                }
                return 1.0;
            }
            $size = @getimagesize($file);
            return ($size && $size[0] > 0 && $size[1] > 0) ? $size[0] / $size[1] : 1.0;
        } catch (\Throwable) {
            return 1.0;
        }
    }

    /** Class attribute value for the login-family `<img>`: base class plus `--wide` for landscape marks. */
    public static function logoImgClass(): string
    {
        return 'ib-login-box__logo-img' . (self::logoAspect() >= 1.4 ? ' ib-login-box__logo-img--wide' : '');
    }

    /**
     * Web URL of an ENTITY logo (ib_obj_entities.logo). Native uploads store a
     * bare filename under /uploads/entities/; tenant-seeded entities (e.g. the
     * a tenant census) may carry an absolute path or URL already — use it as is.
     * Empty input returns ''. (31/08/2026: /uploads/entities//data/... 404s.)
     */
    public static function entityLogoUrl(string $logo): string
    {
        $logo = trim($logo);
        if ($logo === '') return '';
        if ($logo[0] === '/' || preg_match('#^https?://#i', $logo)) return $logo;
        return '/uploads/entities/' . $logo;
    }

    /**
     * Logo d'una EMPRESA per a un correu: ruta web d'un PNG/JPG i mida per a 40 px d'alt, o null.
     * Els clients de correu no mostren SVG i molts tampoc WebP: un WebP es converteix una sola vegada a
     * un PNG germà (`<nom>-email.png`, amb dwebp perquè el GD no té WebP) i un SVG amb rsvg-convert.
     * (12/11/2026, invitacions d'usuari)
     *
     * @return array{path:string,width:int,height:int}|null
     */
    public static function entityEmailLogo(string $logo, int $height = 40): ?array
    {
        $url = self::entityLogoUrl($logo);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return null;
        }
        $public = realpath(__DIR__ . '/../../public');
        $file = $public !== false ? realpath($public . (parse_url($url, PHP_URL_PATH) ?: '')) : false;
        if ($file === false || !str_starts_with($file, $public . '/') || !is_file($file)) {
            return null;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            $png = substr($file, 0, -strlen($ext) - 1) . '-email.png';
            if (!is_file($png) || filemtime($png) < filemtime($file)) {
                $cmd = $ext === 'webp' ? '/usr/bin/dwebp ' . escapeshellarg($file) . ' -o ' . escapeshellarg($png)
                     : ($ext === 'svg' ? '/usr/bin/rsvg-convert -h 160 ' . escapeshellarg($file) . ' -o ' . escapeshellarg($png) : '');
                if ($cmd === '') {
                    return null;
                }
                @exec($cmd . ' >/dev/null 2>&1');
                if (!is_file($png) || filesize($png) === 0) {
                    return null;
                }
            }
            $file = $png;
            $url = substr($url, 0, strrpos($url, '.')) . '-email.png';
        }
        $size = @getimagesize($file);
        if (!$size || $size[1] <= 0) {
            return null;
        }
        $w = (int) round($height * $size[0] / $size[1]);
        if ($w > 220) {
            $w = 220;
            $height = (int) round(220 * $size[1] / $size[0]);
        }
        return ['path' => $url, 'width' => $w, 'height' => $height];
    }

    /**
     * Contrast background for an ENTITY logo, so dark logos with a transparent
     * background stay visible on dark chrome (and vice versa). Same heuristic
     * as a tenant provider (e.g. a `*_logo_bg` setting): average
     * luminance of declared SVG fill/stroke colours, or a 24x24 alpha-aware
     * sample for rasters. Returns '#fff' for dark logos, '#1f2937' for light
     * ones, or null when the logo has its own opaque background, is remote,
     * or cannot be judged. Cached per request. (31/08/2026)
     */
    public static function entityLogoBg(string $logo): ?string
    {
        static $cache = [];
        $logo = trim($logo);
        if ($logo === '') return null;
        if (array_key_exists($logo, $cache)) return $cache[$logo];
        $path = self::resolveLogoFile($logo);
        if ($path === null) return $cache[$logo] = null;
        return $cache[$logo] = self::logoContrastBg($path);
    }

    /** Local file behind an entity logo value; null for remote/unknown paths. */
    private static function resolveLogoFile(string $logo): ?string
    {
        if (preg_match('#^https?://#i', $logo)) return null;
        if ($logo[0] === '/') {
            // Tenant-served asset (e.g. a tenant census: /data/...): resolve
            // against the tenant data dir. Other absolute paths: /uploads/...
            // lives under public/.
            if (str_starts_with($logo, '/data/')) {
                try {
                    $dataPath = Application::getInstance()->getTenantDataPath();
                } catch (\Throwable $e) {
                    return null;
                }
                if ($dataPath === null) return null;
                $file = $dataPath . substr(strtok($logo, '?'), 5);
                return is_file($file) ? $file : null;
            }
            $file = dirname(__DIR__, 2) . '/public' . strtok($logo, '?');
            return is_file($file) ? $file : null;
        }
        $file = dirname(__DIR__, 2) . '/public/uploads/entities/' . $logo;
        return is_file($file) ? $file : null;
    }

    /** The luminance heuristic itself; $path must be a readable local file. */
    public static function logoContrastBg(string $path): ?string
    {
        if (!is_file($path) || (int) filesize($path) > 2 * 1024 * 1024) return null;
        // Resultat desat a disc (clau: camí + mida + data): el WebP s'ha de convertir amb dwebp
        // perquè el GD d'aquest PHP no en té suport, i no s'ha de fer a cada pàgina. (12/11/2026)
        $cacheFile = sys_get_temp_dir() . '/ib-logo-bg-v2-' . md5($path . '|' . filesize($path) . '|' . filemtime($path));
        if (is_file($cacheFile)) {
            $v = trim((string) @file_get_contents($cacheFile));
            return $v === '' ? null : $v;
        }
        $r = self::logoContrastBgCompute($path);
        @file_put_contents($cacheFile, (string) $r);
        return $r;
    }

    private static function logoContrastBgCompute(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $lum = null;

        if ($ext === 'svg') {
            $txt = (string) file_get_contents($path);
            if (preg_match('/<image[\s>]/i', $txt)) return null; // embedded raster
            preg_match_all('/(?:fill|stroke)\s*[:=]\s*["\']?(#[0-9a-fA-F]{3,8}|black|white)/', $txt, $m);
            $lums = [];
            foreach ($m[1] as $c) {
                $c = strtolower($c);
                if ($c === 'black') { $lums[] = 0.0; continue; }
                if ($c === 'white') { $lums[] = 255.0; continue; }
                $h = substr($c, 1);
                if (strlen($h) === 3 || strlen($h) === 4) {
                    $r = hexdec($h[0] . $h[0]); $g = hexdec($h[1] . $h[1]); $b = hexdec($h[2] . $h[2]);
                } elseif (strlen($h) >= 6) {
                    $r = hexdec(substr($h, 0, 2)); $g = hexdec(substr($h, 2, 2)); $b = hexdec(substr($h, 4, 2));
                } else {
                    continue;
                }
                $lums[] = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
            }
            if ($lums === []) return null;
            $lum = array_sum($lums) / count($lums);
        } elseif (in_array($ext, ['png', 'gif', 'webp', 'jpg', 'jpeg'], true)
                  && function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring((string) file_get_contents($path));
            if (!$im && $ext === 'webp' && is_executable('/usr/bin/dwebp')) {
                $tmp = tempnam(sys_get_temp_dir(), 'ib-logo-');
                if ($tmp !== false) {
                    exec('/usr/bin/dwebp ' . escapeshellarg($path) . ' -o ' . escapeshellarg($tmp) . ' >/dev/null 2>&1', $o, $rc);
                    if ($rc === 0) {
                        $im = @imagecreatefrompng($tmp);
                    }
                    @unlink($tmp);
                }
            }
            if (!$im) return null;
            imagesavealpha($im, true);
            $w = imagesx($im); $h = imagesy($im);
            if ($w < 1 || $h < 1) { imagedestroy($im); return null; }
            $n = 24; $sum = 0.0; $op = 0; $alphaSum = 0; $tot = 0;
            for ($iy = 0; $iy < $n; $iy++) {
                for ($ix = 0; $ix < $n; $ix++) {
                    $px = imagecolorat($im, (int) ($ix * $w / $n), (int) ($iy * $h / $n));
                    $a  = ($px >> 24) & 0x7F; // GD: 0 opaque … 127 transparent
                    $alphaSum += 127 - $a; $tot++;
                    if ($a < 14) { // ≈ alpha > 200/255
                        $r = ($px >> 16) & 0xFF; $g = ($px >> 8) & 0xFF; $b = $px & 0xFF;
                        $sum += 0.2126 * $r + 0.7152 * $g + 0.0722 * $b; $op++;
                    }
                }
            }
            imagedestroy($im);
            if ($op === 0) return null;
            if ($alphaSum / (127 * $tot) > 0.85) return null; // opaque background of its own
            $lum = $sum / $op;
        } else {
            return null;
        }

        return $lum < 140 ? '#fff' : '#1f2937';
    }

    /** True when the tenant defines its own logo. */
    public static function isCustom(): bool
    {
        return self::logo() !== self::DEFAULT_LOGO;
    }

    /** Brand display name: tenant client_name if set, 'Intrabase'. */
    public static function name(): string
    {
        $tenant = Application::getInstance()->getTenantConfig();
        $name   = trim((string) ($tenant['client_name'] ?? ''));
        return $name !== '' ? $name : 'Intrabase';
    }

    /**
     * Web path of the logo variant to use as FAVICON.
     *
     * Convention: if a sibling file with the `-favicon` suffix exists next to
     * the tenant logo (e.g. /uploads/branding/acme-favicon.svg for
     * /uploads/branding/acme.svg), that one is used. The favicon renders on
     * the BROWSER's tab strip, which follows the OS/browser color scheme, so
     * the variant should carry its own `prefers-color-scheme` CSS (dark mark
     * on light, light mark on dark). The plain logo stays dark-only because
     * the in-page <img> chrome flattens it with CSS filters — the two files
     * live under different rules and must not be merged.
     */
    public static function faviconLogo(): string
    {
        $logo = self::logo();
        $dot  = strrpos($logo, '.');
        if ($dot !== false) {
            $variant = substr($logo, 0, $dot) . '-favicon' . substr($logo, $dot);
            if (is_file(__DIR__ . '/../../public' . parse_url($variant, PHP_URL_PATH))) {
                return $variant;
            }
        }
        return $logo;
    }

    /**
     * Web path of the logo variant to use in OUTGOING EMAIL, or null when
     * there is none.
     *
     * Convention: a sibling file with the `-email.png` suffix next to the
     * tenant logo (e.g. /uploads/branding/acme-email.png for
     * /uploads/branding/acme.svg). Email clients need a raster image (Gmail
     * blocks SVG) sized with HTML width/height attributes, so the variant is
     * always a PNG prepared for a light background. No variant → the email
     * shows the brand NAME as text only.
     */
    public static function emailLogo(): ?string
    {
        if (!self::isCustom()) {
            return null;
        }
        $logo = self::logo();
        $dot  = strrpos($logo, '.');
        if ($dot === false) {
            return null;
        }
        $variant = substr($logo, 0, $dot) . '-email.png';
        if (is_file(__DIR__ . '/../../public' . parse_url($variant, PHP_URL_PATH))) {
            return $variant;
        }
        return null;
    }

    /**
     * Web path of the logo variant to use as APPLE-TOUCH-ICON (mobile home
     * screen / PWA install), or null when there is none.
     *
     * Convention: a sibling file with the `-touch.png` suffix next to the
     * tenant logo (e.g. /uploads/branding/acme-touch.png). Home-screen icons
     * need a raster with a SOLID background (iOS flattens transparency to
     * black); one square PNG (512×512) serves every size — launchers scale
     * down. No variant → callers keep the Intrabase icons.
     */
    public static function appleTouchIcon(): ?string
    {
        if (!self::isCustom()) {
            return null;
        }
        $logo = self::logo();
        $dot  = strrpos($logo, '.');
        if ($dot === false) {
            return null;
        }
        $variant = substr($logo, 0, $dot) . '-touch.png';
        if (is_file(__DIR__ . '/../../public' . parse_url($variant, PHP_URL_PATH))) {
            return $variant;
        }
        return null;
    }

    /**
     * <link rel="icon"> for the brand SVG favicon, or '' when the tenant has
     * no custom logo (callers keep their default PNG/ICO links in that case).
     * Browsers that understand SVG favicons prefer this link; the PNG/ICO
     * fallbacks around it keep older browsers working.
     */
    public static function faviconSvgLink(): string
    {
        if (!self::isCustom()) {
            return '';
        }
        $logo = htmlspecialchars(self::faviconLogo(), ENT_QUOTES);
        return '<link rel="icon" type="image/svg+xml" href="' . $logo . '">';
    }
}
