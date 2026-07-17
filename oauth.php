<?php
/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    oauth.php
 * \ingroup dalfred
 * \brief   OAuth 2.1 authorization server front controller for the MCP endpoint.
 *
 * Routes (via PATH_INFO, e.g. /custom/dalfred/oauth.php/token):
 *   GET  /.well-known/openid-configuration        AS metadata (RFC 8414 / OIDC flavor)
 *   GET  /.well-known/oauth-authorization-server  AS metadata (RFC 8414)
 *   GET  /.well-known/oauth-protected-resource    Protected Resource Metadata (RFC 9728)
 *   POST /register                                Dynamic Client Registration (RFC 7591)
 *   GET  /authorize                               Authorization endpoint (Dolibarr login + consent)
 *   POST /authorize                               Consent form submission
 *   POST /token                                   Token endpoint (code exchange + refresh, PKCE S256)
 *
 * Discovery note: the issuer is this file's URL (it has a path component),
 * so per the MCP authorization spec clients fall back to the path-appended
 * form {issuer}/.well-known/openid-configuration — which lands here through
 * PATH_INFO without requiring anything at the domain root.
 *
 * This endpoint is OFF by default: every route requires both the Dalfred
 * module to be enabled AND the DALFRED_MCP_EXTERNAL_ENABLED admin toggle.
 */

// Resolve the route before main.inc.php: everything except /authorize runs
// machine-to-machine (no session, no CSRF token, no login redirect).
$dalfred_route = '';
if (!empty($_SERVER['PATH_INFO'])) {
	$dalfred_route = $_SERVER['PATH_INFO'];
} elseif (!empty($_GET['route'])) {
	$dalfred_route = '/'.ltrim((string) $_GET['route'], '/');
}
$dalfred_route = rtrim($dalfred_route, '/');

if ($dalfred_route !== '/authorize') {
	if (!defined('NOLOGIN')) {
		define('NOLOGIN', '1');
	}
	if (!defined('NOCSRFCHECK')) {
		define('NOCSRFCHECK', '1');
	}
	if (!defined('NOTOKENRENEWAL')) {
		define('NOTOKENRENEWAL', '1');
	}
	if (!defined('NOSESSION')) {
		define('NOSESSION', '1');
	}
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	die('{"error":"server_error","error_description":"Main include failed"}');
}

dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
if (dalfred_mcp_oauth_autoload() === null) {
	http_response_code(500);
	die('{"error":"server_error","error_description":"dolibarr-mcp-oauth library not found"}');
}

/**
 * Send a JSON response and exit.
 *
 * @param array $payload  Data
 * @param int   $httpCode HTTP status
 * @return never
 */
function dalfred_oauth_json($payload, $httpCode = 200)
{
	top_httphead('application/json');
	http_response_code($httpCode);
	header('Cache-Control: no-store');
	header('Pragma: no-cache');
	header('Access-Control-Allow-Origin: *');
	print json_encode($payload, JSON_UNESCAPED_SLASHES);
	exit;
}

if (!isModEnabled('dalfred')) {
	dalfred_oauth_json(array('error' => 'server_error', 'error_description' => 'Module Dalfred not enabled'), 503);
}

// OFF by default: gate every route (including /authorize) behind the admin
// toggle. A disabled endpoint has nothing to authorize, so there is no
// value in letting /authorize fall through to its own login requirement.
if (getDolGlobalInt('DALFRED_MCP_EXTERNAL_ENABLED') !== 1) {
	dalfred_oauth_json(array('error' => 'access_denied', 'error_description' => 'External MCP access is disabled'), 403);
}

$issuer = \DolibarrMcpOAuth\Support\UrlHelper::publicUrl('/dalfred/oauth.php');
$mcpUrl = \DolibarrMcpOAuth\Support\UrlHelper::publicUrl('/dalfred/mcp.php');
$config = new \DolibarrMcpOAuth\ExposureConfig('dalfred', 'dcp_', 'DALFRED');
$oauthServer = new \DolibarrMcpOAuth\OAuthServer($db, $config);
$oauthRouter = new \DolibarrMcpOAuth\OAuthRouter($oauthServer, $config, $issuer, $mcpUrl);

// CORS preflight (browser-based MCP clients hit token/register cross-origin)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, Authorization, mcp-protocol-version');
	http_response_code(204);
	exit;
}

switch ($dalfred_route) {
	// --- Discovery ---------------------------------------------------------

	case '/.well-known/openid-configuration':
	case '/.well-known/oauth-authorization-server':
		dalfred_oauth_json($oauthRouter->metadataAuthorizationServer());
		// no break (dalfred_oauth_json exits)

	case '/.well-known/oauth-protected-resource':
		dalfred_oauth_json($oauthRouter->metadataProtectedResource());
		// no break

	// --- Dynamic Client Registration (RFC 7591) -----------------------------

	case '/register':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			dalfred_oauth_json(array('error' => 'invalid_request', 'error_description' => 'POST required'), 405);
		}
		$body = json_decode((string) file_get_contents('php://input'), true);
		if (!is_array($body)) {
			dalfred_oauth_json(array('error' => 'invalid_client_metadata', 'error_description' => 'Invalid JSON body'), 400);
		}
		$r = $oauthRouter->handleRegister($body);
		dalfred_oauth_json($r['payload'], $r['httpCode']);
		// no break

	// --- Token endpoint ------------------------------------------------------

	case '/token':
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			dalfred_oauth_json(array('error' => 'invalid_request', 'error_description' => 'POST required'), 405);
		}
		$getPost = fn(string $name, $default) => GETPOST($name, 'alphanohtml') ?: $default;
		$r = $oauthRouter->handleToken($getPost, $_SERVER);
		dalfred_oauth_json($r['payload'], $r['httpCode']);
		// no break

	// --- Authorization endpoint (login + consent) ----------------------------

	case '/authorize':
		// From here on, $user is a logged-in Dolibarr user (main.inc.php
		// displayed the login form first if needed).

		$params = array(
			'client_id' => (string) GETPOST('client_id', 'alphanohtml'),
			'redirect_uri' => (string) GETPOST('redirect_uri', 'alphanohtml'),
			'state' => (string) GETPOST('state', 'alphanohtml'),
			'scope' => (string) GETPOST('scope', 'alphanohtml'),
			'resource' => (string) GETPOST('resource', 'alphanohtml'),
			'response_type' => (string) GETPOST('response_type', 'alphanohtml'),
			'code_challenge' => (string) GETPOST('code_challenge', 'alphanohtml'),
			'code_challenge_method' => (string) GETPOST('code_challenge_method', 'alphanohtml'),
		);

		$decision = $oauthRouter->validateAuthorizeRequest($params);

		// Never redirect to an unvalidated URI (open redirect protection)
		if (!$decision->valid && $decision->redirectUri === null) {
			llxHeader('', 'Dalfred OAuth');
			print '<div class="error">'.dol_escape_htmltag($decision->error).'</div>';
			llxFooter();
			exit;
		}

		if (!$decision->valid) {
			$redirectParams = array('error' => $decision->error);
			if ($decision->errorDescription !== null) {
				$redirectParams['error_description'] = $decision->errorDescription;
			}
			$redirectParams['state'] = $decision->state;
			header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($decision->redirectUri, $redirectParams), true, 302);
			exit;
		}

		$client = $decision->client;
		$redirectUri = $decision->redirectUri;
		$state = $decision->state;
		$scope = $decision->scope;
		$resource = $decision->resource;
		$codeChallenge = $decision->codeChallenge;

		$action = GETPOST('action', 'aZ09');

		if ($action === 'consent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			// CSRF is enforced by main.inc.php (token checked on POST)
			if (GETPOST('decision', 'aZ09') === 'accept') {
				$code = $oauthServer->createAuthorizationCode($client, (int) $user->id, $redirectUri, $codeChallenge, $scope, $resource);
				if ($code === null) {
					header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($redirectUri, array('error' => 'server_error', 'state' => $state)), true, 302);
					exit;
				}
				dol_syslog('[DALFRED] OAuth consent granted by user '.$user->login.' to client '.$client->client_id, LOG_INFO);
				header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($redirectUri, array('code' => $code, 'state' => $state)), true, 302);
				exit;
			}
			dol_syslog('[DALFRED] OAuth consent denied by user '.$user->login.' to client '.$client->client_id, LOG_INFO);
			header('Location: '.\DolibarrMcpOAuth\Support\UrlHelper::buildRedirect($redirectUri, array('error' => 'access_denied', 'state' => $state)), true, 302);
			exit;
		}

		// Consent screen
		$langs->load('dalfred@dalfred');
		llxHeader('', $langs->trans('DalfredOAuthConsentTitle'));

		$clientLabel = !empty($client->client_name) ? $client->client_name : $client->client_id;

		print '<div class="center" style="max-width:600px;margin:40px auto;">';
		print load_fiche_titre($langs->trans('DalfredOAuthConsentTitle'), '', 'lock');
		print '<div class="info" style="text-align:left;">';
		print $langs->trans('DalfredOAuthConsentIntro', '<strong>'.dol_escape_htmltag($clientLabel).'</strong>', '<strong>'.dol_escape_htmltag($user->login).'</strong>');
		print '</div>';
		print '<div style="text-align:left;margin:16px 0;">';
		print '<ul>';
		print '<li>'.$langs->trans('DalfredOAuthConsentScope1').'</li>';
		print '<li>'.$langs->trans('DalfredOAuthConsentScope2').'</li>';
		print '</ul>';
		print '</div>';

		print '<form method="POST" action="'.dol_escape_htmltag($issuer.'/authorize').'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="consent">';
		foreach ($params as $k => $v) {
			print '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag($v).'">';
		}
		print '<button type="submit" name="decision" value="accept" class="button buttongen marginrightonly">'.$langs->trans('DalfredOAuthAccept').'</button>';
		print '<button type="submit" name="decision" value="deny" class="button buttongen button-cancel">'.$langs->trans('DalfredOAuthDeny').'</button>';
		print '</form>';
		print '</div>';

		llxFooter();
		exit;
		// no break

	default:
		dalfred_oauth_json(array('error' => 'invalid_request', 'error_description' => 'Unknown route: '.$dalfred_route), 404);
}
