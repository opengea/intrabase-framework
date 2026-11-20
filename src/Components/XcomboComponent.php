<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * XcomboComponent
 *
 * Cross-reference combo: select from another table's pre-loaded values.
 * The module is responsible for loading the values array from the
 * referenced table; this component simply renders them as a select dropdown.
 *
 * options:
 *   values      — associative array key => label (pre-loaded by module)
 *   placeholder — placeholder text (default: __('select_ph'), «— Seleccioneu —»; també es tradueix si és una clau)
 *   allow_empty — whether empty selection is allowed (default: true)
 *   required    — whether the field is required
 *   readonly    — whether the field is read-only
 */
final class XcomboComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $current     = (string) $value;
        $values      = (array) ($options['values'] ?? []);
        $placeholder = __((string) ($options['placeholder'] ?? 'select_ph'));
        $allowEmpty  = $options['allow_empty'] ?? true;
        $name        = Output::attr($field);
        $id          = 'field_' . Output::attr($field);
        $req         = !empty($options['required']) ? ' required' : '';
        $ro          = !empty($options['readonly']) ? ' disabled' : '';

        $html = "<select name=\"{$name}\" id=\"{$id}\" class=\"ib-select ib-select--xcombo\"{$req}{$ro}>";

        if ($allowEmpty) {
            $html .= '<option value="">' . Output::e($placeholder) . '</option>';
        }

        foreach ($values as $key => $label) {
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
        $values = (array) ($options['values'] ?? []);
        $label  = $values[$current] ?? $current;
        return Output::e((string) $label);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        $values = (array) ($options['values'] ?? []);
        if (empty($values)) {
            return null;
        }

        $searchLower  = mb_strtolower($searchValue, 'UTF-8');
        $matchingKeys = [];

        foreach ($values as $key => $label) {
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

        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str    = trim((string) $raw);
        $values = (array) ($options['values'] ?? []);

        if ($str === '') {
            return '';
        }

        if (!empty($values) && !array_key_exists($str, $values)) {
            return '';
        }

        return $str;
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
