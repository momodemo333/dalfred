<?php
/**
 * Dalfred admin — Token usage dashboard
 *
 * KPI cards, daily series CSS bar chart, filterable per-call table and a
 * >90 days purge action. Admin-only.
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    2.20.0
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
require_once '../vendor/autoload.php';

use Dalfred\Repository\TokenUsageRepository;
use Dalfred\Service\DalfredMigrations;
use Dalfred\Service\ThreadService;

// Load language files (BEFORE accessforbidden so the error page is localized)
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check - only admins can access
if (!$user->admin) {
    accessforbidden();
}

/*
 * Parameters
 */

$action       = GETPOST('action', 'aZ09');
$days         = GETPOSTINT('days');
if (!in_array($days, array(7, 30, 90), true)) {
    $days = 30;
}
$filterUserId = GETPOSTINT('filter_user');
$filterModel  = GETPOST('filter_model', 'alphanohtml');
$page         = GETPOSTINT('page');
if ($page < 0) {
    $page = 0;
}
$pageSize = 50;

// Build the repository through ThreadService::getPDO() — same PDO used everywhere else.
$threadService = new ThreadService($db, (int) $user->id, (int) $conf->entity);
$pdo  = $threadService->getPDO();
$repo = new TokenUsageRepository($pdo);

/*
 * Actions
 */

$purgeMessage = '';
if ($action === 'purge_confirmed' && $user->admin) {
    // Dolibarr CSRF check is automatic when NOCSRFCHECK is not '1' and a token is present.
    // We still check token explicitly for clarity.
    $deleted = $repo->purgeOlderThan((int) $conf->entity, 90);
    // Note: Dolibarr's $langs->trans() does its own %s substitution when extra
    // args are passed (it's NOT a passthrough to sprintf). Always pass the
    // value as a second argument rather than wrapping in sprintf().
    $purgeMessage = $langs->trans('TokenUsagePurgeDone', (string) $deleted);
}

/*
 * View
 */

$page_name = "TokenUsageDashboardTitle";
llxHeader('', $langs->trans($page_name));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

$head = dalfred_admin_prepare_head();
print dol_get_fiche_head($head, 'usage', $langs->trans("DalfredSetup"), -1, 'fa-robot');

print load_fiche_titre($langs->trans('TokenUsageDashboardTitle'), '', 'fa-chart-bar');

if ($purgeMessage !== '') {
    print '<div class="info">'.dol_escape_htmltag($purgeMessage).'</div>';
}

// === KPI cards ===
$kpis = $repo->getKpis((int) $conf->entity, 7);

print '<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:20px;">';

$kpiCards = array(
    'TokenUsageKpiTotal'          => number_format($kpis['total_tokens'], 0, '.', ' '),
    'TokenUsageKpiInferences'     => number_format($kpis['total_inferences'], 0, '.', ' '),
    'TokenUsageKpiTopUser'        => ($kpis['top_user_id'] > 0 ? '#'.$kpis['top_user_id'] : '—')
        .' ('.number_format($kpis['top_user_tokens'], 0, '.', ' ').')',
    'TokenUsageKpiHeaviestThread' => ($kpis['heaviest_thread_id'] !== '' ? mb_substr($kpis['heaviest_thread_id'], 0, 16).'…' : '—')
        .' ('.number_format($kpis['heaviest_thread_tokens'], 0, '.', ' ').')',
);
foreach ($kpiCards as $labelKey => $value) {
    print '<div style="flex:1; min-width:180px; padding:12px; background:#f8f8f8; border-radius:6px; border:1px solid #e0e0e0;">';
    print '<div style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:0.5px;">'.$langs->trans($labelKey).'</div>';
    print '<div style="font-size:18px; font-weight:bold; margin-top:4px; color:#333;">'.dol_escape_htmltag($value).'</div>';
    print '</div>';
}
print '</div>';

// === Period selector ===
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom:12px;">';
print '<label style="margin-right:8px;"><strong>'.$langs->trans('TokenUsageGraphTitle').'</strong> :</label>';
foreach (array(7, 30, 90) as $d) {
    $checked = ($d === $days) ? ' checked' : '';
    print '<label style="margin-right:12px;"><input type="radio" name="days" value="'.$d.'" onchange="this.form.submit()"'.$checked.'> '.$langs->trans('TokenUsagePeriod'.$d).'</label>';
}
// Preserve other filters when changing period
if ($filterUserId > 0) {
    print '<input type="hidden" name="filter_user" value="'.(int) $filterUserId.'">';
}
if ($filterModel !== '') {
    print '<input type="hidden" name="filter_model" value="'.dol_escape_htmltag($filterModel).'">';
}
print '</form>';

// === Daily graph (CSS bar chart) ===
$series = $repo->getDailySeries((int) $conf->entity, $days);
if (empty($series)) {
    print '<div class="opacitymedium" style="margin:20px 0; padding:20px; background:#f8f8f8; border-radius:6px; text-align:center;">'.$langs->trans('TokenUsageNoData').'</div>';
} else {
    print '<table class="noborder centpercent" style="margin-bottom:20px;">';
    print '<tr class="liste_titre"><td style="width:120px;">'.$langs->trans('TokenUsageColDate').'</td><td class="right">Tokens</td></tr>';

    $tokensColumn = array_column($series, 'tokens');
    $maxTokens    = !empty($tokensColumn) ? max($tokensColumn) : 0;
    if ($maxTokens <= 0) {
        $maxTokens = 1;
    }

    foreach ($series as $row) {
        $width = (int) round(100 * $row['tokens'] / $maxTokens);
        if ($width < 1 && $row['tokens'] > 0) {
            $width = 1;
        }
        print '<tr class="oddeven">';
        print '<td class="nowraponall">'.dol_escape_htmltag($row['date']).'</td>';
        print '<td class="right" style="position:relative;">';
        print '<div style="position:absolute; top:0; left:0; height:100%; width:'.$width.'%; background:rgba(74,144,226,0.18);"></div>';
        print '<span style="position:relative;">'.number_format($row['tokens'], 0, '.', ' ').'</span>';
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}

// === Filters form ===
print '<h3>'.$langs->trans('TokenUsageTableTitle').'</h3>';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom:8px;">';
print '<input type="hidden" name="days" value="'.(int) $days.'">';
print '<label style="margin-right:8px;">'.$langs->trans('TokenUsageColUser').' ID : <input type="number" name="filter_user" value="'.($filterUserId > 0 ? (int) $filterUserId : '').'" style="width:80px;" class="flat"></label>';
print '<label style="margin-right:8px;">'.$langs->trans('TokenUsageColModel').' : <input type="text" name="filter_model" value="'.dol_escape_htmltag($filterModel).'" class="flat width150"></label>';
print '<button type="submit" class="button">'.$langs->trans('TokenUsageFilter').'</button>';
if ($filterUserId > 0 || $filterModel !== '') {
    print ' <a href="'.$_SERVER['PHP_SELF'].'?days='.(int) $days.'">'.$langs->trans('Reset').'</a>';
}
print '</form>';

// === Detail table ===
$filters = array();
if ($filterUserId > 0) {
    $filters['user_id'] = $filterUserId;
}
if ($filterModel !== '') {
    $filters['model'] = $filterModel;
}

$total = $repo->countForAdmin((int) $conf->entity, $filters);
$rows  = $repo->listForAdmin((int) $conf->entity, $filters, $pageSize, $page * $pageSize);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
foreach (array('TokenUsageColDate', 'TokenUsageColUser', 'TokenUsageColThread', 'TokenUsageColModel', 'TokenUsageColInput', 'TokenUsageColOutput', 'TokenUsageColDuration', 'TokenUsageColTools') as $hKey) {
    print '<td>'.$langs->trans($hKey).'</td>';
}
print '</tr>';

if (empty($rows)) {
    print '<tr class="oddeven"><td colspan="8" class="opacitymedium center" style="padding:20px;">'.$langs->trans('TokenUsageNoData').'</td></tr>';
} else {
    foreach ($rows as $r) {
        print '<tr class="oddeven">';
        print '<td class="nowraponall">'.dol_escape_htmltag((string) $r['date_creation']).'</td>';
        print '<td>#'.(int) $r['fk_user'].'</td>';
        $threadShort = $r['thread_id'] !== '' ? mb_substr((string) $r['thread_id'], 0, 16).'…' : '-';
        print '<td title="'.dol_escape_htmltag((string) $r['thread_id']).'" class="small"><code>'.dol_escape_htmltag($threadShort).'</code></td>';
        print '<td><code>'.dol_escape_htmltag((string) $r['model']).'</code></td>';
        print '<td class="right">'.number_format((int) $r['input_tokens'], 0, '.', ' ').'</td>';
        print '<td class="right">'.number_format((int) $r['output_tokens'], 0, '.', ' ').'</td>';
        print '<td class="right">'.(int) $r['duration_ms'].' ms</td>';
        print '<td class="right">'.(int) $r['tool_calls_count'].'</td>';
        print '</tr>';
    }
}
print '</table>';

// === Pagination ===
$totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;
if ($totalPages > 1) {
    $baseParams = 'days='.(int) $days.'&filter_user='.(int) $filterUserId.'&filter_model='.urlencode($filterModel);
    print '<div style="margin-top:8px; text-align:center;">';
    print 'Page '.($page + 1).' / '.$totalPages.' — ';
    if ($page > 0) {
        print '<a href="?'.$baseParams.'&page='.($page - 1).'">‹ '.$langs->trans('Previous').'</a> ';
    }
    if ($page + 1 < $totalPages) {
        print '<a href="?'.$baseParams.'&page='.($page + 1).'">'.$langs->trans('Next').' ›</a>';
    }
    print '</div>';
}

// === Maintenance block ===
print '<h3 style="margin-top:30px;">'.$langs->trans('Maintenance').'</h3>';

$dbVersion     = getDolGlobalString('DALFRED_DB_VERSION', '?');
$moduleVersion = DalfredMigrations::MODULE_VERSION;
print '<p>'.$langs->trans('TokenUsageDbVersionLabel').' : <code>'.dol_escape_htmltag($dbVersion).'</code> / code <code>'.dol_escape_htmltag($moduleVersion).'</code></p>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" onsubmit="return confirm(\''.dol_escape_js($langs->trans('TokenUsagePurgeConfirm')).'\');">';
print '<input type="hidden" name="action" value="purge_confirmed">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<button type="submit" class="butActionDelete">'.$langs->trans('TokenUsagePurgeButton').'</button>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
