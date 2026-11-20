<?php

declare(strict_types=1);

namespace Intrabase\Core;

use Intrabase\Database\Connection;
use Intrabase\Security\CSRF;

/**
 * DynamicModule
 *
 * A concrete Module subclass that builds its field configuration entirely
 * from database definitions stored in:
 *   - ib_sys_mods           (table, primary_key, permissions, order_by)
 *   - ib_sys_mod_attributes (fields, field_type, labels, options)
 *   - ib_sys_mod_actions    (custom per-row actions)
 *
 * This allows defining new CRUD modules through the UI (sys_mod module)
 * without writing any PHP code.
 *
 * Field type → component mapping:
 *   text      → text input
 *   textarea  → textarea
 *   number    → integer (number input step=1)
 *   date      → date picker
 *   datetime  → datetime-local
 *   email     → email input
 *   url       → url input
 *   html      → WYSIWYG editor
 *   file      → file upload
 *   hidden    → hidden (excluded from form/list)
 *   checkbox  → checklist with {'1':'Sí'} option
 *   select    → combo with static options (field_options: "val:Label,val2:Label2" or JSON)
 *   combo     → combo FK lookup (field_options JSON: {"table":"...","key":"...","value":"..."})
 *
 * @package Intrabase\Core
 */
final class DynamicModule extends Module
{
    /** Internal slug name used to load this module from ib_sys_mods */
    private string $dynName = '';

    /**
     * Configure this module instance from database rows.
     *
     * @param array<string, mixed>             $modRow     Row from ib_sys_mods
     * @param list<array<string, mixed>>       $attributes Rows from ib_sys_mod_attributes (ordered by sort_order)
     * @param list<array<string, mixed>>       $actions    Rows from ib_sys_mod_actions (ordered by sort_order)
     */
    public function configure(array $modRow, array $attributes, array $actions): void
    {
        // ── Core table config ──────────────────────────────────────────────
        $this->table    = (string) ($modRow['table_name']  ?? '');
        $this->key      = (string) ($modRow['primary_key'] ?? 'id');
        $this->dynName  = (string) ($modRow['name']        ?? '');
        $this->title    = (string) ($modRow['label']       ?? ucfirst(str_replace('_', ' ', $this->dynName)));
        $this->icon     = (string) ($modRow['icon_class']  ?? 'fa-solid fa-database');

        // ── Ordering ───────────────────────────────────────────────────────
        $orderByRaw = trim((string) ($modRow['order_by'] ?? ''));
        if ($orderByRaw !== '') {
            $parts = explode(' ', $orderByRaw, 2);
            $this->orderBy  = $parts[0];
            $this->orderDir = strtolower($parts[1] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        }

        // ── Browser config ────────────────────────────────────────────────
        $this->perPage = (int) ($modRow['per_page'] ?? 25) ?: 25;
        $this->showKey = (bool) ($modRow['show_key'] ?? false);

        $baseWhere = trim((string) ($modRow['base_where'] ?? ''));
        if ($baseWhere !== '') {
            $this->baseWhere = $baseWhere;
        }

        // ── Permissions ────────────────────────────────────────────────────
        $this->canAdd         = (bool) ($modRow['can_create']      ?? true);
        $this->canEdit        = (bool) ($modRow['can_edit']        ?? true);
        $this->canDelete      = (bool) ($modRow['can_delete']      ?? true);
        $this->canExport      = (bool) ($modRow['can_export']      ?? true);
        $this->canDuplicate   = (bool) ($modRow['can_duplicate']   ?? false);
        $this->canImport      = (bool) ($modRow['can_import']      ?? false);
        $this->canMultiselect = (bool) ($modRow['can_multiselect'] ?? true);

        // ── JS events ─────────────────────────────────────────────────────
        $onReady = trim((string) ($modRow['on_document_ready'] ?? ''));
        if ($onReady !== '') {
            $this->onDocumentReady = $onReady;
        }

        // ── Custom CSS ────────────────────────────────────────────────────
        $customCss = trim((string) ($modRow['custom_css'] ?? ''));
        if ($customCss !== '') {
            $this->customCss = $customCss;
        }

        // ── Fields from ib_sys_mod_attributes ──────────────────────────────
        $groupedFields = [];

        foreach ($attributes as $attr) {
            $fieldName = (string) ($attr['field_name'] ?? '');
            if ($fieldName === '' || $fieldName === $this->key) {
                continue;
            }

            $this->fields[] = $fieldName;

            // Collect field groups for tab support
            $fieldGroup = trim((string) ($attr['field_group'] ?? ''));
            if ($fieldGroup !== '') {
                $groupedFields[$fieldGroup][] = $fieldName;
            }

            // Human label
            $label = trim((string) ($attr['field_label'] ?? ''));
            if ($label !== '') {
                $this->humans[$fieldName] = $label;
            }

            // Show in browser list
            if (!empty($attr['show_in_list'])) {
                $this->showFields[] = $fieldName;
            }

            // Searchable (advanced text filter)
            if (!empty($attr['is_searchable'])) {
                $this->searchFields[]          = $fieldName;
                $this->filterFields[$fieldName] = 'text';
            }

            // Show in edit form
            if (!empty($attr['show_in_edit'])) {
                $this->editFields[] = $fieldName;
            }

            // Show in detail view
            if (!empty($attr['show_in_detail']) && empty($attr['show_in_list'])) {
                $this->detailFields[] = $fieldName;
            }

            // Required validation
            if (!empty($attr['is_required'])) {
                $this->validates[] = $fieldName;
            }

            // Readonly fields
            if (!empty($attr['is_readonly'])) {
                $this->readonly[] = $fieldName;
            }

            // Default value
            $default = trim((string) ($attr['default_value'] ?? ''));
            if ($default !== '') {
                $this->defaultValues[$fieldName] = $default;
            }

            // Abbreviation
            $abbrev = trim((string) ($attr['abbrev'] ?? ''));
            if ($abbrev !== '') {
                $this->abbrev[$fieldName] = $abbrev;
            }

            // Help text / comments
            $helpText = trim((string) ($attr['help_text'] ?? ''));
            if ($helpText !== '') {
                $this->comments[$fieldName] = $helpText;
            }

            // Max length
            $maxLen = (int) ($attr['max_length'] ?? 0);
            if ($maxLen > 0) {
                $this->maxLengths[$fieldName] = $maxLen;
            }

            // No wrap
            if (!empty($attr['no_wrap'])) {
                $this->noWrap[] = $fieldName;
            }

            // CSS class
            $cssClass = trim((string) ($attr['css_class'] ?? ''));
            if ($cssClass !== '') {
                $this->classes[$fieldName] = $cssClass;
            }

            // onFieldChange JS
            $onChangeJs = trim((string) ($attr['on_change_js'] ?? ''));
            if ($onChangeJs !== '') {
                $this->onFieldChange[$fieldName] = $onChangeJs;
            }

            // Totals
            if (!empty($attr['is_totals'])) {
                $this->totalsFields[] = $fieldName;
            }

            // Map field_type → component + combo config
            $this->mapFieldType(
                $fieldName,
                (string) ($attr['field_type']    ?? 'text'),
                (string) ($attr['field_options'] ?? '')
            );
        }

        // Register field groups as tabs
        if (!empty($groupedFields)) {
            foreach ($groupedFields as $grpKey => $grpFields) {
                $this->setGroup($grpKey, ['label' => $grpKey, 'fields' => $grpFields]);
            }
        }

        // If editFields not explicitly set, use all fields
        if (empty($this->editFields)) {
            $this->editFields = $this->fields;
        }

        // If no explicit showFields, show all
        if (empty($this->showFields)) {
            $this->showFields = $this->fields;
        }

        // ── Custom row actions from ib_sys_mod_actions ─────────────────────
        foreach ($actions as $action) {
            if (empty($action['is_active'])) {
                continue;
            }
            $actionType = strtolower((string) ($action['action_type'] ?? 'get'));
            $this->customRowActions[] = [
                'label'   => (string) ($action['action_label']   ?? ''),
                'icon'    => (string) ($action['icon_class']     ?? 'fa-solid fa-bolt'),
                'method'  => $actionType === 'post' ? 'POST' : 'GET',
                'url'     => (string) ($action['url_pattern']    ?? ''),
                'confirm' => (string) ($action['confirm_message'] ?? ''),
                'target'  => (string) ($action['target']         ?? '_self'),
                'action'  => (string) ($action['action_name']    ?? ''),
            ];
        }
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Map a field_type value to the correct Module component type and
     * populate $this->combos when needed.
     */
    private function mapFieldType(string $field, string $type, string $options): void
    {
        switch ($type) {
            case 'textarea':
                $this->components[$field] = 'textarea';
                break;

            case 'number':
                $this->components[$field] = 'integer';
                break;

            case 'date':
                $this->components[$field] = 'date';
                break;

            case 'datetime':
                $this->components[$field] = 'datetime';
                break;

            case 'email':
                $this->components[$field] = 'email';
                break;

            case 'url':
                $this->components[$field] = 'url';
                break;

            case 'html':
                $this->components[$field] = 'html';
                break;

            case 'file':
                $this->components[$field] = 'file';
                break;

            case 'hidden':
                // Add to hidden list so it won't appear in form or browser
                $this->hidden[] = $field;
                break;

            case 'checkbox':
                $this->components[$field]   = 'checklist';
                $this->combos[$field]        = ['data' => ['1' => 'Sí']];
                break;

            case 'select':
                // Static dropdown; field_options = "val:Label,val2:Label2" or JSON
                $this->components[$field] = 'combo';
                $parsed = $this->parseSelectOptions($options);
                if (!empty($parsed)) {
                    $this->combos[$field] = ['data' => $parsed];
                }
                break;

            case 'combo':
                // FK lookup; field_options = JSON {"table":"...","key":"...","value":"..."}
                $this->components[$field] = 'combo';
                if ($options !== '') {
                    $def = json_decode($options, true);
                    if (is_array($def)
                        && !empty($def['table'])
                        && !empty($def['key'])
                        && !empty($def['value'])
                    ) {
                        $this->addCombo(
                            $field,
                            (string) $def['table'],
                            (string) $def['key'],
                            (string) $def['value'],
                            (string) ($def['where'] ?? '')
                        );
                    }
                }
                break;

            case 'integer':
                $this->components[$field] = 'integer';
                break;
            case 'decimal':
            case 'money':
                $this->components[$field] = 'decimal';
                break;
            case 'percentage':
                $this->components[$field] = 'percentage';
                break;
            case 'time':
                $this->components[$field] = 'time';
                break;
            case 'phone':
                $this->components[$field] = 'phone';
                break;
            case 'color':
                $this->components[$field] = 'color';
                break;
            case 'rating':
                $this->components[$field] = 'rating';
                break;
            case 'picture':
                $this->components[$field] = 'picture';
                break;
            case 'slug':
                $this->components[$field] = 'slug';
                break;
            case 'json':
                $this->components[$field] = 'json';
                break;
            case 'text':
            default:
                $this->components[$field] = 'text';
                break;
        }
    }

    /**
     * Parse static select options from the field_options string.
     *
     * Supported formats:
     *   CSV  : "val1:Label 1,val2:Label 2"
     *   JSON array  : ["opt1","opt2"]
     *   JSON object : {"val1":"Label 1","val2":"Label 2"}
     *
     * @return array<string, string>
     */
    private function parseSelectOptions(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        // Try JSON first
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                // ["a","b"] → ["a"=>"a","b"=>"b"]
                return array_combine($decoded, $decoded) ?: [];
            }
            // {"a":"Label A",...}
            return array_map('strval', $decoded);
        }

        // CSV: "val1:Label 1,val2:Label 2"
        $result = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $pos = strpos($pair, ':');
            if ($pos !== false) {
                $result[trim(substr($pair, 0, $pos))] = trim(substr($pair, $pos + 1));
            } else {
                $result[$pair] = $pair;
            }
        }
        return $result;
    }

    /**
     * Return the dynamic module slug as the module identifier.
     */
    public function getModuleName(): string
    {
        return $this->dynName !== '' ? $this->dynName : parent::getModuleName();
    }
}
