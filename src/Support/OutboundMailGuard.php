<?php

declare(strict_types=1);

namespace Intrabase\Support;

use Intrabase\Core\Application;
use Intrabase\Database\Connection;

/**
 * Tallafoc de correu sortint per tenant (18/10/2026).
 *
 * Un tenant amb `ib_tenants.mail_outbound_disabled = 1` (p. ex. un de
 * demostració) no envia cap correu: cada camí d'enviament
 * crida blocked() just abans d'obrir el socket SMTP o de cridar mail(), i si
 * diu que sí, escriu una línia al log d'errors de PHP i fa com si l'enviament
 * hagués anat bé (com un sorral tipus Mailtrap: la intranet segueix el seu
 * flux, però res no surt de la màquina).
 *
 * D'on surt el valor (n'hi ha prou que un dels dos digui 1):
 *   1. La fila del tenant de la petició web (Application::getTenantConfig(),
 *      llegida de la BD mestra per HTTP_HOST).
 *   2. La BD que fa l'enviament (cron, CLI, cues), per nom: la fila
 *      d'ib_tenants amb db_name = DATABASE(), a la mateixa BD i a la mestra
 *      (config db_name). Al cron no hi ha HTTP_HOST.
 *
 * Sense la columna (esquema antic sense migrar) o amb
 * qualsevol error de lectura: false, o sigui el comportament de sempre. Per
 * defecte la columna val 0 a tots els tenants.
 *
 * Camins coberts: Mailer (sistema, notificacions, 2FA, restabliment de
 * contrasenya, recordatoris, factures, cua ib_sys_email_queue), compositor del
 * Mail (MailApiController::smtpTransmit, inclou reaccions i acusos MDN),
 * respostes de tiquets (web i cron), enviaments programats, Mailing (prova i
 * campanyes), avís de carpeta compartida del Drive i les invitacions de
 * Google Calendar (sendUpdates passa a «none»).
 */
final class OutboundMailGuard
{
    /** @var array<string,bool> resultat per nom de BD (dins d'un mateix procés) */
    private static array $byDb = [];

    /** Per a proves: força el resultat (null = càlcul normal). */
    public static ?bool $forced = null;

    public static function blocked(Connection|\PDO|null $db = null): bool
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        // 1. Petició web: la fila del tenant resolta per host
        try {
            $tenant = Application::getInstance()->getTenantConfig();
            if ((int) ($tenant['mail_outbound_disabled'] ?? 0) === 1) {
                return true;
            }
        } catch (\Throwable) {
        }

        // 2. La BD que envia
        try {
            $pdo = $db instanceof Connection ? $db->pdo() : $db;
            if ($pdo === null) {
                $pdo = Connection::getInstance()->pdo();
            }
            $name = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
        if ($name === '') {
            return false;
        }
        if (!array_key_exists($name, self::$byDb)) {
            self::$byDb[$name] = self::flagFor($pdo, $name);
        }
        return self::$byDb[$name];
    }

    /** Escriu al log el correu que no ha sortit (mai el cos). */
    public static function logBlocked(string $channel, string|array $to, string $subject = ''): void
    {
        $to = is_array($to) ? implode(', ', array_map('strval', $to)) : $to;
        error_log(sprintf(
            '[mail-guard] %s: outbound mail disabled for this tenant, NOT sent (to=%s; subject=%s)',
            $channel,
            mb_substr(str_replace(["\r", "\n"], ' ', $to), 0, 300),
            mb_substr(str_replace(["\r", "\n"], ' ', $subject), 0, 150)
        ));
    }

    /** Per a proves i processos llargs: oblida els valors llegits. */
    public static function reset(): void
    {
        self::$byDb = [];
        self::$forced = null;
    }

    private static function flagFor(\PDO $pdo, string $name): bool
    {
        $schemas = ['' => true];   // '' = la mateixa BD
        $primary = self::primaryDb();
        if ($primary !== '' && $primary !== $name) {
            $schemas[$primary] = true;
        }
        foreach (array_keys($schemas) as $schema) {
            $table = $schema === '' ? 'ib_tenants' : '`' . $schema . '`.ib_tenants';
            try {
                $st = $pdo->prepare('SELECT MAX(mail_outbound_disabled) FROM ' . $table . ' WHERE db_name = ?');
                if ($st !== false && $st->execute([$name]) && (int) $st->fetchColumn() === 1) {
                    return true;
                }
            } catch (\Throwable) {
                // Sense la taula o la columna: no hi ha tallafoc en aquesta BD.
            }
        }
        return false;
    }

    /** Nom de la BD mestra (config db_name), validat. '' si no se sap. */
    private static function primaryDb(): string
    {
        $name = '';
        try {
            $name = (string) (Application::getInstance()->getConfig()['db_name'] ?? '');
            if ($name === '') {
                $path = dirname(__DIR__, 2) . '/config/app.php';
                if (is_readable($path)) {
                    $cfg = require $path;
                    $name = is_array($cfg) ? (string) ($cfg['db_name'] ?? '') : '';
                }
            }
        } catch (\Throwable) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) ? $name : '';
    }
}
