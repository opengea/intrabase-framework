<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * MoneyComponent
 *
 * Currency input field with configurable currency symbol and decimals.
 * Renders a number input with step precision and a currency suffix.
 *
 * options:
 *   currency — currency symbol (default: '€')
 *   decimals — decimal places (default: 2)
 *   min      — minimum value
 *   max      — maximum value
 */
final class MoneyComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name     = Output::attr($field);
        $id       = 'field_' . Output::attr($field);
        $decimals = (int) ($options['decimals'] ?? 2);
        $step     = $decimals > 0 ? '0.' . str_repeat('0', $decimals - 1) . '1' : '1';
        $currency = Output::e((string) ($options['currency'] ?? '€'));
        $req      = !empty($options['required']) ? ' required' : '';
        $ro       = !empty($options['readonly']) ? ' readonly' : '';

        $val = '';
        if ($value !== null && $value !== '') {
            $val = Output::attr(number_format((float) $value, $decimals, '.', ''));
        }

        $minAttr = isset($options['min']) ? ' min="' . (float) $options['min'] . '"' : '';
        $maxAttr = isset($options['max']) ? ' max="' . (float) $options['max'] . '"' : '';

        return "<div class=\"ib-input-group\">"
             . "<input type=\"number\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--money\" step=\"{$step}\"{$minAttr}{$maxAttr}{$req}{$ro}>"
             . "<span class=\"ib-input-group__suffix\">{$currency}</span>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        if ($value === null || $value === '') return '';
        $decimals = (int) ($options['decimals'] ?? 2);
        $currency = Output::e((string) ($options['currency'] ?? '€'));
        $formatted = number_format((float) $value, $decimals, ',', '.');
        // Negatius en vermell, com el 'money' de reserva de DataBrowser
        $negative  = round((float) $value, $decimals) < 0 ? ' ib-cell--negative' : '';
        return '<span class="ib-money-display' . $negative . '">' . Output::e($formatted) . ' ' . $currency . '</span>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // Allow numeric search
        $val = str_replace(',', '.', trim($searchValue));
        if (is_numeric($val)) {
            return ['where' => "`{$field}` = ?", 'params' => [(float) $val]];
        }
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') return '';
        // Accept comma as decimal separator
        $str = str_replace(',', '.', $str);
        // Remove any non-numeric chars except dot and minus
        $str = preg_replace('/[^\d.\-]/', '', $str) ?? '';
        return (float) $str;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;

        if ($required && ($value === '' || $value === null)) {
            return ["{$label} is required."];
        }
        if ($value !== '' && $value !== null && !is_numeric($value)) {
            return ["{$label} must be a valid number."];
        }
        if (isset($options['min']) && $value !== '' && $value !== null && (float) $value < (float) $options['min']) {
            return ["{$label} must be at least {$options['min']}."];
        }
        if (isset($options['max']) && $value !== '' && $value !== null && (float) $value > (float) $options['max']) {
            return ["{$label} must be at most {$options['max']}."];
        }
        return [];
    }
}
