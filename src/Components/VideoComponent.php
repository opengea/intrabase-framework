<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * VideoComponent
 *
 * Video embed/upload component. Detects YouTube and Vimeo URLs
 * and renders embedded players.
 *
 * options:
 *   path      — Filesystem path for uploaded videos
 *   webpath   — URL path for video display
 *   providers — Array of supported providers (default: ['youtube', 'vimeo'])
 */
final class VideoComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name = Output::attr($field);
        $id   = 'field_' . Output::attr($field);
        $val  = Output::attr(trim((string) $value));
        $req  = !empty($options['required']) ? ' required' : '';
        $ro   = !empty($options['readonly']) ? ' readonly' : '';

        return "<div class=\"ib-video-input\">"
             . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input\" placeholder=\"YouTube/Vimeo URL or embed code\"{$req}{$ro}>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '<span class="ib-video--empty">--</span>';
        }

        // Detect YouTube
        $youtubeId = $this->extractYoutubeId($url);
        if ($youtubeId !== null) {
            $safeId = Output::attr($youtubeId);
            return "<div class=\"ib-video__embed\">"
                 . "<iframe src=\"https://www.youtube.com/embed/{$safeId}\""
                 . " width=\"560\" height=\"315\" frameborder=\"0\""
                 . " allow=\"accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture\""
                 . " allowfullscreen></iframe>"
                 . "</div>";
        }

        // Detect Vimeo
        $vimeoId = $this->extractVimeoId($url);
        if ($vimeoId !== null) {
            $safeId = Output::attr($vimeoId);
            return "<div class=\"ib-video__embed\">"
                 . "<iframe src=\"https://player.vimeo.com/video/{$safeId}\""
                 . " width=\"560\" height=\"315\" frameborder=\"0\""
                 . " allow=\"autoplay; fullscreen; picture-in-picture\""
                 . " allowfullscreen></iframe>"
                 . "</div>";
        }

        // Fallback: show as link
        $safeUrl = Output::attr($url);
        return "<a href=\"{$safeUrl}\" target=\"_blank\" class=\"ib-video__link\">"
             . "<i class=\"fa-solid fa-video\" aria-hidden=\"true\"></i> "
             . Output::e($url) . "</a>";
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

        return [];
    }

    private function extractYoutubeId(string $url): ?string
    {
        // Match youtube.com/watch?v=ID, youtu.be/ID, youtube.com/embed/ID
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractVimeoId(string $url): ?string
    {
        // Match vimeo.com/ID or player.vimeo.com/video/ID
        if (preg_match('/(?:vimeo\.com\/(?:video\/)?)(\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
