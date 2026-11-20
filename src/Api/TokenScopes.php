<?php

declare(strict_types=1);

namespace Intrabase\Api;

/**
 * Parses the `ib_api_tokens.scopes` column (CSV or JSON array) and answers
 * scope questions shared by ApiController::requireScope() and the Bearer
 * authentication gate.
 *
 * Extracted so a WebDAV app password (scope `dav`) can be recognised and
 * refused by the REST API without depending on the full ApiController.
 *
 * @package Intrabase\Api
 */
final class TokenScopes
{
    /**
     * Parse a token's `scopes` column into a list of scope names.
     *
     * Same logic ApiController::requireScope() used before this extraction:
     * a JSON array if the trimmed value starts with '[', otherwise a
     * comma-separated list, each entry trimmed.
     *
     * @return string[]
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if (!$raw) {
            return [];
        }

        // Try JSON array first
        if ($raw[0] === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_map('strval', $decoded);
            }
        }

        // Comma-separated
        return array_map('trim', explode(',', $raw));
    }

    /**
     * True if the scope list contains the WebDAV app-password scope `dav`.
     *
     * Despite the name (kept to match the brief), this does not require
     * `dav` to be the ONLY scope: a token minted as a WebDAV app password
     * (spec §6) always carries `dav`, possibly alongside other scopes, and
     * must never authenticate against /api/v1/* regardless of what else
     * it lists.
     */
    public static function isDavOnly(string $raw): bool
    {
        return in_array('dav', self::parse($raw), true);
    }

    /**
     * True if the token was minted for the Claude connector (scope `mcp`,
     * ADR 003). Like an app password, it only opens its own door (/mcp)
     * and must never authenticate against /api/v1/*.
     */
    public static function isMcp(string $raw): bool
    {
        return in_array('mcp', self::parse($raw), true);
    }
}
