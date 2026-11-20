<?php

declare(strict_types=1);

namespace Intrabase\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture test — forbid `new PDO(...)` outside Infrastructure / Database.
 *
 * Rule: application and domain code MUST depend on
 * Intrabase\Domain\Contracts\ConnectionInterface, never on PDO directly.
 * This keeps controllers and use cases testable without a real database,
 * and avoids repeating dual-DB wiring across controllers.
 *
 * Pre-existing `new PDO(` sites are listed in LEGACY_ALLOWED_FILES as a
 * technical-debt ledger. Migrate one, remove its entry from the list —
 * the test stays the same, the debt list shrinks. When the list is empty
 * it can be removed together with the Infrastructure/Database exceptions
 * becoming the only allowed sites.
 *
 * @coversNothing
 */
final class NewPdoUsageTest extends TestCase
{
    /**
     * Directories where `new PDO(` is legitimate.
     * Infrastructure is the sanctioned home for concrete driver usage.
     */
    private const ALLOWED_PREFIXES = [
        'src/Infrastructure/',
        'src/Database/', // Connection is the canonical PDO wrapper
    ];

    /**
     * Pre-existing sites tolerated until migrated to ConnectionInterface.
     * DO NOT ADD ENTRIES. Only shrink.
     */
    private const LEGACY_ALLOWED_FILES = [
        'src/Core/Application.php',        // master connection for the tenant lookup
    ];

    public function testNoNewPdoOutsideAllowedSites(): void
    {
        $root    = dirname(__DIR__, 3);
        $srcRoot = $root . '/src';

        $offenders = [];
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $srcRoot,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $info) {
            if (!$info->isFile()) continue;
            if ($info->getExtension() !== 'php') continue;

            $relPath = ltrim(
                str_replace($root, '', $info->getPathname()),
                '/',
            );

            if ($this->isAllowed($relPath)) continue;

            $content = (string) file_get_contents($info->getPathname());
            if (preg_match('/\bnew\s+\\\\?PDO\s*\(/', $content) === 1) {
                $offenders[] = $relPath;
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "Found `new PDO(` in files that must depend on ConnectionInterface instead:\n%s\n\n"
                . "Allowed directories: %s\n"
                . "Grandfathered files:  %s\n\n"
                . "If you really need PDO here, put the class under src/Infrastructure/ or inject ConnectionInterface.",
                implode("\n", array_map(static fn(string $f): string => "  - {$f}", $offenders)),
                implode(', ', self::ALLOWED_PREFIXES),
                implode(', ', self::LEGACY_ALLOWED_FILES),
            ),
        );
    }

    /**
     * Also fail if the legacy list grows stale: if one of those files no
     * longer contains `new PDO(`, remove it from LEGACY_ALLOWED_FILES so
     * we don't keep dead exceptions that hide future regressions.
     */
    public function testLegacyAllowedFilesStillContainPdo(): void
    {
        $root = dirname(__DIR__, 3);
        $stale = [];

        foreach (self::LEGACY_ALLOWED_FILES as $rel) {
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) {
                $stale[] = "{$rel} (file missing)";
                continue;
            }
            $content = (string) file_get_contents($abs);
            if (preg_match('/\bnew\s+\\\\?PDO\s*\(/', $content) !== 1) {
                $stale[] = "{$rel} (no longer contains `new PDO(` — remove from the list)";
            }
        }

        self::assertSame(
            [],
            $stale,
            "LEGACY_ALLOWED_FILES contains stale entries:\n" .
            implode("\n", array_map(static fn(string $e): string => "  - {$e}", $stale)),
        );
    }

    private function isAllowed(string $relPath): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($relPath, $prefix)) return true;
        }
        return in_array($relPath, self::LEGACY_ALLOWED_FILES, true);
    }
}
