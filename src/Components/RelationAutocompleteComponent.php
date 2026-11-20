<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * RelationAutocompleteComponent
 *
 * Autocomplete input that stores a foreign key ID but displays a human-readable label.
 * Searches a related table via AJAX and lets the user pick a record.
 *
 * options:
 *   table         — DB table to search (required, e.g. 'ib_crm_clients')
 *   key           — Primary key column of the related table (default: 'id')
 *   column        — Column to search/display (required, e.g. 'name')
 *   display_value — Pre-resolved label for the current value (injected by DataEditor)
 *   display_field — Virtual field name in the record that holds the resolved label
 *   module        — Target module slug for link generation (optional)
 *   where         — Extra WHERE clause for search query (optional)
 *   limit         — Max suggestions (default: 10)
 *   placeholder   — Input placeholder text
 *   min_chars     — Minimum characters before searching (default: 2)
 */
final class RelationAutocompleteComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name   = Output::attr($field);
        $id     = 'field_' . Output::attr($field);
        $val    = Output::attr((string) $value);
        $req    = !empty($options['required']) ? ' required' : '';
        $ro     = !empty($options['readonly']) ? ' readonly' : '';
        $table  = Output::attr((string) ($options['table'] ?? ''));
        $key    = Output::attr((string) ($options['key'] ?? 'id'));
        $column = Output::attr((string) ($options['column'] ?? ''));
        $limit  = (int) ($options['limit'] ?? 10);
        $minChars = (int) ($options['min_chars'] ?? 2);
        $ph     = Output::attr((string) ($options['placeholder'] ?? 'Type to search...'));
        $where  = Output::attr((string) ($options['where'] ?? ''));

        // Resolve display label for current value
        $displayVal = '';
        if ((string) $value !== '') {
            $displayVal = (string) ($options['display_value'] ?? '');
        }
        $safeDisplay = Output::attr($displayVal);

        return "<div class=\"ib-relation-autocomplete\" data-ra-table=\"{$table}\" data-ra-key=\"{$key}\""
             . " data-ra-column=\"{$column}\" data-ra-limit=\"{$limit}\" data-ra-min-chars=\"{$minChars}\""
             . " data-ra-where=\"{$where}\">"
             . "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\"{$req}>"
             . "<input type=\"text\" id=\"{$id}__display\" value=\"{$safeDisplay}\""
             . " class=\"ib-input ib-relation-autocomplete__input\" placeholder=\"{$ph}\""
             . " autocomplete=\"off\" role=\"combobox\" aria-expanded=\"false\" aria-autocomplete=\"list\"{$ro}>"
             . "<span class=\"ib-relation-autocomplete__clear\" title=\"Clear\" style=\"display:" . ($val !== '' ? 'inline' : 'none') . "\">&times;</span>"
             . "<ul class=\"ib-relation-autocomplete__list\" role=\"listbox\" hidden></ul>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current = (string) $value;
        if ($current === '' || $current === '0') {
            return '';
        }

        // Use pre-resolved display value if available
        $displayVal = (string) ($options['display_value'] ?? '');

        // If not resolved, try to fetch from DB
        if ($displayVal === '') {
            $displayVal = $this->resolveLabel($value, $options);
        }

        if ($displayVal === '') {
            return Output::e($current);
        }

        $safe = Output::e($displayVal);

        // Link to related module if configured
        $module = (string) ($options['module'] ?? '');
        if ($module !== '') {
            $safeModule = Output::attr($module);
            $safeId     = Output::attr($current);
            return "<a href=\"?mod={$safeModule}&view=detail&id={$safeId}\" class=\"ib-xref-link\">{$safe}</a>";
        }

        return $safe;
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // Search by label in the related table — match IDs
        $table  = (string) ($options['table'] ?? '');
        $key    = (string) ($options['key'] ?? 'id');
        $column = (string) ($options['column'] ?? '');

        if ($table === '' || $column === '') {
            return null;
        }

        // Return a subquery filter: field IN (SELECT key FROM table WHERE column LIKE ?)
        return [
            'where'  => "`{$field}` IN (SELECT `{$key}` FROM `{$table}` WHERE `{$column}` LIKE ?)",
            'params' => ['%' . $searchValue . '%'],
        ];
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') {
            return null;
        }
        // Must be numeric (a foreign key ID)
        if (!ctype_digit($str)) {
            return null;
        }
        return (int) $str;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;
        $val   = trim((string) $value);

        if ($required && ($val === '' || $val === '0')) {
            return ["{$label} is required."];
        }

        return [];
    }

    /**
     * Resolve the display label for a given ID by querying the related table.
     */
    private function resolveLabel(mixed $id, array $options): string
    {
        $table  = (string) ($options['table'] ?? '');
        $key    = (string) ($options['key'] ?? 'id');
        $column = (string) ($options['column'] ?? '');

        if ($table === '' || $column === '' || (string) $id === '') {
            return '';
        }

        try {
            $db = \Intrabase\Core\Application::getInstance()->getDb();
            $sql = "SELECT `{$column}` FROM `{$table}` WHERE `{$key}` = :id LIMIT 1";
            $rows = $db->fetchAll($sql, ['id' => $id]);
            return (string) ($rows[0][$column] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
