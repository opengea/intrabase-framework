<?php

declare(strict_types=1);

namespace Intrabase\Support;

/**
 * QR codes drawn in the browser by /assets/vendor/qrcode.min.js
 * (qrcode-generator by Kazuhiko Arase, MIT). The data never leaves the page:
 * no third-party image service (Google Charts used to get TOTP secrets and
 * field values in its URLs).
 */
final class Qr
{
    private static bool $libIncluded = false;

    public static function html(string $data, int $size = 200): string
    {
        $id   = 'ib-qr-' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $lib  = self::$libIncluded ? '' : '<script src="/assets/vendor/qrcode.min.js"></script>';
        self::$libIncluded = true;
        return '<div id="' . $id . '" class="ib-qr" role="img" aria-label="QR"'
             . ' style="width:' . $size . 'px;height:' . $size . 'px;background:#fff;padding:8px;box-sizing:content-box;display:inline-block"></div>'
             . $lib
             . '<script>(function(){if(typeof qrcode!=="function")return;var q=qrcode(0,"M");q.addData(' . $json . ');q.make();'
             . 'var el=document.getElementById("' . $id . '");el.innerHTML=q.createSvgTag({cellSize:4,margin:0});'
             . 'var svg=el.firstChild;if(svg){svg.setAttribute("width","100%");svg.setAttribute("height","100%");}})();</script>';
    }
}
