<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * AutocompleteComponent
 *
 * Text input with live suggestions from a DB table.
 *
 * options:
 *   table    — DB table to query (required)
 *   column   — Column to search and suggest (required)
 *   where    — Optional extra WHERE clause (parameterized: :q = search term)
 *   limit    — Max suggestions (default: 10)
 *   placeholder — Input placeholder text
 *
 * The suggestions are fetched via a data-autocomplete-src attribute
 * that the intrabase.js script reads to perform the AJAX call.
 * Endpoint: GET /?_=autocomplete&field={field}&q={q}&table={t}&column={c}
 */
final class AutocompleteComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name   = Output::attr($field);
        $id     = 'field_' . Output::attr($field);
        $val    = Output::attr((string) $value);
        $req    = !empty($options['required']) ? ' required' : '';
        $ro     = !empty($options['readonly']) ? ' readonly' : '';
        $table  = Output::attr((string) ($options['table']  ?? ''));
        $column = Output::attr((string) ($options['column'] ?? ''));
        $limit  = (int) ($options['limit'] ?? 10);
        $ph     = Output::attr((string) ($options['placeholder'] ?? 'Type to search...'));

        // Build autocomplete src URL (handled by intrabase.js)
        $src = Output::attr("?_=autocomplete&table={$table}&column={$column}&limit={$limit}");

        return "<div class=\"ib-autocomplete\" data-autocomplete-src=\"{$src}\">"
             . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input\" placeholder=\"{$ph}\"{$req}{$ro}"
             . " autocomplete=\"off\" role=\"combobox\" aria-expanded=\"false\" aria-autocomplete=\"list\">"
             . "<ul class=\"ib-autocomplete__list\" role=\"listbox\" hidden></ul>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        return Output::e(trim((string) $value));
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null; // Default LIKE search on the stored text value
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
