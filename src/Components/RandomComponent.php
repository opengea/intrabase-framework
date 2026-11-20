<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * RandomComponent
 *
 * Auto-generated random string displayed as a read-only field.
 * Generates a value on first save if empty.
 *
 * options:
 *   length  — length of the random string (default: 16)
 *   charset — 'alphanumeric', 'hex', 'numeric' (default: 'alphanumeric')
 */
final class RandomComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $val     = Output::attr((string) $value);
        $length  = (int) ($options['length'] ?? 16);
        $charset = $options['charset'] ?? 'alphanumeric';
        $safeCharset = Output::attr($charset);

        return "<div class=\"ib-input-group\">"
             . "<input type=\"text\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input ib-input--random\" readonly>"
             . "<button type=\"button\" class=\"ib-input-group__suffix ib-btn ib-btn--icon\""
             . " title=\"Regenerate\" onclick=\""
             . "var cs='{$safeCharset}',len={$length},chars;"
             . "if(cs==='hex')chars='0123456789abcdef';"
             . "else if(cs==='numeric')chars='0123456789';"
             . "else chars='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';"
             . "var r='';for(var i=0;i<len;i++)r+=chars.charAt(Math.floor(Math.random()*chars.length));"
             . "document.getElementById('{$id}').value=r\">"
             . "<i class=\"fa-solid fa-rotate\" aria-hidden=\"true\"></i>"
             . "</button>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';
        return Output::e($val);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);

        // If empty, generate a random string
        if ($str === '') {
            $str = $this->generate(
                (int) ($options['length'] ?? 16),
                $options['charset'] ?? 'alphanumeric'
            );
        }

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

    /**
     * Generate a cryptographically secure random string.
     */
    private function generate(int $length, string $charset): string
    {
        $chars = match ($charset) {
            'hex'     => '0123456789abcdef',
            'numeric' => '0123456789',
            default   => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
        };

        $max    = strlen($chars) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }

        return $result;
    }
}
