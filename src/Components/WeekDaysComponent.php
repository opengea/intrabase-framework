<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * WeekDaysComponent
 *
 * Week days selector rendered as 7 checkboxes (Mon-Sun).
 * Stores selected days as comma-separated numbers (1=Mon..7=Sun).
 *
 * options:
 *   format        — 'short' or 'long' (default: 'short')
 *   start_monday  — start week on Monday (default: true)
 */
final class WeekDaysComponent implements ComponentInterface
{
    private const DAYS_SHORT = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    private const DAYS_LONG  = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function render(string $field, mixed $value, array $options = []): string
    {
        $name   = Output::attr($field);
        $id     = 'field_' . Output::attr($field);
        $format = ($options['format'] ?? 'short') === 'long' ? 'long' : 'short';
        $days   = $format === 'long' ? self::DAYS_LONG : self::DAYS_SHORT;
        $ro     = !empty($options['readonly']) ? ' disabled' : '';

        // Parse selected days
        $selected = [];
        if (is_string($value) && $value !== '') {
            $selected = array_map('intval', array_filter(explode(',', $value)));
        }

        // Reorder if start_monday is false (start on Sunday)
        $order = [1, 2, 3, 4, 5, 6, 7];
        if (isset($options['start_monday']) && $options['start_monday'] === false) {
            $order = [7, 1, 2, 3, 4, 5, 6];
        }

        $val = Output::attr((string) $value);
        $html = "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\">";
        $html .= "<div class=\"ib-weekdays\" data-field=\"{$name}\">";

        foreach ($order as $dayNum) {
            $label   = $days[$dayNum];
            $checked = in_array($dayNum, $selected, true) ? ' checked' : '';
            $cbId    = "wd_{$name}_{$dayNum}";

            $onChange = "var h=document.getElementById('{$id}'),cbs=document.querySelectorAll('[data-field=\\'{$name}\\'] input[type=checkbox]:checked'),"
                      . "vals=[];cbs.forEach(function(c){vals.push(c.value)});h.value=vals.join(',')";

            $html .= "<label class=\"ib-weekdays__day\" for=\"{$cbId}\">"
                   . "<input type=\"checkbox\" id=\"{$cbId}\" value=\"{$dayNum}\""
                   . " class=\"ib-weekdays__checkbox\"{$checked}{$ro}"
                   . " onchange=\"{$onChange}\">"
                   . "<span class=\"ib-weekdays__label\">" . Output::e($label) . "</span>"
                   . "</label>";
        }

        $html .= '</div>';
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';

        $format = ($options['format'] ?? 'short') === 'long' ? 'long' : 'short';
        $days   = $format === 'long' ? self::DAYS_LONG : self::DAYS_SHORT;
        $nums   = array_map('intval', array_filter(explode(',', $val)));

        $labels = [];
        foreach ($nums as $num) {
            if (isset($days[$num])) {
                $labels[] = $days[$num];
            }
        }

        return Output::e(implode(', ', $labels));
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') return '';

        $nums = array_filter(
            array_map('intval', explode(',', $str)),
            fn(int $n): bool => $n >= 1 && $n <= 7
        );

        // Remove duplicates and sort
        $nums = array_unique($nums);
        sort($nums);

        return implode(',', $nums);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }

        if ($str !== '') {
            $nums = explode(',', $str);
            foreach ($nums as $n) {
                $i = (int) $n;
                if ($i < 1 || $i > 7) {
                    return ["{$label} contains invalid day values."];
                }
            }
        }

        return [];
    }
}
