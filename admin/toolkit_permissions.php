<?php
/**
 * Dalfred Permissions Page
 *
 * User management overview with API key, Dalfred access, and MySQL toolkit permissions.
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.10.0
 * @license    GPL-3.0+
 */

// Load Dolibarr environment
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
    die("Main include failed");
}

// Load required libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once '../lib/dalfred.lib.php';

// Load language files
$langs->loadLangs(array("admin", "users", "dalfred@dalfred"));

// Security check - only admins can access setup
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$userid = GETPOST('userid', 'int');

/*
 * Actions
 */

// Generate API key for a user
if ($action == 'generate_api_key' && $userid > 0) {
    $token = GETPOST('token', 'alpha');
    if (!$token || $token != newToken()) {
        // CSRF protection - silently redirect
        header("Location: ".$_SERVER["PHP_SELF"]);
        exit;
    }

    $tmpUser = new User($db);
    $result = $tmpUser->fetch($userid);

    if ($result > 0) {
        $tmpUser->api_key = getRandomPassword(true, null, 64);
        $result = $tmpUser->update($user);

        if ($result > 0) {
            setEventMessages($langs->trans("ApiKeyGenerated", $tmpUser->login), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("Error"), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// Handle global toolkit toggle
if ($action == 'update_global_mysql') {
    $value = GETPOST('DALFRED_MYSQL_TOOLKIT_ENABLED', 'int') ? '1' : '0';
    dolibarr_set_const($db, 'DALFRED_MYSQL_TOOLKIT_ENABLED', $value, 'chaine', 0, 'Enable MySQL toolkit for Dalfred', $conf->entity);
    setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update_global_mysql_write') {
    $value = GETPOST('DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED', 'int') ? '1' : '0';
    dolibarr_set_const($db, 'DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED', $value, 'chaine', 0, 'Enable MySQL write for Dalfred', $conf->entity);
    setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update_global_mysql_schema') {
    $value = GETPOST('DALFRED_MYSQL_SCHEMA_ENABLED', 'int') ? '1' : '0';
    dolibarr_set_const($db, 'DALFRED_MYSQL_SCHEMA_ENABLED', $value, 'chaine', 0, 'Enable MySQL schema introspection for Dalfred', $conf->entity);
    setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update_global_smartquery') {
    $value = GETPOST('DALFRED_SMARTQUERY_ENABLED', 'int') ? '1' : '0';
    dolibarr_set_const($db, 'DALFRED_SMARTQUERY_ENABLED', $value, 'chaine', 0, 'Enable Smart Queries for Dalfred', $conf->entity);
    setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update_global_file_gen') {
    $value = GETPOST('DALFRED_FILE_GEN_ENABLED', 'int') ? '1' : '0';
    dolibarr_set_const($db, 'DALFRED_FILE_GEN_ENABLED', $value, 'chaine', 0, 'Enable file generation by the agent', $conf->entity);
    setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update_file_gen_max_size') {
    $mb = (int) GETPOST('DALFRED_FILE_GEN_MAX_SIZE_MB', 'int');
    if ($mb < 1) $mb = 1;
    if ($mb > 100) $mb = 100; // sanity cap
    $bytes = $mb * 1024 * 1024;
    dolibarr_set_const($db, 'DALFRED_FILE_GEN_MAX_SIZE', (string) $bytes, 'chaine', 0, 'Max file size in bytes for generated files', $conf->entity);
    setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update_permissions' && $userid > 0) {
    $mysql_enabled = GETPOST('mysql_enabled_'.$userid, 'int') ? 1 : 0;
    $mysql_write = GETPOST('mysql_write_'.$userid, 'int') ? 1 : 0;

    // Check if record exists
    $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."dalfred_toolkit_permissions";
    $sql .= " WHERE fk_user = ".((int) $userid)." AND entity = ".((int) $conf->entity);
    $resql = $db->query($sql);

    if ($resql && $db->num_rows($resql) > 0) {
        // Update existing
        $sql = "UPDATE ".MAIN_DB_PREFIX."dalfred_toolkit_permissions SET";
        $sql .= " mysql_enabled = ".((int) $mysql_enabled).",";
        $sql .= " mysql_write_enabled = ".((int) $mysql_write).",";
        $sql .= " date_modification = '".$db->idate(dol_now())."',";
        $sql .= " fk_user_modif = ".((int) $user->id);
        $sql .= " WHERE fk_user = ".((int) $userid)." AND entity = ".((int) $conf->entity);
    } else {
        // Insert new
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."dalfred_toolkit_permissions";
        $sql .= " (entity, fk_user, mysql_enabled, mysql_write_enabled, date_creation, fk_user_creat)";
        $sql .= " VALUES (".((int) $conf->entity).", ".((int) $userid).", ".((int) $mysql_enabled).", ".((int) $mysql_write).", '".$db->idate(dol_now())."', ".((int) $user->id).")";
    }

    if ($db->query($sql)) {
        setEventMessages($langs->trans("PermissionsUpdated"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

/*
 * View
 */

$page_name = "DalfredSetup";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader with tabs
$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'toolkits', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Title
print load_fiche_titre($langs->trans("ToolkitPermissions"), '', 'title_setup');

// ============================================================
// Section 1: User Management
// ============================================================

$mysqlEnabled = getDolGlobalString('DALFRED_MYSQL_TOOLKIT_ENABLED', '0');
$mysqlWriteEnabled = getDolGlobalString('DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED', '0');
// Schema introspection: default ON. getDolGlobalString returns '' when unset
// (a fresh install before the v2.22.0 migration runs); treat that as enabled.
$schemaRaw = getDolGlobalString('DALFRED_MYSQL_SCHEMA_ENABLED');
$mysqlSchemaEnabled = ($schemaRaw === '') ? '1' : $schemaRaw;

// Get list of active users with toolkit permissions
$sql = "SELECT u.rowid, u.login, u.lastname, u.firstname, u.statut as status, u.admin as is_admin,";
$sql .= " u.api_key,";
$sql .= " tp.mysql_enabled, tp.mysql_write_enabled";
$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."dalfred_toolkit_permissions as tp ON (tp.fk_user = u.rowid AND tp.entity = ".((int) $conf->entity).")";
$sql .= " WHERE u.entity IN (0, ".((int) $conf->entity).")";
$sql .= " AND u.statut = 1"; // Only active users
$sql .= " ORDER BY u.login ASC";

$resql = $db->query($sql);

if ($resql) {
    $num = $db->num_rows($resql);

    print '<div class="div-table-responsive-no-min">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans("User").'</td>';
    print '<td class="center">'.$langs->trans("DalfredAccess").'</td>';
    print '<td class="center">'.$langs->trans("ApiKey").'</td>';
    print '<td class="center">'.$langs->trans("MySQLReadAccess").'</td>';
    print '<td class="center">'.$langs->trans("MySQLWriteAccess").'</td>';
    print '<td class="center">'.$langs->trans("Status").'</td>';
    print '</tr>';

    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            $tmpUser = new User($db);
            $tmpUser->fetch($obj->rowid);
            $tmpUser->getrights('dalfred');

            $hasDalfredRight = !empty($tmpUser->rights->dalfred->use);
            $hasApiKey = !empty($obj->api_key);

            print '<tr class="oddeven">';

            // User
            print '<td>';
            print $tmpUser->getNomUrl(1);
            if ($obj->is_admin) {
                print ' <span class="badge badge-warning">Admin</span>';
            }
            print '</td>';

            // Dalfred Access
            print '<td class="center">';
            if ($hasDalfredRight) {
                print '<span class="badge badge-status4">'.$langs->trans("Activated").'</span>';
            } else {
                print '<span class="badge badge-status8" title="'.$langs->trans("UserNoDalfredRight").'">'.$langs->trans("Disabled").'</span>';
            }
            print '</td>';

            // API Key
            print '<td class="center">';
            if ($hasApiKey) {
                print '<span class="badge badge-status4">'.$langs->trans("ApiKeyConfigured").'</span>';
            } else {
                print '<span class="badge badge-status8">'.$langs->trans("ApiKeyMissing").'</span>';
                print ' <a class="button buttongen reposition smallpaddingimp" href="'.$_SERVER["PHP_SELF"].'?action=generate_api_key&userid='.$obj->rowid.'&token='.newToken().'"';
                print ' onclick="return confirm(\''.dol_escape_js($langs->trans("GenerateApiKeyConfirm")).'\')"';
                print '>';
                print $langs->trans("GenerateApiKey");
                print '</a>';
            }
            print '</td>';

            // MySQL Read
            print '<td class="center">';
            if ($mysqlEnabled) {
                print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
                print '<input type="hidden" name="token" value="'.newToken().'">';
                print '<input type="hidden" name="action" value="update_permissions">';
                print '<input type="hidden" name="userid" value="'.$obj->rowid.'">';
                print '<input type="checkbox" name="mysql_enabled_'.$obj->rowid.'" value="1"'.($obj->mysql_enabled ? ' checked' : '').' onchange="this.form.submit()">';
                print '</form>';
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            // MySQL Write
            print '<td class="center">';
            if ($mysqlEnabled) {
                print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
                print '<input type="hidden" name="token" value="'.newToken().'">';
                print '<input type="hidden" name="action" value="update_permissions">';
                print '<input type="hidden" name="userid" value="'.$obj->rowid.'">';
                print '<input type="hidden" name="mysql_enabled_'.$obj->rowid.'" value="'.($obj->mysql_enabled ? '1' : '0').'">';
                $disabled = !$obj->mysql_enabled ? ' disabled' : '';
                print '<input type="checkbox" name="mysql_write_'.$obj->rowid.'" value="1"'.($obj->mysql_write_enabled ? ' checked' : '').$disabled.' onchange="this.form.submit()">';
                print '</form>';
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            // Status
            print '<td class="center">';
            if ($hasDalfredRight && $hasApiKey) {
                print '<span class="badge badge-status4" title="'.$langs->trans("UserReadyDesc").'">'.$langs->trans("UserReady").'</span>';
            } else {
                print '<span class="badge badge-status8" title="'.$langs->trans("UserReadyDesc").'">'.$langs->trans("UserNotReady").'</span>';
            }
            print '</td>';

            print '</tr>';
        }
    } else {
        print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">'.$langs->trans("NoUsers").'</td></tr>';
    }

    print '</table>';
    print '</div>';

    $db->free($resql);
} else {
    dol_print_error($db);
}

print '<br>';

// ============================================================
// Section 2: MySQL Toolkit Global Settings
// ============================================================

print '<div class="warning" style="padding: 15px; margin: 10px 0; border: 2px solid #ff6b6b; background-color: #ffe0e0; border-radius: 5px;">';
print '<p><strong>'.$langs->trans("Warning").':</strong> '.$langs->trans("MySQLToolkitWarning").'</p>';
print '</div>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("MySQLToolkitGlobalSettings").'</td>';
print '</tr>';

// MySQL Schema Introspection — independent of SELECT/WRITE, default ON
print '<tr class="oddeven">';
print '<td>'.$langs->trans("MySQLSchemaEnable");
print '<br><span class="opacitymedium small">'.$langs->trans("MySQLSchemaEnableDesc").'</span>';
print '</td>';
print '<td class="center" width="100">';
if ($mysqlSchemaEnabled) {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_mysql_schema">';
    print '<input type="hidden" name="DALFRED_MYSQL_SCHEMA_ENABLED" value="0">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Activated"), 'switch_on').'</a>';
    print '</form>';
} else {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_mysql_schema">';
    print '<input type="hidden" name="DALFRED_MYSQL_SCHEMA_ENABLED" value="1">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
    print '</form>';
}
print '</td>';
print '</tr>';

// MySQL Toolkit Enabled
print '<tr class="oddeven">';
print '<td>'.$langs->trans("MySQLToolkitEnable").'</td>';
print '<td class="center" width="100">';
if ($mysqlEnabled) {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_mysql">';
    print '<input type="hidden" name="DALFRED_MYSQL_TOOLKIT_ENABLED" value="0">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Activated"), 'switch_on').'</a>';
    print '</form>';
} else {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_mysql">';
    print '<input type="hidden" name="DALFRED_MYSQL_TOOLKIT_ENABLED" value="1">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
    print '</form>';
}
print '</td>';
print '</tr>';

// MySQL Write Enabled
print '<tr class="oddeven">';
print '<td>'.$langs->trans("MySQLToolkitWriteEnable").'</td>';
print '<td class="center" width="100">';
if (!$mysqlEnabled) {
    print img_picto($langs->trans("Disabled"), 'switch_off', 'class="opacitymedium"');
} elseif ($mysqlWriteEnabled) {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_mysql_write">';
    print '<input type="hidden" name="DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED" value="0">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Activated"), 'switch_on').'</a>';
    print '</form>';
} else {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_mysql_write">';
    print '<input type="hidden" name="DALFRED_MYSQL_TOOLKIT_WRITE_ENABLED" value="1">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
    print '</form>';
}
print '</td>';
print '</tr>';

// Smart Queries Enabled
$smartQueryEnabled = getDolGlobalString('DALFRED_SMARTQUERY_ENABLED', '0');

print '<tr class="oddeven">';
print '<td>'.$langs->trans("SmartQueryEnable").'</td>';
print '<td class="center" width="100">';
if (!$mysqlEnabled) {
    print img_picto($langs->trans("Disabled"), 'switch_off', 'class="opacitymedium"');
} elseif ($smartQueryEnabled) {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_smartquery">';
    print '<input type="hidden" name="DALFRED_SMARTQUERY_ENABLED" value="0">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Activated"), 'switch_on').'</a>';
    print '</form>';
} else {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_smartquery">';
    print '<input type="hidden" name="DALFRED_SMARTQUERY_ENABLED" value="1">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
    print '</form>';
}
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

// If MySQL toolkit is globally disabled, show notice
if (!$mysqlEnabled) {
    print '<div class="opacitymedium" style="padding: 10px 0;">'.$langs->trans("MySQLToolkitGloballyDisabled").'</div>';
}

print '<br>';

// ============================================================
// Section 2bis: File Generation Toolkit
// ============================================================

$fileGenEnabled = getDolGlobalString('DALFRED_FILE_GEN_ENABLED', '0');
$fileGenMaxBytes = (int) getDolGlobalInt('DALFRED_FILE_GEN_MAX_SIZE', 5242880);
$fileGenMaxMb = max(1, (int) round($fileGenMaxBytes / (1024 * 1024)));

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("FileGenerationToolkit").'</td>';
print '</tr>';

// Enable toggle
print '<tr class="oddeven">';
print '<td>'.$langs->trans("EnableFileGeneration").'<br><span class="opacitymedium">'.$langs->trans("FileGenerationDescription").'</span></td>';
print '<td class="center" width="100">';
if ($fileGenEnabled === '1') {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_file_gen">';
    print '<input type="hidden" name="DALFRED_FILE_GEN_ENABLED" value="0">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Activated"), 'switch_on').'</a>';
    print '</form>';
} else {
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="update_global_file_gen">';
    print '<input type="hidden" name="DALFRED_FILE_GEN_ENABLED" value="1">';
    print '<a class="reposition" href="javascript:void(0);" onclick="this.closest(\'form\').submit();">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
    print '</form>';
}
print '</td>';
print '</tr>';

// Max size (MB)
print '<tr class="oddeven">';
print '<td>'.$langs->trans("FileGenerationMaxSize").'</td>';
print '<td class="center" width="100">';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_file_gen_max_size">';
print '<input type="number" name="DALFRED_FILE_GEN_MAX_SIZE_MB" min="1" max="100" value="'.((int) $fileGenMaxMb).'" style="width:80px;">';
print ' <button type="submit" class="butAction">'.$langs->trans("Save").'</button>';
print '</form>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';

if ($fileGenEnabled !== '1') {
    print '<div class="opacitymedium" style="padding: 10px 0;">'.$langs->trans("FileGenerationGloballyDisabled").'</div>';
}

print '<br>';

// ============================================================
// Section 3: Information
// ============================================================

print '<div class="info">';
print '<p><strong>'.$langs->trans("ToolkitPermissionsInfo").':</strong></p>';
print '<ul>';
print '<li><strong>'.$langs->trans("DalfredAccess").'</strong>: '.$langs->trans("UserNoDalfredRight").'</li>';
print '<li><strong>'.$langs->trans("ApiKey").'</strong>: '.$langs->trans("UserReadyDesc").'</li>';
print '<li><strong>'.$langs->trans("MySQLReadAccess").'</strong>: '.$langs->trans("MySQLReadAccessDesc").'</li>';
print '<li><strong>'.$langs->trans("MySQLWriteAccess").'</strong>: '.$langs->trans("MySQLWriteAccessDesc").'</li>';
print '</ul>';
print '<p>'.$langs->trans("PermissionsHowTo", $langs->trans("Permission49140801")).'</p>';
print '<p class="opacitymedium">'.$langs->trans("MySQLToolkitRecommendation").'</p>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
