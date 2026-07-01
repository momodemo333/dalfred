<?php
/**
 * Dalfred Smart Queries - Result Page
 *
 * Displays query results in a table with CSV export.
 * Handles parameter input for parameterized queries.
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
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$result_limit = GETPOSTINT('result_limit') ? GETPOSTINT('result_limit') : 200;
if ($result_limit > 10000) {
    $result_limit = 10000;
}

if ($id <= 0) {
    header('Location: ' . dol_buildpath('/dalfred/smartquery_list.php', 1));
    exit;
}

// Fetch the query
$sql_fetch = "SELECT q.rowid, q.fk_user, q.title, q.description, q.sql_query, q.category, q.parameters, q.scope,";
$sql_fetch .= " q.last_execution, q.execution_count";
$sql_fetch .= " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries as q";
$sql_fetch .= " WHERE q.rowid = " . (int)$id;
$sql_fetch .= " AND q.entity = " . (int)$conf->entity;
$sql_fetch .= " AND (q.fk_user = " . (int)$user->id . " OR q.scope = 'shared')";

$resql_fetch = $db->query($sql_fetch);
if (!$resql_fetch || $db->num_rows($resql_fetch) == 0) {
    setEventMessages($langs->trans("SmartQueryNotFound"), null, 'errors');
    header('Location: ' . dol_buildpath('/dalfred/smartquery_list.php', 1));
    exit;
}
$query = $db->fetch_object($resql_fetch);

// Parse parameters definition
$paramsDef = [];
if (!empty($query->parameters)) {
    $paramsDef = json_decode($query->parameters, true);
    if (!is_array($paramsDef)) {
        $paramsDef = [];
    }
}

// Check if we need parameter input
$needsParams = (count($paramsDef) > 0);
$paramsProvided = true;
$paramValues = [];

if ($needsParams) {
    foreach ($paramsDef as $param) {
        $paramName = $param['name'] ?? '';
        if (empty($paramName)) {
            continue;
        }
        $value = GETPOST('param_' . $paramName, 'alpha');
        if ($value === '' || $value === null) {
            $paramsProvided = false;
        }
        $paramValues[$paramName] = $value ?? '';
    }
}

// SQL validation
$dangerousKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE', 'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'UNION', 'SLEEP', 'BENCHMARK', 'CALL', 'PROCEDURE'];

function validateSelectOnly(string $sql): bool
{
    global $dangerousKeywords;
    $normalized = strtoupper(trim($sql));
    if (strpos($normalized, 'SELECT') !== 0) {
        return false;
    }
    foreach ($dangerousKeywords as $keyword) {
        if (preg_match('/\b' . $keyword . '\b/i', $sql)) {
            return false;
        }
    }
    return true;
}

/*
 * View
 */

llxHeader('', $langs->trans("SmartQueryResults") . ' - ' . dol_escape_htmltag($query->title));

// Header
print '<div class="titre inline-block">';
print img_picto('', 'fa-database', 'class="pictofixedwidth"');
print $langs->trans("SmartQueryResults");
print '</div>';
print '<div class="inline-block floatright" style="padding-top: 8px;">';
print '<a href="' . dol_buildpath('/dalfred/smartquery_card.php', 1) . '?id=' . (int)$id . '" class="butAction">' . $langs->trans("SmartQueryBackToCard") . '</a>';
print ' ';
print '<a href="' . dol_buildpath('/dalfred/smartquery_list.php', 1) . '" class="butAction">' . $langs->trans("SmartQueryBackToList") . '</a>';
print '</div>';
print '<div class="clearboth"></div>';
print '<br>';

// Query info
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . dol_escape_htmltag($query->title) . '</td></tr>';
if (!empty($query->description)) {
    print '<tr class="oddeven"><td style="width: 15%;">' . $langs->trans("Description") . '</td>';
    print '<td>' . dol_escape_htmltag($query->description) . '</td></tr>';
}
if (!empty($query->category)) {
    print '<tr class="oddeven"><td>' . $langs->trans("SmartQueryCategory") . '</td>';
    print '<td>' . dol_escape_htmltag($query->category) . '</td></tr>';
}
print '<tr class="oddeven"><td>' . $langs->trans("SmartQuerySQL") . '</td>';
print '<td><pre style="font-size: 0.85em; white-space: pre-wrap; word-break: break-word; margin: 0;">' . dol_escape_htmltag(dol_trunc($query->sql_query, 500), 0, 1) . '</pre></td></tr>';
print '</table>';
print '<br>';

// Parameter form if needed
if ($needsParams && (!$paramsProvided || $action != 'execute')) {
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '?id=' . (int)$id . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="execute">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("SmartQueryParameters") . '</td></tr>';

    foreach ($paramsDef as $param) {
        $paramName = $param['name'] ?? '';
        $paramLabel = $param['label'] ?? $paramName;
        $paramType = $param['type'] ?? 'text';
        $paramDefault = $param['default'] ?? '';

        if (empty($paramName)) {
            continue;
        }

        $currentValue = $paramValues[$paramName] ?? $paramDefault;

        print '<tr class="oddeven">';
        print '<td style="width: 20%;">' . dol_escape_htmltag($paramLabel) . '</td>';
        print '<td>';
        if ($paramType == 'date') {
            print '<input type="date" name="param_' . dol_escape_htmltag($paramName) . '" value="' . dol_escape_htmltag($currentValue) . '" class="flat minwidth200">';
        } else {
            print '<input type="text" name="param_' . dol_escape_htmltag($paramName) . '" value="' . dol_escape_htmltag($currentValue) . '" class="flat minwidth200">';
        }
        print '</td>';
        print '</tr>';
    }

    print '<tr class="oddeven"><td>' . $langs->trans("SmartQueryResultLimit") . '</td>';
    print '<td><input type="number" name="result_limit" value="' . (int)$result_limit . '" min="1" max="10000" class="flat width100"></td></tr>';

    print '</table>';
    print '<div class="center" style="padding: 10px;">';
    print '<input type="submit" class="button" value="' . $langs->trans("SmartQueryExecute") . '">';
    print '</div>';
    print '</form>';
    print '<br>';
}

// Execute query
if ($action == 'execute' && ($paramsProvided || !$needsParams)) {
    $sqlToExecute = $query->sql_query;

    // Substitute parameters (always wrap in quotes for SQL injection protection)
    if ($needsParams) {
        foreach ($paramValues as $name => $value) {
            $escaped = $db->escape($value);
            $sqlToExecute = str_replace('{{' . $name . '}}', "'" . $escaped . "'", $sqlToExecute);
        }
    }

    // Validate SQL
    if (!validateSelectOnly($sqlToExecute)) {
        print '<div class="error">' . $langs->trans("SmartQueryInvalidSQL") . '</div>';
    } else {
        // Add LIMIT if not present
        if (!preg_match('/\bLIMIT\b/i', $sqlToExecute)) {
            $sqlToExecute .= ' LIMIT ' . (int)$result_limit;
        }

        $startTime = microtime(true);
        $resql_exec = $db->query($sqlToExecute);
        $execTime = round((microtime(true) - $startTime) * 1000);

        if (!$resql_exec) {
            dol_syslog("SmartQuery execution error for id=$id: " . $db->lasterror(), LOG_ERR);
            print '<div class="error">' . $langs->trans("SmartQueryExecutionError") . '</div>';
        } else {
            $numResults = $db->num_rows($resql_exec);

            // Update execution stats
            $sql_update = "UPDATE " . MAIN_DB_PREFIX . "dalfred_smart_queries SET";
            $sql_update .= " last_execution = NOW(),";
            $sql_update .= " execution_count = execution_count + 1";
            $sql_update .= " WHERE rowid = " . (int)$id;
            $db->query($sql_update);

            // Results info + export button
            print '<div style="margin-bottom: 10px;">';
            print '<span class="opacitymedium">' . $langs->trans("SmartQueryResultCount", $numResults, $execTime) . '</span>';
            if ($numResults > 0) {
                // Build export URL with same params
                $exportUrl = dol_buildpath('/dalfred/ajax/smartquery_export.php', 1) . '?id=' . (int)$id . '&token=' . newToken();
                if ($needsParams) {
                    foreach ($paramValues as $name => $value) {
                        $exportUrl .= '&param_' . urlencode($name) . '=' . urlencode($value);
                    }
                }
                $exportUrl .= '&result_limit=' . (int)$result_limit;
                print ' &nbsp; <a href="' . $exportUrl . '" class="butAction small">';
                print img_picto('', 'download', 'class="pictofixedwidth"') . $langs->trans("SmartQueryExportCSV");
                print '</a>';
            }
            print '</div>';

            if ($numResults > 0) {
                // Get column names from first row
                $firstRow = $db->fetch_array($resql_exec);
                $columns = array_keys($firstRow);
                // Filter out numeric indices
                $columns = array_filter($columns, function ($key) {
                    return !is_numeric($key);
                });

                print '<div class="div-table-responsive">';
                print '<table class="noborder centpercent">';

                // Header
                print '<tr class="liste_titre">';
                print '<td class="center" style="width: 40px;">#</td>';
                foreach ($columns as $col) {
                    print '<td>' . dol_escape_htmltag($col) . '</td>';
                }
                print '</tr>';

                // First row
                $rowNum = 1;
                print '<tr class="oddeven">';
                print '<td class="center opacitymedium">' . $rowNum . '</td>';
                foreach ($columns as $col) {
                    $val = $firstRow[$col] ?? '';
                    print '<td>' . dol_escape_htmltag(dol_trunc((string)$val, 150)) . '</td>';
                }
                print '</tr>';

                // Remaining rows
                while ($row = $db->fetch_array($resql_exec)) {
                    $rowNum++;
                    print '<tr class="oddeven">';
                    print '<td class="center opacitymedium">' . $rowNum . '</td>';
                    foreach ($columns as $col) {
                        $val = $row[$col] ?? '';
                        print '<td>' . dol_escape_htmltag(dol_trunc((string)$val, 150)) . '</td>';
                    }
                    print '</tr>';
                }

                print '</table>';
                print '</div>';
            } else {
                print '<div class="opacitymedium">' . $langs->trans("SmartQueryNoResults") . '</div>';
            }

            $db->free($resql_exec);
        }
    }
}

llxFooter();
$db->close();
