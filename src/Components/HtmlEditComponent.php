<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * HtmlEditComponent
 *
 * HTML code editor rendered as a plain textarea with a preview toggle.
 *
 * options:
 *   rows         — textarea rows (default: 20)
 *   allowed_tags — string of allowed HTML tags for sanitization (default: common safe tags)
 */
final class HtmlEditComponent implements ComponentInterface
{
    private const DEFAULT_ALLOWED_TAGS = '<p><br><div><span><h1><h2><h3><h4><h5><h6>'
        . '<a><img><ul><ol><li><table><thead><tbody><tr><th><td>'
        . '<strong><b><em><i><u><s><blockquote><pre><code><hr><sub><sup>';

    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = Output::e((string) $value);
        $rows = (int) ($options['rows'] ?? 20);
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';

        $previewId = 'preview_' . Output::attr($field);

        return "<div class=\"ib-htmledit\">"
             . "<div class=\"ib-htmledit__toolbar\">"
             . "<button type=\"button\" class=\"ib-btn ib-btn--sm\" "
             . "onclick=\"var p=document.getElementById('{$previewId}'),t=document.getElementById('{$id}');"
             . "if(p.style.display==='none'){p.innerHTML=t.value;p.style.display='block';t.style.display='none';this.textContent='Edit'}"
             . "else{p.style.display='none';t.style.display='block';this.textContent='Preview'}\">Preview</button>"
             . "</div>"
             . "<textarea name=\"{$name}\" id=\"{$id}\" rows=\"{$rows}\""
             . " class=\"ib-textarea ib-textarea--code\" spellcheck=\"false\"{$req}{$ro}>{$val}</textarea>"
             . "<div id=\"{$previewId}\" class=\"ib-htmledit__preview\" style=\"display:none\"></div>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = (string) $value;
        if ($val === '') return '';

        $allowedTags = $options['allowed_tags'] ?? self::DEFAULT_ALLOWED_TAGS;
        $stripped    = strip_tags($val, $allowedTags);
        $plain       = strip_tags($val);

        return Output::truncate($plain, 120);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = (string) $raw;

        // When skip_sanitize is set, only remove dangerous attributes (no tag stripping)
        if (empty($options['skip_sanitize'])) {
            $allowedTags = $options['allowed_tags'] ?? self::DEFAULT_ALLOWED_TAGS;
            $str = strip_tags($str, $allowedTags);
        }

        // Remove event handler attributes (onclick, onerror, onload, etc.)
        $str = preg_replace('/\s+on\w+\s*=\s*(["\']).*?\1/i', '', $str);
        $str = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $str);

        // Remove javascript: protocol from href/src attributes
        $str = preg_replace('/(\s+(?:href|src)\s*=\s*["\']?)\s*javascript:/i', '$1#', $str);

        // Remove <script> tags even when skip_sanitize is set
        $str = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $str);

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
