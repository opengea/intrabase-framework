<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * TagsComponent
 *
 * Tag input rendered as comma-separated tags with visual badges.
 *
 * options:
 *   delimiter   — tag separator character (default: ',')
 *   max_tags    — maximum number of tags allowed (default: null = unlimited)
 *   suggestions — array of suggested tag values (default: [])
 */
final class TagsComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name      = Output::attr($field);
        $id        = 'field_' . Output::attr($field);
        $val       = Output::attr((string) $value);
        $delimiter = $options['delimiter'] ?? ',';
        $maxTags   = isset($options['max_tags']) ? (int) $options['max_tags'] : 0;
        $req       = !empty($options['required']) ? ' required' : '';
        $ro        = !empty($options['readonly']) ? ' readonly' : '';
        $ph        = Output::attr($options['placeholder'] ?? 'Add tags...');

        $suggestionsAttr = '';
        if (!empty($options['suggestions']) && is_array($options['suggestions'])) {
            $listId = 'suggest_' . Output::attr($field);
            $suggestionsAttr = " list=\"{$listId}\"";
        }

        // Container with visual tags and hidden input
        $html = "<div class=\"ib-tags\" data-field=\"{$name}\" data-delimiter=\"" . Output::attr($delimiter) . "\""
              . ($maxTags > 0 ? " data-max-tags=\"{$maxTags}\"" : '') . ">"
              . "<div class=\"ib-tags__display\" id=\"tags_display_{$name}\"></div>"
              . "<input type=\"text\" id=\"tags_input_{$name}\" class=\"ib-input ib-input--tags\""
              . " placeholder=\"{$ph}\"{$suggestionsAttr}{$ro}>"
              . "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\"{$req}>"
              . "</div>";

        // Datalist for suggestions
        if (!empty($options['suggestions']) && is_array($options['suggestions'])) {
            $listId = 'suggest_' . Output::attr($field);
            $html .= "<datalist id=\"{$listId}\">";
            foreach ($options['suggestions'] as $suggestion) {
                $html .= '<option value="' . Output::attr((string) $suggestion) . '">';
            }
            $html .= '</datalist>';
        }

        // Inline JS to render tags and handle input
        $safeDelim = Output::js($delimiter);
        $html .= "<script>"
               . "(function(){"
               . "var hidden=document.getElementById('{$id}'),"
               . "display=document.getElementById('tags_display_{$name}'),"
               . "input=document.getElementById('tags_input_{$name}'),"
               . "delim={$safeDelim};"
               . "function renderTags(){"
               . "var tags=hidden.value?hidden.value.split(delim).map(function(t){return t.trim()}).filter(Boolean):[];"
               . "display.innerHTML=tags.map(function(t,i){"
               . "return '<span class=\"ib-tag\">'+t.replace(/</g,'&lt;')+'<button type=\"button\" class=\"ib-tag__remove\" data-index=\"'+i+'\">&times;</button></span>';"
               . "}).join('');"
               . "display.querySelectorAll('.ib-tag__remove').forEach(function(btn){"
               . "btn.onclick=function(){tags.splice(parseInt(this.dataset.index),1);hidden.value=tags.join(delim+' ');renderTags();};"
               . "});"
               . "}"
               . "input.addEventListener('keydown',function(e){"
               . "if(e.key==='Enter'||e.key===delim){e.preventDefault();"
               . "var v=this.value.trim();if(!v)return;"
               . "var tags=hidden.value?hidden.value.split(delim).map(function(t){return t.trim()}).filter(Boolean):[];"
               . "if(tags.indexOf(v)===-1)tags.push(v);"
               . "hidden.value=tags.join(delim+' ');this.value='';renderTags();"
               . "}});"
               . "renderTags();"
               . "})();"
               . "</script>";

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';

        $delimiter = $options['delimiter'] ?? ',';
        $tags = array_filter(array_map('trim', explode($delimiter, $val)));

        if (empty($tags)) return '';

        $html = '';
        foreach ($tags as $tag) {
            $html .= '<span class="ib-tag">' . Output::e($tag) . '</span> ';
        }

        return trim($html);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null; // Default LIKE search works for comma-separated values
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str       = trim((string) $raw);
        $delimiter = $options['delimiter'] ?? ',';

        if ($str === '') return '';

        $tags = array_filter(array_map('trim', explode($delimiter, $str)));

        // Remove duplicates
        $tags = array_unique($tags);

        // Enforce max_tags
        if (isset($options['max_tags']) && (int) $options['max_tags'] > 0) {
            $tags = array_slice($tags, 0, (int) $options['max_tags']);
        }

        return implode($delimiter . ' ', $tags);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }

        if (isset($options['max_tags']) && (int) $options['max_tags'] > 0 && $str !== '') {
            $delimiter = $options['delimiter'] ?? ',';
            $count     = count(array_filter(array_map('trim', explode($delimiter, $str))));
            $max       = (int) $options['max_tags'];
            if ($count > $max) {
                return ["{$label} allows a maximum of {$max} tags."];
            }
        }

        return [];
    }
}
