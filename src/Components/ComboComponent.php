<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * ComboComponent
 *
 * Dropdown combo field backed by static data or DB-sourced options.
 * The combo data is passed via options['combo_data'] (key => label map).
 *
 * options:
 *   combo_data  — associative array key => label
 *   placeholder — placeholder text for the empty option (default: __('select_ph'), «— Seleccioneu —»; també es tradueix si és una clau)
 *   required    — whether the field is required
 *   readonly    — whether the field is read-only
 */
final class ComboComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $current     = (string) $value;
        $data        = (array) ($options['combo_data'] ?? []);
        $placeholder = __((string) ($options['placeholder'] ?? 'select_ph'));
        $name        = Output::attr($field);
        $id          = 'field_' . Output::attr($field);
        $req         = !empty($options['required']) ? ' required' : '';
        $ro          = !empty($options['readonly']) ? ' disabled' : '';

        $html = "<select name=\"{$name}\" id=\"{$id}\" class=\"ib-select\"{$req}{$ro}>";
        $html .= '<option value="">' . Output::e($placeholder) . '</option>';

        foreach ($data as $key => $label) {
            $selected = ((string) $key === $current) ? ' selected' : '';
            $safeKey  = Output::attr((string) $key);
            $safeLbl  = Output::e((string) $label);
            $html .= "<option value=\"{$safeKey}\"{$selected}>{$safeLbl}</option>";
        }

        $html .= '</select>';

        if (!empty($options['readonly'])) {
            $html .= "<input type=\"hidden\" name=\"{$name}\" value=\"" . Output::attr($current) . "\">";
        }

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current = (string) $value;
        if ($current === '') {
            return '';
        }
        $data  = (array) ($options['combo_data'] ?? []);
        $label = $data[$current] ?? $current;
        return Output::e((string) $label);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        $data = (array) ($options['combo_data'] ?? []);
        if (empty($data)) {
            return null;
        }

        $searchLower  = mb_strtolower($searchValue, 'UTF-8');
        $matchingKeys = [];

        foreach ($data as $key => $label) {
            if (mb_strpos(mb_strtolower((string) $label, 'UTF-8'), $searchLower, 0, 'UTF-8') !== false) {
                $matchingKeys[] = (string) $key;
            }
        }

        if (!empty($matchingKeys)) {
            $placeholders = implode(',', array_fill(0, count($matchingKeys), '?'));
            return [
                'where'  => "`{$field}` IN ({$placeholders})",
                'params' => $matchingKeys,
            ];
        }

        // Also try direct LIKE on raw value
        return null;
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
