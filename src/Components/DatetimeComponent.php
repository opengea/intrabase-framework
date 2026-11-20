<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * DatetimeComponent
 *
 * Date and time input field.
 * Renders separate date and time inputs with a hidden combined field.
 * Stores as yyyy-mm-dd HH:ii[:ss] in the database.
 * Displays as dd/mm/yyyy HH:ii.
 *
 * options:
 *   show_seconds  — show seconds in time input (default: false)
 *   format_output — display format (default: 'd/m/Y H:i')
 *   show_time     — show time input (default: true)
 */
class DatetimeComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name      = Output::attr($field);
        $id        = 'field_' . Output::attr($field);
        $req       = !empty($options['required']) ? ' required' : '';
        $ro        = !empty($options['readonly']) ? ' readonly' : '';
        $showTime  = ($options['show_time'] ?? true) !== false;
        $showSec   = !empty($options['show_seconds']);
        $timeStep  = $showSec ? '1' : '60';

        // Parse existing value (yyyy-mm-dd HH:ii:ss)
        $dateVal = '';
        $timeVal = '';
        $strVal  = trim((string) $value);
        if ($strVal !== '' && $strVal !== '0000-00-00' && $strVal !== '0000-00-00 00:00:00') {
            $ts = strtotime($strVal);
            if ($ts !== false) {
                $dateVal = date('Y-m-d', $ts);
                $timeVal = $showSec ? date('H:i:s', $ts) : date('H:i', $ts);
            }
        }

        $safeDateVal = Output::attr($dateVal);
        $safeTimeVal = Output::attr($timeVal);

        $html = "<div class=\"ib-datetime\">"
              . "<input type=\"date\" id=\"{$id}_date\" value=\"{$safeDateVal}\""
              . " class=\"ib-input ib-input--date\"{$ro}"
              . " oninput=\"document.getElementById('{$id}').value="
              . "this.value+' '+(document.getElementById('{$id}_time')?.value||'00:00')\">";

        if ($showTime) {
            $html .= "<input type=\"time\" id=\"{$id}_time\" value=\"{$safeTimeVal}\""
                   . " class=\"ib-input ib-input--time\" step=\"{$timeStep}\"{$ro}"
                   . " oninput=\"document.getElementById('{$id}').value="
                   . "(document.getElementById('{$id}_date')?.value||'')+' '+this.value\">";
        }

        $combined = $dateVal;
        if ($showTime && $timeVal !== '') {
            $combined .= ' ' . $timeVal;
        }
        $safeCombined = Output::attr($combined);

        $html .= "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeCombined}\"{$req}>"
               . "</div>";

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $str = trim((string) $value);
        if ($str === '' || $str === '0000-00-00' || $str === '0000-00-00 00:00:00') return '';

        $ts = strtotime($str);
        if ($ts === false) return Output::e($str);

        $format   = (string) ($options['format_output'] ?? 'd/m/Y H:i');
        $showTime = ($options['show_time'] ?? true) !== false;
        if (!$showTime) {
            $format = 'd/m/Y';
        }

        return '<span class="ib-datetime-display">' . Output::e(date($format, $ts)) . '</span>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // Try to parse dd/mm/yyyy input to yyyy-mm-dd for search
        $parsed = $this->parseDateInput($searchValue);
        if ($parsed !== null) {
            return ['where' => "`{$field}` LIKE ?", 'params' => [$parsed . '%']];
        }
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') return '';

        // Try parsing dd/mm/yyyy [HH:ii[:ss]] format
        $parsed = $this->parseDateInput($str);
        if ($parsed !== null) {
            return $parsed;
        }

        // Try native parsing (yyyy-mm-dd HH:ii:ss)
        $ts = strtotime($str);
        if ($ts !== false) {
            $showSec = !empty($options['show_seconds']);
            $format  = $showSec ? 'Y-m-d H:i:s' : 'Y-m-d H:i';
            return date($format, $ts);
        }

        return '';
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }
        if ($str !== '' && strtotime($str) === false) {
            return ["{$label} is not a valid date/time."];
        }
        return [];
    }

    /**
     * Parse dd/mm/yyyy [HH:ii[:ss]] to yyyy-mm-dd [HH:ii[:ss]].
     */
    private function parseDateInput(string $input): ?string
    {
        $input = trim($input);
        // Match dd/mm/yyyy optionally with time
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{2}:\d{2}(?::\d{2})?))?$#', $input, $m)) {
            $day   = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $year  = $m[3];
            if (!checkdate((int) $month, (int) $day, (int) $year)) {
                return null;
            }
            $date = "{$year}-{$month}-{$day}";
            if (!empty($m[4])) {
                $date .= ' ' . $m[4];
            }
            return $date;
        }
        return null;
    }
}
