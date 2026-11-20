<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * InputComponent
 *
 * Restricted text input field.
 * Only allows alphanumeric characters and underscores [a-zA-Z0-9_].
 * Suitable for identifiers, slugs, machine names, etc.
 *
 * options:
 *   maxlength   — maximum character length
 *   placeholder — input placeholder text
 */
final class InputComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = Output::attr((string) $value);
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';
        $ph   = isset($options['placeholder']) ? ' placeholder="' . Output::attr($options['placeholder']) . '"' : '';
        $ml   = isset($options['maxlength']) ? ' maxlength="' . (int) $options['maxlength'] . '"' : '';

        return "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--restricted\" pattern=\"[a-zA-Z0-9_]+\""
             . " title=\"Only letters, digits and underscores\"{$ph}{$ml}{$req}{$ro}>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';
        return '<code class="ib-input-display">' . Output::e($val) . '</code>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        return preg_replace('/[^a-zA-Z0-9_]/', '', $str) ?? '';
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }
        if ($str !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $str)) {
            return ["{$label} must contain only letters, digits and underscores."];
        }
        if (isset($options['maxlength']) && $str !== '' && mb_strlen($str) > (int) $options['maxlength']) {
            return ["{$label} must be at most {$options['maxlength']} characters."];
        }
        return [];
    }
}
