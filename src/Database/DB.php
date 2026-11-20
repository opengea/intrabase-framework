<?php

declare(strict_types=1);

namespace Intrabase\Database;

/**
 * DB — Backwards-compatibility alias for Connection
 *
 * Many modules were generated with `use Intrabase\Database\DB;`
 * This class allows `$container->get(DB::class)` to resolve
 * to the same Connection instance registered in the DI container.
 *
 * @see Connection
 */
final class DB extends Connection
{
}
