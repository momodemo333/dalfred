<?php
/**
 * Dalfred Diagnostic Page
 *
 * Detect the web stack between the browser and PHP, and recommend the
 * right timeout settings for long Dalfred answers (504 hunting).
 *
 * @package Dalfred
 * @license GPL-3.0+
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once '../lib/dalfred.lib.php';
require_once '../vendor/autoload.php';

use Dalfred\Service\DiagnosticService;

$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Admin only
if (!$user->admin) {
    accessforbidden();
}

$svc = new DiagnosticService();
$snapshot = $svc->snapshot();

/*
 * View
 */

$page_name = "DalfredSetup";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'diagnostic', $langs->trans("DalfredSetup"), -1, 'fa-robot');

print load_fiche_titre('🔍 ' . $langs->trans("Diagnostic"), '', 'title_setup');

print '<p class="opacitymedium">' . dol_escape_htmltag($langs->trans('DiagPageIntro')) . '</p>';

// =============================================================================
// Section 1 — Stack détectée
// =============================================================================
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . dol_escape_htmltag($langs->trans('DiagSectionStack')) . '</td></tr>';

print '<tr class="oddeven"><td style="width: 35%;">' . dol_escape_htmltag($langs->trans('DiagRowPhp')) . '</td><td>';
print '<strong>' . dol_escape_htmltag($snapshot['php']['version']) . '</strong>';
print ' (' . dol_escape_htmltag($snapshot['php']['sapi']) . ')';
print ' &middot; <span class="opacitymedium">' . dol_escape_htmltag($langs->trans('DiagPhpMaxExec'))
    . ' = ' . (int) $snapshot['php']['max_execution_time'] . 's</span>';
if ($snapshot['php']['can_finish_request']) {
    print ' &middot; <span class="badge badge-status4">' . dol_escape_htmltag($langs->trans('DiagBadgeFinishOk')) . '</span>';
} else {
    print ' &middot; <span class="badge badge-warning">' . dol_escape_htmltag($langs->trans('DiagBadgeFinishKo')) . '</span>';
}
print '</td></tr>';

print '<tr class="oddeven"><td>' . dol_escape_htmltag($langs->trans('DiagRowWebserver')) . '</td><td>';
$wsName = $snapshot['webserver']['name'];
$wsRaw = $snapshot['webserver']['raw'];
if ($wsName !== 'unknown') {
    print '<strong>' . dol_escape_htmltag(ucfirst($wsName)) . '</strong>';
} else {
    print '<span class="badge badge-warning">' . dol_escape_htmltag($langs->trans('DiagBadgeUnknown')) . '</span>';
}
if ($wsRaw !== '') {
    print ' &middot; <span class="opacitymedium">' . dol_escape_htmltag($wsRaw) . '</span>';
}
print '</td></tr>';

print '<tr class="oddeven"><td>' . dol_escape_htmltag($langs->trans('DiagRowReverseProxy')) . '</td><td>';
if ($snapshot['reverse_proxy']['detected']) {
    print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans('DiagBadgeYes')) . '</span><br>';
    foreach ($snapshot['reverse_proxy']['hints'] as $hint) {
        print '<span class="opacitymedium">&middot; ' . dol_escape_htmltag($hint) . '</span><br>';
    }
    if (!empty($snapshot['reverse_proxy']['client_ip'])) {
        print '<span class="opacitymedium">&middot; ' . dol_escape_htmltag($langs->trans('DiagProxyRealIp')) . ': '
            . dol_escape_htmltag($snapshot['reverse_proxy']['client_ip']) . '</span><br>';
    }
} else {
    print '<span class="badge badge-warning">' . dol_escape_htmltag($langs->trans('DiagBadgeUndetected')) . '</span>';
    print ' <span class="opacitymedium">(' . dol_escape_htmltag($langs->trans('DiagProxyNoneHint')) . ')</span>';
}
print '</td></tr>';

print '<tr class="oddeven"><td>' . dol_escape_htmltag($langs->trans('DiagRowCdn')) . '</td><td>';
if ($snapshot['cdn']['detected']) {
    print '<span class="badge badge-status4">' . dol_escape_htmltag($snapshot['cdn']['name'] ?? '?') . '</span>';
} else {
    print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans('DiagCdnNoneHint')) . '</span>';
}
print '</td></tr>';

print '<tr class="oddeven"><td>' . dol_escape_htmltag($langs->trans('DiagRowPanel')) . '</td><td>';
if (!empty($snapshot['panel']) && $snapshot['panel']['detected']) {
    print '<span class="badge badge-status4">' . dol_escape_htmltag((string) $snapshot['panel']['name']) . '</span><br>';
    foreach ($snapshot['panel']['hints'] as $hint) {
        print '<span class="opacitymedium">&middot; ' . dol_escape_htmltag($hint) . '</span><br>';
    }
} else {
    print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans('DiagPanelNoneHint')) . '</span>';
}
print '</td></tr>';

print '</table><br>';

// =============================================================================
// Section 2 — Recommandations
// =============================================================================
$recoTimeout = (int) \Dalfred\Service\DiagnosticService::RECOMMENDED_TIMEOUT_S;
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'
    . dol_escape_htmltag($langs->trans('DiagSectionRecos', $recoTimeout))
    . '</td></tr>';
// DiagRecosNote contains <strong> tags by design; do not escape it.
print '<tr class="oddeven"><td colspan="2"><p class="opacitymedium" style="margin: 8px 0;">'
    . $langs->trans('DiagRecosNote')
    . '</p></td></tr>';

if (empty($snapshot['recommendations'])) {
    print '<tr class="oddeven"><td colspan="2"><em>'
        . dol_escape_htmltag($langs->trans('DiagRecosNone'))
        . '</em></td></tr>';
} else {
    foreach ($snapshot['recommendations'] as $reco) {
        print '<tr class="oddeven"><td style="width: 30%; vertical-align: top;">';
        print '<strong>' . dol_escape_htmltag($reco['title']) . '</strong><br>';
        print '<span class="opacitymedium" style="font-size: 0.9em;">' . dol_escape_htmltag($reco['scope']) . '</span>';
        // For the body block we want real newlines preserved inside the <pre>,
        // so we use htmlspecialchars() (newlines pass through as raw \n) rather
        // than dol_escape_htmltag() which by default escapes \n into the
        // literal two-char sequence "\n".
        print '</td><td><pre style="background:#f4f4f4; padding:10px; border-radius:4px; overflow-x:auto; white-space: pre-wrap;">'
            . htmlspecialchars($reco['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</pre></td></tr>';
    }
}

print '</table><br>';

// =============================================================================
// Section 3 — Test de timing
// =============================================================================
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . dol_escape_htmltag($langs->trans('DiagSectionTest')) . '</td></tr>';
print '<tr class="oddeven"><td colspan="2">';
print '<p>' . dol_escape_htmltag($langs->trans('DiagTestIntro')) . '</p>';

$secOpt = static function (int $n, ?string $suffix = null) use ($langs): string {
    $key = $suffix === 'sanity' ? 'DiagTestSanityCheck'
         : ($suffix === 'max' ? 'DiagTestMax' : 'DiagTestSecondsFmt');
    return '<option value="' . $n . '"' . ($n === 30 ? ' selected' : '') . '>'
        . dol_escape_htmltag($langs->trans($key, $n))
        . '</option>';
};

print '<p>'
    . '<label for="dalfred-diag-seconds">' . dol_escape_htmltag($langs->trans('DiagTestDuration')) . ' </label>'
    . '<select id="dalfred-diag-seconds" class="flat">'
    . $secOpt(5, 'sanity')
    . $secOpt(30)
    . $secOpt(60)
    . $secOpt(90)
    . $secOpt(120)
    . $secOpt(180)
    . $secOpt(240)
    . $secOpt(300)
    . $secOpt(600, 'max')
    . '</select> '
    . '<button id="dalfred-diag-run" class="button">' . dol_escape_htmltag($langs->trans('DiagTestRun')) . '</button>'
    . ' <span id="dalfred-diag-status" class="opacitymedium"></span>'
    . '</p>';

print '<div id="dalfred-diag-result" style="display:none; margin-top:10px;"></div>';
print '</td></tr>';
print '</table><br>';

// =============================================================================
// Section 4 — Rapport copiable
// =============================================================================
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . dol_escape_htmltag($langs->trans('DiagSectionReport')) . '</td></tr>';
print '<tr class="oddeven"><td colspan="2">';

$report = "## Stack Dalfred — diagnostic\n\n";
$report .= "PHP : " . $snapshot['php']['version'] . " (" . $snapshot['php']['sapi'] . ")\n";
$report .= "max_execution_time : " . $snapshot['php']['max_execution_time'] . "s\n";
$report .= "fastcgi_finish_request : " . ($snapshot['php']['can_finish_request'] ? 'oui' : 'non') . "\n";
$report .= "Serveur web : " . $snapshot['webserver']['name'] . " (" . $snapshot['webserver']['raw'] . ")\n";
$report .= "Reverse proxy : " . ($snapshot['reverse_proxy']['detected'] ? 'oui' : 'non') . "\n";
foreach ($snapshot['reverse_proxy']['hints'] as $hint) {
    $report .= "  - " . $hint . "\n";
}
if (!empty($snapshot['reverse_proxy']['client_ip'])) {
    $report .= "  - IP cliente : " . $snapshot['reverse_proxy']['client_ip'] . "\n";
}
$report .= "CDN : " . ($snapshot['cdn']['detected'] ? ($snapshot['cdn']['name'] ?? 'oui') : 'non') . "\n";
$panelDetected = !empty($snapshot['panel']) && $snapshot['panel']['detected'];
$report .= "Panel d'hébergement : " . ($panelDetected ? (string) $snapshot['panel']['name'] : 'non détecté') . "\n";
if ($panelDetected) {
    foreach ($snapshot['panel']['hints'] as $hint) {
        $report .= "  - " . $hint . "\n";
    }
}
$report .= "\n";
if (!empty($snapshot['recommendations'])) {
    $report .= "## Recommandations\n\n";
    foreach ($snapshot['recommendations'] as $reco) {
        $report .= "### " . $reco['title'] . "\n";
        $report .= "_" . $reco['scope'] . "_\n\n```\n" . $reco['body'] . "\n```\n\n";
    }
}

// Same reasoning as the <pre> blocks above: we need real newlines in the
// textarea content, so we go through htmlspecialchars() (newlines pass
// through) rather than dol_escape_htmltag() which would escape them into
// the literal two-char sequence "\n".
print '<textarea id="dalfred-diag-report" readonly style="width:100%; min-height:240px; font-family:monospace; font-size:12px;">'
    . htmlspecialchars($report, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</textarea>';
print '<p><button id="dalfred-diag-copy" class="button">' . dol_escape_htmltag($langs->trans('DiagReportCopy')) . '</button> '
    . '<span id="dalfred-diag-copy-status" class="opacitymedium"></span></p>';
print '</td></tr>';
print '</table>';

print dol_get_fiche_end();

?>
<script>
var DALFRED_DIAG_I18N = <?php echo json_encode([
    'running'        => $langs->transnoentities('DiagTestRunning'),
    'ok'             => $langs->transnoentities('DiagTestOk'),
    'ko'             => $langs->transnoentities('DiagTestKo'),
    'resultOk'       => $langs->transnoentities('DiagTestResultOk'),
    'resultKo'       => $langs->transnoentities('DiagTestResultKo'),
    'statusHttp'     => $langs->transnoentities('DiagTestStatusHttp'),
    'serverSide'     => $langs->transnoentities('DiagTestServerSide'),
    'hints'          => $langs->transnoentities('DiagTestHints'),
    'copied'         => $langs->transnoentities('DiagReportCopied'),
    'copyFail'       => $langs->transnoentities('DiagReportCopyFail'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    'use strict';

    var DALFRED_DIAG_AJAX = '<?php echo dol_escape_js(dol_buildpath('/dalfred/ajax/diagnostic.php', 1)); ?>';

    // Tiny printf-style helper so the i18n strings can carry their %d/%s placeholders.
    function fmt(s, args) {
        var i = 0;
        return s.replace(/%[ds]/g, function () { return args[i++]; });
    }

    // --- Section 3: timing test ---
    var runBtn = document.getElementById('dalfred-diag-run');
    var secondsSel = document.getElementById('dalfred-diag-seconds');
    var status = document.getElementById('dalfred-diag-status');
    var resultBox = document.getElementById('dalfred-diag-result');

    if (runBtn) {
        runBtn.addEventListener('click', function () {
            var seconds = parseInt(secondsSel.value, 10) || 5;
            runBtn.disabled = true;
            status.textContent = fmt(DALFRED_DIAG_I18N.running, [seconds]);
            resultBox.style.display = 'none';
            var start = performance.now();

            fetch(DALFRED_DIAG_AJAX + '?action=sleep&seconds=' + seconds, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (resp) {
                var elapsed = ((performance.now() - start) / 1000).toFixed(2);
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status + ' ' + resp.statusText + ' (' + elapsed + 's)');
                }
                return resp.json().then(function (data) {
                    return { data: data, elapsed: elapsed, status: resp.status };
                });
            })
            .then(function (r) {
                runBtn.disabled = false;
                status.textContent = DALFRED_DIAG_I18N.ok;
                resultBox.style.display = 'block';
                resultBox.innerHTML =
                    '<div style="background:#e8f5e9; padding:12px; border-radius:4px; border-left: 4px solid #4caf50;">'
                    + '<strong>' + fmt(DALFRED_DIAG_I18N.resultOk, [r.elapsed]) + '</strong><br>'
                    + DALFRED_DIAG_I18N.statusHttp + ' : ' + r.status + '<br>'
                    + fmt(DALFRED_DIAG_I18N.serverSide, [r.data.requested_seconds, r.data.elapsed_seconds])
                    + '</div>';
            })
            .catch(function (err) {
                runBtn.disabled = false;
                var elapsed = ((performance.now() - start) / 1000).toFixed(2);
                status.textContent = DALFRED_DIAG_I18N.ko;
                resultBox.style.display = 'block';
                resultBox.innerHTML =
                    '<div style="background:#ffebee; padding:12px; border-radius:4px; border-left: 4px solid #c62828;">'
                    + '<strong>' + fmt(DALFRED_DIAG_I18N.resultKo, [elapsed]) + '</strong><br>'
                    + (err && err.message ? err.message : String(err)) + '<br><br>'
                    + '<em>' + DALFRED_DIAG_I18N.hints + '</em>'
                    + '</div>';
            });
        });
    }

    // --- Section 4: copy report ---
    var copyBtn = document.getElementById('dalfred-diag-copy');
    var report = document.getElementById('dalfred-diag-report');
    var copyStatus = document.getElementById('dalfred-diag-copy-status');

    if (copyBtn && report) {
        copyBtn.addEventListener('click', function () {
            try {
                report.select();
                report.setSelectionRange(0, report.value.length);
                navigator.clipboard.writeText(report.value).then(
                    function () {
                        copyStatus.textContent = DALFRED_DIAG_I18N.copied;
                        setTimeout(function () { copyStatus.textContent = ''; }, 2500);
                    },
                    function () {
                        document.execCommand('copy');
                        copyStatus.textContent = DALFRED_DIAG_I18N.copied;
                        setTimeout(function () { copyStatus.textContent = ''; }, 2500);
                    }
                );
            } catch (e) {
                copyStatus.textContent = DALFRED_DIAG_I18N.copyFail;
            }
        });
    }
})();
</script>
<?php

llxFooter();
$db->close();
