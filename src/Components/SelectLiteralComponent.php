<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * SelectLiteralComponent
 *
 * Select dropdown where value equals label (no key/value distinction).
 *
 * options:
 *   values      — simple indexed array of string values
 *   placeholder — placeholder text for the empty option (default: __('select_ph'), «— Seleccioneu —»; també es tradueix si és una clau)
 *   required    — whether the field is required
 *   readonly    — whether the field is read-only
 */
final class SelectLiteralComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $current     = (string) $value;
        $values      = (array) ($options['values'] ?? []);
        $placeholder = __((string) ($options['placeholder'] ?? 'select_ph'));
        $name        = Output::attr($field);
        $id          = 'field_' . Output::attr($field);
        $req         = !empty($options['required']) ? ' required' : '';
        $ro          = !empty($options['readonly']) ? ' disabled' : '';

        $html = "<select name=\"{$name}\" id=\"{$id}\" class=\"ib-select\"{$req}{$ro}>";
        $html .= '<option value="">' . Output::e($placeholder) . '</option>';

        foreach ($values as $val) {
            $strVal   = (string) $val;
            $selected = ($strVal === $current) ? ' selected' : '';
            $safeVal  = Output::attr($strVal);
            $safeLbl  = Output::e($strVal);
            $html .= "<option value=\"{$safeVal}\"{$selected}>{$safeLbl}</option>";
        }

        $html .= '</select>';

        if (!empty($options['readonly'])) {
            $html .= "<input type=\"hidden\" name=\"{$name}\" value=\"" . Output::attr($current) . "\">";
        }

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $str = trim((string) $value);
        return $str === '' ? '' : Output::e($str);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null; // Default LIKE search works fine for literal values
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return trim((string) $raw);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }

        return [];
    }
}
