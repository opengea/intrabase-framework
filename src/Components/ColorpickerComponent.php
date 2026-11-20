<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * ColorpickerComponent
 *
 * Advanced color picker with a predefined palette grid.
 * Renders clickable color swatches plus a native color picker fallback.
 *
 * options:
 *   palette — array of hex color strings (default: standard web colors)
 *   size    — swatch size in px (default: 28)
 *   columns — palette grid columns (default: 8)
 */
final class ColorpickerComponent implements ComponentInterface
{
    /** @var string[] */
    private const DEFAULT_PALETTE = [
        '#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#ffffff',
        '#e06666', '#f6b26b', '#ffd966', '#93c47d', '#76a5af', '#6fa8dc', '#8e7cc3', '#c27ba0',
        '#cc0000', '#e69138', '#f1c232', '#6aa84f', '#45818e', '#3d85c6', '#674ea7', '#a64d79',
        '#990000', '#b45f06', '#bf9000', '#38761d', '#134f5c', '#0b5394', '#351c75', '#741b47',
    ];

    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $val     = $this->normalizeHex((string) $value);
        $safe    = Output::attr($val ?: '#000000');
        $palette = $options['palette'] ?? self::DEFAULT_PALETTE;
        $size    = (int) ($options['size'] ?? 28);
        $cols    = (int) ($options['columns'] ?? 8);
        $req     = !empty($options['required']) ? ' required' : '';
        $ro      = !empty($options['readonly']) ? ' disabled' : '';

        $html = "<div class=\"ib-colorpicker\">"
              . "<div class=\"ib-colorpicker__palette\" style=\"grid-template-columns:repeat({$cols},1fr)\">";

        foreach ($palette as $color) {
            $c = Output::attr($color);
            $sel = (strtolower($color) === $val) ? ' ib-colorpicker__swatch--selected' : '';
            $html .= "<button type=\"button\" class=\"ib-colorpicker__swatch{$sel}\""
                   . " style=\"background-color:{$c};width:{$size}px;height:{$size}px\""
                   . " data-color=\"{$c}\""
                   . " onclick=\"document.getElementById('{$id}').value=this.dataset.color;"
                   . "document.getElementById('{$id}_picker').value=this.dataset.color;"
                   . "this.parentElement.querySelectorAll('.ib-colorpicker__swatch--selected')"
                   . ".forEach(function(el){el.classList.remove('ib-colorpicker__swatch--selected')});"
                   . "this.classList.add('ib-colorpicker__swatch--selected')\""
                   . " aria-label=\"{$c}\"></button>";
        }

        $html .= "</div>"
               . "<div class=\"ib-colorpicker__custom\">"
               . "<input type=\"color\" id=\"{$id}_picker\" value=\"{$safe}\""
               . " class=\"ib-color__picker\"{$ro}"
               . " oninput=\"document.getElementById('{$id}').value=this.value\">"
               . "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$safe}\"{$req}>"
               . "</div>"
               . "</div>";

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';
        $attr = Output::attr($val);
        return "<span class=\"ib-color-display\">"
             . "<span class=\"ib-color-display__circle\" style=\"background-color:{$attr}\"></span>"
             . "</span>";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $hex = $this->normalizeHex((string) $raw);
        return $hex !== '' ? $hex : '';
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }
        if ($str !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $str)) {
            return ["{$label} must be a valid hex color."];
        }
        return [];
    }

    private function normalizeHex(string $val): string
    {
        $val = trim($val);
        if ($val === '') return '';
        if ($val[0] !== '#') {
            $val = '#' . $val;
        }
        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $val, $m)) {
            $val = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
            return '';
        }
        return strtolower($val);
    }
}
