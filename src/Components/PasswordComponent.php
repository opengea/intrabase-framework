<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * PasswordComponent
 *
 * Password input with show/hide toggle and optional confirmation field.
 * Sanitize returns the raw value — hashing should be handled by the module.
 *
 * options:
 *   show_confirm — render a confirmation field (default: true)
 *   min_length   — minimum password length
 */
final class PasswordComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name        = Output::attr($field);
        $id          = 'field_' . Output::attr($field);
        $req         = !empty($options['required']) ? ' required' : '';
        $ro          = !empty($options['readonly']) ? ' readonly' : '';
        $showConfirm = ($options['show_confirm'] ?? true) !== false;
        $showValue   = !empty($options['show_value']);
        $minLen      = isset($options['min_length']) ? ' minlength="' . (int) $options['min_length'] . '"' : '';
        $placeholder = empty($options['required']) ? ' placeholder="••••••••"' : '';
        $safeVal     = $showValue ? Output::attr((string) $value) : '';

        $html = "<div class=\"ib-password\">"
              . "<div class=\"ib-password__wrap\">"
              . "<input type=\"password\" name=\"{$name}\" id=\"{$id}\" value=\"{$safeVal}\""
              . " class=\"ib-input ib-input--password\" autocomplete=\"new-password\"{$minLen}{$placeholder}{$req}{$ro}>"
              . "<span class=\"ib-password__eye\""
              . " onclick=\"var i=document.getElementById('{$id}');"
              . "i.type=i.type==='password'?'text':'password';"
              . "this.querySelector('i').classList.toggle('fa-eye');"
              . "this.querySelector('i').classList.toggle('fa-eye-slash')\""
              . " aria-label=\"Toggle password visibility\">"
              . "<i class=\"fa-solid fa-eye\" aria-hidden=\"true\"></i>"
              . "</span>"
              . "</div>";

        if ($showConfirm) {
            $html .= "<div class=\"ib-input-group ib-password__confirm\">"
                   . "<input type=\"password\" name=\"{$name}_confirm\" id=\"{$id}_confirm\" value=\"\""
                   . " class=\"ib-input ib-input--password\" autocomplete=\"new-password\""
                   . " placeholder=\"" . htmlspecialchars(__('confirm_password'), ENT_QUOTES, 'UTF-8') . "\"{$ro}>"
                   . "</div>";
        }

        $html .= "</div>";
        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = (string) $value;
        if ($val === '') return '';
        return '<span class="ib-password-display">' . str_repeat('&bull;', 8) . '</span>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // Password fields should not be searchable
        return ['where' => '1=0', 'params' => []];
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        // Return raw value — hashing should happen in the module
        return (string) $raw;
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = (string) $value;
        $label = $options['label'] ?? $field;
        $errors = [];

        if ($required && $str === '') {
            return ["{$label} is required."];
        }

        if ($str === '') {
            return [];
        }

        $minLen = (int) ($options['min_length'] ?? 0);
        if ($minLen > 0 && mb_strlen($str) < $minLen) {
            $errors[] = "{$label} must be at least {$minLen} characters.";
        }

        // Check confirmation field if present
        $showConfirm = ($options['show_confirm'] ?? true) !== false;
        if ($showConfirm) {
            $confirm = (string) ($_POST[$field . '_confirm'] ?? '');
            if ($str !== $confirm) {
                $errors[] = "{$label} confirmation does not match.";
            }
        }

        return $errors;
    }
}
