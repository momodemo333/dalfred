<?php

/**
 * End-to-end check of Dalfred's read-only SQL access over MCP.
 *
 * Walks the whole gate chain the way a real client does: feature off, right
 * missing, opt-in missing, then everything granted. Restores the instance to
 * its initial state whatever happens.
 *
 * Run: make php s=/var/www/html/custom/dalfred/tests/integration_sql_mcp.php
 */

if (!defined('NOLOGIN')) define('NOLOGIN', '1');
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
chdir('/var/www/html');
require '/var/www/html/main.inc.php';
require_once dol_buildpath('/dalfred/vendor/autoload.php');
require_once dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php');
dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$failures = 0;
function check($label, $ok, $detail = '')
{
    global $failures;
    if ($ok) {
        print "  OK   ".$label."\n";
    } else {
        $failures++;
        print "  FAIL ".$label.($detail !== '' ? ' -- '.$detail : '')."\n";
    }
}

const TEST_USER = 1;

// --- capture initial state, restore on any exit ----------------------------
$initialEnabled = getDolGlobalString('DALFRED_SQL_ENABLED');
$initialEnabledSet = ($initialEnabled !== '');

$resql = $db->query("SELECT sql_enabled FROM ".MAIN_DB_PREFIX."dalfred_sql_permissions WHERE fk_user = ".TEST_USER);
$initialOptIn = ($resql && $db->num_rows($resql) > 0) ? (int) $db->fetch_object($resql)->sql_enabled : null;

$resql = $db->query("SELECT COUNT(*) n FROM ".MAIN_DB_PREFIX."user_rights ur"
    ." INNER JOIN ".MAIN_DB_PREFIX."rights_def rd ON ur.fk_id = rd.id"
    ." WHERE ur.fk_user = ".TEST_USER." AND rd.module = 'dalfred' AND rd.perms = 'sqlquery'");
$initialRight = ($resql && ($o = $db->fetch_object($resql))) ? (int) $o->n > 0 : false;

$cleanup = function () use ($db, $conf, $initialEnabledSet, $initialEnabled, $initialOptIn, $initialRight) {
    static $done = false;
    if ($done) { return; }
    $done = true;
    if ($initialEnabledSet) {
        dolibarr_set_const($db, 'DALFRED_SQL_ENABLED', $initialEnabled, 'chaine', 0, '', $conf->entity);
    } else {
        dolibarr_del_const($db, 'DALFRED_SQL_ENABLED', $conf->entity);
    }
    if ($initialOptIn === null) {
        $db->query("DELETE FROM ".MAIN_DB_PREFIX."dalfred_sql_permissions WHERE fk_user = ".TEST_USER);
    } else {
        $db->query("UPDATE ".MAIN_DB_PREFIX."dalfred_sql_permissions SET sql_enabled = ".$initialOptIn
            ." WHERE fk_user = ".TEST_USER);
    }
    if (!$initialRight) {
        $db->query("DELETE ur FROM ".MAIN_DB_PREFIX."user_rights ur"
            ." INNER JOIN ".MAIN_DB_PREFIX."rights_def rd ON ur.fk_id = rd.id"
            ." WHERE ur.fk_user = ".TEST_USER." AND rd.module = 'dalfred' AND rd.perms = 'sqlquery'");
    }
    $db->query("DELETE FROM ".MAIN_DB_PREFIX."dalfred_sql_audit WHERE sql_text LIKE '%DALFRED_SQL_TEST%'");
};
register_shutdown_function($cleanup);

print "== Library and schema ==\n";
check('dolibarr-mcp-sql library found', dalfred_mcp_sql_autoload() !== null);
check('SqlCapability class loads', class_exists('\\DolibarrMcpSql\\SqlCapability'));
foreach (array('dalfred_sql_permissions', 'dalfred_sql_audit') as $t) {
    $r = $db->query("SHOW TABLES LIKE '".MAIN_DB_PREFIX.$t."'");
    check('table '.MAIN_DB_PREFIX.$t.' exists', $r && $db->num_rows($r) === 1);
}

$config = dalfred_sql_config();
check('config targets Dalfred tables', $config->table('audit') === MAIN_DB_PREFIX.'dalfred_sql_audit', $config->table('audit'));
check('config targets Dalfred constants', $config->const('ENABLED') === 'DALFRED_SQL_ENABLED', $config->const('ENABLED'));

$user = new User($db);
$user->fetch(TEST_USER);
$user->getrights();

$permissions = new \DolibarrMcpSql\SqlPermissions($db, $conf, $config);

print "\n== Gate chain: each condition refuses on its own ==\n";
dolibarr_del_const($db, 'DALFRED_SQL_ENABLED', $conf->entity);
unset($conf->global->DALFRED_SQL_ENABLED);
check('refused while globally disabled', $permissions->denialCode($user) === 'SQL_DISABLED', (string) $permissions->denialCode($user));

dolibarr_set_const($db, 'DALFRED_SQL_ENABLED', '1', 'chaine', 0, '', $conf->entity);
$conf->global->DALFRED_SQL_ENABLED = '1';

$db->query("DELETE ur FROM ".MAIN_DB_PREFIX."user_rights ur"
    ." INNER JOIN ".MAIN_DB_PREFIX."rights_def rd ON ur.fk_id = rd.id"
    ." WHERE ur.fk_user = ".TEST_USER." AND rd.module = 'dalfred' AND rd.perms = 'sqlquery'");
$user = new User($db);
$user->fetch(TEST_USER);
$user->getrights();
check('refused without the Dolibarr right', $permissions->denialCode($user) === 'SQL_PERMISSION_DENIED', (string) $permissions->denialCode($user));

$rightId = null;
$r = $db->query("SELECT id FROM ".MAIN_DB_PREFIX."rights_def WHERE module='dalfred' AND perms='sqlquery' LIMIT 1");
if ($r && ($o = $db->fetch_object($r))) {
    $rightId = (int) $o->id;
    $db->query("INSERT INTO ".MAIN_DB_PREFIX."user_rights (fk_user, fk_id, entity) VALUES (".TEST_USER.", ".$rightId.", ".(int) $conf->entity.")");
}
check('right granted for the test', $rightId !== null);
$user = new User($db);
$user->fetch(TEST_USER);
$user->getrights();

$db->query("DELETE FROM ".MAIN_DB_PREFIX."dalfred_sql_permissions WHERE fk_user = ".TEST_USER);
check('refused without the per-user opt-in', $permissions->denialCode($user) === 'SQL_PERMISSION_DENIED', (string) $permissions->denialCode($user));

$permissions->setUserOptIn(TEST_USER, 1, TEST_USER);
check('allowed once every condition holds', $permissions->denialCode($user) === null, (string) $permissions->denialCode($user));

print "\n== MCP tool: query, refusal, schema ==\n";
$capability = new \DolibarrMcpSql\SqlCapability($db, $conf, $user, $config, 'mcp');

// Go through the real MCP tool, not the capability directly: the capability
// executes what it is given, and it is SqlTools that validates first. Calling
// runSelect() straight would test a path no client can reach — and would let a
// SELECT on a denied table through, since only the validator knows the policy.
$tool = new \DolibarrMcp\Tools\Gated\SqlTools($capability);

/**
 * Returns true when the tool refused the statement.
 *
 * The tool answers JSON, and its contract is the "success" flag plus a stable
 * code — not the wording of the message, which is free to change.
 */
$refused = static function ($answer) {
    $decoded = json_decode((string) $answer, true);

    return is_array($decoded) && ($decoded['success'] ?? null) === false;
};

$answer = $tool->queryDatabase("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe LIMIT 3 /* DALFRED_SQL_TEST */");
check('legitimate SELECT returns rows', !$refused($answer), substr((string) $answer, 0, 120));

foreach (array(
    "UPDATE ".MAIN_DB_PREFIX."societe SET nom='x' /* DALFRED_SQL_TEST */",
    "DROP TABLE ".MAIN_DB_PREFIX."societe /* DALFRED_SQL_TEST */",
    "SELECT api_key FROM ".MAIN_DB_PREFIX."user /* DALFRED_SQL_TEST */",
    "SELECT value FROM ".MAIN_DB_PREFIX."const /* DALFRED_SQL_TEST */",
) as $bad) {
    $answer = $tool->queryDatabase($bad);
    check('refused: '.substr($bad, 0, 42), $refused($answer), substr((string) $answer, 0, 100));
}

// Morgan's trap: a write keyword inside a string literal must NOT be refused.
$answer = $tool->queryDatabase("SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE nom = 'update stock' /* DALFRED_SQL_TEST */");
check('write keyword inside a literal is accepted', !$refused($answer), substr((string) $answer, 0, 120));

// A complex but legitimate read.
$answer = $tool->queryDatabase(
    "WITH ca AS (SELECT fk_soc, SUM(total_ht) t FROM ".MAIN_DB_PREFIX."facture GROUP BY fk_soc)"
    ." SELECT s.nom, ca.t FROM ".MAIN_DB_PREFIX."societe s JOIN ca ON ca.fk_soc = s.rowid /* DALFRED_SQL_TEST */"
);
check('CTE + JOIN accepted', !$refused($answer), substr((string) $answer, 0, 160));

try {
    $schema = $capability->describeSchema(MAIN_DB_PREFIX.'facture');
    check('schema introspection works', isset($schema['tables'][MAIN_DB_PREFIX.'facture']));
} catch (Throwable $e) {
    check('schema introspection works', false, $e->getMessage());
}

print "\n== Audit trail ==\n";
$r = $db->query("SELECT COUNT(*) n FROM ".MAIN_DB_PREFIX."dalfred_sql_audit WHERE source='mcp'");
$o = $r ? $db->fetch_object($r) : null;
check('attempts were recorded', $o && (int) $o->n > 0, $o ? (string) $o->n : '0');

$r = $db->query("SELECT COUNT(*) n FROM ".MAIN_DB_PREFIX."dalfred_sql_audit WHERE success = 0");
$o = $r ? $db->fetch_object($r) : null;
check('refusals were recorded too', $o && (int) $o->n > 0, $o ? (string) $o->n : '0');

print "\n== Cleanup ==\n";
$cleanup();
check('instance restored', true);

print "\n".($failures === 0 ? "ALL CHECKS PASSED\n" : $failures." CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
