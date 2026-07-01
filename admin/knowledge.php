<?php
/**
 * Dalfred Knowledge/Memory Administration Page
 *
 * @package    Dalfred
 * @author     E-dem
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/dalfred.lib.php';
require_once __DIR__.'/../vendor/autoload.php';

$langs->loadLangs(array("admin", "dalfred@dalfred"));

if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$search_text = GETPOST('search_text', 'alpha');
$search_scope = GETPOST('search_scope', 'alpha');
$search_category = GETPOST('search_category', 'alpha');
$search_user = GETPOSTINT('search_user');
$search_kind = GETPOST('search_kind', 'alpha'); // '' | 'knowledge' | 'commands'

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : 50;
$page = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$offset = $limit * $page;

if (empty($sortfield)) {
    $sortfield = 'k.date_creation';
}
if (empty($sortorder)) {
    $sortorder = 'DESC';
}

/**
 * Normalize escaped newlines from LLM (literal \n) to real newlines
 */
function dalfred_normalize_newlines($str)
{
    return str_replace(array("\\r\\n", "\\r", "\\n"), array("\r\n", "\r", "\n"), $str);
}

/*
 * Actions
 */

if ($action == 'delete' && $id > 0 && $user->admin) {
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."dalfred_knowledge";
    $sql .= " WHERE rowid = ".(int)$id;
    $sql .= " AND entity = ".(int)$conf->entity;
    $resql = $db->query($sql);
    if ($resql && $db->affected_rows($resql) > 0) {
        setEventMessages($langs->trans("KnowledgeDeleted"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
    header('Location: '.$_SERVER["PHP_SELF"].'?search_text='.urlencode($search_text).'&search_scope='.urlencode($search_scope).'&search_category='.urlencode($search_category).'&search_user='.$search_user);
    exit;
}

if ($action == 'update' && $id > 0 && $user->admin) {
    $new_title = GETPOST('edit_title', 'alpha');
    // 3e arg à 2 = autorise multilignes (idiome Dolibarr).
    // Normalisation \r\n -> \n pour stocker une forme canonique.
    $new_content = str_replace(["\r\n", "\r"], "\n", GETPOST('edit_content', 'restricthtml', 2));
    $new_category = GETPOST('edit_category', 'alpha');
    $new_scope = GETPOST('edit_scope', 'alpha');

    $new_command_name = trim((string) GETPOST('edit_command_name', 'alpha'));
    // Empty string means "not a command" — store NULL.
    if ($new_command_name === '') {
        $new_command_name = null;
    } elseif (!\Dalfred\Service\CommandResolver::isValidName($new_command_name)) {
        setEventMessages($langs->trans('KnowledgeCommandInvalidName'), null, 'errors');
        $action = 'edit';
    } else {
        // Collision check (against another row, NOT this one).
        $editingId = (int) $id;
        $check = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."dalfred_knowledge"
            ." WHERE entity = ".(int)$conf->entity
            ." AND command_name = '".$db->escape($new_command_name)."'"
            .($editingId > 0 ? " AND rowid != ".$editingId : "")
            ." LIMIT 1");
        if ($check && $db->num_rows($check) > 0) {
            setEventMessages($langs->trans('KnowledgeCommandNameTaken'), null, 'errors');
            $action = 'edit';
        }
    }

    if ($action != 'edit' && !empty($new_title) && !empty($new_content)) {
        $sql = "UPDATE ".MAIN_DB_PREFIX."dalfred_knowledge SET";
        $sql .= " title = '".$db->escape($new_title)."'";
        $sql .= ", content = '".$db->escape($new_content)."'";
        $sql .= ", category = ".($new_category ? "'".$db->escape($new_category)."'" : "NULL");
        $sql .= ", scope = '".$db->escape(in_array($new_scope, ['private', 'shared']) ? $new_scope : 'private')."'";
        $sql .= ", command_name = ".($new_command_name === null ? "NULL" : "'".$db->escape($new_command_name)."'");
        $sql .= " WHERE rowid = ".(int)$id;
        $sql .= " AND entity = ".(int)$conf->entity;
        $resql = $db->query($sql);
        if ($resql) {
            setEventMessages($langs->trans("KnowledgeUpdated"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("Error"), null, 'errors');
        }
        $action = '';
    } elseif ($action != 'edit') {
        $action = '';
    }
}

if ($action == 'delete_all' && $user->admin) {
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."dalfred_knowledge";
    $sql .= " WHERE entity = ".(int)$conf->entity;
    $resql = $db->query($sql);
    if ($resql) {
        $deleted = $db->affected_rows($resql);
        setEventMessages($langs->trans("LogsPurged", $deleted), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

/*
 * View
 */

$page_name = "KnowledgeManagement";
llxHeader('', $langs->trans($page_name));

$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'knowledge', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Edit form (displayed when editing an entry)
if ($action == 'edit' && $id > 0) {
    $sql_edit = "SELECT rowid, title, content, category, scope, command_name FROM ".MAIN_DB_PREFIX."dalfred_knowledge WHERE rowid = ".(int)$id." AND entity = ".(int)$conf->entity;
    $res_edit = $db->query($sql_edit);
    if ($res_edit && $db->num_rows($res_edit) > 0) {
        $entry = $db->fetch_object($res_edit);
        print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="update">';
        print '<input type="hidden" name="id" value="'.(int)$entry->rowid.'">';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("KnowledgeEdit").' #'.$entry->rowid.'</td></tr>';

        print '<tr class="oddeven"><td>'.$langs->trans("KnowledgeTitle").'</td>';
        print '<td><input type="text" name="edit_title" value="'.dol_escape_htmltag($entry->title).'" class="flat minwidth300" required></td></tr>';

        print '<tr class="oddeven"><td>'.$langs->trans("KnowledgeCommandName").'</td>';
        print '<td>';
        print '<input type="text" name="edit_command_name" value="'
            .dol_escape_htmltag($entry->command_name ?? '')
            .'" class="flat minwidth200" pattern="[a-z0-9\-]{1,64}" '
            .'placeholder="'.$langs->trans("KnowledgeCommandPlaceholder").'">';
        print ' <span class="opacitymedium">'.$langs->trans("KnowledgeCommandNameHelp").'</span>';
        if (!empty($entry->command_name)) {
            $testUrl = dol_buildpath('/dalfred/chat.php?prefill='.urlencode('/'.$entry->command_name), 1);
            print '<br><a href="'.$testUrl.'" target="_blank" class="button buttongen marginleftonly">'
                .'&#x1F9EA; '.$langs->trans("KnowledgeTestCommand").'</a>';
        }
        print '</td></tr>';

        print '<tr class="oddeven"><td>'.$langs->trans("KnowledgeContent").'</td>';
        print '<td><textarea name="edit_content" rows="4" class="flat minwidth300" required>'.dol_escape_htmltag(dalfred_normalize_newlines($entry->content), 0, 1).'</textarea></td></tr>';

        print '<tr class="oddeven"><td>'.$langs->trans("KnowledgeCategory").'</td>';
        print '<td><input type="text" name="edit_category" value="'.dol_escape_htmltag($entry->category ?? '').'" class="flat minwidth200"></td></tr>';

        print '<tr class="oddeven"><td>'.$langs->trans("KnowledgeScope").'</td>';
        print '<td><select name="edit_scope" class="flat">';
        print '<option value="private"'.($entry->scope == 'private' ? ' selected' : '').'>'.$langs->trans("KnowledgeScopePrivate").'</option>';
        print '<option value="shared"'.($entry->scope == 'shared' ? ' selected' : '').'>'.$langs->trans("KnowledgeScopeShared").'</option>';
        print '</select></td></tr>';

        print '</table>';
        print '<div class="center" style="padding: 10px;">';
        print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
        print ' <a class="button button-cancel" href="'.$_SERVER["PHP_SELF"].'">'.$langs->trans("Cancel").'</a>';
        print '</div>';
        print '</form>';
        print '<br>';
    }
}

// Count total
$sql_count = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."dalfred_knowledge as k";
$sql_count .= " WHERE k.entity = ".(int)$conf->entity;
if ($search_text) {
    $sql_count .= " AND (k.title LIKE '%".$db->escape($search_text)."%' OR k.content LIKE '%".$db->escape($search_text)."%')";
}
if ($search_scope) {
    $sql_count .= " AND k.scope = '".$db->escape($search_scope)."'";
}
if ($search_category) {
    $sql_count .= " AND k.category = '".$db->escape($search_category)."'";
}
if ($search_user > 0) {
    $sql_count .= " AND k.fk_user = ".(int)$search_user;
}
if ($search_kind === 'commands') {
    $sql_count .= " AND k.command_name IS NOT NULL";
} elseif ($search_kind === 'knowledge') {
    $sql_count .= " AND k.command_name IS NULL";
}

$resql_count = $db->query($sql_count);
$total = 0;
if ($resql_count) {
    $obj = $db->fetch_object($resql_count);
    $total = (int)$obj->total;
}

// Action buttons
print '<div class="tabsAction">';
print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?action=delete_all&token='.newToken().'" onclick="return confirm(\''.$langs->trans("KnowledgeDeleteAllConfirm").'\');">'.$langs->trans("KnowledgeDeleteAll").'</a>';
print '</div>';

print '<div class="info">'.$langs->trans("KnowledgeAdminDesc", $total).'</div>';
print '<br>';

// Build query
$sql = "SELECT k.rowid, k.fk_user, k.title, k.content, k.category, k.scope, k.command_name, k.date_creation,";
$sql .= " u.login as user_login, u.firstname, u.lastname";
$sql .= " FROM ".MAIN_DB_PREFIX."dalfred_knowledge as k";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON k.fk_user = u.rowid";
$sql .= " WHERE k.entity = ".(int)$conf->entity;

if ($search_text) {
    $sql .= " AND (k.title LIKE '%".$db->escape($search_text)."%' OR k.content LIKE '%".$db->escape($search_text)."%')";
}
if ($search_scope) {
    $sql .= " AND k.scope = '".$db->escape($search_scope)."'";
}
if ($search_category) {
    $sql .= " AND k.category = '".$db->escape($search_category)."'";
}
if ($search_user > 0) {
    $sql .= " AND k.fk_user = ".(int)$search_user;
}
if ($search_kind === 'commands') {
    $sql .= " AND k.command_name IS NOT NULL";
} elseif ($search_kind === 'knowledge') {
    $sql .= " AND k.command_name IS NULL";
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}

$num = $db->num_rows($resql);

// Filter params for pagination
$param = '&search_text='.urlencode($search_text).'&search_scope='.urlencode($search_scope).'&search_category='.urlencode($search_category).'&search_user='.$search_user.'&search_kind='.urlencode($search_kind);

// Navigation
print_barre_liste(
    $langs->trans("KnowledgeEntries"),
    $page,
    $_SERVER["PHP_SELF"],
    $param,
    $sortfield,
    $sortorder,
    '',
    $num,
    $total,
    'fa-brain',
    0,
    '',
    '',
    $limit
);

// Filter form
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre("KnowledgeTitle", $_SERVER["PHP_SELF"], "k.title", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("User", $_SERVER["PHP_SELF"], "u.login", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("KnowledgeCategory", $_SERVER["PHP_SELF"], "k.category", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("KnowledgeScope", $_SERVER["PHP_SELF"], "k.scope", '', $param, '', $sortfield, $sortorder);
print '<th>'.$langs->trans("KnowledgeColCommand").'</th>';
print_liste_field_titre("KnowledgeDate", $_SERVER["PHP_SELF"], "k.date_creation", '', $param, '', $sortfield, $sortorder);
print '<td class="liste_titre">'.$langs->trans("Actions").'</td>';
print '</tr>';

// Search row
print '<tr class="liste_titre">';
print '<td><input type="text" name="search_text" value="'.dol_escape_htmltag($search_text).'" class="flat width150" placeholder="'.$langs->trans("KnowledgeSearchPlaceholder").'"></td>';
print '<td><input type="text" name="search_user" value="'.($search_user > 0 ? (int)$search_user : '').'" class="flat width50" placeholder="ID"></td>';
print '<td><input type="text" name="search_category" value="'.dol_escape_htmltag($search_category).'" class="flat width100"></td>';
print '<td><select name="search_scope" class="flat">';
print '<option value="">'.$langs->trans("KnowledgeAllScopes").'</option>';
print '<option value="private"'.($search_scope == 'private' ? ' selected' : '').'>'.$langs->trans("KnowledgeScopePrivate").'</option>';
print '<option value="shared"'.($search_scope == 'shared' ? ' selected' : '').'>'.$langs->trans("KnowledgeScopeShared").'</option>';
print '</select></td>';
print '<td><select name="search_kind" class="flat">';
print '<option value=""'.($search_kind === '' ? ' selected' : '').'>'.$langs->trans("KnowledgeFilterAll").'</option>';
print '<option value="knowledge"'.($search_kind === 'knowledge' ? ' selected' : '').'>'.$langs->trans("KnowledgeFilterKnowledge").'</option>';
print '<option value="commands"'.($search_kind === 'commands' ? ' selected' : '').'>'.$langs->trans("KnowledgeFilterCommands").'</option>';
print '</select></td>';
print '<td></td>';
print '<td>';
print '<input type="submit" class="button" value="'.$langs->trans("Search").'">';
print ' <a href="'.$_SERVER["PHP_SELF"].'">'.$langs->trans("Reset").'</a>';
print '</td>';
print '</tr>';

// Data rows
$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);

    print '<tr class="oddeven">';

    // Title + content preview
    print '<td>';
    print '<strong>'.dol_escape_htmltag(dol_trunc($obj->title, 60)).'</strong>';
    $normalizedContent = dalfred_normalize_newlines($obj->content);
    $contentPreview = str_replace(array("\r\n", "\r", "\n"), ' ', $normalizedContent);
    print '<br><span class="opacitymedium small">'.dol_escape_htmltag(dol_trunc($contentPreview, 100)).'</span>';
    print '</td>';

    // User
    $username = $obj->user_login ?? 'ID:'.$obj->fk_user;
    if ($obj->firstname || $obj->lastname) {
        $username = trim($obj->firstname.' '.$obj->lastname).' ('.$obj->user_login.')';
    }
    print '<td>'.dol_escape_htmltag($username).'</td>';

    // Category
    print '<td>'.($obj->category ? dol_escape_htmltag($obj->category) : '<span class="opacitymedium">-</span>').'</td>';

    // Scope
    print '<td>';
    if ($obj->scope == 'shared') {
        print '<span class="badge badge-status4">'.$langs->trans("KnowledgeScopeShared").'</span>';
    } else {
        print '<span class="badge badge-status0">'.$langs->trans("KnowledgeScopePrivate").'</span>';
    }
    print '</td>';

    // Command
    print '<td>';
    if (!empty($obj->command_name)) {
        print '<code>/'.dol_escape_htmltag($obj->command_name).'</code>';
    }
    print '</td>';

    // Date
    print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour', 'tzuser').'</td>';

    // Actions
    print '<td class="nowraponall">';
    print '<a href="'.$_SERVER["PHP_SELF"].'?action=edit&id='.(int)$obj->rowid.'&token='.newToken().$param.'" title="'.$langs->trans("KnowledgeEdit").'">';
    print img_picto($langs->trans("KnowledgeEdit"), 'edit');
    print '</a>';
    print ' &nbsp; ';
    print '<a href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.(int)$obj->rowid.'&token='.newToken().$param.'" onclick="return confirm(\''.$langs->trans("KnowledgeDeleteConfirm").'\');" title="'.$langs->trans("KnowledgeDelete").'">';
    print img_picto($langs->trans("KnowledgeDelete"), 'delete');
    print '</a>';
    print '</td>';

    print '</tr>';
    $i++;
}

if ($num == 0) {
    print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans("KnowledgeNoEntries").'</td></tr>';
}

print '</table>';
print '</form>';

$db->free($resql);

print dol_get_fiche_end();

llxFooter();
$db->close();
