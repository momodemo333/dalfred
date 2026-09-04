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

    // MCP SQL access tab. Named "MCP SQL access", never just "SQL access":
    // Dalfred also has SQL tools inside the chat, and an admin must be able to
    // tell at a glance that this page governs the MCP surface only.
    $head[$h][0] = dol_buildpath('/dalfred/admin/sql_access.php', 1);
    $head[$h][1] = $langs->trans("DalfredSqlAccessTab");
    $head[$h][2] = 'sqlaccess';
    $h++;

    // MCP activity: the call log, the rate limit and the alert live together
    // because an administrator reading the log is exactly the person who then
    // wants to change the limit.
    $head[$h][0] = dol_buildpath('/dalfred/admin/mcp_activity.php', 1);
    $head[$h][1] = $langs->trans("DalfredMcpActivityTab");
    $head[$h][2] = 'mcpactivity';
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

/**
 * Build the translation table handed to the chat JavaScript.
 *
 * Shared by the widget (injected by the hook on every Dolibarr page) and by the
 * full-screen chat page, which used to inject nothing at all — every JS string
 * there fell back to its hardcoded French default whatever the user's language.
 *
 * @param  Translate $langs     already loaded with dalfred@dalfred
 * @param  string    $agentName the configured brand name
 * @return array<string,string>
 */
function dalfred_js_translations($langs, string $agentName): array
{
    return array(
        'widgetTitle' => $langs->transnoentities('WidgetTitle', $agentName),
        'online' => $langs->transnoentities('WidgetOnline'),
        'memory' => $langs->transnoentities('WidgetMemory'),
        'fullscreen' => $langs->transnoentities('WidgetFullscreen'),
        'reduce' => $langs->transnoentities('WidgetReduce'),
        'newConversation' => $langs->transnoentities('WidgetNewConversation'),
        'close' => $langs->transnoentities('WidgetClose'),
        'placeholder' => $langs->transnoentities('WidgetPlaceholder'),
        'send' => $langs->transnoentities('WidgetSend'),
        'welcomeTitle' => $langs->transnoentities('WidgetWelcomeTitle', $agentName),
        'welcomeIntro' => $langs->transnoentities('WidgetWelcomeIntro'),
        'helpSearch' => $langs->transnoentities('WidgetHelpSearch'),
        'helpAnalyze' => $langs->transnoentities('WidgetHelpAnalyze'),
        'helpCreate' => $langs->transnoentities('WidgetHelpCreate'),
        'helpQuestions' => $langs->transnoentities('WidgetHelpQuestions'),
        'tryExample' => $langs->transnoentities('WidgetTryExample'),
        'clearConfirm' => $langs->transnoentities('WidgetClearConfirm'),
        'errorCommunication' => $langs->transnoentities('ErrorGeneric'),
        'errorConnection' => $langs->transnoentities('ErrorNetwork'),
        'errorTimeout' => $langs->transnoentities('ChatErrorTimeout'),
        'errorAlreadyProcessing' => $langs->transnoentities('ChatErrorAlreadyProcessing'),
        'attachDropZone' => $langs->transnoentities('AttachDropZone'),
        'attachExpired' => $langs->transnoentities('AttachExpired'),
        'attachUnsupported' => $langs->transnoentities('AttachUnsupported'),
        'copied' => $langs->transnoentities('Copied'),
        'copyFailed' => $langs->transnoentities('CopyFailed'),
        'commandShared' => $langs->transnoentities('ChatCommandShared'),
    );
}
