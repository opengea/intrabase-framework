<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * SliderComponent
 *
 * Range slider input with current value display.
 *
 * options:
 *   min  — Minimum value (default: 0)
 *   max  — Maximum value (default: 100)
 *   step — Step increment (default: 1)
 *   unit — Unit suffix for display (default: '')
 */
final class SliderComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $min  = (float) ($options['min'] ?? 0);
        $max  = (float) ($options['max'] ?? 100);
        $step = (float) ($options['step'] ?? 1);
        $unit = Output::e((string) ($options['unit'] ?? ''));
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' disabled' : '';

        // Clamp current value
        $val = $this->clamp($value, $min, $max);
        $safeVal = Output::attr((string) $val);

        $displayId = "slider_display_{$name}";

        return "<div class=\"ib-slider\">"
             . "<input type=\"range\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\""
             . " class=\"ib-slider__input\""
             . " min=\"{$min}\" max=\"{$max}\" step=\"{$step}\""
             . " oninput=\"document.getElementById('{$displayId}').textContent=this.value\""
             . "{$req}{$ro}>"
             . " <span class=\"ib-slider__value\" id=\"{$displayId}\">{$safeVal}</span>"
             . ($unit !== '' ? " <span class=\"ib-slider__unit\">{$unit}</span>" : '')
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $min  = (float) ($options['min'] ?? 0);
        $max  = (float) ($options['max'] ?? 100);
        $unit = Output::e((string) ($options['unit'] ?? ''));
        $val  = $this->clamp($value, $min, $max);

        $display = Output::e((string) $val);
        if ($unit !== '') {
            $display .= " {$unit}";
        }
        return "<span class=\"ib-slider__display\">{$display}</span>";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        if (is_numeric($searchValue)) {
            return ['where' => "`{$field}` = ?", 'params' => [(float) $searchValue]];
        }
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $min = (float) ($options['min'] ?? 0);
        $max = (float) ($options['max'] ?? 100);
        return $this->clamp($raw, $min, $max);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;
        $min   = (float) ($options['min'] ?? 0);
        $max   = (float) ($options['max'] ?? 100);

        if ($required && ($value === '' || $value === null)) {
            return ["{$label} is required."];
        }

        if (!is_numeric($value) && $value !== '' && $value !== null) {
            return ["{$label} must be a number."];
        }

        $num = (float) $value;
        if ($num < $min || $num > $max) {
            return ["{$label} must be between {$min} and {$max}."];
        }

        return [];
    }

    private function clamp(mixed $value, float $min, float $max): float
    {
        if (!is_numeric($value)) {
            return $min;
        }
        $num = (float) $value;
        return max($min, min($max, $num));
    }
}
