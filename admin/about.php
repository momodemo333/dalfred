<?php
/**
 * Dalfred About Page
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.0.0
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
require_once '../lib/dalfred.lib.php';
require_once __DIR__.'/../vendor/autoload.php';

use Dalfred\Icon;

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check - only admins can access setup
if (!$user->admin) {
    accessforbidden();
}

/*
 * Gather technical info
 */

// Provider & model (from ConfigService if available, otherwise from getDolGlobalString)
$configService = null;
$configServiceFile = __DIR__.'/../src/Service/ConfigService.php';
if (file_exists($configServiceFile)) {
    require_once $configServiceFile;
    if (class_exists('Dalfred\\Service\\ConfigService')) {
        $configService = new \Dalfred\Service\ConfigService($db);
    }
}

if ($configService) {
    $ai_provider = $configService->getProvider();
    $ai_model = $configService->getModel();
    $providers_list = \Dalfred\Service\ConfigService::getSupportedProviders();
    $ai_provider_label = $providers_list[$ai_provider] ?? $ai_provider;
} else {
    $ai_provider = getDolGlobalString('DALFRED_AI_PROVIDER', 'anthropic');
    $ai_model = getDolGlobalString('DALFRED_MODEL', 'claude-sonnet-4-20250514');
    $ai_provider_label = ucfirst($ai_provider);
}

// Async mode detection
$async_supported = false;
$async_label = 'Non';
$asyncServiceFile = __DIR__.'/../src/Service/AsyncResponseService.php';
if (file_exists($asyncServiceFile)) {
    require_once $asyncServiceFile;
    if (class_exists('Dalfred\\Service\\AsyncResponseService')) {
        $asyncService = new \Dalfred\Service\AsyncResponseService($db, (int) $user->id);
        $async_supported = $asyncService->canRunInBackground();
        $async_label = $async_supported ? 'Oui' : 'Non';
    }
}

// API REST module active
$api_rest_active = isModEnabled('api') ? 'Oui' : 'Non';

// Count entities that have any provider API key configured
$nb_api_users = 0;
$sql_count = "SELECT COUNT(DISTINCT entity) as nb FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'DALFRED_%_API_KEY' AND value != '' AND value IS NOT NULL";
$resql = $db->query($sql_count);
if ($resql && ($obj = $db->fetch_object($resql))) {
    $nb_api_users = (int)$obj->nb;
}

// Web server
$web_server = $_SERVER['SERVER_SOFTWARE'] ?? 'Inconnu';

// Module path
$module_path = dol_buildpath('/dalfred', 0);

// PHP extensions
$required_extensions = ['curl', 'json', 'mbstring', 'openssl', 'xml'];
$extensions_status = [];
foreach ($required_extensions as $ext) {
    $extensions_status[$ext] = extension_loaded($ext);
}

// Module version
$dalfred_version = dalfred_get_version();

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

print dol_get_fiche_head($head, 'about', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Title
print load_fiche_titre($langs->trans("About"), '', 'title_setup');

// Module information
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("ModuleInformation").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("ModuleName").'</td>';
print '<td><strong>Dalfred</strong> - AI Assistant for Dolibarr</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("Version").'</td>';
print '<td>'.$dalfred_version.'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("Author").'</td>';
print '<td>E-dem</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("License").'</td>';
print '<td>GPL v3+</td>';
print '</tr>';

print '</table>';

print '<br>';

// Description
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Description").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>';
print '<p>'.$langs->trans("DalfredDescription").'</p>';
print '<p><strong>'.$langs->trans("Features").':</strong></p>';
print '<ul>';
print '<li>'.$langs->trans("FeatureNaturalLanguage").'</li>';
print '<li>'.$langs->trans("FeatureMCP").'</li>';
print '<li>'.$langs->trans("FeatureToolkits").'</li>';
print '<li>'.$langs->trans("FeatureConversationHistory").'</li>';
print '<li>'.$langs->trans("FeaturePermissions").'</li>';
print '</ul>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';

// Technical information
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("TechnicalInformation").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Fournisseur IA</td>';
print '<td>'.dol_escape_htmltag($ai_provider_label).'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Modèle IA</td>';
print '<td><code>'.dol_escape_htmltag($ai_model).'</code></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("MCPServer").'</td>';
print '<td>Dolibarr MCP Server (inclus)</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("PHPVersion").'</td>';
print '<td>'.PHP_VERSION.'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("DolibarrVersion").'</td>';
print '<td>'.DOL_VERSION.'</td>';
print '</tr>';

print '</table>';

print '<br>';

// Support
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Support").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>';
print '<p>'.$langs->trans("SupportInfo").'</p>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';

// -------------------------------------------------------
// Diagnostic / Technical support info (collapsible)
// -------------------------------------------------------

// Build extensions string
$ext_parts = [];
foreach ($extensions_status as $ext => $loaded) {
    $ext_parts[] = $ext.': '.($loaded ? 'OK' : 'MANQUANT');
}
$extensions_str = implode(', ', $ext_parts);

// Build support text for clipboard
$support_text = "--- Dalfred - Infos techniques ---\n";
$support_text .= "Module Dalfred : ".$dalfred_version."\n";
$support_text .= "Dolibarr : ".DOL_VERSION."\n";
$support_text .= "PHP : ".PHP_VERSION."\n";
$support_text .= "SAPI PHP : ".php_sapi_name()."\n";
$support_text .= "OS : ".PHP_OS_FAMILY."\n";
$support_text .= "Serveur web : ".$web_server."\n";
$support_text .= "Fournisseur IA : ".$ai_provider_label." (".$ai_provider.")\n";
$support_text .= "Modèle IA : ".$ai_model."\n";
$support_text .= "Mode async : ".$async_label."\n";
$support_text .= "Module API REST : ".$api_rest_active."\n";
$support_text .= "Entités avec clé API : ".$nb_api_users."\n";
$support_text .= "URL de base : ".DOL_URL_ROOT."\n";
$support_text .= "Chemin module : ".$module_path."\n";
$support_text .= "Extensions PHP : ".$extensions_str."\n";
$support_text .= "--- Fin ---";

print '<div class="fichecenter">';

// Collapsible toggle button
print '<a class="btnTitle" href="#" onclick="jQuery(\'#dalfred_diag_block\').toggle(); return false;">';
print Icon::render('wrench', ['class' => 'paddingright']);
print 'Informations techniques pour le support';
print '</a>';

print '<div id="dalfred_diag_block" style="display: none; margin-top: 10px;">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">Diagnostic</td>';
print '</tr>';

print '<tr class="oddeven"><td class="titlefield">Module Dalfred</td><td>'.$dalfred_version.'</td></tr>';
print '<tr class="oddeven"><td>Dolibarr</td><td>'.DOL_VERSION.'</td></tr>';
print '<tr class="oddeven"><td>PHP</td><td>'.PHP_VERSION.'</td></tr>';
print '<tr class="oddeven"><td>SAPI PHP</td><td>'.php_sapi_name().'</td></tr>';
print '<tr class="oddeven"><td>OS</td><td>'.PHP_OS_FAMILY.'</td></tr>';
print '<tr class="oddeven"><td>Serveur web</td><td>'.dol_escape_htmltag($web_server).'</td></tr>';
print '<tr class="oddeven"><td>Fournisseur IA</td><td>'.dol_escape_htmltag($ai_provider_label).' (<code>'.dol_escape_htmltag($ai_provider).'</code>)</td></tr>';
print '<tr class="oddeven"><td>Modèle IA</td><td><code>'.dol_escape_htmltag($ai_model).'</code></td></tr>';
print '<tr class="oddeven"><td>Mode async</td><td>'.$async_label.'</td></tr>';
print '<tr class="oddeven"><td>Module API REST</td><td>'.$api_rest_active.'</td></tr>';
print '<tr class="oddeven"><td>Entités avec clé API</td><td>'.$nb_api_users.'</td></tr>';
print '<tr class="oddeven"><td>URL de base Dolibarr</td><td><code>'.dol_escape_htmltag(DOL_URL_ROOT).'</code></td></tr>';
print '<tr class="oddeven"><td>Chemin du module</td><td><code>'.dol_escape_htmltag($module_path).'</code></td></tr>';

// Extensions PHP
print '<tr class="oddeven"><td>Extensions PHP</td><td>';
foreach ($extensions_status as $ext => $loaded) {
    if ($loaded) {
        print '<span class="badge badge-status4">'.$ext.'</span> ';
    } else {
        print '<span class="badge badge-status8">'.$ext.' (manquant)</span> ';
    }
}
print '</td></tr>';

print '</table>';

// Copy button
print '<div style="margin-top: 10px; margin-bottom: 10px;">';
print '<button type="button" class="butAction" onclick="dalfredCopyDiag()">';
print Icon::render('copy', ['class' => 'paddingright']).'Copier les infos pour le support';
print '</button>';
print '<span id="dalfred_copy_ok" style="display:none; margin-left: 10px; color: #28a745;">Copié !</span>';
print '</div>';

print '</div>'; // #dalfred_diag_block
print '</div>'; // .fichecenter

// JS for copy
print '<script type="text/javascript">
function dalfredCopyDiag() {
    var text = '.json_encode($support_text).';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            var el = document.getElementById("dalfred_copy_ok");
            el.style.display = "inline";
            setTimeout(function() { el.style.display = "none"; }, 3000);
        }).catch(function() {
            dalfredCopyFallback(text);
        });
    } else {
        dalfredCopyFallback(text);
    }
}
function dalfredCopyFallback(text) {
    var ta = document.createElement("textarea");
    ta.value = text;
    ta.style.position = "fixed";
    ta.style.left = "-9999px";
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand("copy"); } catch(e) {}
    document.body.removeChild(ta);
    var el = document.getElementById("dalfred_copy_ok");
    el.style.display = "inline";
    setTimeout(function() { el.style.display = "none"; }, 3000);
}
</script>';

print dol_get_fiche_end();

llxFooter();
$db->close();
