<?php

declare(strict_types=1);

namespace Intrabase\View;

use Intrabase\Core\Router;
use Intrabase\Database\Connection;
use Intrabase\Lang\Lang;
use Intrabase\Security\CSRF;
use Intrabase\Security\Output;
use Intrabase\Security\Session;
use Intrabase\Support\AppAccess;
use Intrabase\Support\AssetResolver;
use Intrabase\Support\ConfigManager;
use PDO;

/**
 * Layout
 *
 * Renders the full HTML shell for every authenticated page:
 * (A) App-bar — narrow vertical strip (~52px) with one icon-button per app.
 * (B) Sidebar — collapsible panel showing modules of the active app.
 * (C) Header  — top bar with hamburger, search, messages, notifications, avatar dropdown.
 * (D) Main content area (flash + content + footer).
 */
final class Layout
{
    private const APP_NAME_DEFAULT = 'Intrabase';

    private string $appName;

    public function __construct(
        private readonly Session    $session,
        private readonly CSRF       $csrf,
        private readonly Connection $db,
    ) {
        $tenant = \Intrabase\Core\Application::getInstance()->getTenantConfig();
        $this->appName = !empty($tenant['client_name']) ? $tenant['client_name'] : self::APP_NAME_DEFAULT;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function render(string $content, array $options = []): void
    {
        // Embed mode: render only CSS + content, no chrome (header/sidebar/footer)
        // Used for iframing module pages inside preferences, etc.
        // Also opt-in via options['embed'] for controllers exposing their own
        // embedded flag (e.g. /contacts?embedded=1 inside /mailing).
        if (!empty($_GET['_embed']) || !empty($options['embed'])) {
            $this->renderEmbed($content, $options);
            return;
        }

        $lang        = Output::e($options['lang']        ?? \Intrabase\Lang\Lang::locale());
        // El títol pot ser una clau d'ib_lang (el $title dels mòduls nous); un text que no ho és torna igual
        $title       = Output::e(__((string) ($options['title'] ?? $this->appName)));
        $extraCss    = $options['css']                   ?? [];
        $extraJs     = $options['js']                    ?? [];
        $breadcrumb  = $options['breadcrumb']            ?? [];
        $isDashboard = (bool)($options['isDashboard']   ?? false);
        $noSidebar   = (bool)($options['noSidebar']     ?? false);

        // fullTitle will be set after activeApp is resolved (below)

        $hideSidebar = $noSidebar;

        $allApps    = $this->loadApps();
        $apps       = array_values(array_filter($allApps, fn(array $a) => ($a['display_location'] ?? 'appbar') === 'appbar'));
        $headerApps = array_values(array_filter($allApps, fn(array $a) => ($a['display_location'] ?? 'appbar') === 'header'));
        $currentMod = Router::currentMod() ?: ($_GET['mod'] ?? '');
        // Always detect active app (needed for title and favicon), even when sidebar is hidden
        $activeApp  = $this->detectActiveApp($allApps, $currentMod);
        if ($isDashboard) {
            $activeApp = $activeApp ?? [];
            $activeApp['name'] = 'Dashboard';
            $activeApp['keyname'] = 'dashboard';
            $activeApp['modules_list'] = [];
            $hideSidebar = true;
        }
        $sidebarCollapsed = (int)($activeApp['sidebar_collapsed'] ?? 0);

        $csrfMeta    = $this->csrfMeta();
        $cssLinks    = $this->buildCssLinks($extraCss);
        $jsScripts   = $this->buildJsScripts($extraJs);
        $appBarHtml  = $this->buildAppBar($apps, $activeApp, $isDashboard);
        $sidebarHtml = $hideSidebar ? '' : $this->buildSidebar($activeApp, $currentMod);
        // Barra superior de les apps amb menú (títol, cercador i accions), amb l'estil de l'app de correu
        $appTopHtml  = $hideSidebar ? '' : $this->buildAppTop($activeApp ?? [], $currentMod);
        $headerHtml  = $this->buildHeader($breadcrumb, $headerApps);
        $flashHtml   = $this->buildFlash();
        // Footer: config('footer_html') (trusted HTML) or "© year <name>".
        $footerCfg   = (string) (\Intrabase\Core\Application::getInstance()->getConfig()['footer_html'] ?? '');
        $footerHtml  = $footerCfg !== ''
            ? $footerCfg
            : '&copy; ' . $this->currentYear() . ' ' . Output::e(\Intrabase\Support\Branding::name());
        $pluginScripts = LayoutExtensions::scriptsHtml();
        $bodyClass   = $isDashboard ? ' ib-layout--dashboard' : '';
        $bodyClass  .= $noSidebar ? ' ib-layout--no-sidebar' : '';
        $bodyClass  .= $hideSidebar ? '' : ' ib-layout--app';
        // Els botons i eines del llistat ja surten a la franja: s'amaguen del llistat
        $bodyClass  .= ($appTopHtml !== '' && self::$moduleTopSearch !== null) ? ' ib-apptop--search' : '';
        $bodyClass  .= ($appTopHtml !== '' && self::$moduleTopFilters !== null) ? ' ib-apptop--filters' : '';
        $bodyClass  .= ($appTopHtml !== '' && self::$moduleTopTools !== null) ? ' ib-apptop--tools' : '';
        $bodyClass  .= $sidebarCollapsed ? ' ib-sidebar-closed' : '';
        // Tenant attribute for per-tenant CSS overrides
        $tenantCfg = \Intrabase\Core\Application::getInstance()->getTenantConfig();
        $tenantKey = Output::attr((string)($tenantCfg['db_name'] ?? ''));
        // Native mobile app: add a class so CSS can apply the same safe-area
        // / overscroll rules currently guarded by (display-mode: standalone)
        if (preg_match('/IntrabaseMobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $bodyClass .= ' ib-native-mobile';
        }
        $activeAppName = Output::e((string)($activeApp['name'] ?? ''));
        // Build page title: "Module – App – Intrabase" or "App – Intrabase" or "Intrabase"
        if ($title !== $this->appName && $activeAppName !== '' && $title !== $activeAppName) {
            $fullTitle = $title . ' – ' . $activeAppName;
        } elseif ($activeAppName !== '' && $activeAppName !== 'Dashboard') {
            $fullTitle = $activeAppName;
        } else {
            $fullTitle = $title;
        }
        // Build ibI18n: core keys + JS-specific translations
        $ibI18nCore = [
            'confirm_delete'   => __('confirm_delete'),
            'confirm_continue' => __('confirm_continue'),
            'no_results_for'   => __('no_results_for'),
            'theme_to_dark'    => __('theme_to_dark'),
            'theme_to_light'   => __('theme_to_light'),
            'session_expired_title'  => __('session_expired_title'),
            'session_expired_text'   => __('session_expired_text'),
            'session_expired_button' => __('session_expired_button'),
        ];
        $locale = Lang::locale();
        $jsLangFile = dirname(__DIR__, 2) . '/lang/js_' . $locale . '.php';
        $jsStrings = is_file($jsLangFile) ? (require $jsLangFile) : [];
        if (!$jsStrings) {
            $jsFallback = dirname(__DIR__, 2) . '/lang/js_ca.php';
            $jsStrings = is_file($jsFallback) ? (require $jsFallback) : [];
        }
        $ibI18nJson  = json_encode(array_merge($ibI18nCore, is_array($jsStrings) ? $jsStrings : []),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        // Theme from user DB record
        $user = $this->session->user();
        $userTheme = Output::e($user['theme'] ?? 'dark');
        if (preg_match('/IntrabaseMobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $userTheme = 'dark';
        }
        $userFontSize = self::fontSize($user);

        // Active app key for PWA badge icon
        // Try activeApp first, then detect from URL path
        $appKey = $isDashboard ? '' : ($activeApp['keyname'] ?? '');
        if (!$appKey) {
            $urlPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
            $firstSeg = explode('/', $urlPath)[0] ?? '';
            $appKey = preg_match('/^[a-z][a-z0-9_]*$/', $firstSeg) ? $firstSeg : '';
        }
        $iconVersion = '12';
        $hasAppIcon = $appKey && file_exists(__DIR__ . '/../../public/assets/img/app-icons/' . $appKey . '.png');
        // Desktop favicon: app icon with client color bg + border-radius
        $faviconFile = $appKey
            ? '/app-favicon?app=' . $appKey . '&s=32&v=' . $iconVersion
            : '/assets/img/favicon-32.png?v=' . $iconVersion;
        // apple-touch-icon: app icon with color bg + Intrabase logo badge (mobile home screen)
        $appIconFile = $appKey
            ? '/app-favicon?app=' . $appKey . '&s=180&badge=1&v=' . $iconVersion
            : '/assets/img/apple-touch-icon-base.png?v=' . $iconVersion;
        $hasAppIconJs = $hasAppIcon ? 'true' : 'false';
        $faviconLinksDefault = $appKey
            ? '<link rel="icon" href="/app-favicon?app=' . $appKey . '&s=32&v=' . $iconVersion . '" sizes="32x32">'
            : '<link rel="icon" href="/favicon.ico?v=3" sizes="32x32">';
        // 192px icon only in manifest.webmanifest (PWA), not in HTML — browsers
        // prefer the larger icon over 32px, which breaks the desktop favicon style
        $faviconLink192 = '';
        // Tenant brand (Support\Branding): outside app contexts the favicon and
        // the chrome follow ib_tenants.logo when set. Per-app favicons (glyph
        // over client color) stay as they are — they brand the APP, not the
        // product. The apple-touch-icon follows the tenant too when it ships
        // the `-touch.png` raster variant (an SVG cannot feed it).
        $brandLogo = Output::e(\Intrabase\Support\Branding::logo());
        $brandFaviconSvg = \Intrabase\Support\Branding::faviconSvgLink();
        if ($brandFaviconSvg !== '') {
            // Marca de tenant: el seu favicon mana a TOTES les apps i
            // subapartats (28/08/2026) — s'ometen el favicon per-app i el PNG
            // per defecte perquè el navegador no els prefereixi.
            $faviconLinksDefault = '<link rel="icon" href="/favicon.ico?v=3" sizes="32x32">';
            $faviconPngLink = '';
        } else {
            $faviconPngLink = '<link rel="icon" type="image/png" sizes="32x32" href="' . $faviconFile . '">';
        }
        $brandTouchIcon = \Intrabase\Support\Branding::appleTouchIcon();
        if ($brandTouchIcon !== null) {
            // Mateixa regla que el favicon: la icona de pantalla d'inici del
            // tenant mana a totes les apps (28/08/2026).
            $appIconFile = Output::e($brandTouchIcon) . '?v=' . $iconVersion;
        }

        $intrabaseCssUrl = $this->assetUrl('/assets/css/intrabase.css');
        $intrabaseJsUrl = $this->assetUrl('/assets/js/intrabase.js');
        $cspContent = $this->buildCsp();
        $tenantCss = $this->tenantCustomCss();
        $tenantJs = $this->tenantJsTags();
        $year = date('Y');
        $aboutDesc1 = Output::e(__('about_description_1'));
        $aboutDesc2 = Output::e(__('about_description_2'));
        $aboutLicense = Output::e(__('Free software under the GNU AGPL-3.0 licence'));

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}" data-theme="{$userTheme}" data-font-size="{$userFontSize}">
        <head>
            <meta charset="UTF-8">
            <meta name="format-detection" content="telephone=no, date=no, email=no, address=no">
            <script>
                (function(){
                    var sa=window.navigator.standalone||window.matchMedia('(display-mode:standalone)').matches;
                    // Boot splash. Standalone (home-screen PWA) and the native
                    // shell have no address bar, no tab spinner and no progress
                    // bar: while the page loads the user stares at the manifest
                    // background_color (#0d1117) with no sign the app is coming.
                    // The class goes on <html> here, in the head, so the overlay
                    // is already painted with the first frame of <body>.
                    if(sa||/IntrabaseMobile/i.test(navigator.userAgent)){
                        document.documentElement.classList.add('ib-booting');
                    }
                    if(sa){
                        document.documentElement.classList.add('ib-standalone');
                        // Prevent iOS from opening internal links in Safari
                        document.addEventListener('click',function(e){
                            var a=e.target.closest('a[href]');
                            if(!a) return;
                            var href=a.getAttribute('href');
                            if(!href) return;
                            // Skip external links, mailto, tel, javascript, blobs, anchors
                            if(/^(https?:\/\/|mailto:|tel:|javascript:|blob:|#)/i.test(href)){
                                // Allow same-origin links, block external
                                if(/^https?:\/\//i.test(href)){
                                    try{
                                        var u=new URL(href,location.origin);
                                        if(u.origin===location.origin){
                                            e.preventDefault();
                                            location.href=u.pathname+u.search+u.hash;
                                        }
                                    }catch(ex){}
                                }
                                return;
                            }
                            // Internal relative/absolute path — navigate in-app
                            if(a.target && a.target !== '_self') return;
                            e.preventDefault();
                            location.href=href;
                        },true);
                    }
                    document.documentElement.style.setProperty('--ib-sat','0px');
                })();
            </script>
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
            <script>
                /* Phones (portrait, short side up to 500px) get the whole UI 12%
                   larger — text, buttons, spacing — by laying the page out on a
                   narrower viewport and letting the browser scale it to fit.
                   Unlike CSS zoom this keeps vh units right. Landscape and
                   tablets keep device-width (30/10/2026, user request). */
                (function(){
                    var F=1.12,m=document.querySelector('meta[name="viewport"]');
                    if(!m||!window.matchMedia)return;
                    var tail=', user-scalable=no, viewport-fit=cover';
                    function fit(){
                        var s=Math.min(screen.width,screen.height);
                        var portrait=window.matchMedia('(orientation: portrait)').matches;
                        // Never lay out narrower than 348px (what the header
                        // needs): small phones get a bit less enlargement.
                        var f=Math.max(1,Math.min(F,s/348)).toFixed(3);
                        m.setAttribute('content',(s<=500&&portrait&&f>1)
                            ?'width='+Math.round(s/f)+', initial-scale='+f+', maximum-scale='+f+tail
                            :'width=device-width, initial-scale=1, maximum-scale=1'+tail);
                    }
                    fit();
                    window.addEventListener('orientationchange',function(){setTimeout(fit,50);});
                })();
            </script>
            <meta http-equiv="Content-Security-Policy"
                  content="{$cspContent}">
            {$csrfMeta}
            <link rel="manifest" href="/manifest.webmanifest?app={$appKey}&amp;t={$activeAppName}">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            <meta name="mobile-web-app-capable" content="yes">
            {$faviconLinksDefault}
            {$faviconPngLink}
            {$brandFaviconSvg}
            {$faviconLink192}
            <link rel="apple-touch-icon" sizes="180x180" href="{$appIconFile}">
            <title>{$fullTitle}</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="stylesheet"
                  href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
            <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
            {$cssLinks}
            {$this->buildThemeOverrides()}
            {$tenantCss}
            <style>
                /* Boot splash — inline so it never waits for a stylesheet.
                   Just the isotype, small and light grey: the spinner that used
                   to sit under it said nothing the logo was not already saying
                   (22/08/2026, user request).
                   The grey is forced with a filter and not taken from the file:
                   brightness(0) flattens the logo to black whatever its own
                   colours are, and invert() then lifts it to one known grey, so
                   it cannot drift if the SVG is ever restyled. */
                #ib-boot{position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;
                    display:none;align-items:center;justify-content:center;
                    background:#0d1117;transition:opacity .25s ease}
                html[data-theme="light"] #ib-boot{background:#fff}
                html.ib-booting #ib-boot{display:flex}
                /* The isotype only shows up if the boot is taking a while
                   (22/08/2026, user request): on a fast load it would be a
                   flash of logo, which reads worse than a plain background.
                   The overlay itself is painted from the first frame either
                   way — what waits is only the mark. */
                #ib-boot img{width:40px;height:40px;opacity:0;
                    transition:opacity .2s ease;
                    filter:grayscale(1) brightness(0) invert(78%)}
                #ib-boot.ib-boot--slow img{opacity:1}
                /* On white, the same grey would nearly vanish: a touch darker. */
                html[data-theme="light"] #ib-boot img{filter:grayscale(1) brightness(0) invert(68%)}
                #ib-boot.ib-boot--gone{opacity:0;pointer-events:none}
            </style>
        </head>
        <body class="ib-layout{$bodyClass}" data-tenant="{$tenantKey}">

            <div id="ib-boot" aria-hidden="true"><img src="{$brandLogo}" alt=""></div>
            <script>
                (function(){
                    var el=document.getElementById('ib-boot');
                    if(!el) return;
                    // Native shell: drop its launch splash as soon as ours is on
                    // screen. Both are the same #0d1117 with the isotype on it,
                    // so the handover is invisible — and the app never waits out
                    // the splash duration once the page is actually up.
                    try{
                        var cap=window.Capacitor;
                        if(cap&&cap.Plugins&&cap.Plugins.SplashScreen){
                            requestAnimationFrame(function(){ cap.Plugins.SplashScreen.hide(); });
                        }
                    }catch(e){}
                    var done=false;
                    // Show the isotype only once the boot has gone past two
                    // seconds. Under that, the overlay comes and goes with just
                    // its background and the logo never blinks.
                    var slow=setTimeout(function(){
                        if(!done) el.classList.add('ib-boot--slow');
                    },2000);
                    function hide(){
                        if(done) return;
                        done=true;
                        clearTimeout(slow);
                        el.classList.add('ib-boot--gone');
                        setTimeout(function(){
                            if(el.parentNode) el.parentNode.removeChild(el);
                            document.documentElement.classList.remove('ib-booting');
                        },300);
                    }
                    window.addEventListener('load',function(){ setTimeout(hide,150); });
                    // Failsafe: a stalled asset must never leave the app behind
                    // a splash it can't dismiss.
                    setTimeout(hide,10000);
                })();
            </script>

            {$headerHtml}

            {$appBarHtml}

            {$sidebarHtml}

            <div class="ib-layout__main">

                {$appTopHtml}

                <div class="ib-layout__flash" aria-live="polite">
                    {$flashHtml}
                </div>

                <main class="ib-layout__content" id="ib-main-content">
                    {$content}
                </main>

                <footer class="ib-layout__footer">
                    {$footerHtml}
                </footer>
            </div>

            <script>window.ibI18n={$ibI18nJson};window.ibActiveApp='{$activeAppName}';window.t=function(k,p){var s=window.ibI18n[k]||k;if(p){for(var i=0;i<p.length;i++)s=s.replace('%s',p[i]).replace('%d',p[i]);}return s;};</script>
            <script src="{$intrabaseJsUrl}"></script>
            {$tenantJs}
            {$jsScripts}
            <script>
            // Shared browser-tab badge: sums unread from every source (mail, chat,
            // …) into a single document.title prefix + PWA app badge, so the
            // sources never fight over the title. window.ibTabBadge.set(src,n).
            (function(){
                var origFavicon='{$faviconFile}';
                var faviconLink=document.querySelector('link[rel="icon"][type="image/png"][sizes="32x32"]');
                var counts={};
                function render(){
                    var total=0; for(var k in counts) total+=counts[k]||0;
                    var base=document.title.replace(/^\(\d+\)\s*/,'');
                    document.title=total>0?'('+total+') '+base:base;
                    if('setAppBadge' in navigator){
                        if(total>0) navigator.setAppBadge(total); else navigator.clearAppBadge();
                    }
                }
                window.ibTabBadge={
                    set:function(src,n){ counts[src]=parseInt(n)||0; render(); },
                    total:function(){ var t=0; for(var k in counts) t+=counts[k]||0; return t; }
                };
            })();
            </script>
            {$pluginScripts}
            <script>
            (function(){
                if(window.matchMedia('(display-mode: standalone)').matches) return;
                if(!/Mobi|Android|iPhone|iPad/i.test(navigator.userAgent)) return;
                if(/IntrabaseMobile/i.test(navigator.userAgent)) return;
                var path=location.pathname.replace(/\/$/,'');
                var isDash=document.body.classList.contains('ib-layout--dashboard');
                var isAppDash=/^\/[a-z_-]+\/[a-z_]+_dashboard$/.test(path)||/^\/[a-z_-]+$/.test(path);
                if(!isDash&&!isAppDash) return;
                var dismissKey=isDash?'ib-pwa-dismissed':'ib-pwa-dismissed-'+location.pathname.split('/')[1];
                var dismissed=localStorage.getItem(dismissKey);
                if(dismissed && (Date.now()-parseInt(dismissed,10))<7*86400000) return;

                var deferredPrompt=null;
                window.addEventListener('beforeinstallprompt',function(e){
                    e.preventDefault();
                    deferredPrompt=e;
                    showBanner();
                });

                var isIOS=/iPhone|iPad|iPod/.test(navigator.userAgent)&&!window.MSStream;
                if(isIOS) setTimeout(function(){ showBanner(); },2000);
                else setTimeout(function(){ if(!deferredPrompt) showBanner(); },3000);

                var lang=document.documentElement.lang||'ca';
                var appName=window.ibActiveApp||'';
                var t={
                    ca:{banner:'Instal\u00b7la l\u2019aplicaci\u00f3 al teu m\u00f2bil',bannerApp:'Instal\u00b7la l\u2019enlla\u00e7 a <strong>{app}</strong> al teu m\u00f2bil',install:'Instal\u00b7lar',close:'Tancar',step1:'Toca <i class="fa-solid fa-arrow-up-from-bracket"></i> a la barra del navegador',step2:'Selecciona <strong>\"Afegir a la pantalla d\u2019inici\"</strong>',astep1:'Toca <i class="fa-solid fa-ellipsis-vertical"></i> al menú del navegador',astep2:'Selecciona <strong>\"Afegeix a la pantalla d’inici\"</strong> o <strong>\"Instal·la l’aplicació\"</strong>',note:'Pots fer-ho per totes les aplicacions.',ok:'Ent\u00e8s'},
                    es:{banner:'Instala la aplicaci\u00f3n en tu m\u00f3vil',bannerApp:'Instala el enlace a <strong>{app}</strong> en tu m\u00f3vil',install:'Instalar',close:'Cerrar',step1:'Toca <i class="fa-solid fa-arrow-up-from-bracket"></i> en la barra del navegador',step2:'Selecciona <strong>\"A\u00f1adir a la pantalla de inicio\"</strong>',astep1:'Toca <i class="fa-solid fa-ellipsis-vertical"></i> en el menú del navegador',astep2:'Selecciona <strong>\"Añadir a pantalla de inicio\"</strong> o <strong>\"Instalar aplicación\"</strong>',note:'Puedes hacerlo para todas las aplicaciones.',ok:'Entendido'},
                    en:{banner:'Install the app on your phone',bannerApp:'Install the link to <strong>{app}</strong> on your phone',install:'Install',close:'Close',step1:'Tap <i class="fa-solid fa-arrow-up-from-bracket"></i> in the browser toolbar',step2:'Select <strong>\"Add to Home Screen\"</strong>',astep1:'Tap <i class="fa-solid fa-ellipsis-vertical"></i> in the browser menu',astep2:'Select <strong>\"Add to Home screen\"</strong> or <strong>\"Install app\"</strong>',note:'You can do this for all applications.',ok:'Got it'},
                    eu:{banner:'Instalatu aplikazioa zure mugikorrean',bannerApp:'Instalatu <strong>{app}</strong> esteka zure mugikorrean',install:'Instalatu',close:'Itxi',step1:'Sakatu <i class="fa-solid fa-arrow-up-from-bracket"></i> nabigatzailearen barran',step2:'Hautatu <strong>\"Gehitu hasierako pantailara\"</strong>',astep1:'Sakatu <i class="fa-solid fa-ellipsis-vertical"></i> nabigatzailearen menuan',astep2:'Hautatu <strong>\"Gehitu hasierako pantailan\"</strong> edo <strong>\"Instalatu aplikazioa\"</strong>',note:'Aplikazio guztietarako egin dezakezu.',ok:'Ulertuta'}
                };
                var i18n=t[lang]||t.ca;
                var bannerText=(isDash||!appName)?i18n.banner:i18n.bannerApp.replace('{app}',appName);

                function showBanner(){
                    if(document.getElementById('ib-pwa-banner')) return;
                    var banner=document.createElement('div');
                    banner.id='ib-pwa-banner';
                    banner.className='ib-pwa-banner';
                    banner.innerHTML='<div class="ib-pwa-banner__content">'
                        +'<img src="{$brandLogo}" class="ib-pwa-banner__icon" alt="">'
                        +'<span class="ib-pwa-banner__text">'+bannerText+'</span>'
                        +'</div>'
                        +'<div class="ib-pwa-banner__actions">'
                        +'<button class="ib-pwa-banner__btn" id="ib-pwa-install" aria-label="'+i18n.install+'"><i class="fa-solid fa-download"></i></button>'
                        +'<button class="ib-pwa-banner__close" id="ib-pwa-close" aria-label="'+i18n.close+'"><i class="fa-solid fa-xmark"></i></button>'
                        +'</div>';
                    document.body.appendChild(banner);
                    requestAnimationFrame(function(){ banner.classList.add('ib-pwa-banner--visible'); });

                    document.getElementById('ib-pwa-close').addEventListener('click',dismiss);
                    document.getElementById('ib-pwa-install').addEventListener('click',function(){
                        if(deferredPrompt){deferredPrompt.prompt();deferredPrompt=null;dismiss();return;}
                        showIOSHelp(!isIOS);
                    });
                }
                // android: the browser offered no install prompt (Samsung Internet,
                // Firefox, already installed…), so explain the menu route instead.
                function showIOSHelp(android){
                    if(document.getElementById('ib-pwa-ios-help')) return;
                    var overlay=document.createElement('div');
                    overlay.id='ib-pwa-ios-help';
                    overlay.className='ib-pwa-ios-help';
                    overlay.innerHTML='<div class="ib-pwa-ios-help__box">'
                        +'<p>1. '+(android?i18n.astep1:i18n.step1)+'</p>'
                        +'<p>2. '+(android?i18n.astep2:i18n.step2)+'</p>'
                        +'<p class="ib-pwa-ios-help__note">'+i18n.note+'</p>'
                        +'<button class="ib-pwa-ios-help__ok" id="ib-pwa-ios-ok">'+i18n.ok+'</button>'
                        +'</div>';
                    document.body.appendChild(overlay);
                    document.getElementById('ib-pwa-ios-ok').addEventListener('click',function(){
                        overlay.remove();dismiss();
                    });
                    overlay.addEventListener('click',function(e){
                        if(e.target===overlay){overlay.remove();dismiss();}
                    });
                }
                function dismiss(){
                    var b=document.getElementById('ib-pwa-banner');
                    if(b){b.classList.remove('ib-pwa-banner--visible');setTimeout(function(){b.remove();},300);}
                    localStorage.setItem(dismissKey,String(Date.now()));
                }
            })();
            </script>
        <div class="ib-about-overlay" id="ib-about-modal" onclick="if(event.target===this)this.classList.remove('is-open')">
            <div class="ib-about">
                <button class="ib-about__close" onclick="document.getElementById('ib-about-modal').classList.remove('is-open')">&times;</button>
                <img src="/assets/img/logo.svg" alt="Intrabase" class="ib-about__logo">
                <p class="ib-about__desc">{$aboutDesc1}</p>
                <p class="ib-about__desc">{$aboutDesc2}</p>
                <a href="https://github.com/opengea/intrabase-framework" target="_blank" rel="noopener" class="ib-about__link">github.com/opengea/intrabase-framework</a>
                <p class="ib-about__copy">{$aboutLicense}</p>
            </div>
        </div>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Tenant custom CSS
    // -------------------------------------------------------------------------

    /**
     * Inline the tenant's custom stylesheet, if any: <tenant data
     * dir>/css/custom.css — same convention as the KMS data/css/custom.css.
     * Inlined (not linked) so it works without the tenant dir being
     * web-served. Empty string when the tenant has no such file.
     */
    private function tenantCustomCss(): string
    {
        $dataPath = \Intrabase\Core\Application::getInstance()->getTenantDataPath();
        if ($dataPath === null) {
            return '';
        }
        $file = $dataPath . '/css/custom.css';
        if (!is_file($file)) {
            return '';
        }
        $css = (string) file_get_contents($file);
        // Un </style> dins del fitxer trencaria el document
        $css = str_ireplace('</style', '<\\/style', $css);
        return '<style id="ib-tenant-css">' . "\n" . $css . "\n" . '</style>';
    }

    /**
     * JS del tenant: <data>/js/tenant.js, servit pel tenant a /data/js/ (un tenant hi pot tenir
     * l'alias). I la denegació pendent d'un formulari clàssic (TenantFeatureGate::remember),
     * que el JS del tenant ensenya en carregar. Cap dels dos sense fitxer/denegació.
     */
    private function tenantJsTags(): string
    {
        try { $dataPath = \Intrabase\Core\Application::getInstance()->getTenantDataPath(); } catch (\Throwable) { $dataPath = null; }
        if ($dataPath === null) return '';
        $out = '';
        $pending = \Intrabase\Support\TenantFeatureGate::takePending($this->session);
        if ($pending !== null) {
            $out .= '<script>window.ibFeatureDeniedPending=' . json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>';
        }
        $file = $dataPath . '/js/tenant.js';
        if (is_file($file)) {
            $out .= '<script src="/data/js/tenant.js?v=' . (int)@filemtime($file) . '"></script>';
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Content-Security-Policy
    // -------------------------------------------------------------------------

    /**
     * Build the CSP meta content: the Intrabase baseline plus optional
     * per-tenant extensions from <tenant data dir>/csp.php, which returns
     * an array of extra sources per directive, e.g.:
     *
     *   return ['connect-src' => ['https://api.maptiler.com'],
     *           'worker-src'  => ['blob:']];
     *
     * Only http(s) origins and the blob:/data: schemes are accepted from the
     * tenant file — never 'unsafe-*' keywords or wildcards, so a tenant can
     * widen where the browser may CONNECT but not weaken script execution.
     * Without the file the policy is byte-for-byte the historical baseline.
     */
    private function buildCsp(): string
    {
        $csp = [
            'default-src' => ["'self'", 'blob:'],
            'style-src'   => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
            'font-src'    => ["'self'", 'https://fonts.gstatic.com', 'data:', 'blob:'],
            'script-src'  => ["'self'", "'unsafe-inline'"],
            'img-src'     => ["'self'", 'data:', 'blob:', 'https:', 'http:'],
            'frame-src'   => ["'self'", 'blob:'],
            'connect-src' => ["'self'", 'data:', 'blob:'],
        ];

        // Sources added by plugins (LayoutExtensions::addCspSources)
        foreach (LayoutExtensions::csp() as $directive => $sources) {
            foreach ($sources as $src) {
                $csp[$directive][] = $src;
            }
        }

        $dataPath = \Intrabase\Core\Application::getInstance()->getTenantDataPath();
        $cspFile  = $dataPath !== null ? $dataPath . '/csp.php' : null;
        if ($cspFile !== null && is_file($cspFile)) {
            $extra = require $cspFile;
            if (is_array($extra)) {
                foreach ($extra as $directive => $sources) {
                    if (!is_string($directive) || !preg_match('/^[a-z-]+-src$/', $directive) || !is_array($sources)) {
                        continue;
                    }
                    foreach ($sources as $src) {
                        $src = trim((string) $src);
                        if ($src === 'blob:' || $src === 'data:' || preg_match('#^https?://[a-z0-9.-]+$#i', $src)) {
                            $csp[$directive] ??= [];
                            if (!in_array($src, $csp[$directive], true)) {
                                $csp[$directive][] = $src;
                            }
                        }
                    }
                }
            }
        }

        $parts = [];
        foreach ($csp as $directive => $sources) {
            $parts[] = $directive . ' ' . implode(' ', $sources);
        }
        return implode('; ', $parts);
    }

    // -------------------------------------------------------------------------
    // App Bar  (vertical strip ~52px, always visible, dark)
    // -------------------------------------------------------------------------

    private function buildAppBar(array $apps, ?array $activeApp, bool $isDashboard = false): string
    {
        $items     = '';
        $activeKey = (string)($activeApp['keyname'] ?? '');

        foreach ($apps as $app) {
            $appKey     = (string)($app['keyname'] ?? '');
            $appName    = Output::e((string)$app['name']);
            $appIcon    = Output::e((string)$app['icon']);
            $defaultMod = (string)($app['default_module'] ?? '');
            $isActive   = $appKey === $activeKey;
            $activeCls  = $isActive ? ' ib-appbar__item--active' : '';
            // Use short app URL (/{appKey}) for the app bar link — or the tenant's
            // short route for its default module (/map) when it defines one
            $modUrl     = $appKey !== '' ? '/' . $appKey : '/';
            if ($appKey !== '' && $defaultMod !== '') {
                $modUrl = \Intrabase\Support\TenantRoutes::shorten($appKey, $defaultMod) ?? $modUrl;
            }
            $url        = Output::e($modUrl);
            $keyEsc     = Output::e($appKey);

            $badge = '';
            if ($appKey === 'mail') {
                $badge = '<span class="ib-messages-badge" id="ib-mail-unread-badge" style="display:none"></span>';
            } elseif ($appKey === 'chat') {
                $unread = $this->loadChatUnreadCount();
                $badge  = $unread > 0
                    ? '<span class="ib-chat-badge" id="ib-chat-badge" aria-label="' . $unread . ' unread">' . min($unread, 99) . '</span>'
                    : '<span class="ib-chat-badge" id="ib-chat-badge" style="display:none">0</span>';
            }

            $items .= <<<HTML
            <a class="ib-appbar__item{$activeCls}" href="{$url}"
               data-app="{$keyEsc}" aria-label="{$appName}">
                <i class="{$appIcon}" aria-hidden="true"></i>
                {$badge}
                <span class="ib-appbar__tooltip">{$appName}</span>
            </a>
            HTML;
        }

        $dashboardActive = $isDashboard ? ' ib-appbar__item--active' : '';
        $dashboardLabel  = Output::e(__('dashboard'));
        $dashboardBtn    = <<<HTML
        <a class="ib-appbar__item ib-appbar__item--dashboard{$dashboardActive}" href="/dashboard"
           aria-label="{$dashboardLabel}">
            <i class="fa-solid fa-gauge-high" aria-hidden="true"></i>
            <span class="ib-appbar__tooltip">{$dashboardLabel}</span>
        </a>
        HTML;

        $appbarNavLabel = Output::e(__('appbar_nav'));
        return <<<HTML
        <div class="ib-appbar" id="ib-appbar" role="navigation" aria-label="{$appbarNavLabel}">
            <div class="ib-appbar__nav">
                {$dashboardBtn}
                {$items}
            </div>
        </div>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Sidebar  (shows sub-groups + modules of the active app only)
    // -------------------------------------------------------------------------

    /**
     * Accions principals de cada app a la barra superior (com «Nou correu» al correu).
     * [url, clau d'ib_lang, icona, principal?]
     */
    /** Cercador i vistes del llistat principal, que es mostren a la franja superior. */
    private static ?string $moduleTopSearch  = null;
    private static ?string $moduleTopFilters = null;

    /**
     * Un llistat (DataBrowser) ofereix el seu cercador a la franja superior.
     * Només compta el primer: els llistats niats d'una fitxa no hi van.
     */
    public static function offerTopSearch(string $html): void
    {
        if (self::$moduleTopSearch === null && trim($html) !== '') {
            self::$moduleTopSearch = $html;
        }
    }

    /** Ídem per a les vistes desades (filtres). */
    public static function offerTopFilters(string $html): void
    {
        if (self::$moduleTopFilters === null && trim($html) !== '') {
            self::$moduleTopFilters = $html;
        }
    }

    /** Eines del llistat (commutador de columnes) per a la franja superior, al costat de la configuració. */
    private static ?string $moduleTopTools = null;

    public static function offerTopTools(string $html): void
    {
        if (self::$moduleTopTools === null && trim($html) !== '') {
            self::$moduleTopTools = $html;
        }
    }

    private const APP_TOP_ACTIONS = [
        'erp' => [
            ['/erp/invoices/create', 'apptop_new_invoice', 'fa-solid fa-file-invoice', true],
            ['/erp/invoices_providers/create', 'apptop_new_expense', 'fa-solid fa-receipt', false],
            ['/erp/config', 'settings', 'fa-solid fa-gear', null],
        ],
    ];

    /**
     * Barra superior de les apps amb menú lateral: el títol de la secció on som
     * («Grup › Mòdul», o només el mòdul si és l'inici de l'app) i les accions
     * principals, alineada amb la capçalera del menú (06/11/2026).
     */
    private function buildAppTop(array $activeApp, string $currentMod): string
    {
        $appKey = (string) ($activeApp['keyname'] ?? '');

        // Grup i nom del mòdul actual, tal com surten al menú lateral
        $group  = '';
        $module = '';
        $icon   = '';
        foreach ((array) ($activeApp['modules_list'] ?? []) as $grp) {
            // Entrada solta del menú (sense grup): {"key", "name", "icon"}
            $mods = isset($grp['group']) ? (array) ($grp['modules'] ?? []) : (isset($grp['key']) ? [$grp] : []);
            foreach ($mods as $mod) {
                if ((string) ($mod['key'] ?? '') === $currentMod) {
                    $group  = __((string) ($grp['group'] ?? ''));
                    $module = __((string) ($mod['name'] ?? $currentMod));
                    $icon   = (string) ($mod['icon'] ?? '');
                    break 2;
                }
            }
        }
        if ($module === '') {
            $module = __((string) ($activeApp['name'] ?? $this->appName));
        }
        $isHome = $currentMod !== '' && $currentMod === (string) ($activeApp['default_module'] ?? '');

        $title = '';
        if ($icon !== '') {
            $title .= '<i class="' . Output::attr($icon) . ' ib-apptop__icon-mod" aria-hidden="true"></i>';
        }
        if ($group !== '' && !$isHome) {
            $title .= '<span class="ib-apptop__group">' . Output::e($group) . '</span>'
                    . '<i class="fa-solid fa-chevron-right ib-apptop__sep" aria-hidden="true"></i>';
        }
        $title .= '<span class="ib-apptop__current">' . Output::e($module) . '</span>';

        // En un llistat: vistes a la dreta, i les accions generals de l'app (nova factura…)
        // desapareixen; es manté només la icona de configuració
        $hasModule  = self::$moduleTopSearch !== null;
        $actions    = self::$moduleTopFilters !== null ? '<span class="ib-apptop__filters">' . self::$moduleTopFilters . '</span>' : '';
        foreach (self::APP_TOP_ACTIONS[$appKey] ?? [] as [$url, $key, $icon, $primary]) {
            if ($hasModule && $primary !== null) {
                continue;
            }
            $label = Output::e(__($key));
            if ($primary === null && self::$moduleTopTools !== null) {
                $actions .= '<span class="ib-apptop__tools">' . self::$moduleTopTools . '</span>';
                self::$moduleTopTools = '';
            }
            if ($primary === null) {
                $actions .= '<a class="ib-btn ib-btn--icon ib-apptop__icon" href="' . Output::attr($url) . '" title="' . $label
                          . '" aria-label="' . $label . '"><i class="' . Output::attr($icon) . '" aria-hidden="true"></i></a>';
                continue;
            }
            $actions .= '<a class="ib-btn ' . ($primary ? 'ib-btn--primary' : 'ib-btn--compose-outline') . '" href="' . Output::attr($url) . '">'
                      . '<i class="' . Output::attr($icon) . '" aria-hidden="true"></i><span class="ib-apptop__label"> ' . $label . '</span></a>';
        }

        if (!empty(self::$moduleTopTools)) {
            $actions .= '<span class="ib-apptop__tools">' . self::$moduleTopTools . '</span>';
        }

        $search = self::$moduleTopSearch !== null ? '<div class="ib-apptop__search">' . self::$moduleTopSearch . '</div>' : '';

        // El cercador al centre exacte: els dos costats han de fer l'amplada del més ample
        $centerJs = $search === '' ? '' : <<<'JS'
        <script>(function(){var t=document.currentScript&&document.currentScript.parentNode;if(!t)return;
        function f(){if(window.innerWidth<769){t.style.removeProperty('--apptop-side');return;}
        var a=t.querySelector('.ib-apptop__title'),b=t.querySelector('.ib-apptop__actions');
        var w=Math.max(a?a.scrollWidth:0,b?b.scrollWidth:0),cap=Math.max(0,(t.clientWidth-32-260)/2);
        t.style.setProperty('--apptop-side',Math.min(w,cap)+'px');}
        f();window.addEventListener('resize',f);window.addEventListener('load',f);})();</script>
        JS;

        return <<<HTML
        <div class="ib-apptop">
            <h1 class="ib-apptop__title">{$title}</h1>
            {$search}
            <div class="ib-apptop__actions">{$actions}</div>
            {$centerJs}
        </div>
        HTML;
    }

    private function buildSidebar(?array $activeApp, string $currentMod): string
    {
        $appName = Output::e((string)($activeApp['name'] ?? $this->appName));
        $appNameOptions = Output::e(__('sidebar_options_of', $appName));
        $appKey  = Output::e((string)($activeApp['keyname'] ?? ''));
        $appIcon = Output::e((string)($activeApp['icon'] ?? 'fa-solid fa-table-cells-large'));
        $groups  = $activeApp['modules_list'] ?? [];

        $disabledJson = ConfigManager::get('disabled_modules', '[]');
        $disabledMods = json_decode($disabledJson, true) ?: [];
        $disabledSet  = array_flip($disabledMods);

        $subgroupsHtml = '';
        foreach ($groups as $grp) {
            $rawGrpName = (string)($grp['group'] ?? '');
            $grpName    = Output::e(__($rawGrpName));  // i18n: translate sidebar group name
            $grpIcon    = Output::e((string)($grp['icon'] ?? 'fa-solid fa-folder'));
            // Unique stable ID for this group (for localStorage key)
            $grpId      = Output::e('sg-' . substr(md5($rawGrpName), 0, 12));
            // Default collapsed state from app config ('collapsed': true/false)
            $defCollapsed = !empty($grp['collapsed']) ? 'true' : 'false';

            // Entrada solta (sense grup), p. ex. el Tauler de Finances abans de Clients (06/11/2026)
            $standalone = !isset($grp['group']) && isset($grp['key']);

            $modItems    = '';
            $grpHasActive = false;
            foreach ($standalone ? [$grp] : (array)($grp['modules'] ?? []) as $mod) {
                $rawModKey    = (string)($mod['key'] ?? '');
                if (isset($disabledSet[$rawModKey])) {
                    continue;
                }
                if (!\Intrabase\Support\MenuConditions::visible($this->db, $rawModKey)) {
                    continue;
                }
                $appKeyForMod = (string)($activeApp['keyname'] ?? '');
                $defaultMod   = (string)($activeApp['default_module'] ?? '');
                $modKey       = Output::e($rawModKey);
                $modName      = Output::e((string)($mod['name'] ?? $rawModKey));
                $modIcon      = Output::e((string)($mod['icon'] ?? 'fa-solid fa-circle'));
                $isActive     = $currentMod === $rawModKey ? ' ib-sidebar__item--active' : '';
                $grpHasActive = $grpHasActive || $isActive !== '';
                // Generate short URLs: /app/app_mod -> /app/mod
                $tenantShort = \Intrabase\Support\TenantRoutes::shorten($appKeyForMod, $rawModKey);
                if ($tenantShort !== null) {
                    $modUrl = $tenantShort;
                } elseif ($rawModKey === $defaultMod && $appKeyForMod !== '') {
                    $modUrl = '/' . $appKeyForMod;
                } elseif ($appKeyForMod !== '' && str_starts_with($rawModKey, $appKeyForMod . '_')) {
                    $shortMod = substr($rawModKey, strlen($appKeyForMod) + 1);
                    $modUrl = '/' . $appKeyForMod . '/' . $shortMod;
                } else {
                    $modUrl = Router::url($rawModKey, $appKeyForMod);
                }
                $url = Output::e($modUrl);

                if ($standalone) {
                    $modItems .= <<<HTML
                    <li class="ib-sidebar__toplink-item{$isActive}">
                        <a class="ib-sidebar__toplink" href="{$url}" data-mod="{$modKey}">
                            <i class="{$modIcon}" aria-hidden="true"></i>
                            <span class="ib-sidebar__subgroup-name">{$modName}</span>
                        </a>
                    </li>
                    HTML;
                    continue;
                }
                $modItems .= <<<HTML
                <li class="ib-sidebar__item{$isActive}">
                    <a class="ib-sidebar__link ib-sidebar__link--sub" href="{$url}" data-mod="{$modKey}">
                        <i class="{$modIcon} ib-sidebar__icon ib-sidebar__icon--sub" aria-hidden="true"></i>
                        <span class="ib-sidebar__label">{$modName}</span>
                    </a>
                </li>
                HTML;
            }

            if ($modItems === '') {
                continue;
            }
            if ($standalone) {
                $subgroupsHtml .= $modItems;
                continue;
            }

            // L'estat per defecte ja surt del servidor (el grup del mòdul actiu,
            // sempre obert): si no, tots es pintaven oberts i el JS els plegava
            // després, amb un salt visible a cada càrrega.
            $startCollapsed = $defCollapsed === 'true' && !$grpHasActive;
            $grpClass       = $startCollapsed ? ' ib-sidebar__subgroup--collapsed' : '';
            $grpExpanded    = $startCollapsed ? 'false' : 'true';

            $subgroupsHtml .= <<<HTML
            <li class="ib-sidebar__subgroup{$grpClass}"
                data-group-id="{$grpId}"
                data-default-collapsed="{$defCollapsed}">
                <button class="ib-sidebar__subgroup-label" aria-expanded="{$grpExpanded}">
                    <i class="{$grpIcon}" aria-hidden="true"></i>
                    <span class="ib-sidebar__subgroup-name">{$grpName}</span>
                    <i class="fa-solid fa-chevron-down ib-sidebar__subgroup-chevron" aria-hidden="true"></i>
                </button>
                <ul class="ib-sidebar__subgroup-nav" role="list">
                    {$modItems}
                </ul>
            </li>
            HTML;
        }

        if ($subgroupsHtml === '') {
            $subgroupsHtml = '<li class="ib-sidebar__empty"><span>' . __('no_modules') . '</span></li>';
        }

        $mainNavLabel   = __('main_nav');
        $closeMenuLabel = __('close_menu');

        $sidebarCollapsedAttr = !empty($activeApp['sidebar_collapsed']) ? ' data-sidebar-collapsed="1"' : '';
        // Mobile app and narrow screens (<=768px, 28/10/2026) jump straight to
        // the dashboard; wider browsers and the desktop app keep the About modal.
        $isMobileApp = (bool) preg_match('/IntrabaseMobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $logoLinkAttrs = $isMobileApp
            ? 'href="/"'
            : 'href="/" onclick="if(window.matchMedia(\'(min-width: 769px)\').matches){event.preventDefault();document.getElementById(\'ib-about-modal\').classList.add(\'is-open\')}"';

        $brandLogo = Output::e(\Intrabase\Support\Branding::logo());
        $brandName = Output::e(\Intrabase\Support\Branding::name());

        return <<<HTML
        <aside class="ib-sidebar" id="ib-sidebar" data-app-key="{$appKey}"{$sidebarCollapsedAttr} aria-label="{$mainNavLabel}">
            <div class="ib-sidebar__header">
                <a class="ib-sidebar__logo" {$logoLinkAttrs}>
                    <img src="{$brandLogo}" alt="{$brandName}" class="ib-sidebar__logo-img">
                </a>
                <i class="{$appIcon} ib-sidebar__app-icon" aria-hidden="true"></i>
                <span class="ib-sidebar__app-name"><span class="ib-sidebar__app-name--desktop">{$appNameOptions}</span><span class="ib-sidebar__app-name--mobile">{$appName}</span></span>
                <button class="ib-sidebar__toggle" id="ib-sidebar-toggle"
                        aria-label="{$closeMenuLabel}" aria-expanded="false" aria-controls="ib-sidebar">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <nav>
                <ul class="ib-sidebar__nav" role="list">
                    {$subgroupsHtml}
                </ul>
            </nav>
        </aside>
        <script>
        /* Estat desat de cada grup aplicat abans de pintar (vegeu ibInitSidebarSubgroups) */
        (function(){
          var sb = document.getElementById('ib-sidebar'); if (!sb) return;
          var app = sb.getAttribute('data-app-key') || 'default';
          sb.querySelectorAll('.ib-sidebar__subgroup').forEach(function(g){
            if (g.querySelector('.ib-sidebar__item--active')) return;
            var v = null;
            try { v = localStorage.getItem('ib-sg-' + app + '-' + (g.getAttribute('data-group-id') || '')); } catch (e) {}
            if (v === null) return;
            g.classList.toggle('ib-sidebar__subgroup--collapsed', v === '1');
            var b = g.querySelector('.ib-sidebar__subgroup-label');
            if (b) b.setAttribute('aria-expanded', v === '1' ? 'false' : 'true');
          });
        })();
        /* Navegar des del menú no l'ha de fer saltar (19/11/2026): sense animacions als primers
           fotogrames (ib-sb-nofx), menú plegat/desplegat com el va deixar la persona (la mateixa clau
           que ibInitSidebarToggleV2) i el scroll del menú on era a la pàgina anterior de la mateixa app. */
        (function(){
          var sb = document.getElementById('ib-sidebar'); if (!sb) return;
          var app = sb.getAttribute('data-app-key') || 'default';
          var de = document.documentElement;
          de.classList.add('ib-sb-nofx');
          var closed = null;
          try { var c = localStorage.getItem('ib-sidebar-closed-' + app); if (c !== null) closed = c === '1'; } catch (e) {}
          if (window.innerWidth <= 768) closed = true;
          if (closed !== null) document.body.classList.toggle('ib-sidebar-closed', closed);
          var els = [sb, sb.querySelector('.ib-sidebar__nav')];
          var saved = null;
          try { saved = JSON.parse(sessionStorage.getItem('ib-sb-scroll') || 'null'); } catch (e) {}
          if (saved && saved.app === app && saved.t) {
            els.forEach(function(el, i){ if (el && saved.t[i]) el.scrollTop = saved.t[i]; });
          }
          var save = function(){
            try { sessionStorage.setItem('ib-sb-scroll', JSON.stringify({ app: app, t: els.map(function(el){ return el ? el.scrollTop : 0; }) })); } catch (e) {}
          };
          sb.addEventListener('click', function(e){ if (e.target.closest && e.target.closest('a')) save(); });
          window.addEventListener('pagehide', save);
          var fx = function(){ requestAnimationFrame(function(){ requestAnimationFrame(function(){ de.classList.remove('ib-sb-nofx'); }); }); };
          if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fx); else fx();
        })();
        </script>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Active App Detection
    // -------------------------------------------------------------------------

    /**
     * Finds which app the current module belongs to.
     * Falls back to the first app if none matches.
     */
    private function detectActiveApp(array $apps, string $currentMod): ?array
    {
        if ($apps === []) {
            return null;
        }

        // 1. Match by URL path segment against app keyname (highest priority)
        $urlPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
        $firstSeg = explode('/', $urlPath)[0] ?? '';
        if ($firstSeg !== '') {
            foreach ($apps as $app) {
                if (($app['keyname'] ?? '') === $firstSeg) {
                    return $app;
                }
            }
        }

        // 2. Match by module key (fallback for modules not under an app URL)
        if ($currentMod !== '') {
            foreach ($apps as $app) {
                foreach ($app['modules_list'] as $grp) {
                    foreach ((array)($grp['modules'] ?? []) as $mod) {
                        if (($mod['key'] ?? '') === $currentMod) {
                            return $app;
                        }
                    }
                }
            }
        }

        return $apps[0];
    }

    // -------------------------------------------------------------------------
    // Load Apps
    // -------------------------------------------------------------------------

    /**
     * Loads app groups the current user has access to.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadApps(): array
    {
        $apps = AppAccess::visibleApps($this->db, $this->session);
        foreach ($apps as &$app) {
            $decoded = [];
            if (!empty($app['modules'])) {
                $decoded = json_decode((string)$app['modules'], true) ?: [];
            }
            $app['modules_list'] = $decoded;
        }
        unset($app);
        return $apps;
    }

    // -------------------------------------------------------------------------
    // Header
    // -------------------------------------------------------------------------

    private function buildHeader(array $breadcrumb, array $headerApps = []): string
    {
        $user        = $this->session->user();
        $userName    = Output::e((string)($user['name'] ?? $user['username'] ?? $user['email'] ?? 'User'));
        $userEmail   = Output::e((string)($user['email'] ?? ''));
        $initial     = self::getUserInitials((string)($user['name'] ?? $user['email'] ?? 'U'));
        $avatar      = (string)($user['avatar'] ?? '');

        $urlPath  = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
        $firstSeg = explode('/', $urlPath)[0] ?? '';

        $breadcrumbHtml     = $this->buildBreadcrumb($breadcrumb);
        $notifHtml          = $this->buildNotificationBell($firstSeg);

        // Build header app buttons dynamically from ib_apps with display_location='header'
        $headerAppBtns = $this->buildHeaderApps($headerApps, $firstSeg);

        // Load user quick actions for header
        $userId = (int)($user['id'] ?? 0);
        $quickActions      = $this->buildHeaderQuickActions($userId);
        $quickActionsHtml  = $quickActions['nav'];
        $qaTriggerHtml     = $quickActions['trigger'];

        // Load user entities for org switcher
        $userEntities = $userId > 0 ? $this->loadUserEntities($userId) : [];
        $activeEntity = $this->session->get('_ib_active_entity');

        // Tenant accounts (tenants with their own accounts): organizations live in the
        // tenant's own data model, not in ib_user_entities. When the tenant
        // has a provider, ITS rows are the switcher — every organization the
        // user belongs to plus the personal account, each with switch_url (the
        // tenant's own endpoint) — and the duo shows the tenant's active one.
        // Before 25/09/2026 this was only a fallback for users without native
        // entities, so a user seeded with one saw a single row and no switch.
        // Installs without a provider (rows === null) keep the native path.
        $tenantRows = \Intrabase\Support\TenantEntities::rows($this->session);
        if (is_array($tenantRows) && $tenantRows !== []) {
            $userEntities = $tenantRows;
            $activeEntity = \Intrabase\Support\TenantEntities::active($this->session);
        }

        $avatarHtml         = $this->buildAvatarDropdown($userName, $userEmail, $initial, $avatar, $userEntities, $activeEntity);

        $openMenuLabel       = __('open_menu');
        $breadcrumbNavLabel  = __('breadcrumb_nav');
        $searchPhLabel       = __('search_ph');
        $searchGlobalLabel   = __('search_global');
        $searchResultsLabel  = __('search_results');
        $closeSearchLabel    = __('close_search');
        // Mobile app and narrow screens: logo goes to dashboard. Wider: About modal.
        $isMobileApp = (bool) preg_match('/IntrabaseMobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $logoLinkAttrs = $isMobileApp
            ? 'href="/"'
            : 'href="/" onclick="if(window.matchMedia(\'(min-width: 769px)\').matches){event.preventDefault();document.getElementById(\'ib-about-modal\').classList.add(\'is-open\')}"';

        $brandLogo = Output::e(\Intrabase\Support\Branding::logo());
        $brandName = Output::e(\Intrabase\Support\Branding::name());

        return <<<HTML
        <header class="ib-header">
            <div class="ib-header__left">
                <a {$logoLinkAttrs} class="ib-header__logo" aria-label="{$brandName}">
                    <img src="{$brandLogo}" alt="{$brandName}" class="ib-header__logo-img">
                </a>
            </div>
            <div class="ib-header__main">
                <button class="ib-btn ib-btn--icon" id="ib-menu-open"
                        aria-label="{$openMenuLabel}" aria-expanded="false" aria-controls="ib-sidebar">
                    <svg width="18" height="13" viewBox="0 0 18 13" fill="none" aria-hidden="true">
                        <rect x="0.5" y="0.5" width="17" height="12" rx="2" stroke="#a6a6a6" stroke-width="1"/>
                        <rect x="0" y="0" width="6" height="13" rx="2" fill="#a6a6a6"/>
                        <rect x="4" y="0" width="2" height="13" fill="#a6a6a6"/>
                    </svg>
                </button>
                {$quickActionsHtml}
            </div>
            <div class="ib-header__right">
                {$qaTriggerHtml}
                {$headerAppBtns}
                {$notifHtml}
                {$avatarHtml}
            </div>
        </header>
        <div class="ib-search-overlay" id="ib-search-overlay" hidden>
            <div class="ib-search-overlay__inner">
                <i class="fa-solid fa-magnifying-glass ib-search-bar__icon" aria-hidden="true"></i>
                <input type="search" class="ib-search-bar__input" id="ib-search-input"
                       placeholder="{$searchPhLabel}" autocomplete="off" aria-label="{$searchGlobalLabel}" maxlength="100">
                <span class="ib-search-hint" aria-hidden="true"><kbd>Ctrl</kbd>+<kbd>K</kbd></span>
                <button class="ib-btn ib-btn--icon ib-search-overlay__close" id="ib-search-close" aria-label="{$closeSearchLabel}">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <div class="ib-search-bar__results" id="ib-search-results" role="listbox" aria-label="{$searchResultsLabel}" hidden></div>
        </div>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Header Apps (display_location = 'header')
    // -------------------------------------------------------------------------

    private function buildHeaderApps(array $headerApps, string $currentSeg): string
    {
        $html = '';
        foreach ($headerApps as $app) {
            $appKey  = (string)($app['keyname'] ?? '');
            $appName = Output::e((string)$app['name']);
            $appIcon = Output::e((string)$app['icon']);
            $url     = Output::e('/' . $appKey);
            $active  = $currentSeg === $appKey ? ' ib-appbar__item--active' : '';
            $idAttr  = $appKey ? ' id="ib-header-app-' . Output::e($appKey) . '"' : '';

            $badge = '';
            // Special badge support for known app types
            if ($appKey === 'mail') {
                $badge = '<span class="ib-messages-badge" id="ib-mail-unread-badge" style="display:none"></span>';
            } elseif ($appKey === 'chat') {
                $unread = $this->loadChatUnreadCount();
                $badge  = $unread > 0
                    ? '<span class="ib-chat-badge" id="ib-chat-badge" aria-label="' . $unread . ' unread">' . min($unread, 99) . '</span>'
                    : '<span class="ib-chat-badge" id="ib-chat-badge" style="display:none">0</span>';
            }

            $html .= <<<HTML
            <a class="ib-header-app{$active}" href="{$url}" aria-label="{$appName}" title="{$appName}"{$idAttr}>
                <i class="{$appIcon}" aria-hidden="true"></i>
                {$badge}
            </a>
            HTML;
        }
        return $html;
    }

    // -------------------------------------------------------------------------
    // Header Quick Actions (user-customizable shortcuts)
    // -------------------------------------------------------------------------

    /**
     * @return array{nav: string, trigger: string}
     */
    private function buildHeaderQuickActions(int $userId): array
    {
        if ($userId <= 0) {
            return ['nav' => '', 'trigger' => ''];
        }

        $actions = $this->db->fetchAll(
            'SELECT id, label, icon, url FROM ib_user_quick_actions
             WHERE user_id = ? ORDER BY position ASC, id ASC',
            [$userId]
        );

        // Drop actions whose URL points to an app the user cannot access.
        $visibleKeys = AppAccess::visibleKeynames($this->db, $this->session);
        $actions = array_values(array_filter($actions, static function (array $a) use ($visibleKeys): bool {
            $app = AppAccess::urlAppKey((string)($a['url'] ?? ''));
            return $app === '' || in_array($app, $visibleKeys, true);
        }));

        $qaLabel = __('quick_actions');

        // Mobile trigger button (bolt icon) — rendered in ib-header__right
        $trigger = '<button class="ib-header-qa__trigger" id="ib-qa-trigger" aria-label="' . $qaLabel . '">'
                 . '<i class="fa-solid fa-bolt" aria-hidden="true"></i></button>';

        if (empty($actions)) {
            return [
                'nav'     => '<nav class="ib-header-qa" id="ib-header-qa" aria-label="' . $qaLabel . '"></nav>',
                'trigger' => $trigger,
            ];
        }

        $items = '';
        foreach ($actions as $a) {
            $url   = Output::e((string)$a['url']);
            $icon  = Output::e((string)$a['icon']);
            $label = Output::e((string)$a['label']);
            $id    = (int)$a['id'];
            $items .= <<<HTML
            <a class="ib-header-qa__item" href="{$url}" title="{$label}" data-qa-id="{$id}">
                <i class="{$icon}" aria-hidden="true"></i>
                <span class="ib-header-qa__label">{$label}</span>
            </a>
            HTML;
        }

        return [
            'nav'     => '<nav class="ib-header-qa" id="ib-header-qa" aria-label="' . $qaLabel . '">' . $items . '</nav>',
            'trigger' => $trigger,
        ];
    }


    // -------------------------------------------------------------------------
    // Avatar Dropdown (profile, theme toggle, logout)
    // -------------------------------------------------------------------------

    private function buildAvatarDropdown(string $userName, string $userEmail, string $initial, string $avatar = '', array $userEntities = [], ?array $activeEntity = null): string
    {
        $csrfField    = $this->csrf->field();
        $pluginMenuHtml = '';
        foreach (LayoutExtensions::userMenu() as $item) {
            $pluginMenuHtml .= '<li role="menuitem"><a href="' . Output::attr($item['href']) . '">'
                . '<i class="' . Output::attr($item['icon']) . '" aria-hidden="true"></i> '
                . Output::e(__($item['label'])) . '</a></li>';
        }
        $logoutLabel  = __('logout');
        $safeInitial  = Output::e($initial);

        if ($avatar !== '') {
            $avatarUrl = Output::attr('/uploads/avatars/' . $avatar);
            $avatarInner = '<img src="' . $avatarUrl . '" alt="" class="ib-avatar__img">';
        } else {
            $avatarInner = '<span class="ib-avatar__initial" aria-hidden="true">' . $safeInitial . '</span>';
        }

        // Entity avatar next to user avatar, both wrapped in a colored pill.
        // With a PERSONAL-type entity active (personal/freelance) only the
        // plain user avatar shows: no pill, no org badge — the account IS the
        // person. (Tenant-provided personal accounts are filtered earlier, in
        // TenantEntities::active().)
        $orgBadgeHtml = '';
        $duoWrapEnd = '';
        $entityType = (string)($activeEntity['type'] ?? '');
        if ($activeEntity !== null && !in_array($entityType, ['personal', 'freelance'], true)) {
            $entityName = Output::e((string)($activeEntity['name'] ?? ''));
            $entityLogo = (string)($activeEntity['logo'] ?? '');
            $entityColor = (string)($activeEntity['color'] ?? '');

            // Tenant-provided entities carry a ready-made absolute logo URL
            // (logo_url); native ones store a filename under /uploads/entities/.
            $entityLogoAbs = (string)($activeEntity['logo_url'] ?? '');

            {
                if ($entityLogoAbs !== '' || $entityLogo !== '') {
                    $entityLogoUrl = $entityLogoAbs !== ''
                        ? Output::attr($entityLogoAbs)
                        : Output::attr(\Intrabase\Support\Branding::entityLogoUrl($entityLogo));
                    // contain (no cover): el logo sencer s'ajusta dins el cercle.
                    // logo_bg (computat pel proveïdor de tenant segons la
                    // lluminositat del logo) dona fons de contrast als logos
                    // transparents: blanc per a logos foscos i a l'inrevés.
                    $badgeStyle = 'object-fit:contain';
                    $logoBgc = (string)($activeEntity['logo_bg'] ?? '');
                    if ($logoBgc === '' && $entityLogo !== '') {
                        // Native entities carry no precomputed logo_bg: judge
                        // the file here (transparent dark logos need a light
                        // backdrop on the dark appbar). (31/08/2026)
                        $logoBgc = (string) (\Intrabase\Support\Branding::entityLogoBg($entityLogo) ?? '');
                    }
                    if ($logoBgc === '#fff') {
                        // Logo fosc amb fons transparent: s'inverteix de color (negre → blanc) sobre la
                        // barra negra, en lloc de posar-li un cercle blanc al darrere (12/11/2026).
                        $badgeStyle .= ';filter:invert(1)';
                    } elseif ($logoBgc !== '') {
                        $badgeStyle .= ';background:' . $logoBgc . ';padding:3px';
                    }
                    $orgBadgeHtml = '<img src="' . $entityLogoUrl . '" alt="' . $entityName . '" class="ib-avatar__org" title="' . $entityName . '" style="' . Output::attr($badgeStyle) . '">';
                } else {
                    $entityInitials = $this->getInitials((string)($activeEntity['name'] ?? ''));
                    $bgStyle = $entityColor !== '' ? ' style="background:' . Output::attr($entityColor) . '"' : '';
                    $orgBadgeHtml = '<span class="ib-avatar__org ib-avatar__org--initial" title="' . $entityName . '"' . $bgStyle . '>' . Output::e($entityInitials) . '</span>';
                }
            }
            // Wrap both avatars in a pill with the entity color as background
            $duoBg = $entityColor !== '' ? Output::attr($entityColor) : 'var(--ib-primary)';
            $avatarInner = '<span class="ib-avatar__duo" style="background:color-mix(in srgb, ' . $duoBg . ' 15%, transparent)">' . $avatarInner;
            $duoWrapEnd = '</span>';
        }

        // Account switcher (26/09/2026; replaces the «Els meus comptes» list).
        // The top of the panel shows the account to SWITCH TO — not the active
        // one, which the avatar button already shows — and the whole row is the
        // switch: organization → personal, personal → the organization (or,
        // with several, «Canviar d'organització», which opens the picker).
        // Without another account the row just shows the active one. With
        // several organizations a menu item opens the same picker.
        $acc = $this->avatarAccounts($userEntities, $activeEntity, $userName, $userEmail, $avatar, $initial);
        $current  = $acc['current'];
        $orgs     = $acc['orgs'];
        $personal = $acc['personal'];
        $multiOrg = count($orgs) > 1;

        $target = null;   // [account, attrs, title]
        if (!$current['is_personal'] && $personal !== null) {
            $target = [$personal, $personal['attrs'], __('switch_to_personal')];
        } elseif ($current['is_personal'] && count($orgs) === 1) {
            $target = [$orgs[0], $orgs[0]['attrs'], __('switch_to_account', $orgs[0]['name'])];
        } elseif ($current['is_personal'] && $multiOrg) {
            $pick = ['name' => __('choose_organization'), 'sub' => '',
                     'avatar' => '<span class="ib-org-item__initial ib-avatar__account-pick"><i class="fa-solid fa-building" aria-hidden="true"></i></span>'];
            $target = [$pick, ' data-ib-org-modal', __('change_organization')];
        }

        if ($target !== null) {
            [$t, $tAttrs, $tTitle] = $target;
            $tt = Output::attr($tTitle);
            $accountHtml = '<button type="button" class="ib-avatar__account ib-avatar__account--switch"' . $tAttrs . ' title="' . $tt . '" aria-label="' . $tt . '">'
                . $t['avatar']
                . '<span class="ib-avatar__account-info">'
                . '<span class="ib-avatar__account-cap">' . Output::e(__('switch_to')) . '</span>'
                . '<span class="ib-avatar__info-name">' . Output::e($t['name']) . '</span>'
                . ($t['sub'] !== '' ? '<span class="ib-avatar__info-email">' . Output::e($t['sub']) . '</span>' : '')
                . '</span>'
                . '<span class="ib-avatar__swap" aria-hidden="true"><i class="fa-solid fa-arrow-right-arrow-left"></i></span>'
                . '</button>';
        } else {
            $accountHtml = '<div class="ib-avatar__account">'
                . $current['avatar']
                . '<div class="ib-avatar__account-info">'
                . '<div class="ib-avatar__info-name">' . Output::e($current['name']) . '</div>'
                . ($current['sub'] !== '' ? '<div class="ib-avatar__info-email">' . Output::e($current['sub']) . '</div>' : '')
                . '</div>'
                . '</div>';
        }

        $orgMenuHtml  = '';
        $orgModalHtml = '';
        if ($multiOrg) {
            // Menu item only when an organization is active: from the personal
            // profile the switch row above already opens the picker.
            if (!$current['is_personal']) {
                $changeLabel = Output::e(__('change_organization'));
                $orgMenuHtml = '<li role="menuitem"><button type="button" class="ib-avatar__tenant-item" data-ib-org-modal>'
                    . '<i class="fa-solid fa-building" aria-hidden="true"></i> ' . $changeLabel . '</button></li>';
            }
            $itemsHtml = '';
            foreach ($orgs as $o) {
                $itemsHtml .= '<button type="button" class="ib-org-item' . ($o['is_active'] ? ' ib-org-item--active' : '') . '"' . $o['attrs'] . '>'
                    . $o['avatar']
                    . '<span class="ib-org-item__info"><span class="ib-org-item__name">' . Output::e($o['name']) . '</span>'
                    . ($o['sub'] !== '' ? '<span class="ib-org-item__role">' . Output::e($o['sub']) . '</span>' : '')
                    . '</span>'
                    . ($o['is_active'] ? '<i class="fa-solid fa-check ib-org-item__check" aria-hidden="true"></i>' : '')
                    . '</button>';
            }
            $chooseLabel = Output::e(__('choose_organization'));
            $closeLabel  = Output::attr(__('close'));
            $orgModalHtml = <<<HTML
            <div class="ib-dlg-backdrop ib-org-modal" id="ib-org-modal" hidden>
                <div class="ib-dlg ib-org-modal__dlg" role="dialog" aria-modal="true" aria-labelledby="ib-org-modal-title">
                    <button type="button" class="ib-dlg__x" data-ib-org-modal-close aria-label="{$closeLabel}" title="{$closeLabel}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    <div class="ib-dlg__title" id="ib-org-modal-title">{$chooseLabel}</div>
                    <div class="ib-org-modal__list">{$itemsHtml}</div>
                </div>
            </div>
            HTML;
        }

        // Ítems del tenant (Support\TenantMenu; hook <data>/avatar_menu.php). Cap sense hook.
        $tenantItemsHtml = '';
        foreach (\Intrabase\Support\TenantMenu::items($this->session, $activeEntity) as $it) {
            $icon  = Output::attr($it['icon']);
            $label = Output::e($it['label']);
            if ($it['js'] !== '') {
                $fn = Output::attr($it['js']);
                $tenantItemsHtml .= "<li role=\"menuitem\"><button type=\"button\" class=\"ib-avatar__tenant-item\" data-ib-tenant-fn=\"{$fn}\"><i class=\"{$icon}\" aria-hidden=\"true\"></i> {$label}</button></li>";
            } else {
                $href = Output::attr($it['href']);
                $tenantItemsHtml .= "<li role=\"menuitem\"><a href=\"{$href}\"><i class=\"{$icon}\" aria-hidden=\"true\"></i> {$label}</a></li>";
            }
        }

        return <<<HTML
        <div class="ib-avatar" id="ib-avatar">
            <button class="ib-avatar__btn" id="ib-avatar-btn" title="{$userName}"
                    aria-haspopup="true" aria-expanded="false" aria-controls="ib-avatar-panel">
                {$avatarInner}
                {$orgBadgeHtml}
                {$duoWrapEnd}
                <span class="ib-avatar__name">{$userName}</span>
                <i class="fa-solid fa-chevron-down ib-avatar__chevron" aria-hidden="true"></i>
            </button>
            <div class="ib-avatar__panel" id="ib-avatar-panel" hidden>
                {$accountHtml}
                <ul class="ib-avatar__menu" role="menu">
                    {$orgMenuHtml}
                    {$pluginMenuHtml}
                    {$tenantItemsHtml}
                    <li class="ib-avatar__sep" role="separator" aria-hidden="true"></li>
                    <li role="menuitem">
                        <form method="post" action="/logout" style="margin:0;padding:0">
                            {$csrfField}
                            <button type="submit" class="ib-avatar__logout">
                                <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
                                {$logoutLabel}
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
            {$orgModalHtml}
        </div>
        HTML;
    }

    /**
     * The user's accounts for the avatar switcher, from the tenant provider
     * (Support\TenantEntities rows; `is_personal` marks the personal account,
     * rows without an id are not accounts) or from the native entities (type
     * personal/freelance is the personal one; without it, «no active entity»
     * is personal mode, POST /user/switch-entity {entity_id: 0}).
     *
     * Each account: name, sub, avatar (html), attrs (switch data-attributes,
     * empty for the active one), is_active, is_personal.
     *
     * @return array{current: array, personal: ?array, orgs: list<array>}
     */
    private function avatarAccounts(array $userEntities, ?array $activeEntity, string $userName, string $userEmail, string $avatar, string $initial): array
    {
        // $userName/$userEmail arrive escaped (Output::e) for the template: undo it, the
        // account fields are escaped once at render time.
        $plainName  = html_entity_decode($userName, ENT_QUOTES, 'UTF-8');
        $plainEmail = html_entity_decode($userEmail, ENT_QUOTES, 'UTF-8');
        $userAvatar = $avatar !== ''
            ? '<img src="' . Output::attr('/uploads/avatars/' . $avatar) . '" alt="" class="ib-org-item__img">'
            : '<span class="ib-org-item__initial">' . Output::e(html_entity_decode($initial, ENT_QUOTES, 'UTF-8')) . '</span>';
        $mkPersonal = fn (string $attrs, bool $active) => [
            'name' => $plainName, 'sub' => $plainEmail, 'avatar' => $userAvatar,
            'attrs' => $active ? '' : $attrs, 'is_active' => $active, 'is_personal' => true,
        ];

        $personal = null;
        $orgs = [];
        $activeId = (int) ($activeEntity['id'] ?? 0);
        $tenant = false;
        foreach ($userEntities as $ent) {
            if (!empty($ent['_tenant'])) {
                $tenant = true;
                $aid = (int) ($ent['id'] ?? 0);
                if ($aid <= 0) {
                    continue; // the tenant's "create a new one" card
                }
                $active = !empty($ent['is_active']);
                $url    = (string) ($ent['switch_url'] ?? '');
                $attrs  = $url !== '' ? ' data-switch-url="' . Output::attr($url) . '" data-actor-id="' . $aid . '"' : '';
                if (!empty($ent['is_personal'])) {
                    $personal = $mkPersonal($attrs, $active);
                    continue;
                }
                $logoUrl = (string) ($ent['logo_url'] ?? '');
                $logoBg  = (string) ($ent['logo_bg'] ?? '');
                $av = $logoUrl !== ''
                    ? '<img src="' . Output::attr($logoUrl) . '" alt="" class="ib-org-item__img" style="' . Output::attr('object-fit:contain' . ($logoBg !== '' ? ';background:' . $logoBg . ';padding:2px;box-sizing:border-box' : '')) . '">'
                    : '<span class="ib-org-item__initial">' . Output::e($this->getInitials((string) ($ent['name'] ?? ''))) . '</span>';
                $orgs[] = [
                    'name' => (string) ($ent['name'] ?? ''),
                    'sub'  => (string) (($ent['role_label'] ?? '') !== '' ? $ent['role_label'] : ($ent['type_label'] ?? '')),
                    'avatar' => $av, 'attrs' => $active ? '' : $attrs, 'is_active' => $active, 'is_personal' => false,
                ];
                continue;
            }
            $eid    = (int) ($ent['entity_id'] ?? 0);
            $active = $eid === $activeId;
            $attrs  = ' data-entity-id="' . $eid . '"';
            if (in_array((string) ($ent['type'] ?? ''), ['personal', 'freelance'], true) && $personal === null) {
                $personal = $mkPersonal($attrs, $active);
                continue;
            }
            $elogo = (string) ($ent['logo'] ?? '');
            if ($elogo !== '') {
                $eBg = (string) (\Intrabase\Support\Branding::entityLogoBg($elogo) ?? '');
                $av = '<img src="' . Output::attr(\Intrabase\Support\Branding::entityLogoUrl($elogo)) . '" alt="" class="ib-org-item__img" style="'
                    . Output::attr('object-fit:contain' . ($eBg !== '' ? ';background:' . $eBg . ';padding:2px;box-sizing:border-box' : '')) . '">';
            } else {
                $ecolor = (string) ($ent['color'] ?? '');
                $av = '<span class="ib-org-item__initial"' . ($ecolor !== '' ? ' style="background:' . Output::attr($ecolor) . '"' : '') . '>'
                    . Output::e($this->getInitials((string) ($ent['name'] ?? ''))) . '</span>';
            }
            $orgs[] = [
                'name' => (string) ($ent['name'] ?? ''), 'sub' => (string) ($ent['plan'] ?? ''),
                'avatar' => $av, 'attrs' => $active ? '' : $attrs, 'is_active' => $active, 'is_personal' => false,
            ];
        }
        // Native: without a personal entity, «no active entity» is personal mode.
        if (!$tenant && $personal === null) {
            $personal = $mkPersonal(' data-entity-id="0"', $activeId === 0);
        }

        $current = null;
        foreach ($orgs as $o) {
            if ($o['is_active']) {
                $current = $o;
                break;
            }
        }
        if ($current === null) {
            // Personal active — or nothing marked active: show the person as before.
            $current = $personal ?? $mkPersonal('', true);
            $current['is_personal'] = true;
            if ($personal !== null && !$personal['is_active']) {
                $current['attrs'] = '';
            }
        }
        if ($current['is_personal'] && $personal !== null) {
            $personal['is_active'] = true;
        }

        return ['current' => $current, 'personal' => $personal, 'orgs' => $orgs];
    }

    // -------------------------------------------------------------------------
    // Notification Bell (unchanged)
    // -------------------------------------------------------------------------

    private function buildNotificationBell(string $currentSeg = ''): string
    {
        [$unreadCount, $notifications] = $this->loadNotifications();

        $badgeHtml = $unreadCount > 0
            ? '<span class="ib-notif-badge" id="ib-notif-badge">' . min($unreadCount, 99) . '</span>'
            : '<span class="ib-notif-badge ib-notif-badge--hidden" id="ib-notif-badge"></span>';

        $dismissLabel = Output::e(__('notif_mark_read'));

        $itemsHtml = '';
        if ($notifications === []) {
            $itemsHtml = '<div class="ib-notif-empty">' . __('no_notifications') . '</div>';
        } else {
            foreach ($notifications as $n) {
                $typeClass = match ((string)($n['notification_type'] ?? 'info')) {
                    'success' => 'ib-notif-item--success',
                    'warning' => 'ib-notif-item--warning',
                    'danger'  => 'ib-notif-item--danger',
                    'system'  => 'ib-notif-item--system',
                    default   => 'ib-notif-item--info',
                };
                $icon      = Output::e((string)($n['icon_class'] ?? 'fa-solid fa-bell'));
                $title     = Output::e((string)($n['title'] ?? ''));
                $msg       = Output::e(mb_strimwidth((string)($n['message'] ?? ''), 0, 80, '…'));
                $unreadCls = ((int)($n['is_read'] ?? 0) === 0) ? ' ib-notif-item--unread' : '';
                $nid       = (int)($n['id'] ?? 0);
                $url       = !empty($n['url']) ? Output::e((string)$n['url']) : Output::e(Router::url('sys_notifications'));
                $timeAgo   = $this->timeAgo((string)($n['created_at'] ?? ''));

                // La creueta va FORA de l'enllac, no a dins: un <button> dins d'un
                // <a> es HTML invalid i els lectors de pantalla no el poden anunciar
                // com a control separat. El .ib-notif-row nomes hi es per ancorar-la.
                $itemsHtml .= <<<HTML
                <div class="ib-notif-row">
                    <a class="ib-notif-item {$typeClass}{$unreadCls}" href="{$url}" data-nid="{$nid}">
                        <i class="{$icon} ib-notif-item__icon" aria-hidden="true"></i>
                        <div class="ib-notif-item__body">
                            <div class="ib-notif-item__title">{$title}</div>
                            <div class="ib-notif-item__msg">{$msg}</div>
                            <div class="ib-notif-item__time">{$timeAgo}</div>
                        </div>
                    </a>
                    <button type="button" class="ib-notif-item__dismiss" data-nid="{$nid}"
                            title="{$dismissLabel}" aria-label="{$dismissLabel}">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                HTML;
            }
        }

        $hasUnread          = $unreadCount > 0 ? 'true' : 'false';
        $markAllLabel       = __('mark_all_read');
        $notifLabel         = __('notifications');
        $viewAllNotifsLabel = __('view_all_notifs');
        $closeLabel         = Output::e(__('close'));
        $notifsUrl          = Output::e(Router::url('sys_notifications'));

        // --- History modal labels (rendered server-side; the JS carries no literals) ---
        $mTitleLabel      = Output::e(__('notif_all_title'));
        $mEmptyLabel      = Output::e(__('no_notifications'));
        $mLoadingLabel    = Output::e(__('loading'));
        $mErrorLabel      = Output::e(__('notif_load_error'));
        $mNoMoreLabel     = Output::e(__('notif_no_more'));
        $mLoadMoreLabel   = Output::e(__('notif_load_more'));
        $mReadLabel       = Output::e(__('notif_read'));
        $mUnreadLabel     = Output::e(__('notif_unread'));
        $mMarkReadLabel   = Output::e(__('notif_mark_read'));
        $mMarkUnreadLabel = Output::e(__('notif_mark_unread'));

        $modalHtml = <<<HTML
        <div class="ib-notif-modal" id="ib-notif-modal" role="dialog" aria-modal="true"
             aria-labelledby="ib-notif-modal-title" hidden>
            <div class="ib-notif-modal__backdrop" id="ib-notif-modal-backdrop" data-ib-notif-close="1"></div>
            <div class="ib-notif-modal__dialog">
                <div class="ib-notif-modal__header">
                    <h2 class="ib-notif-modal__title" id="ib-notif-modal-title">{$mTitleLabel}</h2>
                    <button type="button" class="ib-notif-modal__close" id="ib-notif-modal-close"
                            aria-label="{$closeLabel}">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="ib-notif-modal__list" id="ib-notif-modal-list"
                     data-empty-label="{$mEmptyLabel}"
                     data-loading-label="{$mLoadingLabel}"
                     data-error-label="{$mErrorLabel}"
                     data-no-more-label="{$mNoMoreLabel}"
                     data-read-label="{$mReadLabel}"
                     data-unread-label="{$mUnreadLabel}"
                     data-mark-read-label="{$mMarkReadLabel}"
                     data-mark-unread-label="{$mMarkUnreadLabel}"></div>
                <div class="ib-notif-modal__footer">
                    <span class="ib-notif-modal__status" id="ib-notif-modal-status"
                          role="status" aria-live="polite"></span>
                    <button type="button" class="ib-btn ib-btn--secondary ib-notif-modal__more"
                            id="ib-notif-modal-more" hidden>{$mLoadMoreLabel}</button>
                </div>
            </div>
        </div>
        HTML;

        return <<<HTML
        <div class="ib-notif" id="ib-notif">
            <button class="ib-notif__btn" id="ib-notif-btn"
                    aria-label="{$notifLabel}" aria-haspopup="true" aria-expanded="false">
                <i class="fa-solid fa-bell" aria-hidden="true"></i>
                {$badgeHtml}
            </button>
            <div class="ib-notif__panel" id="ib-notif-panel" hidden>
                <div class="ib-notif__header">
                    <span class="ib-notif__title">{$notifLabel}</span>
                    <button class="ib-notif__mark-all" id="ib-notif-mark-all"
                            data-has-unread="{$hasUnread}">{$markAllLabel}</button>
                    <button type="button" class="ib-notif__close" id="ib-notif-close"
                            aria-label="{$closeLabel}">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="ib-notif__list" id="ib-notif-list" data-empty-label="{$mEmptyLabel}"
                     data-dismiss-label="{$dismissLabel}">
                    {$itemsHtml}
                </div>
                <div class="ib-notif__footer">
                    <a href="{$notifsUrl}" id="ib-notif-view-all">{$viewAllNotifsLabel}</a>
                </div>
            </div>
            {$modalHtml}
        </div>
        HTML;
    }

    /**
     * Load all entities the user belongs to.
     *
     * @return array<int, array{entity_id: int, name: string, logo: string, role: string, is_default: int}>
     */
    /**
     * Get the first 2 letters of a name (e.g. "Acme SCCL" → "AC", "Intrabase" → "IN").
     */
    private function getInitials(string $name): string
    {
        return self::getUserInitials($name);
    }

    /**
     * Extract initials from a name, ignoring dots.
     * "M.Jane Doe" → "MD", "Acme SCCL" → "AS", "Intrabase" → "IN"
     */
    private static function getUserInitials(string $name): string
    {
        $clean = str_replace('.', '', trim($name));
        $parts = preg_split('/[\s\-_]+/', $clean);
        if (!$parts || $parts[0] === '') {
            return '?';
        }
        if (count($parts) === 1) {
            return strtoupper(mb_substr($parts[0], 0, 2, 'UTF-8'));
        }
        return strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[1], 0, 1, 'UTF-8'));
    }

    private function loadUserEntities(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            $rows = $this->db->fetchAll(
                'SELECT ue.entity_id, e.name, e.logo, e.color, e.type, ue.role, ue.is_default
                 FROM ib_user_entities ue
                 JOIN ib_obj_entities e ON e.id = ue.entity_id
                 WHERE ue.user_id = :uid AND e.status = "active"
                 ORDER BY ue.is_default DESC, e.name ASC',
                ['uid' => $userId]
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$r) {
            $r['plan'] = null;
        }
        unset($r);

        return $rows;
    }

    private function loadNotifications(): array
    {
        $user = $this->session->user();
        $uid  = (int)($user['id'] ?? 0);
        if ($uid <= 0) {
            return [0, []];
        }

        $activeEnt = $this->session->get('_ib_active_entity');
        $eid = is_array($activeEnt) ? (int)($activeEnt['id'] ?? 0) : 0;
        $eid = $eid > 0 ? $eid : null;

        try {
            $pdo = $this->db->pdo();

            $sqlCount = 'SELECT COUNT(*) FROM ib_sys_notifications
                          WHERE is_read = 0
                            AND (user_id = :uid OR user_id IS NULL)
                            AND (entity_id IS NULL' . ($eid !== null ? ' OR entity_id = :eid' : '') . ')
                            AND (expires_at IS NULL OR expires_at > NOW())';
            $stmtCount = $pdo->prepare($sqlCount);
            $stmtCount->bindValue('uid', $uid, PDO::PARAM_INT);
            if ($eid !== null) $stmtCount->bindValue('eid', $eid, PDO::PARAM_INT);
            $stmtCount->execute();
            $unread = (int)$stmtCount->fetchColumn();

            $sqlList = 'SELECT id, notification_type, title, message, url, icon_class, is_read, created_at
                          FROM ib_sys_notifications
                         WHERE is_read = 0
                           AND (user_id = :uid OR user_id IS NULL)
                           AND (entity_id IS NULL' . ($eid !== null ? ' OR entity_id = :eid' : '') . ')
                           AND (expires_at IS NULL OR expires_at > NOW())
                      ORDER BY created_at DESC
                         LIMIT 5';
            $stmtList = $pdo->prepare($sqlList);
            $stmtList->bindValue('uid', $uid, PDO::PARAM_INT);
            if ($eid !== null) $stmtList->bindValue('eid', $eid, PDO::PARAM_INT);
            $stmtList->execute();
            $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return [$unread, $rows];
        } catch (\Throwable) {
            return [0, []];
        }
    }

    private function timeAgo(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }
        try {
            $ts   = strtotime($datetime);
            if ($ts === false) {
                return '';
            }
            $diff = abs(time() - $ts);
            if ($diff < 60)    return __('just_now');
            if ($diff < 3600)  return (int)($diff / 60) . ' ' . __('ago_min');
            if ($diff < 86400) return (int)($diff / 3600) . ' ' . __('ago_hour');
            return (int)($diff / 86400) . ' ' . __('ago_day');
        } catch (\Throwable) {
            return '';
        }
    }

    // -------------------------------------------------------------------------
    // Breadcrumb
    // -------------------------------------------------------------------------

    private function buildBreadcrumb(array $breadcrumb): string
    {
        if ($breadcrumb === []) {
            return '<ol class="ib-breadcrumb"><li class="ib-breadcrumb__item">' . __('home') . '</li></ol>';
        }

        $items = '<li class="ib-breadcrumb__item">'
            . '<a class="ib-breadcrumb__link" href="/">' . __('home') . '</a>'
            . '</li>';

        foreach ($breadcrumb as $crumb) {
            $label = Output::e($crumb['label']);
            if (isset($crumb['url'])) {
                $url    = Output::e($crumb['url']);
                $items .= '<li class="ib-breadcrumb__item">'
                    . '<a class="ib-breadcrumb__link" href="' . $url . '">' . $label . '</a>'
                    . '</li>';
            } else {
                $items .= '<li class="ib-breadcrumb__item ib-breadcrumb__item--current"'
                    . ' aria-current="page">' . $label . '</li>';
            }
        }

        return '<ol class="ib-breadcrumb">' . $items . '</ol>';
    }

    // -------------------------------------------------------------------------
    // Flash Messages
    // -------------------------------------------------------------------------

    private function buildFlash(): string
    {
        $messages = $this->session->flashes();
        if ($messages === []) {
            return '';
        }

        $html = '';
        foreach ($messages as $type => $text) {
            $alertMod = match ($type) {
                'success' => 'success',
                'error'   => 'danger',
                'warning' => 'warning',
                default   => 'info',
            };
            $html .= '<div class="ib-alert ib-alert--' . $alertMod . ' ib-toast" role="alert">'
                . '<span>' . Output::e($text) . '</span>'
                . '<button class="ib-alert__close" aria-label="' . __('close') . '">&times;</button>'
                . '</div>';
        }

        return $html;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function csrfMeta(): string
    {
        return '<meta name="csrf-token" content="' . Output::e($this->csrf->token()) . '">';
    }

    /**
     * Resolve an asset URL to its minified version (if available).
     */
    private function assetUrl(string $url): string
    {
        return AssetResolver::url($url);
    }

    private function buildCssLinks(array $urls): string
    {
        $html = '';
        foreach ($urls as $url) {
            $html .= '<link rel="stylesheet" href="' . Output::e($this->assetUrl($url)) . '">' . "\n";
        }
        return $html;
    }

    private function buildJsScripts(array $urls): string
    {
        $html = '';
        foreach ($urls as $url) {
            $html .= '<script src="' . Output::e($this->assetUrl($url)) . '"></script>' . "\n";
        }
        return $html;
    }

    /**
     * Build inline CSS overrides: active entity color > ib_config primary_color
     */
    private function buildThemeOverrides(): string
    {
        // Priority: active entity color > global primary_color
        $color = '';
        $activeEntity = $this->session->get('_ib_active_entity');
        if (!empty($activeEntity['color']) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $activeEntity['color'])) {
            $color = $activeEntity['color'];
        }
        if ($color === '') {
            $color = ConfigManager::get('primary_color', '');
        }
        if ($color === '' || !preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
            return $this->buildPaletteOverrides();
        }
        $safe = Output::e($color);
        $dark = $this->adjustBrightness($color, -30);
        $light = $this->adjustBrightness($color, 200, 0.15);
        return '<style>:root,[data-theme="light"],[data-theme="dark"]{'
            . '--ib-primary:' . $safe . ';'
            . '--ib-primary-dark:' . Output::e($dark) . ';'
            . '--ib-primary-light:' . Output::e($light) . '}'
            . '</style>' . $this->buildPaletteOverrides();
    }

    /**
     * Neutral palette of the tenant («Paleta de l'organització», Preferences →
     * Interface, admins only; ib_config `theme_neutral`). One base tone, the
     * dark surface, from which every dark grey of the shell AND of the apps is
     * derived keeping its hue: a slate base gives blue-greys (the stylesheet
     * defaults, #1f2a3b), a graphite base (#2a2a2e) neutral greys. The offsets
     * reproduce the defaults exactly when the base is the default slate, so
     * the tokens in intrabase.css and this function must move together.
     * Empty or invalid = nothing emitted (stylesheet defaults).
     */
    public const PALETTE_PRESETS = ['slate' => '#1f2a3b', 'graphite' => '#2a2a2e'];
    public const PALETTE_DEFAULT = '#1f2a3b';

    /** @return array<string, string> token => hex, the dark scale of a base tone */
    public static function paletteTokens(string $base): array
    {
        [$h, $s, $l] = self::hexToHsl($base);
        $tone = static fn (float $dl): string => self::hslToHex($h, $s, max(0.0, min(100.0, $l + $dl)));
        return [
            '--ib-app-bg'           => $tone(-5),
            '--ib-surface'          => strtolower($base),
            '--ib-surface-2'        => $tone(5),
            '--ib-input-bg'         => $tone(-2.5),
            '--ib-border'           => $tone(15),
            '--ib-border-light'     => $tone(8),
            '--ib-desk'             => $tone(-4),
            '--ib-sidebar-bg'       => $tone(-1),
            '--ib-sidebar-hover-bg' => $tone(3),
            '--ib-secondary-light'  => $tone(0),
        ];
    }

    private function buildPaletteOverrides(): string
    {
        $base = (string) ConfigManager::get('theme_neutral', '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $base) || strtolower($base) === self::PALETTE_DEFAULT) {
            return '';
        }
        $css = '';
        foreach (self::paletteTokens($base) as $token => $hex) {
            $css .= $token . ':' . $hex . ';';
        }
        $sidebar = self::paletteTokens($base);
        return '<style>[data-theme="dark"]{' . $css . '}'
            . '[data-theme="light"]{--ib-sidebar-bg:' . $sidebar['--ib-sidebar-bg']
            . ';--ib-sidebar-hover-bg:' . $sidebar['--ib-sidebar-hover-bg'] . '}</style>';
    }

    /** @return array{0: float, 1: float, 2: float} hue 0-360, saturation 0-100, lightness 0-100 */
    public static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d < 1e-9) {
            return [0.0, 0.0, $l * 100];
        }
        $s = $d / (1 - abs(2 * $l - 1));
        if ($max === $r) {
            $h = fmod(($g - $b) / $d, 6);
        } elseif ($max === $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }
        $h = $h * 60;
        if ($h < 0) {
            $h += 360;
        }
        return [$h, $s * 100, $l * 100];
    }

    public static function hslToHex(float $h, float $s, float $l): string
    {
        $s /= 100;
        $l /= 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60  => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default  => [$c, 0, $x],
        };
        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    /**
     * Darken or lighten a hex color.
     * For $opacity < 1, returns a light tint blended with white.
     */
    private function adjustBrightness(string $hex, int $amount, float $opacity = 1.0): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        if ($opacity < 1.0) {
            // Blend with white for light variant
            $r = (int) round($r * $opacity + 255 * (1 - $opacity));
            $g = (int) round($g * $opacity + 255 * (1 - $opacity));
            $b = (int) round($b * $opacity + 255 * (1 - $opacity));
        } else {
            $r = max(0, min(255, $r + $amount));
            $g = max(0, min(255, $g + $amount));
            $b = max(0, min(255, $b + $amount));
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function currentYear(): string
    {
        return date('Y');
    }

    /** Font-size presets offered in Preferences > Interface. */
    public const FONT_SIZES = ['small', 'normal', 'large'];

    /**
     * Resolve the user's font-size preference (ib_users.font_size) into the
     * value of the data-font-size attribute. CSS turns it into --ib-font-scale,
     * which rescales every rem in the built stylesheets — i.e. the whole app.
     *
     * @param array<string,mixed>|null $user
     */
    public static function fontSize(?array $user): string
    {
        $size = (string) ($user['font_size'] ?? 'normal');

        return in_array($size, self::FONT_SIZES, true) ? $size : 'normal';
    }

    /**
     * Render embed mode: minimal page with CSS + content, no header/sidebar/footer.
     * Used when iframing module pages (e.g. mail account edit inside preferences).
     */
    private function renderEmbed(string $content, array $options): void
    {
        $lang = Output::e($options['lang'] ?? \Intrabase\Lang\Lang::locale());
        $title = Output::e($options['title'] ?? 'Intrabase');
        $bodyClass = Output::attr((string)($options['bodyClass'] ?? ''));
        $extraCss = $options['css'] ?? [];
        $extraJs  = $options['js']  ?? [];
        $cssLinks  = $this->buildCssLinks($extraCss);
        $jsScripts = $this->buildJsScripts($extraJs);
        $csrfMeta  = $this->csrfMeta();
        $user = $this->session->user();
        $userTheme = Output::e($user['theme'] ?? 'dark');
        if (preg_match('/IntrabaseMobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '')) {
            $userTheme = 'dark';
        }
        $userFontSize = self::fontSize($user);
        $intrabaseCssUrl = $this->assetUrl('/assets/css/intrabase.css');
        $intrabaseJsUrl  = $this->assetUrl('/assets/js/intrabase.js');
        // Embedded apps (e.g. /contacts?embedded=1 inside /mailing) run the SAME
        // JS as their standalone page and depend on the global t() helper and the
        // JS translation bundle. Build the same ibI18n as the full layout so the
        // embed is not left with an undefined t() — which threw "t is not defined"
        // mid-render in contacts.js (list rendered, but counts stayed 0 and search
        // broke because updateCounts/renderSidebarLabels never ran).
        $ibI18nCore = [
            'confirm_delete'   => __('confirm_delete'),
            'confirm_continue' => __('confirm_continue'),
            'no_results_for'   => __('no_results_for'),
            'theme_to_dark'    => __('theme_to_dark'),
            'theme_to_light'   => __('theme_to_light'),
            'session_expired_title'  => __('session_expired_title'),
            'session_expired_text'   => __('session_expired_text'),
            'session_expired_button' => __('session_expired_button'),
        ];
        $locale = Lang::locale();
        $jsLangFile = dirname(__DIR__, 2) . '/lang/js_' . $locale . '.php';
        $jsStrings = is_file($jsLangFile) ? (require $jsLangFile) : [];
        if (!$jsStrings) {
            $jsFallback = dirname(__DIR__, 2) . '/lang/js_ca.php';
            $jsStrings = is_file($jsFallback) ? (require $jsFallback) : [];
        }
        $ibI18nJson = json_encode(array_merge($ibI18nCore, is_array($jsStrings) ? $jsStrings : []),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="{$lang}" data-theme="{$userTheme}" data-font-size="{$userFontSize}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            {$csrfMeta}
            <title>{$title}</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
            <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css">
            <link rel="stylesheet" href="{$intrabaseCssUrl}">
            {$cssLinks}
            {$this->buildThemeOverrides()}
            <style>
                body { margin: 0; padding: 16px; background: var(--ib-bg, #0d1117); color: var(--ib-text, #e6edf3); }
                .ib-layout__footer { display: none; }
            </style>
        </head>
        <body class="{$bodyClass}">
            {$content}
            <script>window.ibI18n={$ibI18nJson};window.t=function(k,p){var s=window.ibI18n[k]||k;if(p){for(var i=0;i<p.length;i++)s=s.replace('%s',p[i]).replace('%d',p[i]);}return s;};</script>
            <script src="{$intrabaseJsUrl}"></script>
            {$jsScripts}
        </body>
        </html>
        HTML;
    }
}
