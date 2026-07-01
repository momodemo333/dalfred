<?php
/**
 * Dalfred Smart Queries - CSV Export
 *
 * Streams CSV export of a saved query's results.
 * Supports parameterized queries.
 *
 * @package    Dalfred
 * @author     E-dem
 * @license    GPL-3.0+
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include "../../../../main.inc.php";
}
if (!$res) {
    die("Main include failed");
}

$langs->loadLangs(array("dalfred@dalfred"));

if (!$user->hasRight('dalfred', 'use')) {
    http_response_code(403);
    die('Access denied');
}

if (!getDolGlobalString('DALFRED_SMARTQUERY_ENABLED') || !getDolGlobalString('DALFRED_MYSQL_TOOLKIT_ENABLED')) {
    http_response_code(403);
    die('Smart Queries is not enabled');
}

$id = GETPOSTINT('id');
$result_limit = GETPOSTINT('result_limit') ? GETPOSTINT('result_limit') : 10000;
if ($result_limit > 10000) {
    $result_limit = 10000;
}

if ($id <= 0) {
    http_response_code(400);
    die('Invalid query ID');
}

// Fetch the query
$sql_fetch = "SELECT q.rowid, q.fk_user, q.title, q.sql_query, q.parameters, q.scope";
$sql_fetch .= " FROM " . MAIN_DB_PREFIX . "dalfred_smart_queries as q";
$sql_fetch .= " WHERE q.rowid = " . (int)$id;
$sql_fetch .= " AND q.entity = " . (int)$conf->entity;
$sql_fetch .= " AND (q.fk_user = " . (int)$user->id . " OR q.scope = 'shared')";

$resql_fetch = $db->query($sql_fetch);
if (!$resql_fetch || $db->num_rows($resql_fetch) == 0) {
    http_response_code(404);
    die('Query not found');
}
$query = $db->fetch_object($resql_fetch);

// Parse parameters
$paramsDef = [];
if (!empty($query->parameters)) {
    $paramsDef = json_decode($query->parameters, true);
    if (!is_array($paramsDef)) {
        $paramsDef = [];
    }
}

$sqlToExecute = $query->sql_query;

// Substitute parameters (always wrap in quotes for SQL injection protection)
foreach ($paramsDef as $param) {
    $paramName = $param['name'] ?? '';
    if (empty($paramName)) {
        continue;
    }
    $value = GETPOST('param_' . $paramName, 'alpha');
    if ($value !== '' && $value !== null) {
        $escaped = $db->escape($value);
        $sqlToExecute = str_replace('{{' . $paramName . '}}', "'" . $escaped . "'", $sqlToExecute);
    }
}

// Check for unsubstituted parameters
if (preg_match('/\{\{[a-zA-Z0-9_]+\}\}/', $sqlToExecute)) {
    http_response_code(400);
    die('Missing required parameters');
}

// Validate SQL
$dangerousKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'GRANT', 'REVOKE', 'EXEC', 'EXECUTE', 'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'UNION', 'SLEEP', 'BENCHMARK', 'CALL', 'PROCEDURE'];
$normalized = strtoupper(trim($sqlToExecute));
if (strpos($normalized, 'SELECT') !== 0) {
    http_response_code(400);
    die('Invalid SQL: must start with SELECT');
}
foreach ($dangerousKeywords as $keyword) {
    if (preg_match('/\b' . $keyword . '\b/i', $sqlToExecute)) {
        http_response_code(400);
        die('Invalid SQL: contains dangerous keyword');
    }
}

// Add LIMIT if not present
if (!preg_match('/\bLIMIT\b/i', $sqlToExecute)) {
    $sqlToExecute .= ' LIMIT ' . (int)$result_limit;
}

$resql_exec = $db->query($sqlToExecute);
if (!$resql_exec) {
    dol_syslog("SmartQuery export error for id=$id: " . $db->lasterror(), LOG_ERR);
    http_response_code(500);
    die('Query execution failed');
}

// Update execution stats
$sql_update = "UPDATE " . MAIN_DB_PREFIX . "dalfred_smart_queries SET";
$sql_update .= " last_execution = NOW(),";
$sql_update .= " execution_count = execution_count + 1";
$sql_update .= " WHERE rowid = " . (int)$id;
$db->query($sql_update);

// Generate filename
$filename = 'smartquery_' . dol_sanitizeFileName(dol_trunc($query->title, 30, 'right', 'UTF-8', 1)) . '_' . date('Y-m-d') . '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

$numResults = $db->num_rows($resql_exec);
$headerWritten = false;

// Sanitize cell values to prevent CSV formula injection
function sanitizeCsvValue($value)
{
    if (is_string($value) && isset($value[0]) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"])) {
        return "'" . $value;
    }
    return $value;
}

if ($numResults > 0) {
    while ($row = $db->fetch_array($resql_exec)) {
        // Filter numeric keys
        $assocRow = [];
        foreach ($row as $key => $value) {
            if (!is_numeric($key)) {
                $assocRow[$key] = $value;
            }
        }

        // Write header on first row
        if (!$headerWritten) {
            fputcsv($output, array_keys($assocRow), ';');
            $headerWritten = true;
        }

        fputcsv($output, array_map('sanitizeCsvValue', array_values($assocRow)), ';');
    }
}

fclose($output);
$db->free($resql_exec);
$db->close();
