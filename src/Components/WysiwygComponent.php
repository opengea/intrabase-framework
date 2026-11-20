<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * WysiwygComponent
 *
 * WYSIWYG rich text editor (CKEditor/TinyMCE placeholder).
 * The textarea is given the class "ib-wysiwyg" for JS editor initialization.
 *
 * options:
 *   editor  — 'ckeditor' or 'tinymce' (default: 'ckeditor')
 *   toolbar — toolbar preset: 'basic', 'standard', 'full' (default: 'standard')
 *   height  — editor height in pixels (default: 300)
 */
final class WysiwygComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $val     = Output::e((string) $value);
        $req     = !empty($options['required']) ? ' required' : '';
        $ro      = !empty($options['readonly']) ? ' readonly' : '';
        $editor  = Output::attr($options['editor'] ?? 'ckeditor');
        $toolbar = Output::attr($options['toolbar'] ?? 'standard');
        $height  = (int) ($options['height'] ?? 300);

        return "<div class=\"ib-wysiwyg-wrap\">"
             . "<textarea name=\"{$name}\" id=\"{$id}\""
             . " class=\"ib-textarea ib-wysiwyg\""
             . " data-editor=\"{$editor}\" data-toolbar=\"{$toolbar}\""
             . " style=\"min-height:{$height}px\"{$req}{$ro}>{$val}</textarea>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = (string) $value;
        if ($val === '') return '';

        $plain = strip_tags($val);
        return Output::truncate($plain, 150);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = (string) $raw;

        // Strip <script> tags and their content
        $str = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $str);

        // Remove event handler attributes (onclick, onerror, onload, etc.)
        $str = preg_replace('/\s+on\w+\s*=\s*(["\']).*?\1/i', '', $str);
        $str = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $str);

        // Remove javascript: protocol from href/src attributes
        $str = preg_replace('/(\s+(?:href|src)\s*=\s*["\']?)\s*javascript:/i', '$1#', $str);

        // Remove <iframe>, <object>, <embed>, <applet>, <form> tags
        $str = preg_replace('/<\s*\/?\s*(iframe|object|embed|applet|form)\b[^>]*>/i', '', $str);

        return $str;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $plain = trim(strip_tags((string) $value));
        $label = $options['label'] ?? $field;

        if ($required && $plain === '') {
            return ["{$label} is required."];
        }

        return [];
    }
}
