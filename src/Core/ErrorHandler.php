<?php

declare(strict_types=1);

namespace Intrabase\Core;

/**
 * Styled Error Handler
 *
 * Renders a visual error page with stack trace (debug mode)
 * or a generic friendly page (production mode).
 *
 * @package Intrabase\Core
 */
class ErrorHandler
{
    public function __construct(private bool $debug = false) {}

    /**
     * Render the error page and exit.
     */
    public function render(\Throwable $e): void
    {
        // Always leave a trace in the server log (25/09/2026): in production the
        // page only says "temporary error", and a 500 nobody can read costs hours.
        error_log('[Intrabase] ' . get_class($e) . ': ' . $e->getMessage()
            . ' at ' . $e->getFile() . ':' . $e->getLine()
            . ' [' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ']');
        // Discard any stray output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($e instanceof ModuleNotFoundException) {
            http_response_code(404);
            echo $this->render404Page();
            return;
        }

        http_response_code(500);

        if ($this->debug) {
            echo $this->renderDebugPage($e);
        } else {
            echo $this->renderProductionPage();
        }
    }

    // -------------------------------------------------------------------------
    // Debug page
    // -------------------------------------------------------------------------

    private function renderDebugPage(\Throwable $e): string
    {
        $class   = htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file    = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line    = $e->getLine();
        $code    = $e->getCode();
        $trace   = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        // Build clipboard text (JS-escaped)
        $copyRaw = get_class($e) . ': ' . $e->getMessage()
            . "\nFile: " . $e->getFile() . ':' . $e->getLine()
            . "\n\n" . $e->getTraceAsString();
        $copyText = json_encode($copyRaw, JSON_HEX_TAG | JSON_HEX_AMP);

        $snippetHtml = $this->renderCodeSnippet($e->getFile(), $e->getLine());
        $codeHtml    = $code ? '<small style="color:#6b7280"> &nbsp;(code ' . (int) $code . ')</small>' : '';

        // Previous exceptions chain
        $previousHtml = '';
        $prev = $e->getPrevious();
        while ($prev !== null) {
            $prevClass   = htmlspecialchars(get_class($prev), ENT_QUOTES, 'UTF-8');
            $prevMessage = htmlspecialchars($prev->getMessage(), ENT_QUOTES, 'UTF-8');
            $prevFile    = htmlspecialchars($prev->getFile(), ENT_QUOTES, 'UTF-8');
            $prevLine    = $prev->getLine();
            $previousHtml .= '<div class="ib-err-previous">'
                . '<span class="ib-err-badge ib-err-badge--prev">Caused by</span>'
                . "<strong>{$prevClass}</strong>: {$prevMessage}<br>"
                . "<small>{$prevFile}:{$prevLine}</small>"
                . '</div>';
            $prev = $prev->getPrevious();
        }
        $causedByCard = $previousHtml !== ''
            ? '<div class="ib-err-card"><h2>Caused By</h2>' . $previousHtml . '</div>'
            : '';

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>500 — Application Error</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;background:#0f1117;color:#e2e8f0;line-height:1.6;min-height:100vh}
a{color:#7c9ef7;text-decoration:none}a:hover{text-decoration:underline}
.ib-err-container{max-width:960px;margin:0 auto;padding:2rem 1.5rem}
.ib-err-header{display:flex;align-items:flex-start;gap:1rem;margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid #2d3748}
.ib-err-icon{flex-shrink:0;width:48px;height:48px;background:#7f1d1d;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.5rem}
.ib-err-title h1{font-size:1.25rem;font-weight:600;color:#fca5a5;margin-bottom:.25rem}
.ib-err-title p{font-size:.875rem;color:#94a3b8}
.ib-err-badge{display:inline-block;font-size:.7rem;font-weight:700;padding:.1rem .5rem;border-radius:99px;text-transform:uppercase;letter-spacing:.05em;margin-right:.5rem}
.ib-err-badge--class{background:#7f1d1d;color:#fca5a5}
.ib-err-badge--prev{background:#4c1d95;color:#c4b5fd}
.ib-err-card{background:#1a1f2e;border:1px solid #2d3748;border-radius:10px;padding:1.5rem;margin-bottom:1.5rem}
.ib-err-card h2{font-size:.875rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;margin-bottom:1rem}
.ib-err-message{font-size:1.25rem;font-weight:600;color:#f1f5f9;word-break:break-word}
.ib-err-meta{display:flex;flex-wrap:wrap;gap:1rem;margin-top:1rem}
.ib-err-meta-item{font-size:.8rem;color:#94a3b8}
.ib-err-meta-item strong{color:#cbd5e1;display:block;font-size:.7rem;text-transform:uppercase;margin-bottom:.15rem}
.ib-err-snippet{background:#111827;border:1px solid #374151;border-radius:8px;overflow:hidden;margin-top:1rem}
.ib-err-snippet-header{padding:.5rem 1rem;background:#1f2937;font-size:.75rem;color:#6b7280;font-family:monospace}
.ib-err-snippet table{width:100%;border-collapse:collapse;font-family:\'SFMono-Regular\',Consolas,\'Liberation Mono\',monospace;font-size:.8rem}
.ib-err-snippet td{padding:.15rem .5rem;vertical-align:top;white-space:pre-wrap;word-break:break-all}
.ib-err-snippet td.ln{width:3rem;text-align:right;padding-right:1rem;color:#4b5563;user-select:none;border-right:1px solid #374151}
.ib-err-snippet tr.ib-err-highlight{background:#450a0a}
.ib-err-snippet tr.ib-err-highlight td.ln{color:#fca5a5}
.ib-err-snippet tr.ib-err-highlight td.code{color:#fed7d7}
.ib-err-trace{font-family:\'SFMono-Regular\',Consolas,\'Liberation Mono\',monospace;font-size:.78rem;line-height:1.7;color:#94a3b8;white-space:pre-wrap;word-break:break-all;max-height:400px;overflow-y:auto;background:#0d1117;border-radius:6px;padding:1rem}
.ib-err-trace::-webkit-scrollbar{width:6px}.ib-err-trace::-webkit-scrollbar-track{background:#1a1f2e}.ib-err-trace::-webkit-scrollbar-thumb{background:#374151;border-radius:3px}
.ib-err-previous{background:#1e1b4b;border:1px solid #312e81;border-radius:6px;padding:1rem;margin-bottom:.75rem;font-size:.85rem}
.ib-err-actions{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:2rem}
.ib-err-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:6px;font-size:.85rem;font-weight:500;cursor:pointer;border:none;transition:opacity .15s;text-decoration:none}
.ib-err-btn--copy{background:#1d4ed8;color:#fff}.ib-err-btn--copy:hover{background:#1e40af}
.ib-err-btn--back{background:#374151;color:#e2e8f0}.ib-err-btn--back:hover{background:#4b5563}
.ib-err-btn--home{background:#065f46;color:#d1fae5}.ib-err-btn--home:hover{background:#047857}
.ib-err-toast{position:fixed;top:1.5rem;right:1.5rem;background:#065f46;color:#d1fae5;padding:.6rem 1.2rem;border-radius:8px;font-size:.85rem;font-weight:500;display:flex;align-items:center;gap:.4rem;opacity:0;transform:translateY(-10px);transition:opacity .25s,transform .25s;pointer-events:none;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.3)}
.ib-err-toast.ib-err-toast--visible{opacity:1;transform:translateY(0)}
.ib-err-toast.ib-err-toast--error{background:#7f1d1d;color:#fca5a5}
details>summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:.5rem;padding:.5rem 0}
details>summary::before{content:\'▶\';font-size:.7rem;transition:transform .2s;color:#6b7280}
details[open]>summary::before{transform:rotate(90deg)}
details>summary::-webkit-details-marker{display:none}
</style>
</head>
<body>
<div class="ib-err-container">

  <div class="ib-err-header">
    <div class="ib-err-icon">&#9888;</div>
    <div class="ib-err-title">
      <h1>Application Error</h1>
      <p>An unhandled exception occurred. Details below (debug mode is on).</p>
    </div>
  </div>

  <div class="ib-err-card">
    <h2>Exception</h2>
    <div>
      <span class="ib-err-badge ib-err-badge--class">' . $class . '</span>' . $codeHtml . '
    </div>
    <div class="ib-err-message" style="margin-top:.75rem">' . $message . '</div>
    <div class="ib-err-meta">
      <div class="ib-err-meta-item"><strong>File</strong>' . $file . '</div>
      <div class="ib-err-meta-item"><strong>Line</strong>' . $line . '</div>
    </div>
    ' . $snippetHtml . '
  </div>

  ' . $causedByCard . '

  <div class="ib-err-card">
    <details open>
      <summary><h2 style="display:inline;font-size:.875rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em">Stack Trace</h2></summary>
      <div class="ib-err-trace" id="ib-err-trace">' . $trace . '</div>
    </details>
  </div>

  <div class="ib-err-actions">
    <button class="ib-err-btn ib-err-btn--copy" id="ib-err-copy-btn">&#128203; Copy error</button>
    <button class="ib-err-btn ib-err-btn--back" onclick="history.back()">&#8592; Go back</button>
    <a href="/" class="ib-err-btn ib-err-btn--home">&#8962; Dashboard</a>
  </div>

</div>
<div class="ib-err-toast" id="ib-err-toast"></div>
<script>
(function() {
    var copyText = ' . $copyText . ';
    var toastEl = document.getElementById(\'ib-err-toast\');
    var toastTimer = null;

    function showToast(msg, isError) {
        clearTimeout(toastTimer);
        toastEl.textContent = msg;
        toastEl.className = \'ib-err-toast\' + (isError ? \' ib-err-toast--error\' : \'\');
        void toastEl.offsetWidth;
        toastEl.classList.add(\'ib-err-toast--visible\');
        toastTimer = setTimeout(function() {
            toastEl.classList.remove(\'ib-err-toast--visible\');
        }, 2500);
    }

    function fallbackCopy(text) {
        var ta = document.createElement(\'textarea\');
        ta.value = text;
        ta.style.position = \'fixed\';
        ta.style.opacity = \'0\';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand(\'copy\');
            showToast(\'\\u2713 Copied to clipboard\', false);
        } catch(e) {
            showToast(\'\\u2717 Failed to copy\', true);
        }
        document.body.removeChild(ta);
    }

    document.getElementById(\'ib-err-copy-btn\').addEventListener(\'click\', function() {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(copyText).then(function() {
                showToast(\'\\u2713 Copied to clipboard\', false);
            }).catch(function() {
                fallbackCopy(copyText);
            });
        } else {
            fallbackCopy(copyText);
        }
    });
}());
</script>
</body>
</html>';

        return $html;
    }

    /**
     * Render a code snippet around the error line.
     */
    private function renderCodeSnippet(string $file, int $errorLine, int $context = 7): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '';
        }

        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        $start = max(0, $errorLine - $context - 1);
        $end   = min(count($lines) - 1, $errorLine + $context - 1);

        $rows = '';
        for ($i = $start; $i <= $end; $i++) {
            $lineNum  = $i + 1;
            $isError  = $lineNum === $errorLine;
            $rowClass = $isError ? ' class="ib-err-highlight"' : '';
            $lineStr  = htmlspecialchars(rtrim((string) $lines[$i]), ENT_QUOTES, 'UTF-8');
            $rows .= "<tr{$rowClass}><td class=\"ln\">{$lineNum}</td><td class=\"code\">{$lineStr}</td></tr>";
        }

        $fileShort = htmlspecialchars(basename($file), ENT_QUOTES, 'UTF-8');

        return '<div class="ib-err-snippet">'
            . '<div class="ib-err-snippet-header">' . $fileShort . ' &mdash; line ' . $errorLine . '</div>'
            . '<table><tbody>' . $rows . '</tbody></table>'
            . '</div>';
    }

    // -------------------------------------------------------------------------
    // Production page
    // -------------------------------------------------------------------------

    private function renderProductionPage(): string
    {
        return '<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Error temporal</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.container{text-align:center;max-width:520px}
.logo{width:64px;height:64px;margin:0 auto 2rem;color:#0f172a}
.logo svg,.logo img{width:100%;height:100%;object-fit:contain}
.divider{width:48px;height:3px;background:#e2e8f0;border-radius:2px;margin:0 auto 2rem}
h1{font-size:1.5rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;letter-spacing:-.02em}
.subtitle{font-size:1rem;color:#64748b;line-height:1.7;margin-bottom:2.5rem}
.actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:3rem}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.5rem;border-radius:8px;font-size:.875rem;font-weight:500;text-decoration:none;transition:all .15s ease;cursor:pointer;border:none}
.btn-primary{background:#0f172a;color:#fff}.btn-primary:hover{background:#1e293b}
.btn-secondary{background:#e2e8f0;color:#374151}.btn-secondary:hover{background:#cbd5e1}
.footer{font-size:.8rem;color:#94a3b8;line-height:1.6}
.footer a{color:#64748b;text-decoration:none}.footer a:hover{text-decoration:underline}
.status{display:inline-flex;align-items:center;font-size:.8rem;color:#92400e;background:#fef3c7;padding:.35rem .85rem;border-radius:99px;margin-bottom:2rem}
.pulse{display:inline-block;width:8px;height:8px;background:#f59e0b;border-radius:50%;margin-right:.5rem;animation:pulse 2s ease-in-out infinite;vertical-align:middle}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.85)}}
</style>
</head>
<body>
<div class="container">
  <div class="logo">' . $this->brandLogoHtml() . '</div>
  <div class="divider"></div>
  <div class="status"><span class="pulse"></span> Problema detectat</div>
  <h1>Alguna cosa no ha anat be</h1>
  <p class="subtitle">Hem detectat un error inesperat.<br>El nostre equip ja hi esta treballant.<br>Si us plau, torneu a provar-ho d\'aqui uns minuts.</p>
  <div class="actions">
    <button class="btn btn-primary" onclick="location.reload()">Tornar a provar</button>
    <a href="/" class="btn btn-secondary">Anar al Dashboard</a>
  </div>
  <div class="footer">' . $this->brandFooterHtml() . '</div>
</div>
</body>
</html>';
    }

    // -------------------------------------------------------------------------
    // 404 page
    // -------------------------------------------------------------------------

    private function render404Page(): string
    {
        return '<!DOCTYPE html>
<html lang="ca">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Pagina no trobada</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;background:#f8fafc;color:#1e293b;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
.container{text-align:center;max-width:520px}
.logo{width:64px;height:64px;margin:0 auto 2rem;color:#0f172a}
.logo svg,.logo img{width:100%;height:100%;object-fit:contain}
.divider{width:48px;height:3px;background:#e2e8f0;border-radius:2px;margin:0 auto 2rem}
.code{font-size:6rem;font-weight:800;color:#e2e8f0;line-height:1;margin-bottom:.5rem;letter-spacing:-.05em}
h1{font-size:1.5rem;font-weight:700;color:#0f172a;margin-bottom:.75rem;letter-spacing:-.02em}
.subtitle{font-size:1rem;color:#64748b;line-height:1.7;margin-bottom:2.5rem}
.actions{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-bottom:3rem}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.5rem;border-radius:8px;font-size:.875rem;font-weight:500;text-decoration:none;transition:all .15s ease;cursor:pointer;border:none}
.btn-primary{background:#0f172a;color:#fff}.btn-primary:hover{background:#1e293b}
.btn-secondary{background:#e2e8f0;color:#374151}.btn-secondary:hover{background:#cbd5e1}
.footer{font-size:.8rem;color:#94a3b8;line-height:1.6}
.footer a{color:#64748b;text-decoration:none}.footer a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="container">
  <div class="logo">' . $this->brandLogoHtml() . '</div>
  <div class="divider"></div>
  <div class="code">404</div>
  <h1>Pagina no trobada</h1>
  <p class="subtitle">La pagina que busqueu no existeix o ha estat moguda.</p>
  <div class="actions">
    <button class="btn btn-secondary" onclick="history.back()">Tornar enrere</button>
    <a href="/" class="btn btn-primary">Anar al Dashboard</a>
  </div>
  <div class="footer">' . $this->brandFooterHtml() . '</div>
</div>
</body>
</html>';
    }

    // -------------------------------------------------------------------------
    // Marca per-tenant de les pàgines 404/producció
    // -------------------------------------------------------------------------

    /**
     * Marca de la pàgina: logo del tenant si en té (versió FOSCA, com demana
     * Branding — aquestes pàgines són clares), marca Intrabase per defecte.
     * Embolcallat en try/catch: l'ErrorHandler pot executar-se abans que el
     * tenant estigui resolt.
     */
    private function brandLogoHtml(): string
    {
        try {
            if (\Intrabase\Support\Branding::isCustom()) {
                $logo = htmlspecialchars(\Intrabase\Support\Branding::logo(), ENT_QUOTES, 'UTF-8');
                $name = htmlspecialchars(\Intrabase\Support\Branding::name(), ENT_QUOTES, 'UTF-8');
                return '<img src="' . $logo . '" alt="' . $name . '">';
            }
        } catch (\Throwable) {
            // Tenant no resolt: marca per defecte
        }
        return '<svg width="179" height="179" viewBox="0 0 179 179" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M154.326 0.648521C164.405-2.0522 174.766 3.92875 177.467 14.0079C180.168 24.0872 174.186 34.4478 164.106 37.1485C163.101 37.4178 162.094 37.5991 161.091 37.7003C136.669 42.2353 109.546 66.1687 108.255 87.7296C108.602 96.3619 102.958 104.392 94.2627 106.722C93.9511 106.805 93.6391 106.879 93.3272 106.946L93.2715 107.014C58.1333 114.793 47.2857 137.457 37.4375 163.573L37.3291 163.481C35.8255 170.097 30.799 175.681 23.7891 177.56C13.7098 180.26 3.34927 174.279 0.64847 164.199C-2.05228 154.12 3.92949 143.759 14.0088 141.059C14.1373 141.024 14.266 140.992 14.3946 140.96C32.8133 134.397 58.6653 126.931 70.6202 86.1348C71.545 78.7326 76.8329 72.2706 84.4825 70.2208C86.0477 69.8014 87.6199 69.5911 89.169 69.5733C110.391 67.603 136.242 35.685 140.376 17.4278C140.982 9.66789 146.38 2.77789 154.326 0.648521ZM154.689 141.059C164.769 138.358 175.129 144.34 177.83 154.419C180.531 164.498 174.549 174.859 164.47 177.56C154.391 180.26 144.03 174.279 141.329 164.199C138.628 154.12 144.61 143.759 154.689 141.059ZM154.689 70.2208C164.769 67.52 175.129 73.5018 177.83 83.5811C180.531 93.6605 174.549 104.021 164.47 106.722C164.074 106.828 163.678 106.919 163.282 106.998L163.318 107.044C137.863 110.815 113.69 133.765 107.685 163.79L107.536 163.637C105.764 169.795 100.904 174.9 94.2627 176.68C84.1836 179.38 73.8239 173.398 71.1231 163.319C68.4224 153.24 74.4033 142.88 84.4825 140.179C84.6047 140.146 84.7274 140.115 84.8496 140.085L84.8262 140.061C112.963 134.507 135.083 108.787 140.711 87.4132C141.154 79.4859 146.602 72.3877 154.689 70.2208ZM84.2139 0.648521C94.2931-2.0522 104.654 3.92873 107.355 14.0079C110.055 24.0872 104.074 34.4478 93.9942 37.1485C93.0013 37.4146 92.0055 37.5944 91.0147 37.6964C66.7546 41.8722 44.2864 63.2124 37.584 91.4835C37.4583 92.3134 37.2796 93.1307 37.0469 93.9298C37.0378 93.9751 37.0277 94.0202 37.0186 94.0655L37.0088 94.0548C35.1936 100.131 30.3636 105.151 23.7891 106.913C13.7098 109.614 3.34922 103.632 0.64847 93.5528C-2.05211 83.4736 3.92958 73.1139 14.0088 70.4132C14.0693 70.397 14.13 70.3819 14.1905 70.3663L14.1612 70.336C42.8946 64.6647 65.3523 37.9629 70.3799 16.335C71.3835 9.02787 76.6425 2.67733 84.2139 0.648521ZM14.2793 0.648521C24.3585-2.0522 34.7191 3.92877 37.42 14.0079C40.1207 24.0872 34.1389 34.4478 24.0596 37.1485C13.9804 39.8491 3.62067 33.8674 0.919955 23.7882C-1.78061 13.7091 4.20034 3.34943 14.2793 0.648521Z" fill="currentColor"/></svg>';
    }

    /**
     * Footer: the tenant's address (request host) when it has its own brand;
     * otherwise just "Built with Intrabase".
     */
    private function brandFooterHtml(): string
    {
        try {
            if (\Intrabase\Support\Branding::isCustom()) {
                $host = htmlspecialchars((string) ($_SERVER['HTTP_HOST'] ?? ''), ENT_QUOTES, 'UTF-8');
                if ($host !== '') {
                    return '<a href="https://' . $host . '">' . $host . '</a>'
                        . ' &middot; Built with <a href="https://github.com/opengea/intrabase-framework" target="_blank" rel="noopener">Intrabase</a>';
                }
            }
        } catch (\Throwable) {
            // Tenant not resolved: default footer
        }
        return 'Built with <a href="https://github.com/opengea/intrabase-framework" target="_blank" rel="noopener">Intrabase</a>';
    }
}