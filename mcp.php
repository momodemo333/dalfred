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
 * \file    mcp.php
 * \ingroup dalfred
 * \brief   MCP Streamable HTTP endpoint (per-request, PHP-FPM friendly).
 *
 * One HTTP request = one JSON-RPC MCP message, handled by the official
 * MCP PHP SDK (no daemon, no event loop). Sessions are persisted on disk
 * between requests (Mcp-Session-Id header).
 *
 * Authentication: the caller provides a Dolibarr user API key via
 *   - "Authorization: Bearer <key>"  (standard for MCP clients), or
 *   - "DOLAPIKEY: <key>" header      (Dolibarr REST API convention), or
 *   - "?DOLAPIKEY=<key>" query param (fallback for clients without headers).
 * The key is validated against llx_user, then forwarded to the MCP tools
 * which act through the Dolibarr REST API with that user's permissions.
 *
 * This endpoint is OFF by default: it requires both the Dalfred module to
 * be enabled AND the DALFRED_MCP_EXTERNAL_ENABLED admin toggle to be set.
 */

// Same execution context as the native REST API (htdocs/api/index.php)
if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOREQUIRETRAN')) {
	define('NOREQUIRETRAN', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
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
	die('{"error":"Main include failed"}');
}

/**
 * Emit a JSON-RPC style error and exit.
 *
 * On 401, a WWW-Authenticate header advertises the Protected Resource
 * Metadata URL so OAuth-capable MCP clients (claude.ai connectors…) can
 * run the discovery + authorization flow (RFC 9728 §5.1).
 *
 * @param int    $httpCode HTTP status code
 * @param int    $rpcCode  JSON-RPC error code
 * @param string $message  Error message
 * @return never
 */
function dalfred_error($httpCode, $rpcCode, $message)
{
	if ($httpCode == 401) {
		dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
		dalfred_mcp_oauth_autoload();
		$prm = \DolibarrMcpOAuth\Support\UrlHelper::publicUrl('/dalfred/oauth.php').'/.well-known/oauth-protected-resource';
		$endpoint = new \DolibarrMcpOAuth\HttpEndpoint($GLOBALS['db'], new \DolibarrMcpOAuth\ExposureConfig('dalfred', 'dcp_', 'DALFRED'));
		header('WWW-Authenticate: '.$endpoint->wwwAuthenticateHeader($prm));
	}
	http_response_code($httpCode);
	header('Content-Type: application/json');
	print json_encode(array(
		'jsonrpc' => '2.0',
		'id' => null,
		'error' => array('code' => $rpcCode, 'message' => $message),
	));
	exit;
}

if (!isModEnabled('dalfred')) {
	dalfred_error(403, -32000, 'Module Dalfred not enabled');
}
if (getDolGlobalInt('DALFRED_MCP_EXTERNAL_ENABLED') !== 1) {
	dalfred_error(403, -32000, 'External MCP access is disabled for this Dalfred instance');
}

// --- Authentication -------------------------------------------------------
dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
if (dalfred_mcp_oauth_autoload() === null) {
	dalfred_error(500, -32002, 'dolibarr-mcp-oauth library not found');
}

$mcpEndpoint = new \DolibarrMcpOAuth\HttpEndpoint($db, new \DolibarrMcpOAuth\ExposureConfig('dalfred', 'dcp_', 'DALFRED'));
$getPost = fn(string $name, $default) => GETPOST($name, 'alphanohtml') ?: $default;
$auth = $mcpEndpoint->resolveApiKey($_SERVER, $getPost);
if ($auth->apiKey === null) {
	dalfred_error($auth->httpCode, -32001, $auth->error);
}
$apiKey = $auth->apiKey;

// --- Load the Dolibarr MCP server package ----------------------------------

$autoload = \DolibarrMcpOAuth\Support\PackageLocator::findAutoloader(array(
	dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0),
));
if (!$autoload) {
	dalfred_error(500, -32002, 'Dolibarr MCP server package not found');
}
require_once $autoload;

/**
 * Build the read-only SQL capability, or null when the caller may not use it.
 *
 * Returning null is not a soft failure: the MCP runtime then excludes the SQL
 * tools from attribute discovery entirely, so they never appear in tools/list.
 * Deny-by-default is structural rather than a runtime check to remember.
 *
 * Must be called only after the dolibarr-mcp-server autoloader is required —
 * DolibarrMcpSql\SqlCapability implements one of that package's interfaces.
 *
 * @param  DoliDB $db     Database handler
 * @param  Conf   $conf   Dolibarr configuration
 * @param  string $apiKey Caller's validated API key
 * @return \DolibarrMcpSql\SqlCapability|null
 */
function dalfredBuildSqlCapability($db, $conf, $apiKey)
{
	dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
	if (dalfred_mcp_sql_autoload() === null) {
		dol_syslog('[DALFRED] dolibarr-mcp-sql library not found; SQL tools stay hidden', LOG_WARNING);

		return null;
	}

	$config = dalfred_sql_config();
	$permissions = new \DolibarrMcpSql\SqlPermissions($db, $conf, $config);

	// Cheapest gate first: skip loading a User object on the overwhelmingly
	// common path where the feature is simply off.
	if (!$permissions->isGloballyEnabled()) {
		return null;
	}

	$userId = dalfredResolveUserIdFromApiKey($db, $apiKey);
	if ($userId <= 0) {
		return null;
	}

	$mcpUser = new User($db);
	if ($mcpUser->fetch($userId) <= 0) {
		return null;
	}
	$mcpUser->getrights();

	$denial = $permissions->denialCode($mcpUser);
	if ($denial !== null) {
		dol_syslog('[DALFRED] SQL access refused for user '.((int) $userId).': '.$denial, LOG_INFO);

		return null;
	}

	return new \DolibarrMcpSql\SqlCapability($db, $conf, $mcpUser, $config, 'mcp');
}

/**
 * Resolve the Dolibarr user behind an API key.
 *
 * Keys are stored either in clear or encrypted depending on the instance, so
 * both forms are matched. More than one hit is treated as no hit: an ambiguous
 * key must not pick a user.
 *
 * @param  DoliDB $db     Database handler
 * @param  string $apiKey Caller's API key
 * @return int            User id, or 0 when it resolves to nothing usable
 */
function dalfredResolveUserIdFromApiKey($db, $apiKey)
{
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user";
	$sql .= " WHERE api_key = '".$db->escape($apiKey)."'";
	$sql .= " OR api_key = '".$db->escape(dolEncrypt($apiKey, '', '', 'dolibarr'))."'";

	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) !== 1) {
		return 0;
	}
	$obj = $db->fetch_object($resql);

	return $obj ? (int) $obj->rowid : 0;
}

// --- Handle the MCP request ------------------------------------------------

// The MCP tools act through the local Dolibarr REST API with the caller's key.
// The config is request-scoped (no putenv: FPM workers are reused across
// requests and users, process-global state must not carry credentials).
$config = new DolibarrMcp\Config\ConnectionConfig(DOL_MAIN_URL_ROOT, $apiKey);

// Persist MCP sessions between PHP-FPM requests
$sessionDir = DOL_DATA_ROOT.'/dalfred/mcp_sessions';

try {
	// A null capability keeps the SQL tools out of discovery entirely.
	$sqlCapability = dalfredBuildSqlCapability($db, $conf, $apiKey);
	$response = DolibarrMcp\Bootstrap::handleHttpRequest(null, $sessionDir, $config, $sqlCapability);
	DolibarrMcp\Bootstrap::emit($response);
} catch (Throwable $e) {
	dol_syslog('[DALFRED] ERROR '.$e->getMessage(), LOG_ERR);
	dalfred_error(500, -32603, 'Internal MCP server error: '.$e->getMessage());
}

$db->close();
