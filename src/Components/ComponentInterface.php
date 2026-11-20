<?php

declare(strict_types=1);

namespace Intrabase\Components;

/**
 * ComponentInterface
 *
 * Contract for all field component implementations.
 *
 * A component handles the complete lifecycle of a form field:
 *   - render:       HTML for the dataEditor (edit form)
 *   - display:      HTML for the dataBrowser (list) and dataDetailer (read-only view)
 *   - searchFilter: custom WHERE clause for dataBrowser search
 *   - sanitize:     cleans/normalizes the raw POST value for DB storage
 *   - validate:     checks the sanitized value and returns error messages
 *
 * @package Intrabase\Components
 */
interface ComponentInterface
{
    /**
     * Render the HTML input element for the dataEditor (edit form).
     *
     * @param string $field    Field name (used as input name attribute)
     * @param mixed  $value    Current value (from DB row or default)
     * @param array<string, mixed> $options Component-specific options
     * @return string  Safe HTML string ready to echo
     */
    public function render(string $field, mixed $value, array $options = []): string;

    /**
     * Transform the raw DB value for display in the dataBrowser (list)
     * and dataDetailer (read-only detail view).
     *
     * Equivalent to KMS output_filter(). Should return safe HTML.
     * Default implementation returns the escaped value unchanged.
     *
     * @param string   $field   Field name
     * @param mixed    $value   Raw value from DB row
     * @param array<string, mixed> $options Component-specific options
     * @param int|null $rowId   Row ID (for components that need record context, e.g. picture URLs)
     * @return string  Safe HTML for display
     */
    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string;

    /**
     * Modify the search query for this field in the dataBrowser.
     *
     * Equivalent to KMS search_filter(). Allows components to translate
     * user search input (e.g. visible label text) into the actual DB value
     * (e.g. a key or ID) for the WHERE clause.
     *
     * Return null to use default LIKE search. Return an array with:
     *   'where'  => SQL WHERE fragment (use ? for bound parameters)
     *   'params' => array of bound parameter values
     *
     * @param string $field        Field name
     * @param string $searchValue  User search term
     * @param string $operator     Query operator ('=', 'LIKE', etc.)
     * @param array<string, mixed> $options Component-specific options
     * @return array{where: string, params: array}|null  Custom WHERE or null for default
     */
    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array;

    /**
     * Sanitize a raw POST value for DB storage.
     *
     * Called before validation and DB write. Should not throw exceptions —
     * return a cleaned value or an empty string if the input is unusable.
     *
     * @param string $field   Field name
     * @param mixed  $raw     Raw value from $_POST
     * @param array<string, mixed> $options Component-specific options
     * @return mixed  Sanitized value ready for prepared statement
     */
    public function sanitize(string $field, mixed $raw, array $options = []): mixed;

    /**
     * Validate a (sanitized) value.
     *
     * @param string $field    Field name
     * @param mixed  $value    Sanitized value
     * @param bool   $required Whether the field is marked as required
     * @param array<string, mixed> $options Component-specific options
     * @return string[]  Error messages (empty array = valid)
     */
    public function validate(string $field, mixed $value, bool $required, array $options = []): array;
}
