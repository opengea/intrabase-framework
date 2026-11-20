<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * PhoneComponent
 *
 * Renders <input type="tel"> with a phone icon.
 * Sanitizes to digits, spaces, +, -, (, ) only.
 * Validates basic non-empty check when required.
 */
final class PhoneComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $val     = Output::attr((string) $value);
        $req     = !empty($options['required']) ? ' required' : '';
        $ro      = !empty($options['readonly']) ? ' readonly' : '';
        $ph      = Output::attr($options['placeholder'] ?? '');
        $phAttr  = $ph !== '' ? " placeholder=\"{$ph}\"" : ' placeholder="+34 600 000 000"';

        return "<div class=\"ib-input-group\">"
             . "<span class=\"ib-input-group__prefix\"><i class=\"fa-solid fa-phone\" aria-hidden=\"true\"></i></span>"
             . "<input type=\"tel\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--phone\"{$phAttr}{$req}{$ro}>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';
        $safe = Output::e($val);
        return "<a href=\"tel:{$safe}\" class=\"ib-phone-link\"><i class=\"fa-solid fa-phone\" aria-hidden=\"true\"></i> {$safe}</a>";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null; // Default LIKE search works fine for phone numbers
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        // Keep digits, +, -, (, ), spaces, extensions x/ext
        return preg_replace('/[^\d\s\+\-\(\)xXextEXT\.\/]/', '', $str);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str = trim((string) $value);
        if ($required && $str === '') {
            $label = $options['label'] ?? $field;
            return ["{$label} is required."];
        }
        return [];
    }
}
