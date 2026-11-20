<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * FileComponent
 *
 * File upload component with icon display based on extension.
 * Actual file handling (upload/delete) should be done by the module, not the component.
 *
 * options:
 *   path              — Filesystem path for files
 *   webpath           — URL path for file downloads
 *   max_size          — Maximum file size in bytes (default: 0 = unlimited)
 *   allowed_extensions — Array of allowed extensions (default: [] = all)
 */
final class FileComponent implements ComponentInterface
{
    private const ICON_MAP = [
        // Images
        'jpg'  => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png'  => 'fa-file-image',
        'gif'  => 'fa-file-image',
        'svg'  => 'fa-file-image',
        'webp' => 'fa-file-image',
        'bmp'  => 'fa-file-image',
        'ico'  => 'fa-file-image',
        // PDF
        'pdf'  => 'fa-file-pdf',
        // Word
        'doc'  => 'fa-file-word',
        'docx' => 'fa-file-word',
        'odt'  => 'fa-file-word',
        // Excel
        'xls'  => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'ods'  => 'fa-file-excel',
        'csv'  => 'fa-file-excel',
        // Archive
        'zip'  => 'fa-file-archive',
        'rar'  => 'fa-file-archive',
        'gz'   => 'fa-file-archive',
        'tar'  => 'fa-file-archive',
        '7z'   => 'fa-file-archive',
        // Video
        'mp4'  => 'fa-file-video',
        'avi'  => 'fa-file-video',
        'mov'  => 'fa-file-video',
        'mkv'  => 'fa-file-video',
        'webm' => 'fa-file-video',
        // Audio
        'mp3'  => 'fa-file-audio',
        'wav'  => 'fa-file-audio',
        'ogg'  => 'fa-file-audio',
        'flac' => 'fa-file-audio',
        // Code
        'php'  => 'fa-file-code',
        'js'   => 'fa-file-code',
        'css'  => 'fa-file-code',
        'html' => 'fa-file-code',
        'json' => 'fa-file-code',
        'xml'  => 'fa-file-code',
        'sql'  => 'fa-file-code',
    ];

    public function render(string $field, mixed $value, array $options = []): string
    {
        $name      = Output::attr($field);
        $id        = 'field_' . Output::attr($field);
        $current   = trim((string) $value);
        $path      = rtrim((string) ($options['path'] ?? ''), '/');
        $webpath   = rtrim((string) ($options['webpath'] ?? ''), '/');
        $maxSize   = (int) ($options['max_size'] ?? 0);
        $allowed   = (array) ($options['allowed_extensions'] ?? []);
        $req       = !empty($options['required']) ? ' required' : '';
        $ro        = !empty($options['readonly']);

        $html = "<div class=\"ib-file-upload\">";

        // Show current file if exists
        if ($current !== '') {
            $ext      = strtolower(pathinfo($current, PATHINFO_EXTENSION));
            $icon     = self::ICON_MAP[$ext] ?? 'fa-file';
            $safeName = Output::e($current);
            $fileUrl  = Output::attr($webpath . '/' . $current);

            // File size
            $sizeStr = '';
            $fullPath = $path . '/' . $current;
            if ($path !== '' && file_exists($fullPath)) {
                $sizeStr = ' <span class="ib-file__size">(' . $this->humanSize((int) filesize($fullPath)) . ')</span>';
            }

            $html .= "<div class=\"ib-file__current\">"
                   . "<i class=\"fa-solid {$icon}\" aria-hidden=\"true\"></i> "
                   . "<a href=\"{$fileUrl}\" target=\"_blank\" class=\"ib-file__link\">{$safeName}</a>"
                   . $sizeStr;

            if (!$ro) {
                $html .= " <label class=\"ib-file__delete\">"
                       . "<input type=\"checkbox\" name=\"{$name}_delete\" value=\"1\"> Delete"
                       . "</label>";
            }

            $html .= "</div>";
            $html .= "<input type=\"hidden\" name=\"{$name}_current\" value=\"{$safeName}\">";
        }

        // Upload input
        if (!$ro) {
            $accept = '';
            if (!empty($allowed)) {
                $accept = ' accept=".' . implode(',.', $allowed) . '"';
            }
            $html .= "<input type=\"file\" name=\"{$name}\" id=\"{$id}\""
                   . " class=\"ib-input ib-file__input\"{$accept}{$req}>";

            if ($maxSize > 0) {
                $html .= "<small class=\"ib-file__hint\">Max: " . $this->humanSize($maxSize) . "</small>";
            }
        }

        $html .= "</div>";
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $current = trim((string) $value);
        if ($current === '') {
            return '<span class="ib-file--empty">--</span>';
        }

        $webpath  = rtrim((string) ($options['webpath'] ?? ''), '/');
        $ext      = strtolower(pathinfo($current, PATHINFO_EXTENSION));
        $icon     = self::ICON_MAP[$ext] ?? 'fa-file';
        $safeName = Output::e($current);
        $fileUrl  = Output::attr($webpath . '/' . $current);

        return "<a href=\"{$fileUrl}\" target=\"_blank\" class=\"ib-file__link\">"
             . "<i class=\"fa-solid {$icon}\" aria-hidden=\"true\"></i> {$safeName}</a>";
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
        $label   = $options['label'] ?? $field;
        $val     = trim((string) $value);
        $allowed = (array) ($options['allowed_extensions'] ?? []);

        if ($required && $val === '') {
            return ["{$label} is required."];
        }

        if ($val !== '' && !empty($allowed)) {
            $ext = strtolower(pathinfo($val, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                return ["{$label}: extension .{$ext} is not allowed."];
            }
        }

        return [];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $val = $bytes / (1024 ** $i);
        return number_format($val, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
