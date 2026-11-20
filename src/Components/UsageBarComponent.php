<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * UsageBarComponent
 *
 * Renders a progress bar showing usage vs capacity (e.g. disk space, transfer).
 * Ported from KMS status_icon scripts (isp_hostings_space_usage, etc.).
 *
 * In browse/display mode: colored progress bar with percentage and label.
 * In edit mode: readonly integer input.
 *
 * Options:
 *   'max_field' => string  — Name of the column containing the max/capacity value
 *                             (read from the same row). Required for bar display.
 *   'unit'      => string  — 'bytes' (default) or 'count'. Controls label formatting.
 *   'label'     => string  — Human label for the field (optional).
 */
final class UsageBarComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        // Edit mode: show a readonly input with formatted value
        $name   = Output::attr($field);
        $safeVal = Output::attr((string) $value);
        $unit   = (string)($options['unit'] ?? 'bytes');
        $display = $unit === 'bytes' ? self::formatBytes((int) $value) : (string) $value;
        return '<input type="text" name="' . $name . '" id="field_' . $name
            . '" class="ib-input" value="' . $safeVal . '" readonly>'
            . '<small class="ib-form-hint">' . Output::e($display) . '</small>';
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $used    = (int) $value;
        $maxField = (string) ($options['max_field'] ?? '');
        $max     = (int) ($options['max_value'] ?? 0);
        $unit    = (string) ($options['unit'] ?? 'bytes');

        // If max is 0 and we have no way to know the capacity, just show formatted value
        if ($max <= 0 && $used <= 0) {
            return '';
        }

        // Calculate percentage
        if ($max > 0) {
            $pct = (int) round(($used * 100) / $max);
        } else {
            $pct = 0;
        }

        $displayPct = min($pct, 100);
        $barWidth   = $displayPct > 0 ? max($displayPct, 3) : 0;

        // Color thresholds (fill bar + track tint)
        if ($pct >= 90) {
            $color = '#ef4444';
            $colorClass = 'danger';
        } elseif ($pct >= 70) {
            $color = '#f59e0b';
            $colorClass = 'warning';
        } else {
            $color = '#10b981';
            $colorClass = 'success';
        }

        // Format labels
        if ($unit === 'bytes') {
            $usedLabel = self::formatBytes($used);
            $maxLabel  = $max > 0 ? self::formatBytes($max) : 'Unlimited';
            $totalText = $max > 0 ? ($usedLabel . ' / ' . $maxLabel) : $usedLabel;
        } else {
            $totalText = $max > 0 ? ($used . ' / ' . $max) : (string) $used;
        }

        $tooltip = Output::attr($totalText . ($max > 0 ? " ({$pct}%)" : ''));

        $html = '<div class="ib-usage-bar ib-usage-bar--' . $colorClass . '" title="' . $tooltip . '">';
        $html .= '<div class="ib-usage-bar__track">';
        if ($barWidth > 0) {
            $html .= '<div class="ib-usage-bar__fill" style="width:' . $barWidth . '%;background:' . $color . '"></div>';
        }
        $html .= '</div>';
        $html .= '<span class="ib-usage-bar__pct">' . ($max > 0 ? $pct . '%' : '') . '</span>';
        $html .= '<span class="ib-usage-bar__label">' . Output::e($totalText) . '</span>';
        $html .= '</div>';

        return $html;
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        if (!is_numeric($searchValue)) {
            return null;
        }
        return [
            'where'  => "`{$field}` >= ?",
            'params' => [(int) $searchValue],
        ];
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return (int) $raw;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        return [];
    }

    /**
     * Format bytes to human-readable string.
     */
    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $val = (float) $bytes;
        while ($val >= 1024 && $i < count($units) - 1) {
            $val /= 1024;
            $i++;
        }
        return round($val, $i > 1 ? 1 : 0) . ' ' . $units[$i];
    }
}
