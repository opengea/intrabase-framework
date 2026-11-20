<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * MultilangComponent
 *
 * Multilingual text field that stores a JSON object with language keys.
 * Renders a tabbed interface with one input/textarea per language.
 *
 * options:
 *   languages — array of language codes (default: ['ca', 'es', 'en'])
 *   type      — 'text' or 'textarea' (default: 'text')
 */
final class MultilangComponent implements ComponentInterface
{
    private const DEFAULT_LANGUAGES = ['ca', 'es', 'en'];

    public function render(string $field, mixed $value, array $options = []): string
    {
        $name      = Output::attr($field);
        $id        = 'field_' . Output::attr($field);
        $languages = (array) ($options['languages'] ?? self::DEFAULT_LANGUAGES);
        $type      = ($options['type'] ?? 'text') === 'textarea' ? 'textarea' : 'text';
        $req       = !empty($options['required']) ? ' required' : '';
        $ro        = !empty($options['readonly']) ? ' readonly' : '';

        // Decode existing value
        $values = [];
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        } elseif (is_array($value)) {
            $values = $value;
        }

        // Hidden input that holds the JSON value
        $jsonVal = Output::attr(json_encode($values, JSON_UNESCAPED_UNICODE) ?: '{}');
        $html = "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$jsonVal}\">";

        // Tabs
        $html .= "<div class=\"ib-multilang\" data-field=\"{$name}\">";
        $html .= '<ul class="ib-multilang__tabs">';
        foreach ($languages as $i => $lang) {
            $safeLang = Output::attr($lang);
            $active   = $i === 0 ? ' ib-multilang__tab--active' : '';
            $html .= "<li class=\"ib-multilang__tab{$active}\" data-lang=\"{$safeLang}\""
                   . " onclick=\"document.querySelectorAll('[data-field=\\'{$name}\\'] .ib-multilang__tab').forEach(t=>t.classList.remove('ib-multilang__tab--active'));"
                   . "this.classList.add('ib-multilang__tab--active');"
                   . "document.querySelectorAll('[data-field=\\'{$name}\\'] .ib-multilang__panel').forEach(p=>p.style.display='none');"
                   . "document.getElementById('ml_{$name}_{$safeLang}').style.display='block'\">"
                   . strtoupper($safeLang) . "</li>";
        }
        $html .= '</ul>';

        // Panels
        foreach ($languages as $i => $lang) {
            $safeLang = Output::attr($lang);
            $langVal  = Output::attr((string) ($values[$lang] ?? ''));
            $display  = $i === 0 ? 'block' : 'none';
            $panelId  = "ml_{$name}_{$safeLang}";
            $inputId  = "ml_input_{$name}_{$safeLang}";

            $onInput = "var h=document.getElementById('{$id}'),d=JSON.parse(h.value||'{}');"
                     . "d['{$safeLang}']=this.value;h.value=JSON.stringify(d)";

            $html .= "<div id=\"{$panelId}\" class=\"ib-multilang__panel\" style=\"display:{$display}\">";
            if ($type === 'textarea') {
                $html .= "<textarea id=\"{$inputId}\" class=\"ib-textarea\" rows=\"4\""
                       . " oninput=\"{$onInput}\"{$ro}>{$langVal}</textarea>";
            } else {
                $html .= "<input type=\"text\" id=\"{$inputId}\" class=\"ib-input\" value=\"{$langVal}\""
                       . " oninput=\"{$onInput}\"{$ro}>";
            }
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = (string) $value;
        if ($val === '') return '';

        $decoded = json_decode($val, true);
        if (!is_array($decoded)) return Output::e($val);

        $languages = (array) ($options['languages'] ?? self::DEFAULT_LANGUAGES);

        // Try current language first, then first available
        // For now, return first non-empty value
        foreach ($languages as $lang) {
            if (!empty($decoded[$lang])) {
                return Output::e($decoded[$lang]);
            }
        }

        // Fallback: first non-empty value regardless of language order
        foreach ($decoded as $text) {
            if (trim((string) $text) !== '') {
                return Output::e((string) $text);
            }
        }

        return '';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // JSON search — search across all language values
        return [
            'where'  => "`{$field}` LIKE ?",
            'params' => ["%{$searchValue}%"],
        ];
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str       = trim((string) $raw);
        $languages = (array) ($options['languages'] ?? self::DEFAULT_LANGUAGES);

        if ($str === '') return '{}';

        $decoded = json_decode($str, true);
        if (!is_array($decoded)) return '{}';

        // Only keep expected language keys, trim values
        $clean = [];
        foreach ($languages as $lang) {
            $clean[$lang] = trim((string) ($decoded[$lang] ?? ''));
        }

        return json_encode($clean, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label     = $options['label'] ?? $field;
        $languages = (array) ($options['languages'] ?? self::DEFAULT_LANGUAGES);

        if (!$required) return [];

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return ["{$label} is required."];
        }

        // Check that at least one language has a value
        foreach ($languages as $lang) {
            if (!empty(trim((string) ($decoded[$lang] ?? '')))) {
                return [];
            }
        }

        return ["{$label} requires at least one language to have a value."];
    }
}
