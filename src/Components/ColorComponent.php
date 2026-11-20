<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * ColorComponent
 *
 * Color picker field.
 * Renders a native color input alongside a text input for hex code entry.
 * Displays a colored circle with the hex code.
 */
final class ColorComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = $this->normalizeHex((string) $value);
        $safe = Output::attr($val ?: '#000000');
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';
        $roColor = !empty($options['readonly']) ? ' disabled' : '';

        return "<div class=\"ib-color\">"
             . "<input type=\"color\" id=\"{$id}_picker\" value=\"{$safe}\""
             . " class=\"ib-color__picker\"{$roColor}"
             . " oninput=\"document.getElementById('{$id}').value=this.value\">"
             . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$safe}\""
             . " class=\"ib-input ib-input--color\" pattern=\"#[0-9a-fA-F]{6}\""
             . " maxlength=\"7\" placeholder=\"#000000\"{$req}{$ro}"
             . " oninput=\"document.getElementById('{$id}_picker').value=this.value\">"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';
        $safe = Output::e($val);
        $attr = Output::attr($val);
        return "<span class=\"ib-color-display\">"
             . "<span class=\"ib-color-display__circle\" style=\"background-color:{$attr}\"></span>"
             . " <code>{$safe}</code>"
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
            return ["{$label} must be a valid hex color (e.g. #FF5500)."];
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
        // Expand 3-char shorthand: #ABC -> #AABBCC
        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $val, $m)) {
            $val = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
            return '';
        }
        return strtolower($val);
    }
}
