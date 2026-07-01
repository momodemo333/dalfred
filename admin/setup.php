<?php
/**
 * Dalfred General Setup Page
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
use Dalfred\Service\SystemPromptStorage;

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check - only admins can access setup
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if ($action == 'save') {
    $error = 0;
    $errorDetails = array();

    // Chat settings
    $context_window = GETPOST('context_window', 'int');

    // Debug
    $debug_mode = GETPOST('debug_mode', 'int');
    $log_conversations = GETPOST('log_conversations', 'int');

    // System prompt — 'nohtml' (no HTML expected, just text/Markdown) instead of
    // 'restricthtml' which would strip Markdown-looking < and > chars.
    // 3rd arg = 2 keeps newlines.
    $system_prompt = str_replace(["\r\n", "\r"], "\n", GETPOST('system_prompt', 'nohtml', 2));

    // Helper: dolibarr_set_const returns -1 on failure (e.g. utf8mb3 rejecting a
    // 4-byte UTF-8 char). The legacy code ignored the return value and showed
    // "Saved" even when nothing landed in the DB. We now check every call.
    $setConst = function ($name, $value, $type = 'chaine') use ($db, $conf, &$error, &$errorDetails) {
        $res = dolibarr_set_const($db, $name, $value, $type, 0, '', $conf->entity);
        if ($res <= 0) {
            $error++;
            $errorDetails[] = $name . ': ' . $db->lasterror();
        }
        return $res;
    };

    // Save chat settings
    $setConst('DALFRED_CONTEXT_WINDOW', $context_window);

    // Save debug settings
    $setConst('DALFRED_DEBUG', $debug_mode);
    $setConst('DALFRED_LOG_CONVERSATIONS', $log_conversations);

    // Save system prompt — to a file under documents/dalfred/, NOT to llx_const.
    // llx_const.value is utf8mb3 on many existing installs and would silently
    // reject any 4-byte UTF-8 char (emojis like 📌, 🚫, ✅). Filesystem storage
    // also lifts the 64KB TEXT cap and lets sysadmins edit the prompt directly.
    try {
        $promptStorage = new SystemPromptStorage(null, (int) $conf->entity);
        $promptStorage->write($system_prompt);
        // Keep the legacy constant in sync as a marker (empty if cleared) so
        // downgrades and external tools that still read it stay consistent.
        // The 'chaine' type keeps it short — we only store a short marker, not
        // the full prompt — so utf8mb3 is no longer a problem.
        $marker = $system_prompt === '' ? '' : 'file:' . $promptStorage->getFilePath();
        dolibarr_set_const($db, 'DALFRED_SYSTEM_PROMPT', $marker, 'chaine', 0, '', $conf->entity);
    } catch (\Throwable $e) {
        $error++;
        $errorDetails[] = 'system_prompt: ' . $e->getMessage();
        dol_syslog('[Dalfred] Failed to write system prompt to file: ' . $e->getMessage(), LOG_ERR);
    }

    // Save attachments setting
    $attach_enabled = GETPOST('attach_enabled', 'int') ? 1 : 0;
    $setConst('DALFRED_ATTACH_ENABLED', $attach_enabled);

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("ErrorSavingConfig"), $errorDetails, 'errors');
    }
}

/*
 * View
 */

// Load current configuration
$context_window = getDolGlobalInt('DALFRED_CONTEXT_WINDOW', 150000);
$debug_mode = getDolGlobalInt('DALFRED_DEBUG', 0);
$log_conversations = getDolGlobalInt('DALFRED_LOG_CONVERSATIONS', 1);

// System prompt: file-first, with one-shot fallback to the legacy constant for
// installs that have not yet run the v2.13.8 migration (covers users browsing
// setup before the printCommonFooter hook had a chance to fire).
$promptStorage = new SystemPromptStorage(null, (int) $conf->entity);
if ($promptStorage->exists()) {
    $system_prompt = $promptStorage->read();
} else {
    $legacy = getDolGlobalString('DALFRED_SYSTEM_PROMPT', '');
    // The new save format stores 'file:/path' as the marker; ignore that.
    $system_prompt = (strncmp($legacy, 'file:', 5) === 0) ? '' : $legacy;
}

$page_name = "DalfredSetup";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader with tabs
$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'general', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Configuration form
print load_fiche_titre($langs->trans("GeneralConfiguration"), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

print '<br>';

// Chat Configuration
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("ChatConfiguration").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '</tr>';

// Context window
print '<tr class="oddeven">';
print '<td><label for="context_window">'.$langs->trans("ContextWindow").'</label></td>';
print '<td><input type="number" name="context_window" id="context_window" value="'.$context_window.'" class="flat width100" min="1000" max="200000"> tokens</td>';
print '<td class="opacitymedium">'.$langs->trans("ContextWindowDesc").'</td>';
print '</tr>';

print '</table>';

print '<br>';

// System Prompt Configuration
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("SystemPromptConfiguration").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td colspan="2">';
print '<p class="opacitymedium">'.$langs->trans("SystemPromptDesc").'</p>';
// 3rd arg ($keepn) = 1 → preserve \n in textarea content (else dol_escape_htmltag
// strips/encodes them and the multi-line value renders as a single mangled line).
print '<textarea name="system_prompt" id="system_prompt" class="flat" style="width:100%; min-height:150px;" placeholder="'.$langs->trans("SystemPromptPlaceholder").'">'.dol_escape_htmltag($system_prompt, 0, 1).'</textarea>';
print '</td>';
print '</tr>';

print '</table>';

// Preview full prompt button
print '<div style="margin-top: 8px; margin-bottom: 8px;">';
print '<a href="#" id="toggle-prompt-preview" class="button buttongen" onclick="togglePromptPreview(); return false;">';
print Icon::render('eye', ['class' => 'paddingright']).$langs->trans("ShowFullPromptPreview");
print '</a>';
print '</div>';

// Full prompt preview (hidden by default)
$defaultPrompt = \Dalfred\Agent\DalfredAgent::getDefaultSystemPrompt();
$fullPreview = $defaultPrompt;
if (!empty($system_prompt)) {
    $fullPreview .= "\n\n## Instructions spécifiques\n" . $system_prompt;
}
$fullPreview .= "\n\n## Utilisateur actuel\n[...contexte utilisateur dynamique...]\n\n## Contexte de page\n[...contexte de page dynamique...]\n\n## Navigation Dolibarr\n[...URLs dynamiques...]\n\n## Mémoire / Base de connaissances\n[...instructions mémoire...]";

print '<div id="prompt-preview-container" style="display:none; margin-top: 10px;">';
print '<div class="info">'.$langs->trans("FullPromptPreviewInfo").'</div>';
print '<pre id="prompt-preview-content" style="background: #f8f8f8; border: 1px solid #ddd; padding: 15px; max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; font-size: 12px; line-height: 1.5;">'.htmlspecialchars($fullPreview, ENT_QUOTES, 'UTF-8').'</pre>';
print '</div>';

print '<script>
function togglePromptPreview() {
    var container = document.getElementById("prompt-preview-container");
    var btn = document.getElementById("toggle-prompt-preview");
    if (container.style.display === "none") {
        container.style.display = "block";
        btn.innerHTML = \''.dol_escape_js(Icon::render('eye-off', ['class' => 'paddingright'])).dol_escape_js($langs->trans("HideFullPromptPreview")).'\';
    } else {
        container.style.display = "none";
        btn.innerHTML = \''.dol_escape_js(Icon::render('eye', ['class' => 'paddingright'])).dol_escape_js($langs->trans("ShowFullPromptPreview")).'\';
    }
}
</script>';

print '<br>';

// Attachments Configuration
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("AttachmentsEnabled").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td><label for="attach_enabled">'.$langs->trans("AttachmentsEnabled").'</label></td>';
print '<td><input type="checkbox" name="attach_enabled" id="attach_enabled" value="1"'.(getDolGlobalInt('DALFRED_ATTACH_ENABLED', 1) ? ' checked' : '').'></td>';
print '<td class="opacitymedium">'.$langs->trans("AttachmentsEnabledDesc").'</td>';
print '</tr>';
print '</table>';

print '<br>';

// Debug Configuration
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("DebugConfiguration").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '</tr>';

// Debug mode
print '<tr class="oddeven">';
print '<td><label for="debug_mode">'.$langs->trans("DebugMode").'</label></td>';
print '<td>';
print '<input type="checkbox" name="debug_mode" id="debug_mode" value="1"'.($debug_mode ? ' checked' : '').'>';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans("DebugModeDesc").'</td>';
print '</tr>';

// Log conversations
print '<tr class="oddeven">';
print '<td><label for="log_conversations">'.$langs->trans("LogConversations").'</label></td>';
print '<td>';
print '<input type="checkbox" name="log_conversations" id="log_conversations" value="1"'.($log_conversations ? ' checked' : '').'>';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans("LogConversationsDesc").'</td>';
print '</tr>';

print '</table>';

print '<br>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
