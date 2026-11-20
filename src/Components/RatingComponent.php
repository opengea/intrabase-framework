<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * RatingComponent
 *
 * Star rating input (1–5 stars, or configurable min/max).
 * Renders styled radio buttons as star icons.
 *
 * options:
 *   min — minimum value (default: 1)
 *   max — maximum value (default: 5)
 */
final class RatingComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $current = (int) $value;
        $min     = (int) ($options['min'] ?? 1);
        $max     = (int) ($options['max'] ?? 5);
        $req     = !empty($options['required']) ? ' required' : '';
        $ro      = !empty($options['readonly']) ? ' disabled' : '';

        $html = "<div class=\"ib-rating\" role=\"group\" aria-label=\"Rating\">";

        for ($i = $min; $i <= $max; $i++) {
            $checked = ($i === $current) ? ' checked' : '';
            $id      = "rating_{$field}_{$i}";
            $html .= "<input type=\"radio\" name=\"{$name}\" id=\"{$id}\" value=\"{$i}\""
                   . " class=\"ib-rating__input sr-only\"{$checked}{$req}{$ro}>"
                   . "<label for=\"{$id}\" class=\"ib-rating__star\" title=\"{$i} star" . ($i > 1 ? 's' : '') . "\">"
                   . "<i class=\"fa-solid fa-star\" aria-hidden=\"true\"></i>"
                   . "</label>";
        }

        $html .= "</div>";
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current = (int) $value;
        $max     = (int) ($options['max'] ?? 5);
        if ($current === 0) return '<span class="ib-rating--empty">—</span>';

        $html = '<span class="ib-rating ib-rating--readonly">';
        for ($i = 1; $i <= $max; $i++) {
            $cls = $i <= $current ? 'ib-rating__star--filled' : 'ib-rating__star--empty';
            $html .= "<i class=\"fa-solid fa-star {$cls}\" aria-hidden=\"true\"></i>";
        }
        $html .= '</span>';
        return $html;
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // Allow searching by numeric value directly
        $val = (int) $searchValue;
        if ($val > 0) {
            return ['where' => "`{$field}` = ?", 'params' => [$val]];
        }
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $min = (int) ($options['min'] ?? 1);
        $max = (int) ($options['max'] ?? 5);
        $val = (int) $raw;
        return max($min, min($max, $val));
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;
        $val   = (int) $value;
        $min   = (int) ($options['min'] ?? 1);
        $max   = (int) ($options['max'] ?? 5);

        if ($required && $val === 0) {
            return ["{$label} is required."];
        }
        if ($val !== 0 && ($val < $min || $val > $max)) {
            return ["{$label} must be between {$min} and {$max}."];
        }
        return [];
    }
}
