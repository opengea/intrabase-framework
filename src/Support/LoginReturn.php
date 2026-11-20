<?php
declare(strict_types=1);

namespace Intrabase\Support;

/**
 * Pagina a la qual tornar despres del login (25/09/2026).
 *
 * Quan una pagina demana sessio, Application::redirectToLogin() hi desa el cami
 * (clau de sessio SESSION_KEY) i, un cop autenticat, la primera visita a "/"
 * hi redirigeix. Nomes s'accepta un cami intern de pagina: res que pugui sortir
 * del domini (open redirect), ni APIs, ni fitxers (una peticio de /manifest.json
 * sense sessio no ha de sobreescriure la pagina que l'usuari volia).
 */
final class LoginReturn
{
    public const SESSION_KEY = '_ib_login_return';

    public static function fromRequest(string $uri, string $method, ?string $secFetchDest): ?string
    {
        if (strtoupper($method) !== 'GET') {
            return null;
        }
        // Si el navegador diu que no es una navegacio de document (imatge, fetch...), no.
        if ($secFetchDest !== null && $secFetchDest !== '' && $secFetchDest !== 'document') {
            return null;
        }
        if ($uri === '' || strlen($uri) > 512) {
            return null;
        }
        $path  = (string) strtok($uri, '?');
        $query = (string) substr($uri, strlen($path) + 1);
        // Cami absolut intern: "/" seguit d'un caracter que no sigui "/" ni "\" (//host, /\host)
        // Les pagines del Drive (/drive/page/…, 13/10/2026) son fitxers: s'hi admet el punt, pero mai «..».
        $page = str_starts_with($path, '/drive/page/');
        $chars = $page ? 'A-Za-z0-9/_\-~%.' : 'A-Za-z0-9/_\-~%';
        if (!preg_match('#^/[A-Za-z0-9_\-~%][' . $chars . ']*$#', $path) || ($page && str_contains($path, '..'))) {
            return null;
        }
        if ($query !== '' && !preg_match('#^[A-Za-z0-9=&_\-.~%+]*$#', $query)) {
            return null;
        }
        if (preg_match('#^/(login|logout|api|assets|uploads)(/|$)#', $path) || preg_match('#^/[^/]+/api(/|$)#', $path)) {
            return null;
        }
        return $query !== '' ? $path . '?' . $query : $path;
    }
}
