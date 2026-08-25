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
 * \file    admin/sql_access.php
 * \ingroup dalfred
 * \brief   Read-only SQL access: global switch, limits, per-user opt-in, audit.
 *
 * Every other MCP tool inherits the caller's Dolibarr permissions through the
 * REST API. Raw SQL inherits nothing, so this page is the only place where the
 * access is granted — deliberately in several explicit steps, none of which is
 * on by default.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Main include failed");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/dalfred/lib/dalfred.lib.php');
require_once dol_buildpath('/dalfred/vendor/autoload.php', 0);

use Dalfred\Service\DalfredMigrations;

dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
dalfred_mcp_sql_autoload();

$langs->loadLangs(array('admin', 'users', 'other', 'dalfred@dalfred'));

if (!$user->admin) {
	accessforbidden();
}

// Dolibarr does not replay init() when a customer merely uploads new files, and
// this module has no business UI to hang a hook on: its entry points carry the
// migration instead. See DalfredMigrations for the full reasoning.
//
// A failure is surfaced rather than ignored: the settings below write to tables
// the migration is responsible for, so letting an administrator toggle things
// against a stale schema produces confusing half-failures.
$migrationResult = DalfredMigrations::createHelper($db)->migrateIfNeeded();
$schemaReady = ($migrationResult['errors'] === []);

$permissions = new \DolibarrMcpSql\SqlPermissions($db, $conf, dalfred_sql_config());

$action = GETPOST('action', 'aZ09');
$userid = GETPOST('userid', 'int');

// Hard ceilings, kept in sync with \DolibarrMcp\Sql\SqlPolicy so the admin page
// cannot store a value the policy would silently clamp anyway.
$maxRowsCeiling = 5000;
$timeoutCeiling = 30;
$maxBytesCeiling = 4194304;

// Constants driven by ajax_constantonoff(). The AJAX path writes them through
// core/ajax/constantonoff.php; this list only serves the no-javascript
// fallback, which posts back here as set_<CODE> / del_<CODE>.
$togglableConstants = array(
	'DALFRED_SQL_ENABLED',
	'DALFRED_SQL_AUDIT_HASH_ONLY',
	'DALFRED_SQL_ALLOW_MULTIENTITY',
);

/*
 * Actions
 */

if ($action !== '') {
	$token = GETPOST('token', 'alpha');
	if (!$token || $token !== newToken()) {
		// CSRF protection: drop the request and come back to a clean page.
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	// Every action here writes to a table the migration owns.
	if (!$schemaReady) {
		setEventMessages($langs->trans('DalfredSqlSchemaOutOfDate'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}

// ajax_constantonoff() fallback when javascript is unavailable
if (preg_match('/^(set|del)_([A-Z0-9_]+)$/', $action, $reg) && in_array($reg[2], $togglableConstants, true)) {
	if ($reg[1] === 'set') {
		dolibarr_set_const($db, $reg[2], '1', 'chaine', 0, '', $conf->entity);
	} else {
		dolibarr_del_const($db, $reg[2], $conf->entity);
	}
	setEventMessages($langs->trans('RecordModifiedSuccessfully'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'update_limits') {
	$maxRows = (int) GETPOST('DALFRED_SQL_MAX_ROWS', 'int');
	$timeout = (int) GETPOST('DALFRED_SQL_TIMEOUT', 'int');
	$maxBytes = (int) GETPOST('DALFRED_SQL_MAX_BYTES', 'int');

	$maxRows = max(1, min($maxRowsCeiling, $maxRows ?: 200));
	$timeout = max(1, min($timeoutCeiling, $timeout ?: 5));
	$maxBytes = max(1024, min($maxBytesCeiling, $maxBytes ?: 262144));

	dolibarr_set_const($db, 'DALFRED_SQL_MAX_ROWS', (string) $maxRows, 'chaine', 0, 'Dalfred read-only SQL: max rows returned', $conf->entity);
	dolibarr_set_const($db, 'DALFRED_SQL_TIMEOUT', (string) $timeout, 'chaine', 0, 'Dalfred read-only SQL: statement timeout in seconds', $conf->entity);
	dolibarr_set_const($db, 'DALFRED_SQL_MAX_BYTES', (string) $maxBytes, 'chaine', 0, 'Dalfred read-only SQL: max response size in bytes', $conf->entity);

	setEventMessages($langs->trans('DalfredSqlSettingsSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'update_permissions' && $userid > 0) {
	$enabled = GETPOST('sql_enabled_'.$userid, 'int') ? true : false;

	if ($permissions->setUserOptIn($userid, $enabled, $user->id)) {
		setEventMessages($langs->trans('DalfredSqlPermissionsUpdated'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$globallyEnabled = $permissions->isGloballyEnabled();
$maxRows = getDolGlobalInt('DALFRED_SQL_MAX_ROWS') ?: 200;
$timeout = getDolGlobalInt('DALFRED_SQL_TIMEOUT') ?: 5;
$maxBytes = getDolGlobalInt('DALFRED_SQL_MAX_BYTES') ?: 262144;

llxHeader('', $langs->trans('DalfredSqlAccess'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'title_setup');

$head = dalfred_admin_prepare_head();
print dol_get_fiche_head($head, 'sqlaccess', $langs->trans('DalfredSetup'), -1, 'fa-robot');

print '<span class="opacitymedium">'.$langs->trans('DalfredSqlAccessScope').'<br><br>'.$langs->trans('DalfredSqlAccessIntro').'<br><br>'.$langs->trans('DalfredSqlAccessConnection').'</span><br><br>';

if (!$schemaReady) {
	print '<div class="error" style="padding:12px;margin:10px 0;">';
	print dol_escape_htmltag($langs->trans('DalfredSqlSchemaOutOfDate'));
	print '</div>';
}

// ---------------------------------------------------------------------------
// Section 1 — global switch
// ---------------------------------------------------------------------------

print load_fiche_titre($langs->trans('DalfredSqlGlobalSwitch'), '', '');

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center" width="100">'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DalfredSqlEnabled');
print '<br><span class="opacitymedium small">'.$langs->trans('DalfredSqlEnabledDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('DALFRED_SQL_ENABLED').'</td></tr>';

print '</table>';
print '</div>';

if (!$globallyEnabled) {
	print '<div class="opacitymedium" style="padding:10px 0;">'.$langs->trans('DalfredSqlGloballyDisabled').'</div>';
}

print '<br>';

// ---------------------------------------------------------------------------
// Section 2 — limits and audit behaviour
// ---------------------------------------------------------------------------

print load_fiche_titre($langs->trans('DalfredSqlLimits'), '', '');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_limits">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center" width="160">'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DalfredSqlMaxRows');
print '<br><span class="opacitymedium small">'.$langs->trans('DalfredSqlMaxRowsDesc', $maxRowsCeiling).'</span></td>';
print '<td class="center"><input type="number" class="width75" name="DALFRED_SQL_MAX_ROWS" min="1" max="'.((int) $maxRowsCeiling).'" value="'.((int) $maxRows).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredSqlTimeout');
print '<br><span class="opacitymedium small">'.$langs->trans('DalfredSqlTimeoutDesc', $timeoutCeiling).'</span></td>';
print '<td class="center"><input type="number" class="width75" name="DALFRED_SQL_TIMEOUT" min="1" max="'.((int) $timeoutCeiling).'" value="'.((int) $timeout).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredSqlMaxBytes');
print '<br><span class="opacitymedium small">'.$langs->trans('DalfredSqlMaxBytesDesc', $maxBytesCeiling).'</span></td>';
print '<td class="center"><input type="number" class="width100" name="DALFRED_SQL_MAX_BYTES" min="1024" max="'.((int) $maxBytesCeiling).'" value="'.((int) $maxBytes).'"></td></tr>';

print '<tr class="oddeven"><td colspan="2" class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans('Save').'">';
print '</td></tr>';

print '</table>';
print '</div>';
print '</form>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center" width="100">'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DalfredSqlAuditHashOnly');
print '<br><span class="opacitymedium small">'.$langs->trans('DalfredSqlAuditHashOnlyDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('DALFRED_SQL_AUDIT_HASH_ONLY').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredSqlAllowMultiEntity');
print '<br><span class="opacitymedium small">'.$langs->trans('DalfredSqlAllowMultiEntityDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('DALFRED_SQL_ALLOW_MULTIENTITY').'</td></tr>';

print '</table>';
print '</div>';

print '<br>';

// ---------------------------------------------------------------------------
// Section 3 — per-user opt-in
// ---------------------------------------------------------------------------

print load_fiche_titre($langs->trans('DalfredSqlUsers'), '', '');

// Mandatory warning: this right has nothing to do with the granted user's
// usual business permissions, and an administrator must not discover that
// afterwards.
print '<div class="warning" style="padding:15px;margin:10px 0;border:2px solid #ff6b6b;background-color:#ffe0e0;border-radius:5px;">';
print '<strong>'.$langs->trans('Warning').' : </strong>'.$langs->trans('DalfredSqlUsersWarning');
print '</div>';

$candidates = $permissions->listCandidateUsers();

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('User').'</td>';
print '<td>'.$langs->trans('Login').'</td>';
print '<td class="center">'.$langs->trans('Administrator').'</td>';
print '<td class="center">'.$langs->trans('DalfredSqlDolibarrRight').'</td>';
print '<td class="center">'.$langs->trans('DalfredSqlOptIn').'</td>';
print '<td class="center">'.$langs->trans('DalfredSqlEffectiveAccess').'</td>';
print '</tr>';

if (empty($candidates)) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans('DalfredSqlNoUsers').'</td></tr>';
}

foreach ($candidates as $candidate) {
	$tmpUser = new User($db);
	$hasRight = false;
	if ($tmpUser->fetch($candidate['id']) > 0) {
		$tmpUser->getrights('dalfred');
		$hasRight = method_exists($tmpUser, 'hasRight')
			? (bool) $tmpUser->hasRight('dalfred', 'sqlquery', 'read')
			: !empty($tmpUser->rights->dalfred->sqlquery->read);
	}

	print '<tr class="oddeven">';

	print '<td>'.($tmpUser->id > 0 ? $tmpUser->getNomUrl(1) : dol_escape_htmltag($candidate['login'])).'</td>';
	print '<td>'.dol_escape_htmltag($candidate['login']).'</td>';

	print '<td class="center">';
	print $candidate['admin'] ? img_picto($langs->trans('Yes'), 'tick') : '<span class="opacitymedium">-</span>';
	print '</td>';

	// Dolibarr right, granted from the user's "Permissions" tab
	print '<td class="center">';
	if ($hasRight) {
		print '<span class="badge badge-status4 badge-status">'.$langs->trans('Enabled').'</span>';
	} else {
		print '<span class="badge badge-status8 badge-status" title="'.dol_escape_htmltag($langs->trans('DalfredSqlRightMissing')).'">'.$langs->trans('Disabled').'</span>';
	}
	print '</td>';

	// Per-user opt-in stored by this page
	print '<td class="center">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_permissions">';
	print '<input type="hidden" name="userid" value="'.((int) $candidate['id']).'">';
	print '<input type="checkbox" name="sql_enabled_'.((int) $candidate['id']).'" value="1"'.($candidate['opted_in'] ? ' checked' : '').' onchange="this.form.submit()">';
	print '</form>';
	print '</td>';

	// What the MCP endpoint will actually answer
	print '<td class="center">';
	if ($globallyEnabled && $hasRight && $candidate['opted_in']) {
		print '<span class="badge badge-status4 badge-status">'.$langs->trans('Enabled').'</span>';
	} else {
		print '<span class="badge badge-status8 badge-status">'.$langs->trans('Disabled').'</span>';
	}
	print '</td>';

	print '</tr>';
}

print '</table>';
print '</div>';

print '<div class="opacitymedium small" style="padding:8px 0;">'.$langs->trans('DalfredSqlOptInHelp').'</div>';

print '<br>';

// ---------------------------------------------------------------------------
// Section 5 — read-only reminder of what the policy refuses
// ---------------------------------------------------------------------------

print load_fiche_titre($langs->trans('DalfredSqlPolicy'), '', '');

$policy = null;
// The policy display needs the MCP server package; when it is absent the page
// still renders, it just cannot show the denied scope.
$autoload = dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0);
if ($autoload && file_exists($autoload)) {
	require_once $autoload;
	if (class_exists('\DolibarrMcp\Sql\SqlPolicy')) {
		$policy = new \DolibarrMcp\Sql\SqlPolicy(MAIN_DB_PREFIX, array(
			'maxRows' => $maxRows,
			'timeoutSeconds' => $timeout,
			'maxBytes' => $maxBytes,
		));
	}
}

if ($policy === null) {
	print '<div class="opacitymedium">'.$langs->trans('DalfredSqlPolicyUnavailable').'</div>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('DalfredSqlPolicyIntro').'</span><br><br>';

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

	$rows = array(
		'DalfredSqlDeniedTables' => $policy->deniedTables(),
		'DalfredSqlDeniedColumns' => $policy->deniedColumns(),
		'DalfredSqlDeniedColumnFragments' => $policy->deniedColumnFragments(),
		'DalfredSqlDeniedFunctions' => $policy->deniedFunctions(),
	);
	foreach ($rows as $key => $values) {
		print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($key).'</td>';
		print '<td><span class="small">'.dol_escape_htmltag(implode(', ', $values)).'</span></td></tr>';
	}

	print '</table>';
	print '</div>';
}

print '<br>';

// ---------------------------------------------------------------------------
// Section 6 — last audit records (query results are never stored)
// ---------------------------------------------------------------------------

print load_fiche_titre($langs->trans('DalfredSqlAuditTrail'), '', '');

print '<span class="opacitymedium">'.$langs->trans('DalfredSqlAuditTrailIntro').'</span><br><br>';

$sql = "SELECT a.rowid, a.date_creation, a.fk_user, a.sql_hash, a.sql_text, a.duration_ms,";
$sql .= " a.row_count, a.success, a.error_code, a.source, u.login";
$sql .= " FROM ".MAIN_DB_PREFIX."dalfred_sql_audit as a";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = a.fk_user";
$sql .= " WHERE a.entity = ".((int) $conf->entity);
$sql .= " ORDER BY a.date_creation DESC, a.rowid DESC";
$sql .= $db->plimit(20, 0);

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('User').'</td>';
print '<td class="center">'.$langs->trans('DalfredSqlAuditDuration').'</td>';
print '<td class="center">'.$langs->trans('DalfredSqlAuditRows').'</td>';
print '<td class="center">'.$langs->trans('Status').'</td>';
print '<td class="center">'.$langs->trans('DalfredSqlAuditSource').'</td>';
print '<td>'.$langs->trans('DalfredSqlAuditQuery').'</td>';
print '</tr>';

$resql = $db->query($sql);
if (!$resql) {
	print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans('DalfredSqlAuditEmpty').'</td></tr>';
} else {
	if ($db->num_rows($resql) === 0) {
		print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans('DalfredSqlAuditEmpty').'</td></tr>';
	}
	while ($obj = $db->fetch_object($resql)) {
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhoursec').'</td>';
		print '<td>'.dol_escape_htmltag($obj->login ? $obj->login : '#'.((int) $obj->fk_user)).'</td>';
		print '<td class="center">'.((int) $obj->duration_ms).' ms</td>';
		print '<td class="center">'.((int) $obj->row_count).'</td>';
		print '<td class="center">';
		if ((int) $obj->success === 1) {
			print img_picto($langs->trans('DalfredSqlAuditSuccess'), 'tick');
		} else {
			print img_picto($langs->trans('Error').($obj->error_code ? ' — '.$obj->error_code : ''), 'error');
		}
		print '</td>';
		print '<td class="center">'.dol_escape_htmltag((string) $obj->source).'</td>';
		// Never a query result: only the query itself, truncated, or its hash
		// when DALFRED_SQL_AUDIT_HASH_ONLY is on.
		print '<td><span class="small">';
		if ($obj->sql_text !== null && $obj->sql_text !== '') {
			print dol_escape_htmltag(dol_trunc((string) $obj->sql_text, 160));
		} else {
			print '<span class="opacitymedium">'.$langs->trans('DalfredSqlAuditHashOnlyRow').' '.dol_escape_htmltag(substr((string) $obj->sql_hash, 0, 16)).'…</span>';
		}
		print '</span></td>';
		print '</tr>';
	}
	$db->free($resql);
}

print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
