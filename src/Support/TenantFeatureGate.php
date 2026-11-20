<?php
declare(strict_types=1);
namespace Intrabase\Support;

use Intrabase\Security\Session;

/**
 * TenantFeatureGate — el tenant decideix si l'usuari pot fer servir una funció
 * (videoconferència, crear una crida, publicar una oferta…). Hook:
 * `<data>/feature_gate.php` → callable(string $feature, array $ibUser, ?array $activeEntity): ?array
 * null = permès; array {message, quota} = denegat. Sense hook: tot permès.
 */
final class TenantFeatureGate
{
    public const HOOK_FILE   = 'feature_gate.php';
    public const SESSION_KEY = '_ib_feature_denied';

    public static function check(string $feature, Session $session): ?array
    {
        $entity = $session->get('_ib_active_entity');
        return self::checkWith(TenantHook::resolve(self::HOOK_FILE), $feature, $session->user() ?? [], is_array($entity) ? $entity : null);
    }

    /** Pur. */
    public static function checkWith(?callable $gate, string $feature, array $user, ?array $entity): ?array
    {
        if ($gate === null) return null;
        try {
            $r = $gate($feature, $user, $entity);
        } catch (\Throwable) {
            return null;                      // un gate que peta no ha de tancar la porta a ningú
        }
        if (!is_array($r)) return null;
        $msg = trim((string)($r['message'] ?? ''));
        return [
            'feature' => $feature,
            'message' => $msg !== '' ? $msg : __('feature_not_in_plan'),
            'quota'   => is_array($r['quota'] ?? null) ? $r['quota'] : [],
        ];
    }

    /** Resposta JSON d'una API: 402 + capçalera que el fetch global d'Intrabase reconeix. */
    public static function deny(array $denial): never
    {
        http_response_code(402);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Ib-Feature: denied');
        echo json_encode(['ok' => false, 'error' => $denial['message'], 'quota' => $denial['quota']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Per a fluxos de formulari clàssic (redirect): el Layout ho ensenya a la pàgina següent. */
    public static function remember(Session $session, array $denial): void { $session->set(self::SESSION_KEY, $denial); }

    public static function takePending(Session $session): ?array
    {
        $d = $session->get(self::SESSION_KEY);
        if ($d !== null) $session->forget(self::SESSION_KEY);
        return is_array($d) ? $d : null;
    }
}
