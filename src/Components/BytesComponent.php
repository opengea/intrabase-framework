<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * BytesComponent
 *
 * Renders a number input for byte sizes, with a human-readable display.
 * Equivalent to KMS bytes.php component.
 *
 * options:
 *   unit    — Display unit: 'auto' (default), 'B', 'KB', 'MB', 'GB'
 */
final class BytesComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name  = Output::attr($field);
        $id    = 'field_' . Output::attr($field);
        $val   = Output::attr((string) (int) $value);
        $req   = !empty($options['required']) ? ' required' : '';
        $ro    = !empty($options['readonly']) ? ' readonly' : '';
        $human = $this->humanSize((int) $value);

        return "<div class=\"ib-input-group\">"
             . "<input type=\"number\" name=\"{$name}\" id=\"{$id}\" value=\"{$val}\""
             . " class=\"ib-input\" step=\"1\" min=\"0\"{$req}{$ro}>"
             . "<span class=\"ib-input-group__suffix ib-bytes-display\">{$human}</span>"
             . "</div>";
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        return Output::e($this->humanSize((int) $value));
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        return null;
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        return max(0, (int) $raw);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $label = $options['label'] ?? $field;
        if ($required && ((int) $value) === 0 && trim((string) $value) === '') {
            return ["{$label} is required."];
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
