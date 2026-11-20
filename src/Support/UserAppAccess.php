<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Database\Connection;
use Intrabase\Security\Session;

/**
 * Permisos d'aplicació per persona i empresa (12/11/2026): sense accés, lectura o
 * escriptura. Es configuren a /account/{id} → Usuaris → Editar (només
 * administradores de l'empresa).
 *
 * Taula ib_user_app_access (user_id, entity_id, app_key, level). Sense fila =
 * escriptura (comportament d'abans). També val per a les administradores, que se'n
 * poden desactivar i tornar a activar (només elles poden canviar-ho).
 *
 * On s'aplica:
 *  - AppAccess::visibleApps: les apps 'none' no surten al menú ni als ginys;
 *  - Application::enforceUserAppGate: 'none' bloqueja qualsevol petició a l'app;
 *    'read' bloqueja les peticions que no són GET/HEAD;
 *  - Module::applyPermissions: 'read' treu Afegir/Editar/Esborrar dels mòduls.
 */
final class UserAppAccess
{
    public const LEVELS = ['none', 'read', 'write'];

    /** @var array<string, array<string,string>> */
    private static array $cache = [];

    /** @var array<string, bool> */
    private static array $managed = [];

    public static function reset(): void
    {
        self::$cache = [];
        self::$managed = [];
    }

    /**
     * La persona té els permisos d'apps decidits des de /account (té grup de l'empresa o
     * excepcions desades)? Llavors manen aquests i NO els grups del sistema
     * (ib_apps.group_access), llevat de l'app Sistema: una administradora ha de poder
     * activar qualsevol app a qualsevol usuari de qualsevol grup.
     */
    public static function managed(Connection $db, int $userId, int $entityId): bool
    {
        self::levels($db, $userId, $entityId);
        return self::$managed[$userId . ':' . $entityId] ?? false;
    }

    /** @return array<string,string> app_key => level (només les restringides: none|read) */
    public static function levels(Connection $db, int $userId, int $entityId): array
    {
        $k = $userId . ':' . $entityId;
        if (isset(self::$cache[$k])) {
            return self::$cache[$k];
        }
        $all = [];
        $isManaged = false;
        // També s'aplica a les administradores (12/11/2026): poden desactivar-se apps i tornar-les a activar.
        if ($userId > 0 && $entityId > 0) {
            try {
                // 1) El perfil de la persona a l'empresa (plantilla).
                $st = $db->pdo()->prepare('SELECT p.levels FROM ib_user_entities ue JOIN ib_user_profiles p ON p.id = ue.profile_id
                                            WHERE ue.user_id = ? AND ue.entity_id = ?');
                $st->execute([$userId, $entityId]);
                $pl = $st->fetchColumn();
                $isManaged = $pl !== false;
                $all = self::decode((string) ($pl ?: ''));
            } catch (\Throwable) {
            }
            try {
                // 2) Les excepcions de la persona (poden tornar a donar «write» sobre el perfil).
                $st = $db->pdo()->prepare('SELECT app_key, level FROM ib_user_app_access WHERE user_id = ? AND entity_id = ?');
                $st->execute([$userId, $entityId]);
                foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                    $isManaged = true;
                    if (in_array($r['level'], self::LEVELS, true)) {
                        $all[(string) $r['app_key']] = (string) $r['level'];
                    }
                }
            } catch (\Throwable) {
                // taula encara no creada en aquesta BD: sense restriccions
            }
        }
        self::$managed[$k] = $isManaged;
        return self::$cache[$k] = array_filter($all, static fn(string $l): bool => $l !== 'write');
    }

    /** @return array<string,string> */
    public static function decode(string $json): array
    {
        $out = [];
        foreach ((array) (json_decode($json, true) ?: []) as $app => $level) {
            if (is_string($app) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $app) && in_array($level, self::LEVELS, true)) {
                $out[$app] = (string) $level;
            }
        }
        return $out;
    }

    /** Nivells del perfil assignat a la persona (buit si no en té). */
    public static function profileLevels(Connection $db, int $userId, int $entityId): array
    {
        try {
            $st = $db->pdo()->prepare('SELECT p.levels FROM ib_user_entities ue JOIN ib_user_profiles p ON p.id = ue.profile_id
                                        WHERE ue.user_id = ? AND ue.entity_id = ?');
            $st->execute([$userId, $entityId]);
            return self::decode((string) ($st->fetchColumn() ?: ''));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function level(Connection $db, int $userId, int $entityId, string $appKey): string
    {
        return self::levels($db, $userId, $entityId)[$appKey] ?? 'write';
    }

    /** Nivell de l'usuari de la sessió a l'empresa activa. */
    public static function currentLevel(Connection $db, Session $session, string $appKey): string
    {
        $uid = (int) ($session->userId() ?? 0);
        return $uid > 0 ? self::level($db, $uid, EntityPlan::activeEntityId($session), $appKey) : 'write';
    }

    public static function isFullAccess(Connection $db, int $userId, int $entityId): bool
    {
        try {
            $st = $db->pdo()->prepare(
                "SELECT (SELECT COUNT(*) FROM ib_user_entities WHERE user_id = ? AND entity_id = ? AND role = 'admin')
                      + (SELECT COUNT(*) FROM ib_user_groups ug JOIN ib_groups g ON g.id = ug.group_id
                          WHERE ug.user_id = ? AND g.name IN ('admin', 'Clients - admin'))"
            );
            $st->execute([$userId, $entityId, $userId]);
            return (int) $st->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Desa els nivells de la persona com a excepcions sobre el seu perfil: només es guarden
     * les apps on el nivell triat no és el del perfil (sense perfil, el per defecte és «write»).
     * @param array<string,string> $levels
     */
    public static function save(Connection $db, int $userId, int $entityId, array $levels): void
    {
        $pdo = $db->pdo();
        $hasProfile = false;
        try {
            $hp = $pdo->prepare('SELECT 1 FROM ib_user_entities ue JOIN ib_user_profiles p ON p.id = ue.profile_id WHERE ue.user_id = ? AND ue.entity_id = ?');
            $hp->execute([$userId, $entityId]);
            $hasProfile = (bool) $hp->fetchColumn();
        } catch (\Throwable) {
        }
        $base = self::profileLevels($db, $userId, $entityId);
        foreach ($levels as $app => $level) {
            $app = (string) $app;
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $app) || !in_array($level, self::LEVELS, true)) {
                continue;
            }
            // Amb grup: només les diferències. Sense grup: tot explícit (també «write»), perquè
            // la decisió de l'administradora passi per davant dels grups del sistema.
            if ($hasProfile && $level === ($base[$app] ?? 'write')) {
                $pdo->prepare('DELETE FROM ib_user_app_access WHERE user_id = ? AND entity_id = ? AND app_key = ?')
                    ->execute([$userId, $entityId, $app]);
            } else {
                $pdo->prepare('INSERT INTO ib_user_app_access (user_id, entity_id, app_key, level) VALUES (?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE level = VALUES(level)')
                    ->execute([$userId, $entityId, $app, $level]);
            }
        }
        unset(self::$cache[$userId . ':' . $entityId], self::$managed[$userId . ':' . $entityId]);
    }
}
