<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * StatusBadgeComponent
 *
 * Renders a colored status badge. Equivalent to KMS status_icon component.
 *
 * In edit mode: a <select> dropdown
 * In readonly mode: a styled <span> badge
 *
 * options['values'] — array of value => label (the options)
 * options['classes'] — array of value => CSS class suffix (e.g. 'active' => 'success')
 *                       If omitted, uses the value itself as the class suffix.
 *
 * CSS classes added: ib-badge ib-badge--{class}
 * Default class suffixes for common status values are applied automatically.
 */
final class StatusBadgeComponent implements ComponentInterface
{
    /** Default CSS class mapping for common statuses */
    private const DEFAULT_CLASS_MAP = [
        'active'      => 'success',
        'enabled'     => 'success',
        'published'   => 'success',
        'confirmed'   => 'success',
        'completed'   => 'success',
        'approved'    => 'success',
        'paid'        => 'success',
        'won'         => 'success',
        'live'        => 'success',
        'done'        => 'success',
        'resolved'    => 'success',
        'online'      => 'success',

        'inactive'    => 'secondary',
        'disabled'    => 'secondary',
        'archived'    => 'secondary',
        'closed'      => 'secondary',
        'cancelled'   => 'secondary',
        'expired'     => 'secondary',
        'ended'       => 'secondary',
        'lost'        => 'secondary',
        'rejected'    => 'secondary',

        'draft'       => 'info',
        'pending'     => 'warning',
        'in_progress' => 'primary',
        'scheduled'   => 'primary',
        'sent'        => 'info',
        'processing'  => 'info',
        'overdue'     => 'danger',
        'error'       => 'danger',
        'failed'      => 'danger',
        'critical'    => 'danger',
    ];

    public function render(string $field, mixed $value, array $options = []): string
    {
        $current  = (string) $value;
        $values   = (array) ($options['values'] ?? []);
        $classes  = (array) ($options['classes'] ?? []);
        $readonly = !empty($options['readonly']);

        // In read-only mode, render as a badge span
        if ($readonly) {
            $label    = $values[$current] ?? $current;
            $cls      = $classes[$current] ?? self::DEFAULT_CLASS_MAP[$current] ?? 'secondary';
            $safeLbl  = Output::e($label);
            return "<span class=\"ib-badge ib-badge--{$cls}\">{$safeLbl}</span>";
        }

        // In edit mode, render as a styled <select>
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $req     = !empty($options['required']) ? ' required' : '';

        $html = "<select name=\"{$name}\" id=\"{$id}\" class=\"ib-select ib-select--status\"{$req}>";
        $html .= '<option value="">' . __("select_default") . '</option>';

        foreach ($values as $val => $label) {
            $selected = ($val === $current) ? ' selected' : '';
            $cls      = $classes[$val] ?? self::DEFAULT_CLASS_MAP[$val] ?? 'secondary';
            $safeLbl  = Output::e((string) $label);
            $safeVal  = Output::attr((string) $val);
            $html .= "<option value=\"{$safeVal}\" data-badge-class=\"{$cls}\"{$selected}>{$safeLbl}</option>";
        }

        $html .= '</select>';
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current = (string) $value;
        if ($current === '') return '';
        $values  = (array) ($options['values'] ?? []);
        $classes = (array) ($options['classes'] ?? []);
        $label   = $values[$current] ?? $current;
        $cls     = $classes[$current] ?? self::DEFAULT_CLASS_MAP[$current] ?? 'secondary';
        return "<span class=\"ib-badge ib-badge--{$cls}\">" . Output::e($label) . "</span>";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        $values = (array) ($options['values'] ?? []);
        if (empty($values)) return null;

        // Search by visible label text, resolve to actual DB keys
        $searchLower = mb_strtolower($searchValue, 'UTF-8');
        $matchingKeys = [];
        foreach ($values as $key => $label) {
            $cleanLabel = mb_strtolower(strip_tags((string) $label), 'UTF-8');
            if (mb_strpos($cleanLabel, $searchLower, 0, 'UTF-8') !== false) {
                $matchingKeys[] = $key;
            }
        }

        if (!empty($matchingKeys)) {
            $placeholders = implode(',', array_fill(0, count($matchingKeys), '?'));
            return [
                'where'  => "`{$field}` IN ({$placeholders})",
                'params' => $matchingKeys,
            ];
        }

        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        // Optional: whitelist against known values
        $values = array_keys((array) ($options['values'] ?? []));
        if (!empty($values) && !in_array($str, $values, true)) {
            return '';
        }
        return $str;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }
        return [];
    }
}
