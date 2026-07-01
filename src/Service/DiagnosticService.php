<?php

declare(strict_types=1);

namespace Dalfred\Service;

use function function_exists;
use function getenv;
use function ini_get;
use function is_dir;
use function is_file;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Inspects the current request to figure out the web stack between the
 * browser and PHP, so we can give the operator a precise list of timeout
 * settings to bump when long Dalfred answers run into 504s.
 *
 * Detection is best-effort: it relies on conventional `$_SERVER` headers
 * the various proxies / front-ends are known to inject, plus a few well-
 * known filesystem markers (Plesk, …). When uncertain, we say "unknown"
 * rather than guessing — false positives would mislead the operator more
 * than missing data.
 */
final class DiagnosticService
{
    /**
     * Recommended timeout for long AI calls, in seconds.
     * Bumped from 180 → 600 in v2.13.5: in real production traffic an agent
     * answer can stay under 60s most of the time but exceed 180s when the
     * LLM is slow or when several MCP tools chain. 600s is a comfortable
     * upper bound that still kills genuinely runaway requests.
     */
    public const RECOMMENDED_TIMEOUT_S = 600;

    /**
     * Build a structured snapshot of the current request stack.
     *
     * @return array{
     *   php: array{version:string, sapi:string, max_execution_time:int, can_finish_request:bool},
     *   webserver: array{name:string, raw:string},
     *   reverse_proxy: array{detected:bool, hints:string[], client_ip:string|null, host:string|null, scheme:string|null},
     *   cdn: array{detected:bool, name:string|null},
     *   panel: array{detected:bool, name:string|null, hints:string[]},
     *   recommendations: array<int, array{title:string, scope:string, body:string}>
     * }
     */
    public function snapshot(): array
    {
        $php = [
            'version'             => PHP_VERSION,
            'sapi'                => PHP_SAPI,
            'max_execution_time'  => (int) ini_get('max_execution_time'),
            'can_finish_request'  => function_exists('fastcgi_finish_request'),
        ];

        $serverSoft = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
        $webserver = $this->detectWebserver($serverSoft);

        $proxy = $this->detectReverseProxy();
        $cdn = $this->detectCdn();
        $panel = $this->detectControlPanel();

        $recommendations = $this->buildRecommendations($webserver, $php, $proxy, $cdn, $panel);

        return [
            'php'             => $php,
            'webserver'       => $webserver,
            'reverse_proxy'   => $proxy,
            'cdn'             => $cdn,
            'panel'           => $panel,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * @return array{name:string, raw:string}
     */
    private function detectWebserver(string $serverSoft): array
    {
        $raw = trim($serverSoft);
        $lower = strtolower($raw);

        if ($lower === '') {
            return ['name' => 'unknown', 'raw' => ''];
        }

        // SERVER_SOFTWARE is the *closest* server PHP talks to. Behind a
        // reverse proxy this will typically be the upstream Apache / nginx,
        // not the front-facing one — that's exactly what we want to tune.
        if (preg_match('#^apache#', $lower)) {
            return ['name' => 'apache', 'raw' => $raw];
        }
        if (preg_match('#^nginx#', $lower)) {
            return ['name' => 'nginx', 'raw' => $raw];
        }
        if (preg_match('#caddy#', $lower)) {
            return ['name' => 'caddy', 'raw' => $raw];
        }
        if (preg_match('#litespeed#', $lower)) {
            return ['name' => 'litespeed', 'raw' => $raw];
        }
        if (preg_match('#iis#', $lower)) {
            return ['name' => 'iis', 'raw' => $raw];
        }
        return ['name' => 'unknown', 'raw' => $raw];
    }

    /**
     * @return array{detected:bool, hints:string[], client_ip:string|null, host:string|null, scheme:string|null}
     */
    private function detectReverseProxy(): array
    {
        $hints = [];
        $clientIp = null;
        $host = null;
        $scheme = null;

        // Conventional reverse-proxy headers
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hints[] = 'X-Forwarded-For présent';
            $clientIp = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $hints[] = 'X-Real-IP présent (typique nginx)';
            $clientIp = $clientIp ?? (string) $_SERVER['HTTP_X_REAL_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $hints[] = 'X-Forwarded-Host présent';
            $host = (string) $_SERVER['HTTP_X_FORWARDED_HOST'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $hints[] = 'X-Forwarded-Proto présent (TLS terminé en amont)';
            $scheme = (string) $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        if (!empty($_SERVER['HTTP_FORWARDED'])) {
            $hints[] = 'Header RFC 7239 Forwarded présent';
        }
        if (!empty($_SERVER['HTTP_VIA'])) {
            $hints[] = 'Via présent (' . (string) $_SERVER['HTTP_VIA'] . ')';
        }

        return [
            'detected'  => $hints !== [],
            'hints'     => $hints,
            'client_ip' => $clientIp,
            'host'      => $host,
            'scheme'    => $scheme,
        ];
    }

    /**
     * @return array{detected:bool, name:string|null}
     */
    private function detectCdn(): array
    {
        // Cloudflare
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_CF_RAY'])) {
            return ['detected' => true, 'name' => 'Cloudflare'];
        }
        // Fastly
        if (!empty($_SERVER['HTTP_FASTLY_CLIENT_IP']) || !empty($_SERVER['HTTP_X_FASTLY_REQUEST_ID'])) {
            return ['detected' => true, 'name' => 'Fastly'];
        }
        // Akamai
        if (!empty($_SERVER['HTTP_TRUE_CLIENT_IP']) || !empty($_SERVER['HTTP_AKAMAI_ORIGIN_HOP'])) {
            return ['detected' => true, 'name' => 'Akamai'];
        }
        // Generic CDN-Loop (set by Cloudflare and others)
        if (!empty($_SERVER['HTTP_CDN_LOOP'])) {
            return ['detected' => true, 'name' => (string) $_SERVER['HTTP_CDN_LOOP']];
        }
        return ['detected' => false, 'name' => null];
    }

    /**
     * Best-effort detection of the hosting control panel (Plesk, cPanel, …).
     *
     * Filesystem markers only (since 2.13.7). The previous header heuristic
     * was generating false positives — see the explanation below.
     *
     * @return array{detected:bool, name:string|null, hints:string[]}
     */
    private function detectControlPanel(): array
    {
        $hints = [];

        // --- Plesk ---
        $pleskFsMarkers = [
            '/usr/local/psa',          // Plesk install root (Linux, all editions)
            '/etc/psa',                // config dir
            '/var/www/vhosts/system',  // per-domain config tree (Plesk Onyx+)
        ];
        $pleskFsHit = false;
        foreach ($pleskFsMarkers as $path) {
            if (@is_dir($path) || @is_file($path)) {
                $pleskFsHit = true;
                $hints[] = 'Plesk filesystem marker présent : ' . $path;
                break; // one is enough
            }
        }
        if (!$pleskFsHit && getenv('PLESK_VHOSTS_DIR') !== false) {
            $pleskFsHit = true;
            $hints[] = 'Plesk env var PLESK_VHOSTS_DIR présente';
        }
        if ($pleskFsHit) {
            return ['detected' => true, 'name' => 'Plesk', 'hints' => $hints];
        }

        // NOTE: a previous version (2.13.5) used a header heuristic ("Apache
        // upstream + X-Forwarded-For + X-Forwarded-Proto only") to flag
        // "Plesk (probable)". That heuristic produced false positives on
        // legitimate Apache-front + HAProxy / OVH Anti-DDoS / unknown reverse
        // proxy setups. Removed in 2.13.7 — we now only claim Plesk when at
        // least one filesystem marker is present. The unknown-proxy case is
        // covered by a dedicated recommendation block ("Reverse proxy en
        // frontal — origine non identifiée") in buildRecommendations().

        // --- cPanel ---
        if (@is_dir('/usr/local/cpanel')) {
            return ['detected' => true, 'name' => 'cPanel', 'hints' => ['Marqueur /usr/local/cpanel présent']];
        }

        // --- DirectAdmin ---
        if (@is_dir('/usr/local/directadmin')) {
            return ['detected' => true, 'name' => 'DirectAdmin', 'hints' => ['Marqueur /usr/local/directadmin présent']];
        }

        return ['detected' => false, 'name' => null, 'hints' => []];
    }

    /**
     * @param array{name:string, raw:string} $webserver
     * @param array{version:string, sapi:string, max_execution_time:int, can_finish_request:bool} $php
     * @param array{detected:bool, hints:string[], client_ip:string|null, host:string|null, scheme:string|null} $proxy
     * @param array{detected:bool, name:string|null} $cdn
     * @param array{detected:bool, name:string|null, hints:string[]} $panel
     * @return array<int, array{title:string, scope:string, body:string}>
     */
    private function buildRecommendations(array $webserver, array $php, array $proxy, array $cdn, array $panel): array
    {
        $recos = [];
        $t = self::RECOMMENDED_TIMEOUT_S;

        // ---------------------------------------------------------------------
        // Plesk-specific recommendation FIRST: GUI-driven, no SSH needed,
        // covers the nginx + Apache pair in one go. If the operator follows
        // this they don't need to read the generic blocks below.
        // (Since 2.13.7 we only enter this block when filesystem markers are
        //  present, so the check is a simple `name === 'Plesk'`.)
        // ---------------------------------------------------------------------
        $isPlesk = $panel['detected'] && (string) $panel['name'] === 'Plesk';

        if ($isPlesk) {
            $recos[] = [
                'title' => 'Plesk — modification sans SSH',
                'scope' => 'Domaine → « Apache & nginx Settings » (interface Plesk)',
                'body'  =>
                    "Champ « Additional nginx directives » (frontal) :\n"
                    . "    proxy_read_timeout " . $t . "s;\n"
                    . "    proxy_send_timeout " . $t . "s;\n"
                    . "    proxy_connect_timeout " . $t . "s;\n"
                    . "    fastcgi_read_timeout " . $t . "s;\n"
                    . "    fastcgi_send_timeout " . $t . "s;\n"
                    . "\n"
                    . "Champ « Additional Apache directives » (HTTP et HTTPS) :\n"
                    . "    Timeout " . $t . "\n"
                    . "    ProxyTimeout " . $t . "\n"
                    . "\n"
                    . "Domaine → « PHP Settings » :\n"
                    . "    max_execution_time = " . $t . "  (vous y êtes peut-être déjà)\n"
                    . "\n"
                    . "Cliquer « OK » : Plesk reconfigure nginx et Apache automatiquement,\n"
                    . "aucun SSH requis. Le PHP-FPM pool est rechargé en arrière-plan.",
            ];
        }

        // ---------------------------------------------------------------------
        // PHP layer (always listed for context)
        // ---------------------------------------------------------------------
        if ($php['sapi'] === 'fpm-fcgi' || $php['sapi'] === 'cgi-fcgi') {
            $recos[] = [
                'title' => 'PHP-FPM',
                'scope' => 'pool config (e.g. /etc/php/8.x/fpm/pool.d/www.conf)',
                'body'  => "request_terminate_timeout = " . $t . "\n"
                         . "; …puis : systemctl reload php-fpm\n"
                         . "; (max_execution_time côté serveur : actuellement " . $php['max_execution_time'] . "s)",
            ];
        } elseif ($php['sapi'] === 'apache2handler') {
            $recos[] = [
                'title' => 'PHP via mod_php (Apache)',
                'scope' => 'php.ini ou .htaccess',
                'body'  => "max_execution_time = " . $t . "\n"
                         . "; (déjà " . $php['max_execution_time'] . " côté serveur)\n"
                         . "; PHP via mod_php tient le worker Apache pendant tout l'appel ;\n"
                         . "; envisager un passage à PHP-FPM si plusieurs requêtes longues simultanées.",
            ];
        }

        // ---------------------------------------------------------------------
        // Webserver-specific (skip when Plesk is detected — already covered)
        // ---------------------------------------------------------------------
        if (!$isPlesk) {
            if ($webserver['name'] === 'nginx') {
                $recos[] = [
                    'title' => 'nginx (vhost / location ~ \\.php$)',
                    'scope' => '/etc/nginx/sites-enabled/<vhost>.conf — nécessite un reload nginx',
                    'body'  => "fastcgi_read_timeout " . $t . "s;\n"
                             . "fastcgi_send_timeout " . $t . "s;\n"
                             . "proxy_read_timeout   " . $t . "s;\n"
                             . "proxy_send_timeout   " . $t . "s;\n"
                             . "proxy_connect_timeout " . $t . "s;\n"
                             . "# …puis : nginx -t && systemctl reload nginx",
                ];
            } elseif ($webserver['name'] === 'apache') {
                // Three concrete cases for Apache:
                //  1. Apache + mod_proxy_fcgi → PHP-FPM (most common)
                //  2. Apache + mod_php (legacy, still very common)
                //  3. Apache reverse-proxy → Apache backend (rare but real)
                $recos[] = [
                    'title' => 'Apache',
                    'scope' => 'apache2.conf (global) + vhost dédié',
                    'body'  => "# 1. Global — apache2.conf :\n"
                             . "Timeout " . $t . "\n"
                             . "\n"
                             . "# 2. Vhost — sites-available/<domaine>.conf, dans <VirtualHost> :\n"
                             . "ProxyTimeout " . $t . "\n"
                             . "\n"
                             . "# 3a. Cas standard — Apache → PHP-FPM via mod_proxy_fcgi :\n"
                             . "<FilesMatch \"\\.php$\">\n"
                             . "    SetHandler \"proxy:unix:/run/php/php8.x-fpm.sock|fcgi://localhost\"\n"
                             . "</FilesMatch>\n"
                             . "<Proxy \"fcgi://localhost/\">\n"
                             . "    ProxySet timeout=" . $t . "\n"
                             . "</Proxy>\n"
                             . "\n"
                             . "# 3b. Cas rare — Apache reverse-proxy → Apache backend (2 niveaux) :\n"
                             . "ProxyPass        / http://backend.local:8080/ timeout=" . $t . " connectiontimeout=10\n"
                             . "ProxyPassReverse / http://backend.local:8080/\n"
                             . "\n"
                             . "# Recharger : apache2ctl configtest && systemctl reload apache2",
                ];
            } elseif ($webserver['name'] === 'caddy') {
                $recos[] = [
                    'title' => 'Caddy',
                    'scope' => 'Caddyfile',
                    'body'  => "reverse_proxy phpfpm_upstream {\n"
                             . "    transport fastcgi {\n"
                             . "        read_timeout " . $t . "s\n"
                             . "        write_timeout " . $t . "s\n"
                             . "    }\n"
                             . "}",
                ];
            }

            // ----------------------------------------------------------------
            // Reverse proxy in front (separate hop), not identified by name.
            // Triggered when X-Forwarded-* are present AND no panel was
            // detected. The operator probably has HAProxy / Traefik / OVH
            // Anti-DDoS / a corporate WAF / Varnish in front and doesn't
            // necessarily know it. Surface the candidates explicitly so they
            // know where else to look beyond their Apache config.
            // ----------------------------------------------------------------
            if ($proxy['detected']) {
                $recos[] = [
                    'title' => 'Reverse proxy en frontal — origine non identifiée',
                    'scope' => 'au-delà du serveur web ci-dessus, un proxy intercepte les requêtes',
                    'body'  => "Headers de proxy détectés mais le frontal n'a pas pu être\n"
                             . "identifié automatiquement. Pistes courantes (à interroger\n"
                             . "auprès de votre hébergeur ou de votre équipe sysadmin) :\n"
                             . "\n"
                             . "  - HAProxy :   timeout server " . $t . "s; timeout client " . $t . "s;\n"
                             . "  - Traefik :   --entrypoints.web.transport.respondingTimeouts.readTimeout=" . $t . "s\n"
                             . "  - Varnish :   .first_byte_timeout = " . $t . "s; .between_bytes_timeout = " . $t . "s;\n"
                             . "  - nginx :     proxy_read_timeout " . $t . "s; (mêmes directives que la carte nginx ci-dessus)\n"
                             . "  - OVH Anti-DDoS : timeout fixé côté OVH, contacter le support pour ajustement\n"
                             . "  - WAF / Cloudflare en mode DNS-only : timeout du WAF\n"
                             . "\n"
                             . "Indice de diagnostic : si un timeout précis revient (45 s, 50 s, 100 s, 120 s),\n"
                             . "c'est typiquement la signature d'un composant. Lancez le test « Tester un\n"
                             . "appel long » avec différentes durées pour identifier la limite exacte.",
                ];
            }
        }

        // ---------------------------------------------------------------------
        // CDN cap (especially Cloudflare free)
        // ---------------------------------------------------------------------
        if ($cdn['detected'] && $cdn['name'] === 'Cloudflare') {
            $recos[] = [
                'title' => 'Cloudflare détecté',
                'scope' => 'panneau Cloudflare → Network / Rules',
                'body'  => "ATTENTION : Cloudflare plan gratuit/Pro a un timeout proxy de 100 secondes\n"
                         . "non modifiable. Au-delà, vous obtiendrez un 524 systématique.\n"
                         . "Options :\n"
                         . "  - Passer le sous-domaine Dolibarr en mode \"DNS only\" (nuage gris)\n"
                         . "  - Passer en plan Enterprise (timeout configurable)\n"
                         . "  - Rester en deçà du seuil et utiliser le mode async/streaming Dalfred",
            ];
        } elseif ($cdn['detected']) {
            $recos[] = [
                'title' => 'CDN détecté (' . ($cdn['name'] ?? 'inconnu') . ')',
                'scope' => 'console du CDN',
                'body'  => "Vérifier le timeout d'origine côté CDN. La plupart des CDN ont un\n"
                         . "cap entre 60 et 300 secondes selon le plan.",
            ];
        }

        return $recos;
    }
}
