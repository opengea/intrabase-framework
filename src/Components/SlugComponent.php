<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * SlugComponent
 *
 * Text input that auto-generates a URL slug from another field.
 *
 * options:
 *   source_field — The field whose value to slugify (default: 'name' or 'title')
 *   editable     — Whether the user can manually edit the slug (default: true)
 */
final class SlugComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $val     = Output::attr((string) $value);
        $req     = !empty($options['required']) ? ' required' : '';
        $ro      = !empty($options['readonly']) ? ' readonly' : '';
        $source  = Output::attr((string) ($options['source_field'] ?? 'name'));

        return "<div class=\"ib-slug-wrapper\">"
             . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--slug\" pattern=\"[a-z0-9-]+\""
             . " data-slug-source=\"field_{$source}\""
             . " placeholder=\"auto-generated-slug\"{$req}{$ro}>"
             . "<small class=\"ib-form__help\">Lowercase letters, digits and hyphens only.</small>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';
        return '<code class="ib-slug">' . Output::e($val) . '</code>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null; // Default LIKE search on slug text
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim(strtolower((string) $raw));
        // Basic slug normalization
        $str = preg_replace('/[^a-z0-9\-_]/', '-', $str) ?? $str;
        $str = preg_replace('/-{2,}/', '-', $str) ?? $str;
        return trim($str, '-');
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }
        if ($str !== '' && !preg_match('/^[a-z0-9\-_]+$/', $str)) {
            return ["{$label} must contain only lowercase letters, digits, hyphens and underscores."];
        }
        return [];
    }
}
