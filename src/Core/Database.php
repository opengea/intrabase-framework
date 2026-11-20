<?php

declare(strict_types=1);

namespace Intrabase\Core;

use Intrabase\Database\Connection;

/**
 * Database — àlies de compatibilitat de Connection al namespace Core.
 *
 * Diversos mòduls generats resolen `$container->get(Intrabase\Core\Database::class)`
 * des dels hooks. Application el vincula a la mateixa Connection, igual que
 * ja es feia amb `Intrabase\Database\DB`.
 *
 * @see \Intrabase\Database\Connection
 */
final class Database extends Connection
{
}
