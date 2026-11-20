<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * TextareaComponent
 *
 * Multiline text input field.
 * Renders a <textarea> element.
 * Displays truncated text in the dataBrowser.
 *
 * options:
 *   rows       — textarea rows (default: 4)
 *   maxlength  — maximum character length
 *   truncate   — display truncation length (default: 120)
 */
final class TextareaComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = Output::e((string) $value);
        $rows = (int) ($options['rows'] ?? 4);
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';
        $ph   = isset($options['placeholder']) ? ' placeholder="' . Output::attr($options['placeholder']) . '"' : '';
        $ml   = isset($options['maxlength']) ? ' maxlength="' . (int) $options['maxlength'] . '"' : '';

        return "<textarea name=\"{$name}\" id=\"{$id}\" rows=\"{$rows}\""
             . " class=\"ib-textarea\"{$ph}{$ml}{$req}{$ro}>{$val}</textarea>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $str = trim((string) $value);
        if ($str === '') return '';

        $limit = (int) ($options['truncate'] ?? 120);
        $text  = Output::e($str);

        if (mb_strlen($str) > $limit) {
            $text = Output::e(mb_substr($str, 0, $limit)) . '&hellip;';
        }

        return '<span class="ib-textarea-display">' . nl2br($text) . '</span>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
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
        if (isset($options['maxlength']) && $str !== '' && mb_strlen($str) > (int) $options['maxlength']) {
            return ["{$label} must be at most {$options['maxlength']} characters."];
        }
        return [];
    }
}
