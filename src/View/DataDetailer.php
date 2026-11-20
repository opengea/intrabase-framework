<?php

declare(strict_types=1);

namespace Intrabase\View;

use Intrabase\Components\ComponentRegistry;
use Intrabase\Core\Module;
use Intrabase\Core\Router;
use Intrabase\Security\Input;
use Intrabase\Security\Output;

/**
 * DataDetailer — Read-only Record Detail View
 *
 * Renders a single record in a structured read-only card layout.
 * Useful for preview/detail pages before entering edit mode.
 *
 * Field resolution order for the detail view:
 *   1. $module->detailFields   if non-empty → use these exclusively
 *   2. $module->showFields     if non-empty → use these
 *   3. $module->fields         fallback
 *
 * @package Intrabase\View
 */
class DataDetailer
{
    public function render(Module $module, int $id): void
    {
        if ($id <= 0) {
            echo '<div class="ib-alert ib-alert--error">' . __('invalid_record_id') . '</div>';
            return;
        }

        $record = $module->find($id);

        if ($record === null) {
            echo '<div class="ib-alert ib-alert--error">' . __('record_not_found') . '</div>';
            return;
        }

        $mod      = \Intrabase\Core\Router::currentMod();
        $listUrl  = $module->listReturnUrl((string) $mod);
        $editUrl  = Router::editUrl($mod, $id);

        echo '<div class="ib-detailer">';
        echo '<div class="ib-detailer__header">';
        echo '<h2 class="ib-detailer__title">';
        echo '<i class="' . Output::e($module->icon) . '" aria-hidden="true"></i> ';
        echo Output::e(__($module->title));
        echo '</h2>';
        $csrf        = $module->getCsrf();
        $postBaseUrl = Output::attr(Router::url($mod));

        echo '<div class="ib-detailer__actions">';
        if ($module->canEdit) {
            echo '<a href="' . Output::attr($editUrl) . '" class="ib-btn ib-btn--primary">'
               . '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> ' . __('edit') . '</a>';
        }
        foreach ($module->customDetailActions as $cda) {
            $cdaUrl    = Router::actionUrl(Router::currentMod(), $cda['action'] ?? '', $id, Router::currentApp());
            $cdaClass  = Output::e($cda['class'] ?? 'ib-btn--secondary');
            $cdaIcon   = $cda['icon'] ?? '';
            // L'etiqueta pot ser una constant d'ib_lang o un text literal: __() torna
            // el mateix text si no és cap clau (igual que fa el DataBrowser amb les de fila).
            $cdaLabel  = Output::e(__((string) ($cda['label'] ?? '')));
            $cdaTarget = ($cda['target'] ?? '') === '_blank' ? ' target="_blank"' : '';
            echo '<a href="' . Output::attr($cdaUrl) . '" class="ib-btn ' . $cdaClass . '"' . $cdaTarget . '>';
            if ($cdaIcon) echo '<i class="' . Output::e($cdaIcon) . '" aria-hidden="true"></i> ';
            echo $cdaLabel . '</a>';
        }
        if ($module->canDuplicate) {
            echo '<form id="ib-detail-dup-form" method="post" action="' . $postBaseUrl . '" hidden>';
            echo '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
            echo '<input type="hidden" name="_" value="dp">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo $csrf->field();
            echo '</form>';
            echo '<button type="submit" form="ib-detail-dup-form" class="ib-btn ib-btn--secondary ib-btn--confirm" '
               . 'data-confirm="' . __('confirm_duplicate') . '" title="' . __('duplicate') . '">'
               . '<i class="fa-solid fa-copy" aria-hidden="true"></i> ' . __('duplicate') . '</button>';
        }
        if ($module->canDelete) {
            echo '<form id="ib-detail-del-form" method="post" action="' . $postBaseUrl . '" hidden>';
            echo '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
            echo '<input type="hidden" name="_" value="dl">';
            echo '<input type="hidden" name="id" value="' . $id . '">';
            echo $csrf->field();
            echo '</form>';
            echo '<button type="submit" form="ib-detail-del-form" class="ib-btn ib-btn--danger ib-btn--confirm" '
               . 'data-confirm="' . __('confirm_delete') . '" title="' . __('delete') . '">'
               . '<i class="fa-solid fa-trash" aria-hidden="true"></i> ' . __('delete') . '</button>';
        }
        // Dynamic detail actions (modules can override getDetailActions)
        if (method_exists($module, 'getDetailActions')) {
            foreach ($module->getDetailActions($record) as $da) {
                $daUrl   = Output::attr($da['url'] ?? '#');
                $daClass = Output::e($da['class'] ?? 'ib-btn--secondary');
                $daIcon  = $da['icon'] ?? '';
                $daLabel = Output::e($da['label'] ?? '');
                echo '<a href="' . $daUrl . '" class="ib-btn ' . $daClass . '">';
                if ($daIcon) echo '<i class="' . Output::e($daIcon) . '" aria-hidden="true"></i> ';
                echo $daLabel . '</a>';
            }
        }
        echo '<a href="' . Output::attr($listUrl) . '" class="ib-btn ib-btn--secondary">'
           . '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> ' . __('back') . '</a>';
        echo '</div>';
        echo '</div>'; // header

        echo '<div class="ib-detailer__body">';

        // Field priority: detailFields > showFields > fields
        $fields = $module->detailFields ?: ($module->showFields ?: $module->fields);

        // For detail view, fetch the record including any extra fields not in $fields
        // (e.g. JSON blobs defined in detailFields but absent from the SELECT fields list)
        $fullRecord = $record;
        if (!empty($module->detailFields)) {
            $extraFields = array_diff($module->detailFields, $module->fields);
            if (!empty($extraFields)) {
                $fullRecord = $this->fetchFullRecord($module, $id, $module->detailFields);
            }
        }

        // Render fields in a responsive grid (dl > div > dt + dd is valid HTML5)
        echo '<dl class="ib-detailer__grid">';

        foreach ($fields as $field) {
            if (in_array($field, $module->hidden ?? [], true)) {
                continue;
            }

            $label = __($module->humans[$field] ?? ucwords(str_replace('_', ' ', $field)));
            $value = $fullRecord[$field] ?? '';
            $type  = $module->components[$field] ?? 'text';

            $displayValue = $this->formatValue($field, $value, $type, $module);

            echo '<div class="ib-detailer__field">';
            echo '<dt class="ib-detailer__field-label">' . Output::e($label) . '</dt>';
            echo '<dd class="ib-detailer__field-value">' . $displayValue . '</dd>';
            echo '</div>';
        }

        echo '</dl>';

        echo '</div>'; // body

        // Allow modules to append custom content (e.g. sub-tables, version history)
        if (method_exists($module, 'renderDetailExtra')) {
            try {
                $extra = $module->renderDetailExtra($record);
                if (is_string($extra)) {
                    echo $extra;
                }
            } catch (\Throwable) {
                // Silent fail — module detail extras are non-critical
            }
        }

        echo '</div>'; // ib-detailer
    }

    /**
     * Fetch a record selecting specific columns (for detailFields with extras).
     * Field names are validated to only allow safe SQL identifiers.
     *
     * @param  Module   $module
     * @param  int      $id
     * @param  string[] $fields Column names to SELECT
     * @return array<string, mixed>
     */
    private function fetchFullRecord(Module $module, int $id, array $fields): array
    {
        // Only allow valid SQL identifier characters to prevent injection
        $safeFields = array_values(array_filter(
            $fields,
            fn($f) => (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $f)
        ));
        if (empty($safeFields)) {
            return [];
        }
        $cols = implode(', ', array_map(fn($f) => '`' . $f . '`', $safeFields));
        $pk   = $module->key;

        $row = $module->getDb()->fetchOne(
            "SELECT {$cols} FROM `{$module->table}` WHERE `{$pk}` = :id LIMIT 1",
            ['id' => $id]
        );

        return $row ?? [];
    }

    private function formatValue(string $field, mixed $value, string $type, Module $module): string
    {
        if ($value === null || $value === '') {
            return '<span class="ib-detailer__empty">—</span>';
        }

        // Camp `status` amb select estàtic: badge amb l'estil del KMS, com al llistat
        if ($field === 'status' && ($type === 'combo' || $type === 'select')
            && isset($module->combos[$field]['data']) && is_array($module->combos[$field]['data'])) {
            $lbl = $module->combos[$field]['data'][$value] ?? $value;
            return Output::kmsStatusBadge((string) $value, __((string) $lbl));
        }

        // Delegate to registered component if available
        if (ComponentRegistry::has($type)) {
            $component = ComponentRegistry::make($type);
            $options = $module->componentOptions[$field] ?? [];
            // Inject combo data for combo/select components
            if (($type === 'combo' || $type === 'select') && !isset($options['combo_data']) && !isset($options['values'])) {
                $comboConfig = $module->combos[$field] ?? null;
                if ($comboConfig && isset($comboConfig['data']) && is_array($comboConfig['data'])) {
                    $options['combo_data'] = array_map(fn($l) => __((string) $l), $comboConfig['data']);
                    $options['values']     = $options['combo_data'];
                } elseif ($type === 'combo' && $comboConfig && isset($comboConfig['table'], $comboConfig['key'])) {
                    // Combo de BD (clau forana): nom en lloc de l'id, com al llistat (11/11/2026).
                    $labels = $this->fetchDetailComboOptions($field, $module);
                    if (isset($labels[(string) $value])) {
                        return Output::e($labels[(string) $value]);
                    }
                }
            }
            return $component->display($field, $value, $options);
        }

        return match ($type) {
            'date'       => Output::date((string) $value),
            'datetime'   => $this->formatDatetime((string) $value),
            'money',
            'decimal'    => Output::money((float) $value),
            'percentage' => Output::e(number_format((float) $value, 2)) . '%',
            'integer',
            'number'     => Output::e((string)(int) $value),
            'html',
            'wysiwyg'    => '<div class="ib-detailer__html">' . Input::sanitizeHtml((string) $value) . '</div>',
            'url'        => '<a href="' . Output::url((string) $value) . '" target="_blank" rel="noopener">'
                          . Output::e((string) $value) . '</a>',
            'email'      => '<a href="mailto:' . Output::attr((string) $value) . '">'
                          . Output::e((string) $value) . '</a>',
            'picture'    => '<img class="ib-detailer__image" src="' . Output::url((string) $value) . '" alt="">',
            'file'       => $value
                              ? '<a href="' . \Intrabase\Security\Output::attr(\Intrabase\Support\DownloadLink::url((string) $value)) . '" class="ib-btn ib-btn--sm">'
                              . '<i class="fa-solid fa-download" aria-hidden="true"></i> ' . Output::e(basename((string) $value)) . '</a>'
                              : '<span class="ib-detailer__empty">' . __('no_file') . '</span>',
            'json'       => $this->formatJson((string) $value),
            'bool',
            'boolean',
            'toggle',
            'checkbox'   => ($value && $value !== '0' && $value !== 'false')
                              ? '<span class="ib-badge ib-badge--success"><i class="fa-solid fa-check"></i> ' . __('yes') . '</span>'
                              : '<span class="ib-badge ib-badge--neutral"><i class="fa-solid fa-xmark"></i> ' . __('no') . '</span>',
            'combo',
            'select',
            'radiolist'  => $this->resolveComboLabel($field, (string) $value, $module),
            'multicombo',
            'checklist'  => $this->resolveMultiComboLabels($field, (string) $value, $module),
            'color'      => $this->renderColorSwatch((string) $value),
            'time'       => (strlen((string) $value) >= 5 ? substr((string) $value, 0, 5) : Output::e((string) $value)),
            'phone'      => $value !== '' ? '<a href="tel:' . Output::attr((string) $value) . '">' . Output::e((string) $value) . '</a>' : '<span class="ib-detailer__empty">\u2014</span>',
            'rating'     => $this->renderRatingStars((int) $value),
            'tags'       => $this->renderTagsBadges((string) $value),
            'textarea'   => '<div class="ib-detailer__pre">' . nl2br(Output::e((string) $value)) . '</div>',
            'badge',
            'status',
            'status_icon' => $this->renderStatusBadge($field, (string) $value, $module),
            default      => Output::e((string) $value),
        };
    }

    /**
     * Render a colored status badge using module statusColors or keyword-based fallback.
     */
    private function renderStatusBadge(string $field, string $value, Module $module, ?string $label = null): string
    {
        // Camp `status`: badge amb l'estil del KMS (kms-status-badge kms-status-{valor}) i l'etiqueta del select
        if ($field === 'status') {
            $lbl = $label ?? $module->selects[$field][$value] ?? ucwords(str_replace('_', ' ', $value));
            return Output::kmsStatusBadge($value, __((string) $lbl));
        }
        $colorMap = $module->statusColors[$field] ?? [];
        $color    = $colorMap[$value] ?? $this->guessStatusColor($value);
        // Com a `status`: l'etiqueta del select (pot ser una clau d'ib_lang), no el valor cru
        $label    = Output::e(__((string) ($label ?? $module->selects[$field][$value] ?? ucwords(str_replace('_', ' ', $value)))));
        return "<span class=\"ib-badge ib-badge--{$color}\">{$label}</span>";
    }

    private function guessStatusColor(string $value): string
    {
        $v = strtolower($value);
        if (in_array($v, ['active', 'approved', 'paid', 'completed', 'done', 'confirmed', 'published', 'issued', 'reconciled', 'won', 'compliant', 'succeeded', 'success', 'passed', 'on_track'], true)) {
            return 'success';
        }
        if (in_array($v, ['pending', 'draft', 'new', 'in_progress', 'under_review', 'submitted', 'open', 'scheduled', 'planned', 'in_review', 'reviewing', 'processing', 'sent'], true)) {
            return 'info';
        }
        if (in_array($v, ['warning', 'at_risk', 'expiring_soon', 'overdue', 'delayed', 'on_hold', 'suspended', 'review', 'conditional', 'partial'], true)) {
            return 'warning';
        }
        if (in_array($v, ['error', 'failed', 'rejected', 'cancelled', 'expired', 'deleted', 'lost', 'non_compliant', 'critical', 'blocked', 'terminated', 'off_track'], true)) {
            return 'danger';
        }
        return 'neutral';
    }

    /**
     * Resolve a combo value to its display label.
     * For status-like fields with static combos, auto-renders as colored badge.
     */
    private function resolveComboLabel(string $field, string $value, Module $module): string
    {
        $options = $this->fetchDetailComboOptions($field, $module);
        $label   = $options[$value] ?? $value;

        // Auto-badge for status-like fields with static data combos
        $isBadgeField = in_array($field, ['status', 'state', 'priority', 'severity', 'result', 'risk_level'], true)
            || str_ends_with($field, '_status')
            || str_ends_with($field, '_state')
            || str_ends_with($field, '_priority');
        $isStaticCombo = isset($module->combos[$field]['data']);

        if ($isBadgeField && $isStaticCombo) {
            return $this->renderStatusBadge($field, $value, $module, (string) $label);
        }

        return Output::e($label);
    }

    private function resolveMultiComboLabels(string $field, string $value, Module $module): string
    {
        $options = $this->fetchDetailComboOptions($field, $module);
        $parts   = array_filter(explode(',', $value));
        if (empty($parts)) {
            return '<span class="ib-detailer__empty">—</span>';
        }
        $labels = array_map(fn($v) => Output::e($options[trim($v)] ?? trim($v)), $parts);
        return implode(', ', $labels);
    }

    private function fetchDetailComboOptions(string $field, Module $module): array
    {
        $config = $module->combos[$field] ?? null;
        if (!$config) {
            return [];
        }
        if (isset($config['data']) && is_array($config['data'])) {
            return array_map('strval', $config['data']);
        }
        try {
            $table = $config['table'];
            $key   = $config['key'];
            $val   = $config['value'] ?? $config['label'] ?? '';
            $where = $config['where'] ?? '';
            $sql   = "SELECT `{$key}`, `{$val}` FROM `{$table}`";
            if ($where) $sql .= " WHERE {$where}";
            $orderCol = $config['orderBy'] ?? ('`' . $val . '` ASC');
            $sql .= " ORDER BY {$orderCol}";
            $rows   = $module->getDb()->fetchAll($sql);
            $labels = [];
            foreach ($rows as $row) {
                $labels[(string)$row[$key]] = (string)$row[$val];
            }
            return $labels;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pretty-print a JSON string inside a scrollable code block.
     * If the string is not valid JSON, display it as plain preformatted text.
     */
    private function formatJson(string $raw): string
    {
        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Not valid JSON — render as plain preformatted text
            return '<pre class="ib-detailer__json">' . Output::e($raw) . '</pre>';
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<pre class="ib-detailer__json">' . Output::e((string) $pretty) . '</pre>';
    }

    /**
     * Render a color swatch with the hex value.
     */
    private function formatDatetime(string $value): string
    {
        if (preg_match('/\b00:00(:00)?$/', $value) || strlen($value) <= 10) {
            return Output::date($value);
        }
        return Output::date($value, 'd/m/Y H:i');
    }

    private function renderTagsBadges(string $value): string
    {
        $tags = array_filter(array_map('trim', explode(',', $value)));
        if (empty($tags)) {
            return '<span class="ib-detailer__empty">—</span>';
        }
        $html = '<div style="display:flex;flex-wrap:wrap;gap:.3rem">';
        foreach ($tags as $tag) {
            $html .= '<span class="ib-badge ib-badge--primary">' . Output::e($tag) . '</span>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderColorSwatch(string $value): string
    {
        if ($value === ''  || $value === null) {
            return '<span class="ib-detailer__empty">—</span>';
        }
        $safe = Output::attr($value);
        $text = Output::e($value);
        return '<span class="ib-color-swatch"><span class="ib-color-swatch__dot" style="background:' . $safe . '"></span> ' . $text . '</span>';
    }

    /**
     * Render a star rating (1-5).
     */
    private function renderRatingStars(int $value): string
    {
        if ($value <= 0) {
            return '<span class="ib-detailer__empty">—</span>';
        }
        $max   = 5;
        $stars = '';
        for ($i = 1; $i <= $max; $i++) {
            $filled = $i <= $value ? 'fa-solid' : 'fa-regular';
            $color  = $i <= $value ? 'color:#f59e0b' : 'color:#d1d5db';
            $stars .= '<i class="' . $filled . ' fa-star" style="' . $color . '" aria-hidden="true"></i>';
        }
        return '<span class="ib-rating">' . $stars . ' <span class="ib-rating__label">' . $value . '/' . $max . '</span></span>';
    }
}
