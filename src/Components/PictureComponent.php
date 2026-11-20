<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * PictureComponent
 *
 * Image upload with preview thumbnail.
 *
 * options:
 *   path         — Filesystem path for images
 *   webpath      — URL path for image display
 *   thumb_size   — Thumbnail dimension in pixels for list view (default: 80)
 *   edit_size    — Preview dimension in pixels for edit form (default: thumb_size)
 *   image_size   — Maximum image dimension (default: 800)
 *   show_preview — Show preview in edit form (default: true)
 *   round        — Use circular shape (border-radius: 50%) instead of default 8px
 */
final class PictureComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name        = Output::attr($field);
        $id          = 'field_' . Output::attr($field);
        $current     = trim((string) $value);
        $webpath     = rtrim((string) ($options['webpath'] ?? ''), '/');
        $thumbSize   = (int) ($options['thumb_size'] ?? 80);
        $editSize    = (int) ($options['edit_size'] ?? $thumbSize);
        $showPreview = (bool) ($options['show_preview'] ?? true);
        $round       = !empty($options['round']);
        $radius      = $round ? '50%' : '8px';
        $req         = !empty($options['required']) ? ' required' : '';
        $ro          = !empty($options['readonly']);

        $displayValue = isset($options['display_value']) ? Output::e((string)$options['display_value']) : '';

        $html = "<div class=\"ib-picture-upload\">";

        // Show preview if image exists
        if ($current !== '' && $showPreview) {
            $imgUrl  = Output::attr($webpath . '/' . $current);
            $safeName = Output::e($current);

            if ($displayValue !== '') {
                $html .= "<div class=\"ib-picture__header\">";
            }

            $html .= "<div class=\"ib-picture__preview\">"
                   . "<img src=\"{$imgUrl}\" alt=\"{$safeName}\""
                   . " class=\"ib-picture__thumb\""
                   . " style=\"width:{$editSize}px;height:{$editSize}px;border-radius:{$radius};object-fit:cover;\">"
                   . "</div>";

            if ($displayValue !== '') {
                $html .= "<div class=\"ib-picture__name\">{$displayValue}</div>"
                       . "</div>";
            }

            if (!$ro) {
                $html .= "<label class=\"ib-picture__delete\">"
                       . "<input type=\"checkbox\" name=\"{$name}_delete\" value=\"1\"> Delete"
                       . "</label>";
            }

            $html .= "<input type=\"hidden\" name=\"{$name}_current\" value=\"{$safeName}\">";
        } elseif ($displayValue !== '' && $current === '') {
            // No image but display_value set: show initial + name
            $initial = strtoupper(mb_substr($options['display_value'], 0, 1));
            $html .= "<div class=\"ib-picture__header\">"
                   . "<div class=\"ib-picture__preview\">"
                   . "<span style=\"display:inline-flex;align-items:center;justify-content:center;"
                   . "width:{$editSize}px;height:{$editSize}px;border-radius:{$radius};"
                   . "background:var(--ib-border,#333);color:var(--ib-text);font-size:2.5rem;font-weight:600\">"
                   . Output::e($initial) . "</span>"
                   . "</div>"
                   . "<div class=\"ib-picture__name\">{$displayValue}</div>"
                   . "</div>";
        }

        // Upload input
        if (!$ro) {
            $html .= "<input type=\"file\" name=\"{$name}\" id=\"{$id}\""
                   . " class=\"ib-input ib-picture__input\" accept=\"image/*\"{$req}>";
        }

        $html .= "</div>";
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current   = trim((string) $value);
        $webpath   = rtrim((string) ($options['webpath'] ?? ''), '/');
        $thumbSize = (int) ($options['thumb_size'] ?? 80);
        $round     = !empty($options['round']);
        $radius    = $round ? '50%' : '8px';

        if ($current === '') {
            return '<span class="ib-picture--empty">'
                 . '<i class="fa-solid fa-image" aria-hidden="true"></i>'
                 . '</span>';
        }

        $imgUrl   = Output::attr($webpath . '/' . $current);
        $safeName = Output::e($current);

        return "<img src=\"{$imgUrl}\" alt=\"{$safeName}\""
             . " class=\"ib-picture__thumb\""
             . " style=\"width:{$thumbSize}px;height:{$thumbSize}px;border-radius:{$radius};object-fit:cover;\">";
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return trim((string) $raw);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;
        $val   = trim((string) $value);

        if ($required && $val === '') {
            return ["{$label} is required."];
        }

        if ($val !== '') {
            $ext = strtolower(pathinfo($val, PATHINFO_EXTENSION));
            $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico'];
            if (!in_array($ext, $imageExts, true)) {
                return ["{$label}: file must be an image."];
            }
        }

        return [];
    }
}
