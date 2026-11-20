<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * ReadonlyFieldComponent
 *
 * Displays a field value as plain text (non-editable).
 * Useful for computed/auto-generated fields shown in the form.
 * Renders a <span> + hidden input so the value is still submitted.
 */
final class ReadonlyFieldComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $display = Output::e((string) $value);
        $raw     = Output::attr((string) $value);
        $cls     = Output::attr($options['class'] ?? 'ib-readonly-value');

        return "<span class=\"{$cls}\" id=\"{$id}\">{$display}</span>"
             . "<input type=\"hidden\" name=\"{$name}\" value=\"{$raw}\">";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        return Output::e((string) $value);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        // Pass through — the value comes from the hidden input
        return $raw;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        return []; // Read-only fields are never user-validated
    }
}
