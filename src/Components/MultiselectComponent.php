<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * MultiselectComponent
 *
 * Multiple selection with a dual-list UI (available / selected).
 * Stores comma-separated values in a single DB column.
 *
 * options:
 *   values    — associative array key => label (required)
 *   delimiter — separator for stored values (default: ',')
 *   required  — whether the field is required
 *   readonly  — whether the field is read-only
 */
final class MultiselectComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $values    = (array) ($options['values'] ?? []);
        $delimiter = (string) ($options['delimiter'] ?? ',');
        $readonly  = !empty($options['readonly']);
        $req       = !empty($options['required']) ? ' required' : '';
        $name      = Output::attr($field);
        $id        = 'field_' . Output::attr($field);

        // Parse current selection
        $selected = [];
        if ((string) $value !== '') {
            $selected = array_map('trim', explode($delimiter, (string) $value));
        }

        $safeValue = Output::attr(implode($delimiter, $selected));
        $disabled  = $readonly ? ' disabled' : '';

        $html  = "<div class=\"ib-multiselect\" data-field=\"{$name}\" data-delimiter=\"" . Output::attr($delimiter) . "\">";
        $html .= "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeValue}\"{$req}>";

        // Search box
        $html .= '<div class="ib-multiselect__search">';
        $html .= "<input type=\"text\" class=\"ib-input ib-multiselect__filter\" placeholder=\"Search...\"{$disabled} autocomplete=\"off\">";
        $html .= '</div>';

        $html .= '<div class="ib-multiselect__panels">';

        // Available list
        $html .= '<div class="ib-multiselect__available">';
        $html .= '<div class="ib-multiselect__header">Available</div>';
        $html .= '<ul class="ib-multiselect__list">';
        foreach ($values as $key => $label) {
            if (in_array((string) $key, $selected, true)) {
                continue;
            }
            $safeKey = Output::attr((string) $key);
            $safeLbl = Output::e((string) $label);
            $html .= "<li data-value=\"{$safeKey}\">{$safeLbl}</li>";
        }
        $html .= '</ul></div>';

        // Controls
        $html .= '<div class="ib-multiselect__controls">';
        $html .= "<button type=\"button\" class=\"ib-btn ib-multiselect__add\" title=\"Add\"{$disabled}>&raquo;</button>";
        $html .= "<button type=\"button\" class=\"ib-btn ib-multiselect__remove\" title=\"Remove\"{$disabled}>&laquo;</button>";
        $html .= '</div>';

        // Selected list
        $html .= '<div class="ib-multiselect__selected">';
        $html .= '<div class="ib-multiselect__header">Selected</div>';
        $html .= '<ul class="ib-multiselect__list">';
        foreach ($selected as $key) {
            if (!isset($values[$key])) {
                continue;
            }
            $safeKey = Output::attr((string) $key);
            $safeLbl = Output::e((string) $values[$key]);
            $html .= "<li data-value=\"{$safeKey}\">{$safeLbl}</li>";
        }
        $html .= '</ul></div>';

        $html .= '</div>'; // panels
        $html .= '</div>'; // ib-multiselect

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $str = trim((string) $value);
        if ($str === '') {
            return '';
        }

        $values    = (array) ($options['values'] ?? []);
        $delimiter = (string) ($options['delimiter'] ?? ',');
        $keys      = array_map('trim', explode($delimiter, $str));
        $labels    = [];

        foreach ($keys as $key) {
            $labels[] = Output::e((string) ($values[$key] ?? $key));
        }

        return implode(', ', $labels);
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
            // Search in comma-separated field using FIND_IN_SET
            $conditions = [];
            $params     = [];
            foreach ($matchingKeys as $key) {
                $conditions[] = "FIND_IN_SET(?, `{$field}`)";
                $params[]     = $key;
            }
            return [
                'where'  => '(' . implode(' OR ', $conditions) . ')',
                'params' => $params,
            ];
        }

        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') {
            return '';
        }

        $values    = (array) ($options['values'] ?? []);
        $delimiter = (string) ($options['delimiter'] ?? ',');
        $keys      = array_map('trim', explode($delimiter, $str));

        // Whitelist against known keys
        $valid = [];
        foreach ($keys as $key) {
            if ($key !== '' && (empty($values) || array_key_exists($key, $values))) {
                $valid[] = $key;
            }
        }

        return implode($delimiter, $valid);
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
