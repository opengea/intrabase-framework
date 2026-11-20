<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * JsonEditorComponent
 *
 * Renders a <textarea> for JSON data with:
 *   - Client-side syntax highlighting via data attribute
 *   - Server-side JSON validation
 *   - Pretty-print on save (optional via options['pretty'])
 */
final class JsonEditorComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name  = Output::attr($field);
        $id    = 'field_' . Output::attr($field);
        $req   = !empty($options['required']) ? ' required' : '';
        $ro    = !empty($options['readonly']) ? ' readonly' : '';
        $rows  = (int) ($options['rows'] ?? 8);

        // Pretty-print for display
        $displayValue = '';
        if ((string) $value !== '') {
            $decoded = json_decode((string) $value, true);
            $displayValue = $decoded !== null
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value;
        }

        $safe = htmlspecialchars($displayValue, ENT_QUOTES, 'UTF-8');

        return "<textarea name=\"{$name}\" id=\"{$id}\" rows=\"{$rows}\""
             . " class=\"ib-textarea ib-json-editor\" data-editor=\"json\""
             . " spellcheck=\"false\"{$req}{$ro}>{$safe}</textarea>"
             . "<small class=\"ib-form__help\">Valid JSON required</small>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $str = trim((string) $value);
        if ($str === '') return '';
        $decoded = json_decode($str, true);
        if ($decoded !== null) {
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return '<code class="ib-json-display">' . Output::e($pretty) . '</code>';
        }
        return '<code>' . Output::e($str) . '</code>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null; // Default LIKE search within the JSON string
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') {
            return '';
        }
        $decoded = json_decode($str, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return $str; // Keep raw string; validation will catch the error
        }
        // Normalize to compact JSON for DB storage
        $compact = !empty($options['pretty']);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($compact) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($decoded, $flags);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($str === '') {
            if ($required) {
                return ["{$label} is required."];
            }
            return [];
        }

        json_decode($str);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ["{$label}: " . json_last_error_msg()];
        }
        return [];
    }
}
