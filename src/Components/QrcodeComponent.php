<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * QrcodeComponent
 *
 * QR code display using Google Charts API.
 *
 * options:
 *   size — QR code image size in pixels (default: 150)
 */
final class QrcodeComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = Output::attr(trim((string) $value));
        $size = (int) ($options['size'] ?? 150);
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';

        $html = "<div class=\"ib-qrcode\">"
              . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
              . " class=\"ib-input\" placeholder=\"Data to encode\"{$req}{$ro}>";

        // Preview QR if value exists
        if (trim((string) $value) !== '') {
            $encoded = urlencode(trim((string) $value));
            $html .= "<div class=\"ib-qrcode__preview\">"
                   . "<img src=\"https://chart.googleapis.com/chart?chs={$size}x{$size}&amp;cht=qr&amp;chl={$encoded}\""
                   . " alt=\"QR Code\" width=\"{$size}\" height=\"{$size}\">"
                   . "</div>";
        }

        $html .= "</div>";
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $data = trim((string) $value);
        if ($data === '') {
            return '<span class="ib-qrcode--empty">--</span>';
        }

        $size    = (int) ($options['size'] ?? 150);
        $encoded = urlencode($data);

        return "<img src=\"https://chart.googleapis.com/chart?chs={$size}x{$size}&amp;cht=qr&amp;chl={$encoded}\""
             . " alt=\"QR Code\" class=\"ib-qrcode__img\" width=\"{$size}\" height=\"{$size}\">";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return trim((string) $raw);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;
        if ($required && trim((string) $value) === '') {
            return ["{$label} is required."];
        }
        return [];
    }
}
