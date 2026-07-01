<?php
/**
 * Dalfred Activity Log Page
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once '../lib/dalfred.lib.php';

$langs->loadLangs(array("admin", "dalfred@dalfred"));

if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$search_event_type = GETPOST('search_event_type', 'alpha');
$search_tool_name = GETPOST('search_tool_name', 'alpha');
$search_thread_id = GETPOST('search_thread_id', 'alpha');
$search_user = GETPOST('search_user', 'alpha');

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : 50;
$page = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$offset = $limit * $page;

if (empty($sortfield)) {
    $sortfield = 'l.date_creation';
}
if (empty($sortorder)) {
    $sortorder = 'DESC';
}

/*
 * Actions
 */

if ($action == 'purge' && $user->admin) {
    $retention_days = getDolGlobalInt('DALFRED_LOG_RETENTION_DAYS', 30);
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."dalfred_activity_log";
    $sql .= " WHERE date_creation < DATE_SUB(NOW(), INTERVAL ".(int)$retention_days." DAY)";
    $sql .= " AND entity = ".(int)$conf->entity;
    $resql = $db->query($sql);
    if ($resql) {
        $deleted = $db->affected_rows($resql);
        setEventMessages($langs->trans("LogsPurged", $deleted), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

if ($action == 'purge_all' && $user->admin) {
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."dalfred_activity_log";
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

$page_name = "DalfredActivityLog";
llxHeader('', $langs->trans($page_name));

$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'activitylog', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Count total
$sql_count = "SELECT COUNT(*) as total FROM ".MAIN_DB_PREFIX."dalfred_activity_log as l";
if ($search_user) {
    $sql_count .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON l.fk_user = u.rowid";
}
$sql_count .= " WHERE l.entity = ".(int)$conf->entity;
if ($search_event_type) {
    $sql_count .= " AND l.event_type = '".$db->escape($search_event_type)."'";
}
if ($search_tool_name) {
    $sql_count .= " AND l.tool_name LIKE '%".$db->escape($search_tool_name)."%'";
}
if ($search_thread_id) {
    $sql_count .= " AND l.thread_id = '".$db->escape($search_thread_id)."'";
}
if ($search_user) {
    $sql_count .= " AND u.login LIKE '%".$db->escape($search_user)."%'";
}

$resql_count = $db->query($sql_count);
$total = 0;
if ($resql_count) {
    $obj = $db->fetch_object($resql_count);
    $total = (int)$obj->total;
}

// Purge buttons
$retention_days = getDolGlobalInt('DALFRED_LOG_RETENTION_DAYS', 30);
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=purge&token='.newToken().'">'.$langs->trans("PurgeOldLogs", $retention_days).'</a>';
print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?action=purge_all&token='.newToken().'" onclick="return confirm(\''.$langs->trans("ConfirmPurgeAll").'\');">'.$langs->trans("PurgeAllLogs").'</a>';
print '</div>';

print '<div class="info">'.$langs->trans("ActivityLogDesc", $total).'</div>';
print '<br>';

// Build query
$sql = "SELECT l.rowid, l.fk_user, l.thread_id, l.event_type, l.tool_name,";
$sql .= " l.tool_inputs, l.tool_result, l.duration_ms, l.error_message,";
$sql .= " l.exception_class, l.exception_file, l.stack_trace, l.context,";
$sql .= " l.date_creation, u.login as user_login";
$sql .= " FROM ".MAIN_DB_PREFIX."dalfred_activity_log as l";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON l.fk_user = u.rowid";
$sql .= " WHERE l.entity = ".(int)$conf->entity;

if ($search_event_type) {
    $sql .= " AND l.event_type = '".$db->escape($search_event_type)."'";
}
if ($search_tool_name) {
    $sql .= " AND l.tool_name LIKE '%".$db->escape($search_tool_name)."%'";
}
if ($search_thread_id) {
    $sql .= " AND l.thread_id = '".$db->escape($search_thread_id)."'";
}
if ($search_user) {
    $sql .= " AND u.login LIKE '%".$db->escape($search_user)."%'";
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}

$num = $db->num_rows($resql);

// Navigation
$param = '&search_event_type='.urlencode($search_event_type).'&search_tool_name='.urlencode($search_tool_name).'&search_thread_id='.urlencode($search_thread_id).'&search_user='.urlencode($search_user).'&limit='.(int)$limit;
print_barre_liste(
    $langs->trans("ActivityLog"),
    $page,
    $_SERVER["PHP_SELF"],
    $param,
    $sortfield,
    $sortorder,
    '',
    $num,
    $total,
    'fa-list',
    0,
    '',
    '',
    $limit
);

// Limit selector
print '<div class="right marginbottomonly">';
print '<span class="opacitymedium">'.$langs->trans("ResultsPerPage").' </span>';
print '<select name="limit" class="flat" onchange="window.location=\''.$_SERVER["PHP_SELF"].'?limit=\'+this.value+\'&search_event_type='.urlencode($search_event_type).'&search_tool_name='.urlencode($search_tool_name).'&search_thread_id='.urlencode($search_thread_id).'&search_user='.urlencode($search_user).'\'">';
foreach (array(25, 50, 100, 200) as $nb) {
    $selected = ($limit == $nb) ? ' selected' : '';
    print '<option value="'.$nb.'"'.$selected.'>'.$nb.'</option>';
}
print '</select>';
print '</div>';

// Filter form
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="limit" value="'.(int)$limit.'">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "l.date_creation", '', '', '', $sortfield, $sortorder);
print_liste_field_titre("User", $_SERVER["PHP_SELF"], "u.login", '', '', '', $sortfield, $sortorder);
print_liste_field_titre("EventType", $_SERVER["PHP_SELF"], "l.event_type", '', '', '', $sortfield, $sortorder);
print_liste_field_titre("ToolName", $_SERVER["PHP_SELF"], "l.tool_name", '', '', '', $sortfield, $sortorder);
print_liste_field_titre("ThreadID", $_SERVER["PHP_SELF"], "l.thread_id", '', '', '', $sortfield, $sortorder);
print_liste_field_titre("Duration", $_SERVER["PHP_SELF"], "l.duration_ms", '', '', 'align="right"', $sortfield, $sortorder);
print '<td class="liste_titre">'.$langs->trans("Details").'</td>';
print '</tr>';

// Search row
print '<tr class="liste_titre">';
print '<td></td>'; // Date
print '<td><input type="text" name="search_user" value="'.dol_escape_htmltag($search_user).'" class="flat width75" placeholder="Login"></td>';
print '<td><select name="search_event_type" class="flat">';
print '<option value="">'.$langs->trans("All").'</option>';
foreach (array('tool_calling', 'tool_called', 'inference_start', 'inference_stop', 'error') as $type) {
    $selected = ($search_event_type == $type) ? ' selected' : '';
    print '<option value="'.$type.'"'.$selected.'>'.$type.'</option>';
}
print '</select></td>';
print '<td><input type="text" name="search_tool_name" value="'.dol_escape_htmltag($search_tool_name).'" class="flat width100"></td>';
print '<td><input type="text" name="search_thread_id" value="'.dol_escape_htmltag($search_thread_id).'" class="flat width100"></td>';
print '<td></td>'; // Duration
print '<td>';
print '<input type="submit" class="button" value="'.$langs->trans("Search").'">';
print ' <a href="'.$_SERVER["PHP_SELF"].'">'.$langs->trans("Reset").'</a>';
print '</td>';
print '</tr>';

// Data rows
$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);

    $event_class = '';
    $event_icon = '';
    switch ($obj->event_type) {
        case 'tool_calling':
            $event_icon = '<span class="fas fa-play" style="color: #4a90d9;" title="Tool calling"></span>';
            break;
        case 'tool_called':
            $event_icon = '<span class="fas fa-check" style="color: #28a745;" title="Tool called"></span>';
            break;
        case 'inference_start':
            $event_icon = '<span class="fas fa-brain" style="color: #6f42c1;" title="Inference start"></span>';
            break;
        case 'inference_stop':
            $event_icon = '<span class="fas fa-brain" style="color: #17a2b8;" title="Inference stop"></span>';
            break;
        case 'error':
            $event_icon = '<span class="fas fa-exclamation-triangle" style="color: #dc3545;" title="Error"></span>';
            $event_class = ' style="background-color: #fff5f5;"';
            break;
    }

    print '<tr class="oddeven"'.$event_class.'>';

    // Date
    print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'dayhour', 'tzuser').'</td>';

    // User
    print '<td>'.dol_escape_htmltag($obj->user_login ?? 'ID:'.$obj->fk_user).'</td>';

    // Event type
    print '<td>'.$event_icon.' '.dol_escape_htmltag($obj->event_type).'</td>';

    // Tool name
    print '<td>'.($obj->tool_name ? '<code>'.dol_escape_htmltag($obj->tool_name).'</code>' : '-').'</td>';

    // Thread ID
    $short_thread = $obj->thread_id ? mb_substr($obj->thread_id, 0, 20).'...' : '-';
    print '<td title="'.dol_escape_htmltag($obj->thread_id ?? '').'" class="small">'.dol_escape_htmltag($short_thread).'</td>';

    // Duration
    print '<td class="right">';
    if ($obj->duration_ms !== null) {
        if ($obj->duration_ms > 1000) {
            print '<span style="color: '.($obj->duration_ms > 5000 ? '#dc3545' : '#856404').';">'.number_format($obj->duration_ms / 1000, 1).'s</span>';
        } else {
            print $obj->duration_ms.'ms';
        }
    } else {
        print '-';
    }
    print '</td>';

    // Details (inline 300 chars + "Voir" button if richer payload exists)
    print '<td class="small">';

    $inlineText = '';
    $inlineStyle = '';
    if ($obj->error_message) {
        $inlineText = $obj->error_message;
        $inlineStyle = 'color: #dc3545;';
    } elseif ($obj->tool_inputs && $obj->tool_inputs !== '[]' && $obj->tool_inputs !== '{}') {
        $inputs = json_decode($obj->tool_inputs, true);
        if (is_array($inputs)) {
            $parts = [];
            foreach ($inputs as $k => $v) {
                if (is_string($v)) {
                    $parts[] = $k.'='.mb_substr($v, 0, 80);
                } elseif ($v !== null) {
                    $parts[] = $k.'='.json_encode($v);
                }
            }
            $inlineText = implode(', ', $parts);
            $inlineStyle = 'opacity: 0.75;';
        }
    }

    if ($inlineText !== '') {
        $displayed = mb_substr($inlineText, 0, 300);
        if (mb_strlen($inlineText) > 300) {
            $displayed .= '…';
        }
        print '<span style="'.$inlineStyle.'">'.dol_escape_htmltag($displayed).'</span>';
    }

    // "Voir" button if any of the rich fields exist (tool_result / stack_trace
    // / long error / long inputs / context). The payload is base64-encoded JSON
    // on a data attribute — modal JS decodes and renders.
    $hasRichPayload = (
        $obj->tool_result !== null
        || $obj->stack_trace !== null
        || ($obj->error_message !== null && mb_strlen($obj->error_message) > 300)
        || ($obj->tool_inputs !== null && mb_strlen($obj->tool_inputs) > 300)
        || $obj->context !== null
    );

    if ($hasRichPayload) {
        $payload = [
            'date'            => dol_print_date($db->jdate($obj->date_creation), 'dayhour', 'tzuser'),
            'user'            => $obj->user_login ?? ('ID:'.$obj->fk_user),
            'event_type'      => $obj->event_type,
            'tool_name'       => $obj->tool_name,
            'thread_id'       => $obj->thread_id,
            'duration_ms'     => $obj->duration_ms,
            'tool_inputs'     => $obj->tool_inputs,
            'tool_result'     => $obj->tool_result,
            'error_message'   => $obj->error_message,
            'exception_class' => $obj->exception_class,
            'exception_file'  => $obj->exception_file,
            'stack_trace'     => $obj->stack_trace,
            'context'         => $obj->context,
        ];
        $encoded = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        print ' <a href="#" class="button_search dalfred-log-view-btn" data-payload="'.$encoded.'" style="margin-left:8px;">'.$langs->trans("View").'</a>';
    }

    print '</td>';

    print '</tr>';
    $i++;
}

if ($num == 0) {
    print '<tr class="oddeven"><td colspan="7" class="opacitymedium center">'.$langs->trans("NoActivityLogs").'</td></tr>';
}

print '</table>';
print '</form>';

$db->free($resql);

// Modal markup (hidden by default) — populated by dalfred-log-modal.js
print '<div id="dalfred-log-modal" style="display:none;">';
print '  <div class="dalfred-log-modal-backdrop"></div>';
print '  <div class="dalfred-log-modal-content">';
print '    <div class="dalfred-log-modal-header">';
print '      <h3>'.$langs->trans("LogDetailTitle").'</h3>';
print '      <a href="#" class="dalfred-log-modal-close">&times;</a>';
print '    </div>';
print '    <div class="dalfred-log-modal-body"></div>';
print '  </div>';
print '</div>';

print '<link rel="stylesheet" href="'.dol_buildpath('/dalfred/css/dalfred-log-modal.css', 1).'?v=2.21.1">';
print '<script src="'.dol_buildpath('/dalfred/js/dalfred-log-modal.js', 1).'?v=2.21.1"></script>';

print dol_get_fiche_end();

llxFooter();
$db->close();
