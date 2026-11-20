<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * CheckboxComponent
 *
 * Boolean checkbox field (0/1).
 * Renders a checkbox with a hidden input to ensure a value is always submitted.
 * Displays a check or cross icon.
 */
final class CheckboxComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name    = Output::attr($field);
        $id      = 'field_' . Output::attr($field);
        $checked = ((int) $value === 1) ? ' checked' : '';
        $req     = !empty($options['required']) ? ' required' : '';
        $ro      = !empty($options['readonly']) ? ' disabled' : '';
        // La fila del formulari ja pinta l'etiqueta del camp (.ib-form__label);
        // repetir-la aqui la deixava dos cops a la mateixa linia. Nomes es
        // pinta si el modul en demana una de propia amb 'inline_label'.
        $label   = Output::e((string) ($options['inline_label'] ?? ''));

        return "<div class=\"ib-checkbox\">"
             . "<input type=\"hidden\" name=\"{$name}\" value=\"0\">"
             . "<input type=\"checkbox\" name=\"{$name}\" id=\"{$id}\" value=\"1\""
             . " class=\"ib-checkbox__input\"{$checked}{$req}{$ro}>"
             . ($label !== '' ? "<label for=\"{$id}\" class=\"ib-checkbox__label\"> {$label}</label>" : '')
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        if ((int) $value === 1) {
            return '<span class="ib-checkbox--checked"><i class="fa-solid fa-check" aria-hidden="true"></i></span>';
        }
        return '<span class="ib-checkbox--unchecked"><i class="fa-solid fa-xmark" aria-hidden="true"></i></span>';
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return ((int) $raw === 1) ? '1' : '0';
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        if ($required && (string) $value !== '1') {
            $label = $options['label'] ?? $field;
            return ["{$label} must be checked."];
        }
        return [];
    }
}
