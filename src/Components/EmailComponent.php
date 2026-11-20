<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * EmailComponent
 *
 * Email input with validation indicator and clickable display.
 *
 * options:
 *   show_icon     — show envelope icon (default: true)
 *   copy_on_click — copy to clipboard on click (default: true)
 */
final class EmailComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = Output::attr((string) $value);
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';
        $ph   = Output::attr($options['placeholder'] ?? '');
        $phAttr = $ph !== '' ? " placeholder=\"{$ph}\"" : ' placeholder="email@example.com"';

        return "<div class=\"ib-input-group\">"
             . "<span class=\"ib-input-group__prefix\"><i class=\"fa-solid fa-envelope\" aria-hidden=\"true\"></i></span>"
             . "<input type=\"email\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--email\"{$phAttr}{$req}{$ro}"
             . " oninput=\"this.classList.toggle('ib-input--valid',this.validity.valid&&this.value!=='');"
             . "this.classList.toggle('ib-input--invalid',!this.validity.valid&&this.value!=='')\">"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';

        $safe     = Output::e($val);
        $href     = Output::attr($val);
        $showIcon = ($options['show_icon'] ?? true);
        $copyAttr = ($options['copy_on_click'] ?? true)
            ? " onclick=\"navigator.clipboard.writeText('{$href}');return false;\" title=\"Click to copy\" style=\"cursor:pointer\""
            : '';

        $icon = $showIcon ? '<i class="fa-solid fa-envelope" aria-hidden="true"></i> ' : '';

        return "<a href=\"mailto:{$href}\" class=\"ib-email-link\"{$copyAttr}>{$icon}{$safe}</a>";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return strtolower(trim((string) $raw));
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }

        if ($str !== '' && filter_var($str, FILTER_VALIDATE_EMAIL) === false) {
            return ["{$label} must be a valid email address."];
        }

        return [];
    }
}
