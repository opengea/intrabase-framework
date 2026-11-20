<?php

declare(strict_types=1);

namespace Intrabase\View;

use Intrabase\Components\ComponentRegistry;
use Intrabase\Core\Module;
use Intrabase\Core\Router;
use Intrabase\Security\Input;
use Intrabase\Security\Output;

/**
 * DataEditor — Generic Create/Edit Form
 *
 * Renders an HTML form for creating or editing a single record.
 *
 * Features:
 * - Automatic CSRF token injection
 * - Component-based field rendering (text, date, combo, picture, etc.)
 * - Field groups / tabs
 * - Required field markers
 * - Readonly field support
 * - Client-side validation via HTML5 attributes
 *
 * @package Intrabase\View
 */
class DataEditor
{
    private Module $module;
    private int $recordId;

    /** @var array<string, mixed> */
    private array $record = [];

    public function render(Module $module, int $id): void
    {
        $this->module   = $module;
        $this->recordId = $id;

        if ($id > 0) {
            $this->record = $module->find($id) ?? [];
            if (empty($this->record)) {
                echo '<div class="ib-alert ib-alert--error">' . __('record_not_found') . '</div>';
                return;
            }
        }

        $this->output();
    }

    // =========================================================================
    // HTML Output
    // =========================================================================

    private function output(): void
    {
        $mod    = \Intrabase\Core\Router::currentMod();
        $isNew  = $this->recordId === 0;
        $title  = ($isNew ? __('add_prefix') : __('edit_prefix')) . ' — ' . Output::e(__($this->module->title));
        $csrf   = $this->module->getCsrf();

        // Resolve URLs using Router (structured if possible, legacy fallback)
        $listUrl = Router::url($mod);
        $saveUrl = Router::saveUrl($mod);
        $isStructured = str_starts_with($listUrl, '/') && !str_starts_with($listUrl, '/?');
        if (!$isStructured) {
            $saveUrl = '?mod=' . urlencode($mod) . '&_=sv';
        }

        // Tornar i Cancel·la porten al llistat tal com era (vegeu Module::rememberListQuery)
        $listUrl = $this->module->listReturnUrl((string) $mod);
        $returnQ = Input::get('_q');
        if ($returnQ !== '' && !str_contains($listUrl, 'q=')) {
            $listUrl .= (str_contains($listUrl, '?') ? '&' : '?') . 'q=' . urlencode($returnQ);
        }

        echo '<div class="ib-editor">';
        echo '<div class="ib-editor__header">';
        echo '<h2 class="ib-editor__title">';
        echo '<i class="' . Output::e($this->module->icon) . '" aria-hidden="true"></i> ' . $title;

        // Show record subtitle (name + identifying field) when editing
        if (!$isNew && !empty($this->record)) {
            $subtitle = $this->buildRecordSubtitle();
            if ($subtitle) {
                echo ' <span class="ib-editor__subtitle">' . $subtitle . '</span>';
            }
        }
        echo '</h2>';

        echo '<a href="' . Output::attr($listUrl) . '" class="ib-btn ib-btn--back">'
           . '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> ' . __('back') . '</a>';
        echo '</div>';

        echo '<form class="ib-form" method="post" action="' . Output::attr($saveUrl) . '" '
           . 'enctype="multipart/form-data" novalidate>';

        echo $csrf->field();
        echo '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
        echo '<input type="hidden" name="id" value="' . $this->recordId . '">';
        // Active tab — filled in by ibInitTabs() so the post-save redirect returns here
        echo '<input type="hidden" name="_tab" value="">';
        if ($returnQ !== '') {
            echo '<input type="hidden" name="_q" value="' . Output::attr($returnQ) . '">';
        }

        $groups = $this->module->getGroups();

        if (!empty($groups)) {
            $this->renderGroupedFields($groups);
        } else {
            $this->renderFields($this->resolveEditFields());
        }

        echo '<div class="ib-form__actions">';
        echo '<button type="submit" class="ib-btn ib-btn--primary">'
           . '<i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> ' . __('save') . '</button>';
        // Duplicate button — adds _duplicate flag and submits main form to create new record with current data
        if (!$isNew && $this->module->canDuplicate) {
            echo '<button type="button" class="ib-btn ib-btn--secondary" '
               . 'title="' . __('duplicate') . '" '
               . 'onclick="if(!confirm(\'' . Output::attr(__('confirm_duplicate')) . '\'))return;var f=this.closest(\'.ib-editor\').querySelector(\'form.ib-form\');if(f){var h=document.createElement(\'input\');h.type=\'hidden\';h.name=\'_duplicate\';h.value=\'1\';f.appendChild(h);f.submit();}">'
               . '<i class="fa-solid fa-copy" aria-hidden="true"></i> ' . __('duplicate') . '</button>';
        }
        // Delete — submits the separate form below (forms cannot nest), same POST as the detail view
        if (!$isNew && $this->module->canDelete) {
            echo '<button type="submit" form="ib-editor-del-form" class="ib-btn ib-btn--danger ib-btn--confirm" '
               . 'data-confirm="' . __('confirm_delete') . '" title="' . __('delete') . '">'
               . '<i class="fa-solid fa-trash" aria-hidden="true"></i> ' . __('delete') . '</button>';
        }
        echo '<a href="' . Output::attr($listUrl) . '" class="ib-btn ib-btn--secondary" style="margin-left:auto">' . __('cancel') . '</a>';
        echo '</div>';

        echo '</form>';

        if (!$isNew && $this->module->canDelete) {
            echo '<form id="ib-editor-del-form" method="post" action="' . Output::attr(Router::url($mod)) . '" hidden>';
            echo '<input type="hidden" name="mod" value="' . Output::attr($mod) . '">';
            echo '<input type="hidden" name="_" value="dl">';
            echo '<input type="hidden" name="id" value="' . $this->recordId . '">';
            echo $csrf->field();
            echo '</form>';
        }

        // Inject JavaScript events
        $this->renderJs();

        echo '</div>'; // ib-editor
    }

    /**
     * Render fields organized into tab groups.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function renderGroupedFields(array $groups): void
    {
        $groupNames = array_keys($groups);
        $firstId    = htmlspecialchars($groupNames[0], ENT_QUOTES, 'UTF-8');

        // Tab nav
        echo '<div class="ib-tabs">';
        echo '<ul class="ib-tabs__nav" role="tablist">';
        foreach ($groups as $name => $config) {
            $id      = Output::attr($name);
            $label   = Output::e(__($config["label"] ?? ucfirst($name)));
            $icon    = isset($config['icon']) ? '<i class="fa ' . Output::e($config['icon']) . '"></i> ' : '';
            $active  = $name === $groupNames[0] ? ' ib-tabs__nav-item--active' : '';
            echo "<li class=\"ib-tabs__nav-item{$active}\" role=\"tab\" "
               . "data-tab=\"tab-{$id}\" aria-controls=\"tab-{$id}\" tabindex=\"0\">{$icon}{$label}</li>";
        }
        echo '</ul>';

        // Tab panels
        echo '<div class="ib-tabs__panels">';
        foreach ($groups as $name => $config) {
            $id     = Output::attr($name);
            $active = $name === $groupNames[0] ? ' ib-tabs__panel--active' : '';
            echo "<div class=\"ib-tabs__panel{$active}\" id=\"tab-{$id}\" role=\"tabpanel\">";
            // Group options (26/09/2026): 'columns' => 2|3 lays the fields out in a grid on desktop;
            // 'full' => [...] lists fields that span every column (long texts do so automatically).
            $this->renderFields($config['fields'] ?? [], (int) ($config['columns'] ?? 1), (array) ($config['full'] ?? []));
            echo '</div>';
        }
        echo '</div>';
        echo '</div>'; // ib-tabs
    }

    /**
     * Render a list of fields.
     *
     * @param string[] $fields
     */
    private function renderFields(array $fields, int $columns = 1, array $full = []): void
    {
        $columns = max(1, min(3, $columns));
        echo '<div class="ib-form__fields' . ($columns > 1 ? ' ib-form__fields--cols' . $columns : '') . '">';
        $longTypes = ['textarea', 'html', 'richtext', 'editor', 'wysiwyg', 'markdown', 'map', 'geolocalize', 'file', 'image', 'upload'];
        foreach ($fields as $field) {
            $type = (string) ($this->module->components[$field] ?? '');
            $isFull = $columns > 1 && (in_array($field, $full, true) || in_array($type, $longTypes, true));
            $this->renderFieldRow($field, $isFull);
        }
        echo '</div>';
    }

    /**
     * Render a single field row (label + input).
     */
    private function renderFieldRow(string $field, bool $full = false): void
    {
        if (in_array($field, $this->module->hidden ?? [], true)) {
            return;
        }

        $type = $this->module->components[$field] ?? '';
        $currentVal = $this->record[$field] ?? ($this->module->defaultValues[$field] ?? '');

        // Hidden fields: render only the input, no label/wrapper
        if ($type === 'hidden') {
            echo $this->renderInput($field, $currentVal, false, false);
            return;
        }

        $label      = __($this->module->humans[$field]  ?? ucwords(str_replace('_', ' ', $field)));
        $isRequired = in_array($field, $this->module->validates ?? [], true);
        $isReadonly = in_array($field, $this->module->readonly ?? [], true);
        $comment    = __($this->module->comments[$field] ?? '');
        $opts       = $this->module->componentOptions[$field] ?? [];
        $hideLabel  = !empty($opts['hide_label']);

        $requiredMark = $isRequired ? '<span class="ib-form__required" aria-label="Required">*</span>' : '';

        echo '<div class="ib-form__row' . ($full ? ' ib-form__row--full' : '') . '">';
        if (!$hideLabel) {
            echo '<label class="ib-form__label" for="field_' . Output::attr($field) . '">';
            echo Output::e($label) . $requiredMark;
            echo '</label>';
        }
        echo '<div class="ib-form__control">';
        echo $this->renderInput($field, $currentVal, $isRequired, $isReadonly);
        if ($comment) {
            echo '<p class="ib-form__help">' . Output::e($comment) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render the appropriate input element for a field based on its component type.
     */
    private function renderInput(string $field, mixed $value, bool $required, bool $readonly): string
    {
        $type     = $this->module->components[$field] ?? (isset($this->module->combos[$field]) ? "combo" : "text");

        // Delegate to registered component if available
        if (ComponentRegistry::has($type)) {
            $component = ComponentRegistry::make($type);
            $options = array_merge(
                $this->module->componentOptions[$field] ?? [],
                ['required' => $required, 'readonly' => $readonly, 'label' => __($this->module->humans[$field] ?? $field)]
            );
            // Inject combo data for combo/select components
            if (($type === 'combo' || $type === 'select') && !isset($options['combo_data']) && !isset($options['values'])) {
                $comboOptions = $this->fetchComboOptions($field);
                if (!empty($comboOptions)) {
                    $options['combo_data'] = $comboOptions;
                    $options['values']     = $comboOptions;
                }
            }
            // Inject display_value from record when display_field is configured
            if (isset($options['display_field']) && !empty($this->record[$options['display_field']])) {
                $options['display_value'] = (string) $this->record[$options['display_field']];
            }
            // For relation_autocomplete: resolve display_value from related table if not already set
            if ($type === 'relation_autocomplete' && !isset($options['display_value']) && $value !== null && (string) $value !== '') {
                $raTable  = (string) ($options['table'] ?? '');
                $raKey    = (string) ($options['key'] ?? 'id');
                $raColumn = (string) ($options['column'] ?? '');
                if ($raTable !== '' && $raColumn !== '') {
                    try {
                        $rows = $this->module->getDb()->fetchAll(
                            "SELECT `{$raColumn}` FROM `{$raTable}` WHERE `{$raKey}` = :id LIMIT 1",
                            ['id' => $value]
                        );
                        if (!empty($rows)) {
                            $options['display_value'] = (string) $rows[0][$raColumn];
                        }
                    } catch (\Throwable) {
                        // silently fail
                    }
                }
            }
            return $component->render($field, $value, $options);
        }

        $name     = Output::attr($field);
        $safeVal  = Output::attr((string) $value);
        $id       = 'field_' . Output::attr($field);
        $req      = $required ? ' required' : '';
        $ro       = $readonly ? ' readonly' : '';
        $maxLen   = isset($this->module->maxLengths[$field]) ? ' maxlength="' . (int)$this->module->maxLengths[$field] . '"' : '';
        $class    = 'ib-input' . (isset($this->module->classes[$field]) ? ' ' . Output::attr($this->module->classes[$field]) : '');

        // datetime-local inputs require "YYYY-MM-DDTHH:MM" format;
        // DB stores "YYYY-MM-DD HH:MM:SS" — convert on the fly.
        $datetimeVal = '';
        if ($type === 'datetime' && $value !== '' && $value !== null) {
            $datetimeVal = Output::attr(
                preg_replace('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}).*$/', '$1T$2', (string) $value) ?? ''
            );
        }

        // <input type="date"> only accepts "YYYY-MM-DD" and shows a blank
        // field for anything else — a DATETIME column declared as 'date'
        // ("2026-10-27 00:00:00") must be trimmed to its date part.
        if ($type === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', (string) $value, $m)) {
            $safeVal = Output::attr($m[1]);
        }

        return match ($type) {
            'text'       => "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}{$maxLen}>",
            'password'   => "<div class=\"ib-input-group\"><input type=\"password\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class} ib-input--has-suffix\"{$req}{$ro} autocomplete=\"off\"><button type=\"button\" class=\"ib-input-group__suffix ib-btn-icon ib-pass-toggle\" data-target=\"{$id}\"><i class=\"fa-solid fa-eye\"></i></button></div>",
            'email'      => "<input type=\"email\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}>",
            'url'        => "<input type=\"url\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}>",
            'integer',
            'number'     => "<input type=\"number\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\" step=\"1\"{$req}{$ro}>",
            'money',
            'decimal'    => "<input type=\"number\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\" step=\"0.01\"{$req}{$ro}>",
            'currency'   => "<div class=\"ib-input-group\"><input type=\"text\" inputmode=\"decimal\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class} ib-input--has-suffix\"{$req}{$ro}><span class=\"ib-input-group__suffix\">€</span></div>",
            'percentage' => "<input type=\"number\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\" step=\"0.01\" min=\"0\" max=\"100\"{$req}{$ro}>",
            'date'       => "<input type=\"date\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}>",
            'datetime'   => "<input type=\"datetime-local\" name=\"{$name}\" id=\"{$id}\" value=\"{$datetimeVal}\" class=\"{$class}\"{$req}{$ro}>",
            'time'       => "<input type=\"time\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}>",
            'color'      => "<input type=\"color\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}>",
            'textarea'   => $this->renderTextarea($name, $id, (string)$value, $class, $req, $ro),
            'html',
            'wysiwyg'    => $this->renderHtmlEditor($name, $id, (string)$value, $req),
            'select',
            'combo'      => $this->renderCombo($field, $name, $id, (string)$value, $req, $ro),
            'multicombo' => $this->renderMultiCombo($field, $name, $id, (string)$value, $req, $ro),
            'checklist'  => $this->renderChecklist($field, $name, (string)$value, $ro),
            'radiolist'  => $this->renderRadiolist($field, $name, (string)$value, $ro),
            'bool',
            'boolean',
            'toggle',
            'checkbox'   => $this->renderCheckbox($name, $id, (string)$value, $readonly),
            'picture'    => $this->renderPictureUpload($field, $name, $id, (string)$value, $req),
            'file'       => $this->renderFileUpload($field, $name, $id, (string)$value, $req),
            'tags'       => $this->renderTagsInput($name, $id, (string)$value, $readonly),
            'hidden'     => "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\">",
            default      => "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\" class=\"{$class}\"{$req}{$ro}{$maxLen}>",
        };
    }

    private function renderTextarea(string $name, string $id, string $value, string $class, string $req, string $ro): string
    {
        $safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return "<textarea name=\"{$name}\" id=\"{$id}\" class=\"{$class} ib-textarea\" rows=\"5\"{$req}{$ro}>{$safe}</textarea>";
    }

    private function renderHtmlEditor(string $name, string $id, string $value, string $req): string
    {
        $safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return "<textarea name=\"{$name}\" id=\"{$id}\" class=\"ib-html-editor\" data-editor=\"html\"{$req}>{$safe}</textarea>";
    }

    private function renderCombo(string $field, string $name, string $id, string $current, string $req, string $ro): string
    {
        $options = $this->fetchComboOptions($field);
        $disabled = $ro ? ' disabled' : '';

        $html = "<select name=\"{$name}\" id=\"{$id}\" class=\"ib-select\"{$req}{$disabled}>";
        $html .= '<option value="">' . __('select_ph') . '</option>';

        foreach ($options as $val => $label) {
            $selected = ((string) $val === $current) ? ' selected' : '';
            $html .= '<option value="' . Output::attr((string) $val) . '"' . $selected . '>'
                   . Output::e(__((string) $label)) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    private function renderMultiCombo(string $field, string $name, string $id, string $current, string $req, string $ro): string
    {
        $options = $this->fetchComboOptions($field);
        $selected = array_filter(explode(',', $current));
        $disabled = $ro ? ' disabled' : '';

        $html = "<select name=\"{$name}[]\" id=\"{$id}\" class=\"ib-select ib-select--multi\" multiple{$req}{$disabled}>";

        foreach ($options as $val => $label) {
            $sel  = in_array((string) $val, $selected, true) ? ' selected' : '';
            $html .= '<option value="' . Output::attr((string) $val) . '"' . $sel . '>'
                   . Output::e(__((string) $label)) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    private function renderChecklist(string $field, string $name, string $current, string $ro): string
    {
        $options  = $this->fetchComboOptions($field);
        $selected = array_filter(explode(',', $current));
        $disabled = $ro ? ' disabled' : '';

        $html = '<div class="ib-checklist">';
        foreach ($options as $val => $label) {
            $checked = in_array((string) $val, $selected, true) ? ' checked' : '';
            $safeVal = Output::attr((string) $val);
            $safeId  = 'chk_' . Output::attr($field) . '_' . Output::attr((string) $val);
            $html .= "<label class=\"ib-checkbox\">"
                   . "<input type=\"checkbox\" name=\"{$name}[]\" id=\"{$safeId}\" value=\"{$safeVal}\"{$checked}{$disabled}>"
                   . '<span class="ib-checkbox__label">' . Output::e((string) $label) . '</span>'
                   . '</label>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderRadiolist(string $field, string $name, string $current, string $ro): string
    {
        $options  = $this->fetchComboOptions($field);
        $disabled = $ro ? ' disabled' : '';

        $html = '<div class="ib-radiolist">';
        foreach ($options as $val => $label) {
            $checked = ((string) $val === $current) ? ' checked' : '';
            $safeVal = Output::attr((string) $val);
            $safeId  = 'rad_' . Output::attr($field) . '_' . Output::attr((string) $val);
            $html .= "<label class=\"ib-radio\">"
                   . "<input type=\"radio\" name=\"{$name}\" id=\"{$safeId}\" value=\"{$safeVal}\"{$checked}{$disabled}>"
                   . '<span class="ib-radio__label">' . Output::e((string) $label) . '</span>'
                   . '</label>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderPictureUpload(string $field, string $name, string $id, string $current, string $req): string
    {
        $html = '<div class="ib-upload ib-upload--picture">';
        if ($current) {
            $html .= '<img class="ib-upload__preview" src="' . Output::url($current) . '" alt="">';
        }
        $html .= "<input type=\"file\" name=\"{$name}\" id=\"{$id}\" class=\"ib-upload__input\" "
               . "accept=\"image/jpeg,image/png,image/webp,image/gif\"{$req}>";
        if ($current) {
            $html .= '<label class="ib-checkbox ib-upload__clear">'
                   . '<input type="checkbox" name="__clear_' . Output::attr($name) . '" value="1">'
                   . ' ' . __('remove_image') . '</label>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderFileUpload(string $field, string $name, string $id, string $current, string $req): string
    {
        $html = '<div class="ib-upload ib-upload--file">';
        if ($current) {
            $html .= '<span class="ib-upload__current"><i class="fa-solid fa-file" aria-hidden="true"></i> '
                   . Output::e(basename($current)) . '</span>';
        }
        $html .= "<input type=\"file\" name=\"{$name}\" id=\"{$id}\" class=\"ib-upload__input\"{$req}>";
        $html .= '</div>';
        return $html;
    }

    private function renderCheckbox(string $name, string $id, string $value, bool $readonly): string
    {
        $checked  = ($value === '1' || $value === 'true' || $value === 'yes') ? ' checked' : '';
        $disabled = $readonly ? ' disabled' : '';
        // Hidden input ensures the field is submitted as 0 when unchecked
        return '<input type="hidden" name="' . $name . '" value="0">'
             . '<label class="ib-toggle">'
             . "<input type=\"checkbox\" name=\"{$name}\" id=\"{$id}\" value=\"1\"{$checked}{$disabled} class=\"ib-toggle__input\">"
             . '<span class="ib-toggle__slider"></span>'
             . '</label>';
    }

    private function renderTagsInput(string $name, string $id, string $value, bool $readonly): string
    {
        $safeName  = Output::attr($name);
        $safeId    = Output::attr($id);
        $safeValue = Output::attr($value);
        $ro        = $readonly ? ' readonly' : '';
        $tags      = array_filter(array_map('trim', explode(',', $value)));

        $html  = '<div class="ib-tags-input" id="' . $safeId . '_wrap">';
        $html .= '<input type="hidden" name="' . $safeName . '" id="' . $safeId . '" value="' . $safeValue . '">';
        $html .= '<div class="ib-tags-input__list">';
        foreach ($tags as $tag) {
            $safeTag = Output::e($tag);
            $html .= '<span class="ib-tags-input__tag">' . $safeTag;
            if (!$readonly) {
                $html .= '<button type="button" class="ib-tags-input__remove" data-tag="' . Output::attr($tag) . '">&times;</button>';
            }
            $html .= '</span>';
        }
        $html .= '</div>';
        if (!$readonly) {
            $html .= '<input type="text" class="ib-tags-input__field ib-input" placeholder="Afegir etiqueta…"' . $ro . '>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Build a subtitle string from the record's name/email/title fields.
     */
    private function buildRecordSubtitle(): string
    {
        foreach (['email', 'url', 'reference', 'code', 'name', 'title', 'label'] as $f) {
            if (!empty($this->record[$f])) {
                return '— ' . Output::e((string)$this->record[$f]);
            }
        }
        return '';
    }

    /**
     * Fetch combo options from the configured data source.
     *
     * Supports two config formats:
     *   Static:  ['data' => ['key' => 'Label', ...]]
     *   DB-backed: ['table' => '...', 'key' => '...', 'value' => '...']
     *
     * @return array<string, string>
     */
    private function fetchComboOptions(string $field): array
    {
        $config = $this->module->combos[$field] ?? null;
        if (!$config) {
            return [];
        }

        // Static data combo
        if (isset($config['data']) && is_array($config['data'])) {
            return array_map('strval', $config['data']);
        }

        // DB-backed combo
        if (!isset($config['table'])) {
            return [];
        }
        try {
            $table = $config['table'];
            $key   = $config['key'] ?? null;
            if ($key === null) {
                return [];
            }
            $value = $config['value'] ?? $config['label'] ?? $config['labelField'] ?? $config['label_col'] ?? $config['label_field'] ?? $config['display'] ?? '';
            $where = $config['where'] ?? '';

            // Detect if value is a SQL expression (contains brackets or spaces)
            // If so, use an alias and don't wrap in backticks
            $isSqlExpr = str_contains($value, '(') || str_contains($value, ' ');
            if ($isSqlExpr) {
                $valueAlias = '__combo_label';
                $valueSql   = "{$value} AS `{$valueAlias}`";
            } else {
                $valueAlias = $value;
                $valueSql   = "`{$value}`";
            }

            $sql = "SELECT `{$key}`, {$valueSql} FROM `{$table}`";
            if ($where) {
                $sql .= " WHERE {$where}";
            }
            $orderCol = $config['orderBy'] ?? ($isSqlExpr ? $value : ('`' . $value . '` ASC'));
            $sql .= " ORDER BY {$orderCol}";

            $rows = $this->module->getDb()->fetchAll($sql);
            $options = [];
            foreach ($rows as $row) {
                $options[(string) $row[$key]] = (string) ($row[$valueAlias] ?? '');
            }
            return $options;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Determine which fields to display in the editor.
     *
     * @return string[]
     */
    private function resolveEditFields(): array
    {
        $fields = $this->module->editFields ?: $this->module->fields;
        return array_filter($fields, fn($f) => $f !== $this->module->key);
    }

    /**
     * Render JavaScript event hooks defined by the module.
     */
    private function renderJs(): void
    {
        $scripts = [];

        if ($this->module->onDocumentReady) {
            $scripts[] = "document.addEventListener('DOMContentLoaded', function() {\n"
                       . $this->module->onDocumentReady . "\n});";
        }

        foreach ($this->module->onFieldChange as $field => $js) {
            $safeField = preg_replace('/[^a-zA-Z0-9_]/', '', $field);
            $scripts[] = "document.addEventListener('DOMContentLoaded', function() {"
                       . "var el = document.getElementById('field_{$safeField}');"
                       . "if (el) el.addEventListener('change', function() { {$js} });"
                       . "});";
        }

        // Password toggle — event delegation
        $scripts[] = "document.addEventListener('click',function(e){"
                   . "var btn=e.target.closest('.ib-pass-toggle');"
                   . "if(!btn)return;"
                   . "e.preventDefault();e.stopPropagation();"
                   . "var inp=document.getElementById(btn.dataset.target);"
                   . "if(!inp)return;"
                   . "var ico=btn.querySelector('i');"
                   . "if(inp.type==='password'){inp.type='text';ico.className='fa-solid fa-eye-slash';}"
                   . "else{inp.type='password';ico.className='fa-solid fa-eye';}"
                   . "});";

        if (!empty($scripts)) {
            echo '<script>' . implode("\n", $scripts) . '</script>';
        }

        if ($this->module->customCss !== '') {
            echo '<style>' . $this->module->customCss . '</style>';
        }
    }
}
