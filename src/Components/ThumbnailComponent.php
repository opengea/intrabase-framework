<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * ThumbnailComponent
 *
 * Read-only thumbnail display (simpler than PictureComponent).
 *
 * options:
 *   path    — Filesystem path for images
 *   webpath — URL path for image display
 *   size    — Thumbnail dimension in pixels (default: 50)
 */
final class ThumbnailComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        // Read-only: same as display
        return $this->display($field, $value, $options);
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current = trim((string) $value);
        $webpath = rtrim((string) ($options['webpath'] ?? ''), '/');
        $size    = (int) ($options['size'] ?? 50);

        if ($current === '') {
            return '<span class="ib-thumbnail--empty">'
                 . '<i class="fa-solid fa-image" aria-hidden="true"></i>'
                 . '</span>';
        }

        $imgUrl   = Output::attr($webpath . '/' . $current);
        $safeName = Output::e($current);

        return "<img src=\"{$imgUrl}\" alt=\"{$safeName}\""
             . " class=\"ib-thumbnail__img\""
             . " style=\"max-width:{$size}px;max-height:{$size}px;\">";
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
        if ($required && trim((string) $value) === '') {
            return ["{$label} is required."];
        }
        return [];
    }
}
