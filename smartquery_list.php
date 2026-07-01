<?php
/**
 * Dalfred Smart Queries - List & Execute Page
 *
 * Displays saved SQL queries with search, execution, and export.
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

$langs->loadLangs(array("dalfred@dalfred"));

if (!$user->hasRight('dalfred', 'use')) {
    accessforbidden();
}

// Check Smart Queries is enabled
if (!getDolGlobalString('DALFRED_SMARTQUERY_ENABLED') || !getDolGlobalString('DALFRED_MYSQL_TOOLKIT_ENABLED')) {
    accessforbidden('Smart Queries is not enabled');
}

// Parameters
$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$search_text = GETPOST('search_text', 'alpha');
$search_category = GETPOST('search_category', 'alpha');
$search_scope = GETPOST('search_scope', 'alpha');

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : 25;
$page = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$offset = $limit * $page;

if (empty($sortfield)) {
    $sortfield = 'q.last_execution';
}
if (empty($sortorder)) {
    $sortorder = 'DESC';
}

/*
 * Actions
 */

if ($action == 'delete' && $id > 0) {
    $sql = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries";
    $sql .= " WHERE rowid = " . (int)$id;
    $sql .= " AND entity = " . (int)$conf->entity;
    // Only owner or admin can delete
    if (!$user->admin) {
        $sql .= " AND fk_user = " . (int)$user->id;
    }
    $resql = $db->query($sql);
    if ($resql && $db->affected_rows($resql) > 0) {
        setEventMessages($langs->trans("SmartQueryDeleted"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
    header('Location: ' . $_SERVER["PHP_SELF"] . '?search_text=' . urlencode($search_text) . '&search_category=' . urlencode($search_category) . '&search_scope=' . urlencode($search_scope));
    exit;
}

/*
 * View
 */

llxHeader('', $langs->trans("SmartQueries"));

// Header
print '<div class="titre inline-block">';
print img_picto('', 'fa-database', 'class="pictofixedwidth"');
print $langs->trans("SmartQueries");
print '</div>';
print '<div class="inline-block floatright" style="padding-top: 8px;">';
print '<a href="' . dol_buildpath('/dalfred/smartquery_card.php', 1) . '?action=create" class="butAction">' . $langs->trans("SmartQueryNew") . '</a>';
print ' ';
print '<a href="' . dol_buildpath('/dalfred/chat.php', 1) . '" class="butAction">' . $langs->trans("BackToChat") . '</a>';
print '</div>';
print '<div class="clearboth"></div>';
print '<br>';

print '<div class="opacitymedium">' . $langs->trans("SmartQueryDesc") . '</div>';
print '<br>';

// Count total visible to this user
$sql_count = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries as q";
$sql_count .= " WHERE q.entity = " . (int)$conf->entity;
$sql_count .= " AND (q.fk_user = " . (int)$user->id . " OR q.scope = 'shared')";
if ($search_text) {
    $sql_count .= " AND (q.title LIKE '%" . $db->escape($search_text) . "%' OR q.description LIKE '%" . $db->escape($search_text) . "%')";
}
if ($search_category) {
    $sql_count .= " AND q.category = '" . $db->escape($search_category) . "'";
}
if ($search_scope) {
    $sql_count .= " AND q.scope = '" . $db->escape($search_scope) . "'";
}

$resql_count = $db->query($sql_count);
$total = 0;
if ($resql_count) {
    $obj = $db->fetch_object($resql_count);
    $total = (int)$obj->total;
}

// Build query
$sql = "SELECT q.rowid, q.fk_user, q.title, q.description, q.sql_query, q.category, q.parameters, q.scope,";
$sql .= " q.last_execution, q.execution_count, q.date_creation,";
$sql .= " u.login as user_login, u.firstname as user_firstname, u.lastname as user_lastname";
$sql .= " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries as q";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON u.rowid = q.fk_user";
$sql .= " WHERE q.entity = " . (int)$conf->entity;
$sql .= " AND (q.fk_user = " . (int)$user->id . " OR q.scope = 'shared')";

if ($search_text) {
    $sql .= " AND (q.title LIKE '%" . $db->escape($search_text) . "%' OR q.description LIKE '%" . $db->escape($search_text) . "%')";
}
if ($search_category) {
    $sql .= " AND q.category = '" . $db->escape($search_category) . "'";
}
if ($search_scope) {
    $sql .= " AND q.scope = '" . $db->escape($search_scope) . "'";
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}

$num = $db->num_rows($resql);

$param = '&search_text=' . urlencode($search_text) . '&search_category=' . urlencode($search_category) . '&search_scope=' . urlencode($search_scope);

// Navigation bar
print_barre_liste(
    $langs->trans("SmartQueryEntries"),
    $page,
    $_SERVER["PHP_SELF"],
    $param,
    $sortfield,
    $sortorder,
    '',
    $num,
    $total,
    '',
    0,
    '',
    '',
    $limit
);

// Filter form
print '<form method="GET" action="' . $_SERVER["PHP_SELF"] . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre("SmartQueryTitle", $_SERVER["PHP_SELF"], "q.title", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("SmartQueryCategory", $_SERVER["PHP_SELF"], "q.category", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("SmartQueryScope", $_SERVER["PHP_SELF"], "q.scope", '', $param, '', $sortfield, $sortorder);
print_liste_field_titre("SmartQueryExecutions", $_SERVER["PHP_SELF"], "q.execution_count", '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre("SmartQueryLastExec", $_SERVER["PHP_SELF"], "q.last_execution", '', $param, '', $sortfield, $sortorder);
print '<td class="liste_titre">' . $langs->trans("Actions") . '</td>';
print '</tr>';

// Search row
print '<tr class="liste_titre">';
print '<td><input type="text" name="search_text" value="' . dol_escape_htmltag($search_text) . '" class="flat width150" placeholder="' . $langs->trans("SmartQuerySearchPlaceholder") . '"></td>';
print '<td><input type="text" name="search_category" value="' . dol_escape_htmltag($search_category) . '" class="flat width100"></td>';
print '<td><select name="search_scope" class="flat">';
print '<option value="">' . $langs->trans("KnowledgeAllScopes") . '</option>';
print '<option value="private"' . ($search_scope == 'private' ? ' selected' : '') . '>' . $langs->trans("KnowledgeScopePrivate") . '</option>';
print '<option value="shared"' . ($search_scope == 'shared' ? ' selected' : '') . '>' . $langs->trans("KnowledgeScopeShared") . '</option>';
print '</select></td>';
print '<td></td>';
print '<td></td>';
print '<td>';
print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';
print ' <a href="' . $_SERVER["PHP_SELF"] . '">' . $langs->trans("Reset") . '</a>';
print '</td>';
print '</tr>';

// Data rows
$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);

    $hasParams = !empty($obj->parameters);
    $params = $hasParams ? json_decode($obj->parameters, true) : [];
    $isOwner = ((int)$obj->fk_user === (int)$user->id);

    print '<tr class="oddeven">';

    // Title + description (clickable link to card)
    print '<td>';
    print '<a href="' . dol_buildpath('/dalfred/smartquery_card.php', 1) . '?id=' . (int)$obj->rowid . '"><strong>' . dol_escape_htmltag(dol_trunc($obj->title, 60)) . '</strong></a>';
    if (!empty($obj->description)) {
        print '<br><span class="opacitymedium small">' . dol_escape_htmltag(dol_trunc($obj->description, 120)) . '</span>';
    }
    if (!$isOwner) {
        $ownerName = trim(($obj->user_firstname ?? '') . ' ' . ($obj->user_lastname ?? ''));
        if (empty($ownerName)) {
            $ownerName = $obj->user_login ?? '?';
        }
        print '<br><span class="opacitymedium small">' . $langs->trans("Owner") . ': ' . dol_escape_htmltag($ownerName) . '</span>';
    }
    print '</td>';

    // Category
    print '<td>' . ($obj->category ? dol_escape_htmltag($obj->category) : '<span class="opacitymedium">-</span>') . '</td>';

    // Scope
    print '<td>';
    if ($obj->scope == 'shared') {
        print '<span class="badge badge-status4">' . $langs->trans("KnowledgeScopeShared") . '</span>';
    } else {
        print '<span class="badge badge-status0">' . $langs->trans("KnowledgeScopePrivate") . '</span>';
    }
    print '</td>';

    // Execution count
    print '<td class="center">' . (int)$obj->execution_count . '</td>';

    // Last execution
    print '<td class="nowraponall">';
    if (!empty($obj->last_execution) && $obj->last_execution !== '0000-00-00 00:00:00') {
        print dol_print_date($db->jdate($obj->last_execution), 'dayhour', 'tzuser');
    } else {
        print '<span class="opacitymedium">' . $langs->trans("Never") . '</span>';
    }
    print '</td>';

    // Actions
    print '<td class="nowraponall">';

    // Execute button
    if ($hasParams && is_array($params) && count($params) > 0) {
        // Has parameters - link to result page with param form
        print '<a href="' . dol_buildpath('/dalfred/smartquery_result.php', 1) . '?id=' . (int)$obj->rowid . '" class="butAction small" title="' . $langs->trans("SmartQueryExecute") . '">';
        print img_picto($langs->trans("SmartQueryExecute"), 'play', 'class="pictofixedwidth"') . $langs->trans("SmartQueryExecute");
        print '</a> ';
    } else {
        // No parameters - direct execute
        print '<a href="' . dol_buildpath('/dalfred/smartquery_result.php', 1) . '?id=' . (int)$obj->rowid . '&action=execute" class="butAction small" title="' . $langs->trans("SmartQueryExecute") . '">';
        print img_picto($langs->trans("SmartQueryExecute"), 'play', 'class="pictofixedwidth"') . $langs->trans("SmartQueryExecute");
        print '</a> ';
    }

    // Edit (only owner or admin)
    if ($isOwner || $user->admin) {
        print '<a href="' . dol_buildpath('/dalfred/smartquery_card.php', 1) . '?id=' . (int)$obj->rowid . '&action=edit" title="' . $langs->trans("SmartQueryEdit") . '">';
        print img_picto($langs->trans("SmartQueryEdit"), 'edit');
        print '</a> ';
    }

    // Duplicate
    print '<a href="' . dol_buildpath('/dalfred/smartquery_card.php', 1) . '?id=' . (int)$obj->rowid . '&action=duplicate&token=' . newToken() . '" title="' . $langs->trans("SmartQueryDuplicate") . '">';
    print img_picto($langs->trans("SmartQueryDuplicate"), 'fa-copy');
    print '</a> ';

    // Delete (only owner or admin)
    if ($isOwner || $user->admin) {
        print '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete&id=' . (int)$obj->rowid . '&token=' . newToken() . $param . '" onclick="return confirm(\'' . $langs->trans("SmartQueryDeleteConfirm") . '\');" title="' . $langs->trans("Delete") . '">';
        print img_picto($langs->trans("Delete"), 'delete');
        print '</a>';
    }

    print '</td>';

    print '</tr>';
    $i++;
}

if ($num == 0) {
    print '<tr class="oddeven"><td colspan="6" class="opacitymedium center">';
    print $langs->trans("SmartQueryNoEntries");
    print ' <a href="' . dol_buildpath('/dalfred/smartquery_card.php', 1) . '?action=create">' . $langs->trans("SmartQueryNew") . '</a>';
    print '</td></tr>';
}

print '</table>';
print '</form>';

$db->free($resql);

llxFooter();
$db->close();
