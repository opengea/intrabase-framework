<?php
declare(strict_types=1);
namespace Intrabase\Support;

use Intrabase\Core\Application;

/**
 * TenantHook — carrega `<tenant data dir>/<file>` quan retorna un callable.
 * Generalització del patró de TenantEntities/TenantRoutes: sense fitxer, o
 * amb un fitxer que peta o no retorna un callable, el resultat és null i
 * l'app es comporta com si el tenant no tingués el hook. Un cop per petició.
 */
final class TenantHook
{
    /** @var array<string, ?callable> */
    private static array $cache = [];

    public static function reset(): void { self::$cache = []; }

    /** Pur: cap dependència de l'aplicació. $file és un nom simple (sense '/'). */
    public static function load(?string $dataPath, string $file): ?callable
    {
        if ($dataPath === null || $dataPath === '' || $file === '' || str_contains($file, '/') || str_contains($file, "\0")) return null;
        $path = $dataPath . '/' . $file;
        if (!is_file($path)) return null;
        if (!self::safeForCurrentUser($dataPath, $path)) {
            error_log("[TenantHook] $path ignorat: el proces corre com a root i el fitxer o el seu directori els pot modificar un altre usuari");
            return null;
        }
        try {
            $fn = require $path;
        } catch (\Throwable) {
            return null;
        }
        return is_callable($fn) ? $fn : null;
    }

    /**
     * Seguretat (11/11/2026): el data dir del tenant és d'apache o de l'usuari del
     * vhost, però hi ha crons de root que hi carreguen hooks (connectors-sync.php
     * crida PeerRegistry::forDataPath cada 5 min). Si el procés és root, només
     * s'accepta un hook si tant el fitxer com el directori són de root i ningú més
     * hi pot escriure; si no, un `peer.php` deixat per apache s'executaria com a root.
     * Fora de root (la web, com a apache) no canvia res.
     */
    private static function safeForCurrentUser(string $dir, string $path): bool
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) return true;
        foreach ([$dir, $path] as $p) {
            $st = @lstat($p);
            if ($st === false || ($st['mode'] & 0170000) === 0120000) return false;   // no existeix o és un symlink
            if ($st['uid'] !== 0 || ($st['mode'] & 0022) !== 0) return false;
        }
        return true;
    }

    /** Amb el data dir del tenant de la petició, amb cache. */
    public static function resolve(string $file): ?callable
    {
        if (array_key_exists($file, self::$cache)) return self::$cache[$file];
        try {
            $dataPath = Application::getInstance()->getTenantDataPath();
        } catch (\Throwable) {
            $dataPath = null;
        }
        return self::$cache[$file] = self::load($dataPath, $file);
    }
}
