<?php
/**
 * Dalfred module library
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.0.0
 * @license    GPL-3.0+
 */

/**
 * Prepare admin pages header tabs
 *
 * @return array Array of tabs
 */
function dalfred_admin_prepare_head()
{
    global $langs, $conf, $db;

    $langs->load("dalfred@dalfred");

    $h = 0;
    $head = array();

    // General setup tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/setup.php', 1);
    $head[$h][1] = $langs->trans("GeneralSetup");
    $head[$h][2] = 'general';
    $h++;

    // Branding tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/branding.php', 1);
    $head[$h][1] = $langs->trans("DalfredBrandingTab");
    $head[$h][2] = 'branding';
    $h++;

    // AI Configuration tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/ai_setup.php', 1);
    $head[$h][1] = $langs->trans("AIConfiguration");
    $head[$h][2] = 'ai';
    $h++;

    // Toolkit permissions tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/toolkit_permissions.php', 1);
    $head[$h][1] = $langs->trans("ToolkitPermissions");
    $head[$h][2] = 'toolkits';
    $h++;

    // Activity Log tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/activity_log.php', 1);
    $head[$h][1] = $langs->trans("ActivityLog");
    $head[$h][2] = 'activitylog';
    $h++;

    // Knowledge/Memory tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/knowledge.php', 1);
    $head[$h][1] = $langs->trans("KnowledgeMemory");
    $head[$h][2] = 'knowledge';
    $h++;

    // Token usage tab — LLM context observability
    $head[$h][0] = dol_buildpath('/dalfred/admin/usage_dashboard.php', 1);
    $head[$h][1] = $langs->trans("TokenUsageTab");
    $head[$h][2] = 'usage';
    $h++;

    // Maintenance tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/maintenance.php', 1);
    $head[$h][1] = $langs->trans("Maintenance");
    $head[$h][2] = 'maintenance';
    $h++;

    // Diagnostic tab — web stack & timeouts (helps tracking 504s)
    $head[$h][0] = dol_buildpath('/dalfred/admin/diagnostic.php', 1);
    $head[$h][1] = $langs->trans("Diagnostic");
    $head[$h][2] = 'diagnostic';
    $h++;

    // External MCP access tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/mcp_external.php', 1);
    $head[$h][1] = $langs->trans("DalfredMcpExternalTab");
    $head[$h][2] = 'mcpexternal';
    $h++;

    // About tab
    $head[$h][0] = dol_buildpath('/dalfred/admin/about.php', 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    return $head;
}

/**
 * Get module version
 *
 * @return string Version string
 */
function dalfred_get_version()
{
    global $db;

    include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
    include_once dol_buildpath('/dalfred/core/modules/modDalfred.class.php', 0);

    $mod = new modDalfred($db);
    return $mod->version;
}
