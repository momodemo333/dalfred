<?php
/**
 * Dalfred External MCP Access Page
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

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check - only admins can access setup
if (!$user->admin) {
    accessforbidden();
}

// Current toggle state. Deliberately NOT registered in the module descriptor's
// $this->const array (see modDalfred.class.php) so disabling/enabling the
// module never resets a customer's choice — read via getDolGlobalInt, written
// via ajax_constantonoff.
$mcpExternalEnabled = getDolGlobalInt('DALFRED_MCP_EXTERNAL_ENABLED') === 1;

// Gather connection info only when the feature is actually enabled — no
// point resolving/exposing endpoint details for a surface that returns 403.
$mcpEndpoint = '';
$oauthMetadata = '';
$autoload = null;
$apiEnabled = false;
if ($mcpExternalEnabled) {
    dol_include_once('/dalfred/lib/dalfred_mcp_bootstrap.php');
    dalfred_mcp_oauth_autoload();

    $mcpEndpoint = \DolibarrMcpOAuth\Support\UrlHelper::publicUrl('/dalfred/mcp.php');
    $oauthMetadata = \DolibarrMcpOAuth\Support\UrlHelper::publicUrl('/dalfred/oauth.php').'/.well-known/oauth-authorization-server';
    $autoload = \DolibarrMcpOAuth\Support\PackageLocator::findAutoloader(array(
        dol_buildpath('/dalfred/dolibarr-mcp-server/vendor/autoload.php', 0),
    ));
    $apiEnabled = isModEnabled('api');
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

print dol_get_fiche_head($head, 'mcpexternal', $langs->trans("DalfredSetup"), -1, 'fa-robot');

print load_fiche_titre($langs->trans("DalfredMcpExternalTitle"), '', 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('DalfredMcpExternalIntro').'</span><br><br>';

// Lightweight styling for the code blocks (same pattern as emMCP's setup page)
print '<style>
.dalfred-mcp-code,.mcp-code{background:var(--colorbacklinepair2,#f6f6f6);border:1px solid var(--bordercolor,#e0e0e0);border-radius:5px;padding:10px 12px;margin:6px 0 14px;overflow-x:auto;white-space:pre;font-size:0.85em;line-height:1.45;}
.dalfred-mcp-step{margin:0 0 22px;}
.dalfred-mcp-step h3{margin:0 0 4px;font-size:1.05em;}
</style>';

// Toggle row
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("DalfredMcpEnableExternal").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td><label for="DALFRED_MCP_EXTERNAL_ENABLED">'.$langs->trans("DalfredMcpEnableExternal").'</label></td>';
print '<td class="right">'.ajax_constantonoff('DALFRED_MCP_EXTERNAL_ENABLED', array(), $conf->entity).'</td>';
print '</tr>';
print '</table>';

print '<br>';

if (!$mcpExternalEnabled) {
    // Disabled: only the toggle + intro (already printed above) + the note.
    print '<div class="warning">'.$langs->trans('DalfredMcpExternalDisabledNote').'</div>';

    print dol_get_fiche_end();

    llxFooter();
    $db->close();
    exit;
}

// Enabled: full connection info, mirroring emMCP's admin/setup.php.

// Status board
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('DalfredMcpEndpoint').'</td>';
print '<td><strong>'.showValueWithClipboardCPButton($mcpEndpoint, 1, $mcpEndpoint).'</strong></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredMcpTransport').'</td>';
print '<td>Streamable HTTP (MCP) — POST JSON-RPC 2.0</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredMcpAuthMethods').'</td>';
print '<td>'.$langs->trans('DalfredMcpAuthMethodsValue').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredMcpOAuthMetadata').'</td>';
print '<td>'.showValueWithClipboardCPButton($oauthMetadata, 1, $oauthMetadata).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredMcpServerPackage').'</td>';
print '<td>'.($autoload ? img_picto('', 'tick').' '.$langs->trans('DalfredMcpServerPackageOk') : img_picto('', 'error').' '.$langs->trans('DalfredMcpServerPackageMissing')).'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DalfredMcpRestApiModule').'</td>';
print '<td>'.($apiEnabled ? img_picto('', 'tick').' '.$langs->trans('Enabled') : img_picto('', 'error').' '.$langs->trans('DalfredMcpRestApiMissing')).'</td></tr>';

print '</table>';
print '</div>';

print '<br>';

// Client configuration examples
print load_fiche_titre($langs->trans('DalfredMcpClientExamples'), '', '');

// --- Option 1: claude.ai custom connector (OAuth, recommended) ---
print '<div class="dalfred-mcp-step">';
print '<h3>'.img_picto('', 'fa-plug', 'class="pictofixedwidth"').$langs->trans('DalfredMcpClientClaudeAi').' <span class="badge badge-status4 badge-status">'.$langs->trans('DalfredMcpRecommended').'</span></h3>';
print '<div class="opacitymedium">'.$langs->trans('DalfredMcpConnectorOAuthHelp').'</div>';
print \DolibarrMcpOAuth\Support\UrlHelper::codeBlock($mcpEndpoint, $langs->trans('DalfredMcpConnectorUrlLabel'));
print '</div>';

// --- Option 2: Claude Code (API key) ---
$claudeCodeCmd = 'claude mcp add dolibarr --transport http '.$mcpEndpoint.' --header "Authorization: Bearer '.$langs->trans('DalfredMcpYourApiKey').'"';
print '<div class="dalfred-mcp-step">';
print '<h3>'.img_picto('', 'fa-terminal', 'class="pictofixedwidth"').$langs->trans('DalfredMcpClientClaudeCode').'</h3>';
print '<div class="opacitymedium">'.$langs->trans('DalfredMcpAuthNote').'</div>';
print \DolibarrMcpOAuth\Support\UrlHelper::codeBlock($claudeCodeCmd, $langs->trans('DalfredMcpCommandLabel'));
print '</div>';

// --- Option 3: generic MCP client (mcp.json) ---
$mcpJson = json_encode(array(
    'mcpServers' => array(
        'dolibarr' => array(
            'type' => 'http',
            'url' => $mcpEndpoint,
            'headers' => array('Authorization' => 'Bearer '.$langs->trans('DalfredMcpYourApiKey')),
        ),
    ),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
print '<div class="dalfred-mcp-step">';
print '<h3>'.img_picto('', 'fa-file-code', 'class="pictofixedwidth"').$langs->trans('DalfredMcpClientJson').'</h3>';
print \DolibarrMcpOAuth\Support\UrlHelper::codeBlock($mcpJson, 'mcp.json');
print '</div>';

print '<div class="info">'.$langs->trans('DalfredMcpApiKeyHelp').'</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
