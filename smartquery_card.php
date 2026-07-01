<?php
/**
 * Dalfred Smart Queries - Card Page (View/Edit/Create)
 *
 * Provides view, edit, create, duplicate, and test functionality
 * for saved SQL queries.
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
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');

// Cancel button redirect
if ($cancel) {
    if ($id > 0) {
        header('Location: ' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id);
    } else {
        header('Location: ' . dol_buildpath('/dalfred/smartquery_list.php', 1));
    }
    exit;
}

// SQL validation function
$dangerousKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE', 'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'UNION', 'SLEEP', 'BENCHMARK', 'CALL', 'PROCEDURE'];

function validateSelectOnly(string $sql): array
{
    global $dangerousKeywords;
    $trimmed = trim($sql);

    if (!preg_match('/^\s*SELECT\b/i', $trimmed)) {
        return ['valid' => false, 'error' => 'Seules les requêtes SELECT sont autorisées.'];
    }

    foreach ($dangerousKeywords as $keyword) {
        $cleanKeyword = str_replace(['\\b', '\\s+'], ['', ' '], $keyword);
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $trimmed)) {
            return ['valid' => false, 'error' => "Mot-clé interdit : {$cleanKeyword}"];
        }
    }

    return ['valid' => true];
}

// Fetch existing query if id is set
$query = null;
$isOwner = false;
$canEdit = false;

if ($id > 0) {
    $sql_fetch = "SELECT q.rowid, q.fk_user, q.title, q.description, q.sql_query, q.category, q.parameters, q.scope,";
    $sql_fetch .= " q.last_execution, q.execution_count, q.date_creation, q.tms,";
    $sql_fetch .= " u.login as user_login, u.firstname as user_firstname, u.lastname as user_lastname";
    $sql_fetch .= " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries as q";
    $sql_fetch .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON u.rowid = q.fk_user";
    $sql_fetch .= " WHERE q.rowid = " . (int) $id;
    $sql_fetch .= " AND q.entity = " . (int) $conf->entity;
    $sql_fetch .= " AND (q.fk_user = " . (int) $user->id . " OR q.scope = 'shared')";

    $resql_fetch = $db->query($sql_fetch);
    if (!$resql_fetch || $db->num_rows($resql_fetch) == 0) {
        setEventMessages($langs->trans("SmartQueryNotFound"), null, 'errors');
        header('Location: ' . dol_buildpath('/dalfred/smartquery_list.php', 1));
        exit;
    }
    $query = $db->fetch_object($resql_fetch);
    $isOwner = ((int) $query->fk_user === (int) $user->id);
    $canEdit = ($isOwner || $user->admin);
}

/*
 * Actions
 */

// Action: Create new query (add)
if ($action == 'add') {
    $title = GETPOST('title', 'alphanohtml');
    $description = GETPOST('description', 'restricthtml');
    $sql_query = GETPOST('sql_query', 'none'); // Raw SQL, we validate ourselves
    $category = GETPOST('category', 'alphanohtml');
    $scope = GETPOST('scope', 'alpha');
    $parameters = GETPOST('parameters', 'none');

    if (empty($title) || empty($sql_query)) {
        setEventMessages($langs->trans("SmartQueryMissingSQLOrTitle"), null, 'errors');
        $action = 'create';
    } else {
        // Validate SQL
        $validation = validateSelectOnly($sql_query);
        if (!$validation['valid']) {
            setEventMessages($langs->transnoentities("SmartQuerySQLValidationError", $validation['error']), null, 'errors');
            $action = 'create';
        } else {
            // Validate parameters JSON
            if (!empty($parameters)) {
                $decoded = json_decode($parameters, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    setEventMessages($langs->trans("SmartQuerySQLValidationError", 'JSON invalide: ' . json_last_error_msg()), null, 'errors');
                    $action = 'create';
                } else {
                    $parameters = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                }
            }

            if ($action != 'create') {
                if (!in_array($scope, ['private', 'shared'])) {
                    $scope = 'private';
                }

                $sql_ins = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_smart_queries";
                $sql_ins .= " (entity, fk_user, title, description, sql_query, category, parameters, scope, date_creation)";
                $sql_ins .= " VALUES (";
                $sql_ins .= (int) $conf->entity . ", ";
                $sql_ins .= (int) $user->id . ", ";
                $sql_ins .= "'" . $db->escape($title) . "', ";
                $sql_ins .= (!empty($description) ? "'" . $db->escape($description) . "'" : "NULL") . ", ";
                $sql_ins .= "'" . $db->escape($sql_query) . "', ";
                $sql_ins .= (!empty($category) ? "'" . $db->escape($category) . "'" : "NULL") . ", ";
                $sql_ins .= (!empty($parameters) ? "'" . $db->escape($parameters) . "'" : "NULL") . ", ";
                $sql_ins .= "'" . $db->escape($scope) . "', ";
                $sql_ins .= "NOW()";
                $sql_ins .= ")";

                $result = $db->query($sql_ins);
                if ($result) {
                    $newid = $db->last_insert_id(MAIN_DB_PREFIX . 'dalfred_smart_queries');
                    setEventMessages($langs->trans("SmartQueryCreated"), null, 'mesgs');
                    header('Location: ' . $_SERVER["PHP_SELF"] . '?id=' . (int) $newid);
                    exit;
                } else {
                    setEventMessages($langs->trans("Error"), null, 'errors');
                    $action = 'create';
                }
            }
        }
    }
}

// Action: Update existing query
if ($action == 'update' && $id > 0) {
    if (!$canEdit) {
        setEventMessages($langs->trans("SmartQueryNotOwner"), null, 'errors');
    } else {
        $title = GETPOST('title', 'alphanohtml');
        $description = GETPOST('description', 'restricthtml');
        $sql_query = GETPOST('sql_query', 'none');
        $category = GETPOST('category', 'alphanohtml');
        $scope = GETPOST('scope', 'alpha');
        $parameters = GETPOST('parameters', 'none');

        if (empty($title) || empty($sql_query)) {
            setEventMessages($langs->trans("SmartQueryMissingSQLOrTitle"), null, 'errors');
            $action = 'edit';
        } else {
            $validation = validateSelectOnly($sql_query);
            if (!$validation['valid']) {
                setEventMessages($langs->transnoentities("SmartQuerySQLValidationError", $validation['error']), null, 'errors');
                $action = 'edit';
            } else {
                if (!empty($parameters)) {
                    $decoded = json_decode($parameters, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        setEventMessages($langs->trans("SmartQuerySQLValidationError", 'JSON invalide: ' . json_last_error_msg()), null, 'errors');
                        $action = 'edit';
                    } else {
                        $parameters = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                    }
                }

                if ($action != 'edit') {
                    if (!in_array($scope, ['private', 'shared'])) {
                        $scope = 'private';
                    }

                    $sql_upd = "UPDATE " . MAIN_DB_PREFIX . "dalfred_smart_queries SET";
                    $sql_upd .= " title = '" . $db->escape($title) . "',";
                    $sql_upd .= " description = " . (!empty($description) ? "'" . $db->escape($description) . "'" : "NULL") . ",";
                    $sql_upd .= " sql_query = '" . $db->escape($sql_query) . "',";
                    $sql_upd .= " category = " . (!empty($category) ? "'" . $db->escape($category) . "'" : "NULL") . ",";
                    $sql_upd .= " parameters = " . (!empty($parameters) ? "'" . $db->escape($parameters) . "'" : "NULL") . ",";
                    $sql_upd .= " scope = '" . $db->escape($scope) . "'";
                    $sql_upd .= " WHERE rowid = " . (int) $id;
                    $sql_upd .= " AND entity = " . (int) $conf->entity;

                    $result = $db->query($sql_upd);
                    if ($result) {
                        setEventMessages($langs->trans("SmartQueryUpdated"), null, 'mesgs');
                        header('Location: ' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id);
                        exit;
                    } else {
                        setEventMessages($langs->trans("Error"), null, 'errors');
                        $action = 'edit';
                    }
                }
            }
        }
    }
}

// Action: Duplicate
if ($action == 'duplicate' && $id > 0 && $query) {
    $sql_dup = "INSERT INTO " . MAIN_DB_PREFIX . "dalfred_smart_queries";
    $sql_dup .= " (entity, fk_user, title, description, sql_query, category, parameters, scope, date_creation)";
    $sql_dup .= " SELECT " . (int) $conf->entity . ", " . (int) $user->id . ",";
    $sql_dup .= " CONCAT(title, ' (copie)'), description, sql_query, category, parameters, 'private', NOW()";
    $sql_dup .= " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries";
    $sql_dup .= " WHERE rowid = " . (int) $id;
    $sql_dup .= " AND entity = " . (int) $conf->entity;

    $result = $db->query($sql_dup);
    if ($result) {
        $newid = $db->last_insert_id(MAIN_DB_PREFIX . 'dalfred_smart_queries');
        setEventMessages($langs->trans("SmartQueryDuplicated"), null, 'mesgs');
        header('Location: ' . $_SERVER["PHP_SELF"] . '?id=' . (int) $newid);
        exit;
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

// Action: Delete (with confirm)
if ($action == 'confirm_delete' && $confirm == 'yes' && $id > 0) {
    if (!$canEdit) {
        setEventMessages($langs->trans("SmartQueryNotOwner"), null, 'errors');
    } else {
        $sql_del = "DELETE FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries";
        $sql_del .= " WHERE rowid = " . (int) $id;
        $sql_del .= " AND entity = " . (int) $conf->entity;
        if (!$user->admin) {
            $sql_del .= " AND fk_user = " . (int) $user->id;
        }

        $result = $db->query($sql_del);
        if ($result && $db->affected_rows($result) > 0) {
            setEventMessages($langs->trans("SmartQueryDeleted"), null, 'mesgs');
            header('Location: ' . dol_buildpath('/dalfred/smartquery_list.php', 1));
            exit;
        } else {
            setEventMessages($langs->trans("Error"), null, 'errors');
        }
    }
}

// Re-fetch query after actions that may have changed it
if ($id > 0 && in_array($action, ['', 'edit', 'testquery', 'delete'])) {
    $sql_fetch = "SELECT q.rowid, q.fk_user, q.title, q.description, q.sql_query, q.category, q.parameters, q.scope,";
    $sql_fetch .= " q.last_execution, q.execution_count, q.date_creation, q.tms,";
    $sql_fetch .= " u.login as user_login, u.firstname as user_firstname, u.lastname as user_lastname";
    $sql_fetch .= " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries as q";
    $sql_fetch .= " LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON u.rowid = q.fk_user";
    $sql_fetch .= " WHERE q.rowid = " . (int) $id;
    $sql_fetch .= " AND q.entity = " . (int) $conf->entity;
    $sql_fetch .= " AND (q.fk_user = " . (int) $user->id . " OR q.scope = 'shared')";

    $resql_fetch = $db->query($sql_fetch);
    if ($resql_fetch && $db->num_rows($resql_fetch) > 0) {
        $query = $db->fetch_object($resql_fetch);
        $isOwner = ((int) $query->fk_user === (int) $user->id);
        $canEdit = ($isOwner || $user->admin);
    }
}

/*
 * View
 */

$title = $langs->trans("SmartQueryCard");
if ($query) {
    $title .= ' - ' . dol_escape_htmltag($query->title);
}

llxHeader('', $title);

// Delete confirmation dialog
if ($action == 'delete' && $id > 0) {
    $formconfirm = $form ?? new Form($db);
    print $formconfirm->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . (int) $id,
        $langs->trans("Delete"),
        $langs->trans("SmartQueryConfirmDelete"),
        'confirm_delete',
        '',
        0,
        1
    );
}

// ---- CREATE MODE ----
if ($action == 'create' || ($id <= 0 && !$query)) {
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="add">';

    print load_fiche_titre($langs->trans("SmartQueryCreate"), '', 'fa-database');

    print dol_get_fiche_head([], '', '', 0);

    print '<table class="border centpercent">';

    // Title
    print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("SmartQueryTitle") . '</td>';
    print '<td><input type="text" name="title" value="' . dol_escape_htmltag(GETPOST('title', 'alphanohtml')) . '" class="flat minwidth400 maxwidth500" required></td></tr>';

    // Description
    print '<tr><td>' . $langs->trans("SmartQueryDescription") . '</td>';
    print '<td><textarea name="description" class="flat minwidth400 maxwidth500" rows="3">' . dol_escape_htmltag(GETPOST('description', 'restricthtml')) . '</textarea></td></tr>';

    // Category
    $categories = explode(',', $langs->transnoentities("SmartQueryCategoryList"));
    print '<tr><td>' . $langs->trans("SmartQueryCategory") . '</td>';
    print '<td><select name="category" class="flat minwidth200">';
    print '<option value="">(' . $langs->trans("SmartQueryNoCategory") . ')</option>';
    foreach ($categories as $cat) {
        $cat = trim($cat);
        $selected = (GETPOST('category', 'alphanohtml') == $cat) ? ' selected' : '';
        print '<option value="' . dol_escape_htmltag($cat) . '"' . $selected . '>' . dol_escape_htmltag(ucfirst($cat)) . '</option>';
    }
    print '</select></td></tr>';

    // Scope
    print '<tr><td>' . $langs->trans("SmartQueryScope") . '</td>';
    print '<td><select name="scope" class="flat">';
    $currentScope = GETPOST('scope', 'alpha') ?: 'private';
    print '<option value="private"' . ($currentScope == 'private' ? ' selected' : '') . '>' . $langs->trans("SmartQueryScopePrivate") . '</option>';
    print '<option value="shared"' . ($currentScope == 'shared' ? ' selected' : '') . '>' . $langs->trans("SmartQueryScopeShared") . '</option>';
    print '</select></td></tr>';

    // SQL Query
    print '<tr><td class="fieldrequired tdtop">' . $langs->trans("SmartQuerySQL") . '</td>';
    print '<td>';
    print '<textarea name="sql_query" class="flat" style="width: 100%; min-height: 200px; font-family: \'Courier New\', Courier, monospace; font-size: 13px; tab-size: 4;" required>' . dol_escape_htmltag(GETPOST('sql_query', 'none')) . '</textarea>';
    print '<br><span class="opacitymedium small">' . $langs->trans("SmartQuerySQLHelp") . '</span>';
    print '</td></tr>';

    // Parameters (JSON)
    print '<tr><td class="tdtop">' . $langs->trans("SmartQueryParamsJSON") . '</td>';
    print '<td>';
    print '<textarea name="parameters" class="flat" style="width: 100%; min-height: 80px; font-family: \'Courier New\', Courier, monospace; font-size: 12px;">' . dol_escape_htmltag(GETPOST('parameters', 'none')) . '</textarea>';
    print '<br><span class="opacitymedium small">' . $langs->trans("SmartQueryParamsJSONHelp") . '</span>';
    print '</td></tr>';

    print '</table>';

    print dol_get_fiche_end();

    print '<div class="center">';
    print '<input type="submit" class="button button-save" value="' . $langs->trans("SmartQuerySave") . '">';
    print ' &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="' . $langs->trans("Cancel") . '">';
    print '</div>';

    print '</form>';

// ---- EDIT MODE ----
} elseif ($action == 'edit' && $id > 0 && $query) {
    if (!$canEdit) {
        setEventMessages($langs->trans("SmartQueryNotOwner"), null, 'errors');
        $action = '';
    } else {
        print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id . '">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="update">';

        print load_fiche_titre(
            $langs->trans("SmartQueryEdit") . ' - ' . dol_escape_htmltag($query->title),
            '<a href="' . dol_buildpath('/dalfred/smartquery_list.php', 1) . '" class="butAction">' . $langs->trans("SmartQueryBackToList") . '</a>',
            'fa-database'
        );

        print dol_get_fiche_head([], '', '', 0);

        // Use POST values if validation failed, otherwise use DB values
        $val_title = GETPOSTISSET('title') ? GETPOST('title', 'alphanohtml') : $query->title;
        $val_description = GETPOSTISSET('description') ? GETPOST('description', 'restricthtml') : $query->description;
        $val_sql = GETPOSTISSET('sql_query') ? GETPOST('sql_query', 'none') : $query->sql_query;
        $val_category = GETPOSTISSET('category') ? GETPOST('category', 'alphanohtml') : $query->category;
        $val_scope = GETPOSTISSET('scope') ? GETPOST('scope', 'alpha') : $query->scope;
        $val_params = GETPOSTISSET('parameters') ? GETPOST('parameters', 'none') : $query->parameters;

        // Pretty-print JSON parameters for editing
        if (!GETPOSTISSET('parameters') && !empty($val_params)) {
            $decoded = json_decode($val_params, true);
            if ($decoded !== null) {
                $val_params = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }

        print '<table class="border centpercent">';

        // Title
        print '<tr><td class="titlefieldcreate fieldrequired">' . $langs->trans("SmartQueryTitle") . '</td>';
        print '<td><input type="text" name="title" value="' . dol_escape_htmltag($val_title) . '" class="flat minwidth400 maxwidth500" required></td></tr>';

        // Description
        print '<tr><td>' . $langs->trans("SmartQueryDescription") . '</td>';
        print '<td><textarea name="description" class="flat minwidth400 maxwidth500" rows="3">' . dol_escape_htmltag($val_description, 0, 1) . '</textarea></td></tr>';

        // Category
        $categories = explode(',', $langs->transnoentities("SmartQueryCategoryList"));
        print '<tr><td>' . $langs->trans("SmartQueryCategory") . '</td>';
        print '<td><select name="category" class="flat minwidth200">';
        print '<option value="">(' . $langs->trans("SmartQueryNoCategory") . ')</option>';
        foreach ($categories as $cat) {
            $cat = trim($cat);
            $selected = ($val_category == $cat) ? ' selected' : '';
            print '<option value="' . dol_escape_htmltag($cat) . '"' . $selected . '>' . dol_escape_htmltag(ucfirst($cat)) . '</option>';
        }
        print '</select></td></tr>';

        // Scope
        print '<tr><td>' . $langs->trans("SmartQueryScope") . '</td>';
        print '<td><select name="scope" class="flat">';
        print '<option value="private"' . ($val_scope == 'private' ? ' selected' : '') . '>' . $langs->trans("SmartQueryScopePrivate") . '</option>';
        print '<option value="shared"' . ($val_scope == 'shared' ? ' selected' : '') . '>' . $langs->trans("SmartQueryScopeShared") . '</option>';
        print '</select></td></tr>';

        // SQL Query
        print '<tr><td class="fieldrequired tdtop">' . $langs->trans("SmartQuerySQL") . '</td>';
        print '<td>';
        print '<textarea name="sql_query" class="flat" style="width: 100%; min-height: 200px; font-family: \'Courier New\', Courier, monospace; font-size: 13px; tab-size: 4;" required>' . dol_escape_htmltag($val_sql, 0, 1) . '</textarea>';
        print '<br><span class="opacitymedium small">' . $langs->trans("SmartQuerySQLHelp") . '</span>';
        print '</td></tr>';

        // Parameters (JSON)
        print '<tr><td class="tdtop">' . $langs->trans("SmartQueryParamsJSON") . '</td>';
        print '<td>';
        print '<textarea name="parameters" class="flat" style="width: 100%; min-height: 80px; font-family: \'Courier New\', Courier, monospace; font-size: 12px;">' . dol_escape_htmltag($val_params, 0, 1) . '</textarea>';
        print '<br><span class="opacitymedium small">' . $langs->trans("SmartQueryParamsJSONHelp") . '</span>';
        print '</td></tr>';

        print '</table>';

        print dol_get_fiche_end();

        print '<div class="center">';
        print '<input type="submit" class="button button-save" value="' . $langs->trans("SmartQuerySave") . '">';
        print ' &nbsp; <input type="submit" class="button button-cancel" name="cancel" value="' . $langs->trans("Cancel") . '">';
        print '</div>';

        print '</form>';
    }
}

// ---- VIEW MODE ----
if ((empty($action) || in_array($action, ['testquery', 'delete'])) && $id > 0 && $query) {
    // Header with title and actions
    $linkback = '<a href="' . dol_buildpath('/dalfred/smartquery_list.php', 1) . '">' . $langs->trans("SmartQueryBackToList") . '</a>';

    print load_fiche_titre(
        img_picto('', 'fa-database', 'class="pictofixedwidth"') . dol_escape_htmltag($query->title),
        $linkback
    );

    print dol_get_fiche_head([], '', '', 0);

    print '<table class="border centpercent">';

    // Title
    print '<tr><td class="titlefield">' . $langs->trans("SmartQueryTitle") . '</td>';
    print '<td>' . dol_escape_htmltag($query->title) . '</td></tr>';

    // Description
    if (!empty($query->description)) {
        print '<tr><td>' . $langs->trans("SmartQueryDescription") . '</td>';
        print '<td>' . nl2br(dol_escape_htmltag($query->description, 0, 1)) . '</td></tr>';
    }

    // Category
    print '<tr><td>' . $langs->trans("SmartQueryCategory") . '</td>';
    print '<td>' . ($query->category ? dol_escape_htmltag(ucfirst($query->category)) : '<span class="opacitymedium">' . $langs->trans("SmartQueryNoCategory") . '</span>') . '</td></tr>';

    // Scope
    print '<tr><td>' . $langs->trans("SmartQueryScope") . '</td>';
    print '<td>';
    if ($query->scope == 'shared') {
        print '<span class="badge badge-status4">' . $langs->trans("SmartQueryScopeShared") . '</span>';
    } else {
        print '<span class="badge badge-status0">' . $langs->trans("SmartQueryScopePrivate") . '</span>';
    }
    print '</td></tr>';

    // Owner
    $ownerName = trim(($query->user_firstname ?? '') . ' ' . ($query->user_lastname ?? ''));
    if (empty($ownerName)) {
        $ownerName = $query->user_login ?? '?';
    }
    print '<tr><td>' . $langs->trans("SmartQueryOwner") . '</td>';
    print '<td>' . dol_escape_htmltag($ownerName) . '</td></tr>';

    // SQL Query
    print '<tr><td class="tdtop">' . $langs->trans("SmartQuerySQL") . '</td>';
    print '<td><pre style="background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 13px; overflow-x: auto; white-space: pre-wrap; word-break: break-word;">' . dol_escape_htmltag($query->sql_query, 0, 1) . '</pre></td></tr>';

    // Parameters
    if (!empty($query->parameters)) {
        $paramsDef = json_decode($query->parameters, true);
        if (is_array($paramsDef) && count($paramsDef) > 0) {
            print '<tr><td class="tdtop">' . $langs->trans("SmartQueryParameters") . '</td>';
            print '<td><ul class="noborder" style="margin: 0; padding-left: 20px;">';
            foreach ($paramsDef as $param) {
                $paramName = $param['name'] ?? '';
                $paramLabel = $param['label'] ?? $paramName;
                $paramType = $param['type'] ?? 'text';
                $paramDefault = $param['default'] ?? '';
                print '<li><strong>{{' . dol_escape_htmltag($paramName) . '}}</strong> — ' . dol_escape_htmltag($paramLabel);
                print ' <span class="opacitymedium">(' . dol_escape_htmltag($paramType);
                if (!empty($paramDefault)) {
                    print ', default: ' . dol_escape_htmltag($paramDefault);
                }
                print ')</span></li>';
            }
            print '</ul></td></tr>';
        }
    }

    // Execution stats
    print '<tr><td>' . $langs->trans("SmartQueryExecutions") . '</td>';
    print '<td>' . (int) $query->execution_count . '</td></tr>';

    print '<tr><td>' . $langs->trans("SmartQueryLastExec") . '</td>';
    print '<td>';
    if (!empty($query->last_execution) && $query->last_execution !== '0000-00-00 00:00:00') {
        print dol_print_date($db->jdate($query->last_execution), 'dayhour', 'tzuser');
    } else {
        print '<span class="opacitymedium">' . $langs->trans("Never") . '</span>';
    }
    print '</td></tr>';

    // Created date
    print '<tr><td>' . $langs->trans("SmartQueryCreatedDate") . '</td>';
    print '<td>' . dol_print_date($db->jdate($query->date_creation), 'dayhour', 'tzuser') . '</td></tr>';

    // Modified date
    if (!empty($query->tms)) {
        print '<tr><td>' . $langs->trans("SmartQueryModifiedDate") . '</td>';
        print '<td>' . dol_print_date($db->jdate($query->tms), 'dayhour', 'tzuser') . '</td></tr>';
    }

    print '</table>';

    print dol_get_fiche_end();

    // Action buttons
    print '<div class="tabsAction">';

    // Execute
    print '<a href="' . dol_buildpath('/dalfred/smartquery_result.php', 1) . '?id=' . (int) $id . '&action=execute" class="butAction">' . $langs->trans("SmartQueryExecute") . '</a>';

    // Test (inline)
    print '<a href="' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id . '&action=testquery&token=' . newToken() . '" class="butAction">' . $langs->trans("SmartQueryTestSQL") . '</a>';

    // Edit
    if ($canEdit) {
        print '<a href="' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id . '&action=edit" class="butAction">' . $langs->trans("SmartQueryEdit") . '</a>';
    } else {
        print '<span class="butActionRefused classfortooltip" title="' . $langs->trans("SmartQueryNotOwner") . '">' . $langs->trans("SmartQueryEdit") . '</span>';
    }

    // Duplicate
    print '<a href="' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id . '&action=duplicate&token=' . newToken() . '" class="butAction">' . $langs->trans("SmartQueryDuplicate") . '</a>';

    // Delete
    if ($canEdit) {
        print '<a href="' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id . '&action=delete&token=' . newToken() . '" class="butActionDelete">' . $langs->trans("Delete") . '</a>';
    }

    print '</div>';

    // ---- INLINE TEST RESULTS ----
    if ($action == 'testquery' && $query) {
        print '<br>';
        print load_fiche_titre($langs->trans("SmartQueryTestResults"), '', 'fa-play');

        $sqlToExecute = $query->sql_query;

        // For test, replace parameters with defaults if available
        $paramsDef = [];
        if (!empty($query->parameters)) {
            $paramsDef = json_decode($query->parameters, true);
            if (is_array($paramsDef)) {
                foreach ($paramsDef as $param) {
                    $paramName = $param['name'] ?? '';
                    $paramDefault = $param['default'] ?? '';
                    if (!empty($paramName) && !empty($paramDefault)) {
                        $escaped = $db->escape($paramDefault);
                        $sqlToExecute = str_replace('{{' . $paramName . '}}', "'" . $escaped . "'", $sqlToExecute);
                    }
                }
            }
        }

        // Check for unsubstituted parameters
        if (preg_match('/\{\{([a-zA-Z0-9_]+)\}\}/', $sqlToExecute, $matches)) {
            print '<div class="warning">' . $langs->trans("SmartQueryParameters") . ' : {{' . dol_escape_htmltag($matches[1]) . '}} — ';
            print '<a href="' . dol_buildpath('/dalfred/smartquery_result.php', 1) . '?id=' . (int) $id . '">' . $langs->trans("SmartQueryExecute") . '</a>';
            print '</div>';
        } else {
            $validation = validateSelectOnly($sqlToExecute);
            if (!$validation['valid']) {
                print '<div class="error">' . $langs->trans("SmartQueryInvalidSQL") . '</div>';
            } else {
                // Add test LIMIT
                if (!preg_match('/\bLIMIT\b/i', $sqlToExecute)) {
                    $sqlToExecute .= ' LIMIT 20';
                }

                $startTime = microtime(true);
                $resql_exec = $db->query($sqlToExecute);
                $execTime = round((microtime(true) - $startTime) * 1000);

                if (!$resql_exec) {
                    print '<div class="error">' . $langs->trans("SmartQueryExecutionError") . ' : ' . dol_escape_htmltag($db->lasterror()) . '</div>';
                } else {
                    $numResults = $db->num_rows($resql_exec);
                    print '<div class="opacitymedium" style="margin-bottom: 8px;">';
                    print $langs->trans("SmartQueryResultCount", $numResults, $execTime);
                    if (!preg_match('/\bLIMIT\b/i', $query->sql_query)) {
                        print ' <span class="opacitymedium small">(test limité à 20 lignes)</span>';
                    }
                    print '</div>';

                    if ($numResults > 0) {
                        $firstRow = $db->fetch_array($resql_exec);
                        $columns = array_filter(array_keys($firstRow), function ($key) {
                            return !is_numeric($key);
                        });

                        print '<div class="div-table-responsive">';
                        print '<table class="noborder centpercent">';
                        print '<tr class="liste_titre">';
                        print '<td class="center" style="width: 40px;">#</td>';
                        foreach ($columns as $col) {
                            print '<td>' . dol_escape_htmltag($col) . '</td>';
                        }
                        print '</tr>';

                        $rowNum = 1;
                        print '<tr class="oddeven">';
                        print '<td class="center opacitymedium">' . $rowNum . '</td>';
                        foreach ($columns as $col) {
                            $val = $firstRow[$col] ?? '';
                            print '<td>' . dol_escape_htmltag(dol_trunc((string) $val, 100)) . '</td>';
                        }
                        print '</tr>';

                        while ($row = $db->fetch_array($resql_exec)) {
                            $rowNum++;
                            print '<tr class="oddeven">';
                            print '<td class="center opacitymedium">' . $rowNum . '</td>';
                            foreach ($columns as $col) {
                                $val = $row[$col] ?? '';
                                print '<td>' . dol_escape_htmltag(dol_trunc((string) $val, 100)) . '</td>';
                            }
                            print '</tr>';
                        }

                        print '</table>';
                        print '</div>';
                    }

                    $db->free($resql_exec);
                }
            }
        }
    }
}

// Handle case where action is 'edit' but canEdit was denied (fall through to view mode)
if ($action == 'edit' && !$canEdit && $id > 0 && $query) {
    $action = '';
    // The view mode above won't trigger because action is 'edit', so we redirect
    header('Location: ' . $_SERVER["PHP_SELF"] . '?id=' . (int) $id);
    exit;
}

llxFooter();
$db->close();
