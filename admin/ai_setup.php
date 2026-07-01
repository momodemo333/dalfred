<?php
/**
 * Dalfred AI Configuration Page - Multi-provider support
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.4.0
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

// Autoload Dalfred classes
require_once dirname(__DIR__).'/vendor/autoload.php';
use Dalfred\Service\ConfigService;

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check - only admins can access setup
if (!$user->admin) {
    accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');

// Provider definitions
$supportedProviders = ConfigService::getSupportedProviders();

/*
 * Actions
 */

if ($action == 'save') {
    $error = 0;

    $ai_provider = GETPOST('ai_provider', 'alpha');
    $ai_model = GETPOST('ai_model', 'alphanohtml');
    $custom_model = GETPOST('custom_model', 'alphanohtml');
    $max_tokens = GETPOST('max_tokens', 'int');

    // Use custom model if "Other" was selected or custom field is filled
    if ($ai_model === '__other__' || !empty($custom_model)) {
        $ai_model = $custom_model;
    }

    // Validate provider
    if (!isset($supportedProviders[$ai_provider])) {
        $error++;
        setEventMessages('Invalid AI provider', null, 'errors');
    }

    // Validate credentials based on provider
    if (!$error) {
        if ($ai_provider === 'ollama') {
            $ollama_url = GETPOST('ollama_url', 'alphanohtml');
            if (empty($ollama_url)) {
                $error++;
                setEventMessages($langs->trans("OllamaURLRequired"), null, 'errors');
            }
        } else {
            $api_key = GETPOST('api_key', 'alpha');
            // If key is empty, check if one already exists for this provider
            if (empty($api_key)) {
                $tmpConfig = new ConfigService($db, $conf->entity);
                $existingKey = $tmpConfig->getProviderApiKey($ai_provider);
                if (empty($existingKey)) {
                    $error++;
                    setEventMessages($langs->trans("APIKeyRequired"), null, 'errors');
                }
            }
        }
    }

    if (!$error) {
        // Save provider and model
        dolibarr_set_const($db, 'DALFRED_AI_PROVIDER', $ai_provider, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'DALFRED_MODEL', $ai_model, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'DALFRED_MAX_TOKENS', $max_tokens, 'chaine', 0, '', $conf->entity);

        // Cloud HTTP timeout (Anthropic, OpenAI, Mistral, Gemini). NeuronAI's
        // default is 60 s, which is too short on large contexts + tool catalogue
        // and causes cURL error 28 incidents in production. Clamp into a sane
        // range so a typo can't lock the agent forever or below 30 s.
        $cloudHttpTimeout = max(30, min(3600, (int) GETPOST('cloud_http_timeout', 'int')));
        dolibarr_set_const($db, 'DALFRED_HTTP_TIMEOUT', $cloudHttpTimeout, 'chaine', 0, '', $conf->entity);

        // Maximum tool invocations per turn (DALFRED_TOOL_MAX_RUNS). NeuronAI
        // default is 10, too low for legitimate analytical workloads — two
        // prod incidents in 2.22.2 hit the cap on ~11 mysql_select_query
        // calls in a single turn. Clamp [5, 100].
        $toolMaxRuns = max(5, min(100, (int) GETPOST('tool_max_runs', 'int')));
        dolibarr_set_const($db, 'DALFRED_TOOL_MAX_RUNS', $toolMaxRuns, 'chaine', 0, '', $conf->entity);

        // Tool payload truncation threshold (DALFRED_TOOL_PAYLOAD_MAX_TOKENS).
        // 0 = disabled, max 20000. Step 500 matches the admin UI.
        $toolPayloadMaxTokens = max(0, min(20000, (int) GETPOST('DALFRED_TOOL_PAYLOAD_MAX_TOKENS', 'int')));
        dolibarr_set_const($db, 'DALFRED_TOOL_PAYLOAD_MAX_TOKENS', $toolPayloadMaxTokens, 'int', 0, '', $conf->entity);

        // Save provider-specific credentials (only if a new key was provided)
        if ($ai_provider === 'ollama') {
            dolibarr_set_const($db, 'DALFRED_OLLAMA_URL', $ollama_url, 'chaine', 0, '', $conf->entity);

            // Ollama HTTP read timeout (NeuronAI's default is 60s, which is too
            // short for self-hosted Ollama on CPU). Clamp into a safe range so
            // an admin typo can't lock the agent forever or below 30s.
            $ollamaTimeout = max(30, min(3600, (int) GETPOST('ollama_timeout', 'int')));
            dolibarr_set_const($db, 'DALFRED_OLLAMA_TIMEOUT', $ollamaTimeout, 'chaine', 0, '', $conf->entity);

            // Ollama context window (num_ctx). 0 = let Ollama use its built-in
            // default (typically 2048-4096), which is too small for the Dalfred
            // tool catalogue. 16384 is a safe default that fits 8-12 GB VRAM
            // for a 7B model.
            $ollamaNumCtx = max(0, min(131072, (int) GETPOST('ollama_num_ctx', 'int')));
            dolibarr_set_const($db, 'DALFRED_OLLAMA_NUM_CTX', $ollamaNumCtx, 'chaine', 0, '', $conf->entity);
        } elseif (!empty($api_key)) {
            $keyConst = 'DALFRED_' . strtoupper($ai_provider) . '_API_KEY';
            dolibarr_set_const($db, $keyConst, $api_key, 'chaine', 0, '', $conf->entity);
        }

        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
}

if ($action == 'test_connection') {
    $configService = new ConfigService($db, $conf->entity);

    if (!$configService->isApiKeyConfigured()) {
        setEventMessages($langs->trans("APIKeyNotConfigured"), null, 'errors');
    } else {
        $testResult = $configService->testAiConnection();

        if ($testResult['success']) {
            setEventMessages($langs->trans("AIConnectionSuccess") . ' (Provider: ' . $configService->getProvider() . ')', null, 'mesgs');
        } else {
            setEventMessages($langs->trans("AIConnectionFailed") . ': ' . $testResult['error'], null, 'errors');
        }
    }
}

/*
 * View
 */

// Load current configuration
if (!isset($configService)) {
    $configService = new ConfigService($db, $conf->entity);
}
$ai_provider = $configService->getProvider();
$ai_model = $configService->getModel();
$max_tokens = $configService->getMaxTokens();

// Build models JSON for JavaScript
$modelsJson = [];
foreach (array_keys($supportedProviders) as $p) {
    $modelsJson[$p] = ConfigService::getModelsForProvider($p);
}

// Get current API keys for each provider (for display)
$providerKeys = [];
foreach (['anthropic', 'openai', 'mistral', 'gemini'] as $p) {
    $providerKeys[$p] = $configService->getProviderApiKey($p);
}
$ollamaUrl = $configService->getOllamaUrl();

$page_name = "DalfredSetup";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader with tabs
$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredSetup'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'ai', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Configuration form
print load_fiche_titre($langs->trans("AIConfiguration"), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';

// AI Provider Configuration
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("AIProviderSettings").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '<td>'.$langs->trans("Description").'</td>';
print '</tr>';

// Provider selection
print '<tr class="oddeven">';
print '<td><label for="ai_provider">'.$langs->trans("AIProvider").'</label></td>';
print '<td>';
print '<select name="ai_provider" id="ai_provider" class="flat minwidth200" onchange="dalfredUpdateProviderFields()">';
foreach ($supportedProviders as $value => $label) {
    print '<option value="'.$value.'"'.($ai_provider == $value ? ' selected' : '').'>'.$label.'</option>';
}
print '</select>';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans("AIProviderDesc").'</td>';
print '</tr>';

// Model selection (dynamic based on provider) with "Other" option
print '<tr class="oddeven" id="row_model_select">';
print '<td><label for="ai_model">'.$langs->trans("AIModel").'</label></td>';
print '<td>';
print '<select name="ai_model" id="ai_model" class="flat minwidth200" onchange="dalfredOnModelChange()">';
// Will be populated by JavaScript
print '</select>';
print '<input type="text" name="custom_model" id="custom_model" class="flat minwidth200 margintoponlysmall" style="display:none" placeholder="'.dol_escape_htmltag(html_entity_decode($langs->trans("CustomModelPlaceholder"), ENT_QUOTES, 'UTF-8')).'" value="">';
print '</td>';
print '<td class="opacitymedium">'.$langs->trans("AIModelDesc").'</td>';
print '</tr>';

// API Key (for Anthropic, OpenAI, Mistral)
print '<tr class="oddeven provider-field provider-apikey" id="row_api_key">';
print '<td><label for="api_key" id="label_api_key">'.$langs->trans("ProviderAPIKey").' <span class="star">*</span></label></td>';
print '<td><input type="password" name="api_key" id="api_key" value="" class="flat minwidth300" autocomplete="new-password">';
print '<br><span class="opacitymedium small" id="api_key_status"></span>';
print '</td>';
print '<td class="opacitymedium" id="desc_api_key"></td>';
print '</tr>';

// Ollama URL
print '<tr class="oddeven provider-field provider-ollama" id="row_ollama_url" style="display:none">';
print '<td><label for="ollama_url">'.$langs->trans("OllamaURL").' <span class="star">*</span></label></td>';
print '<td><input type="text" name="ollama_url" id="ollama_url" value="'.dol_escape_htmltag($ollamaUrl).'" class="flat minwidth300" placeholder="http://localhost:11434/api"></td>';
print '<td class="opacitymedium">'.$langs->trans("OllamaURLDesc").'</td>';
print '</tr>';

// Ollama HTTP timeout
print '<tr class="oddeven provider-field provider-ollama" id="row_ollama_timeout" style="display:none">';
print '<td><label for="ollama_timeout">'.$langs->trans("OllamaTimeout").'</label></td>';
print '<td><input type="number" name="ollama_timeout" id="ollama_timeout" value="'.((int) $configService->getOllamaTimeout()).'" class="flat width100" min="30" max="3600"> '.$langs->trans("Seconds").'</td>';
print '<td class="opacitymedium">'.$langs->trans("OllamaTimeoutDesc").'</td>';
print '</tr>';

// Ollama context window (num_ctx)
print '<tr class="oddeven provider-field provider-ollama" id="row_ollama_num_ctx" style="display:none">';
print '<td><label for="ollama_num_ctx">'.$langs->trans("OllamaNumCtx").'</label></td>';
print '<td><input type="number" name="ollama_num_ctx" id="ollama_num_ctx" value="'.$configService->getOllamaNumCtx().'" class="flat width100" min="0" max="131072" step="1024"></td>';
print '<td class="opacitymedium">'.$langs->trans("OllamaNumCtxDesc").'</td>';
print '</tr>';

// Max tokens
print '<tr class="oddeven">';
print '<td><label for="max_tokens">'.$langs->trans("MaxTokens").'</label></td>';
print '<td><input type="number" name="max_tokens" id="max_tokens" value="'.$max_tokens.'" class="flat width100" min="100" max="32000"></td>';
print '<td class="opacitymedium">'.$langs->trans("MaxTokensDesc").'</td>';
print '</tr>';

// Cloud HTTP timeout (Anthropic, OpenAI, Mistral, Gemini). Always visible —
// it's a cross-provider tuning knob, hiding it behind a "cloud only" condition
// would confuse admins switching providers.
print '<tr class="oddeven">';
print '<td><label for="cloud_http_timeout">'.$langs->trans("CloudHttpTimeout").'</label></td>';
print '<td><input type="number" name="cloud_http_timeout" id="cloud_http_timeout" value="'.((int) $configService->getCloudHttpTimeout()).'" class="flat width100" min="30" max="3600"> '.$langs->trans("Seconds").'</td>';
print '<td class="opacitymedium">'.$langs->trans("CloudHttpTimeoutDesc").'</td>';
print '</tr>';

// Tool max runs per turn (DALFRED_TOOL_MAX_RUNS). Cross-provider knob, same
// rationale as the HTTP timeout above. Bumping from NeuronAI's 10 to 25
// covers legitimate multi-query analytical workloads without enabling
// runaway loops (SafeToolWrapper still intercepts true loops on the 3rd
// identical call).
print '<tr class="oddeven">';
print '<td><label for="tool_max_runs">'.$langs->trans("ToolMaxRuns").'</label></td>';
print '<td><input type="number" name="tool_max_runs" id="tool_max_runs" value="'.((int) $configService->getToolMaxRuns()).'" class="flat width100" min="5" max="100"></td>';
print '<td class="opacitymedium">'.$langs->trans("ToolMaxRunsDesc").'</td>';
print '</tr>';

// Tool payload truncation threshold
print '<tr class="oddeven">';
print '<td><label for="DALFRED_TOOL_PAYLOAD_MAX_TOKENS">'.$langs->trans("DalfredToolPayloadMaxTokens").'</label></td>';
print '<td><input type="number" name="DALFRED_TOOL_PAYLOAD_MAX_TOKENS" id="DALFRED_TOOL_PAYLOAD_MAX_TOKENS" value="'.dol_escape_htmltag(getDolGlobalString('DALFRED_TOOL_PAYLOAD_MAX_TOKENS', '2000')).'" class="flat width100" min="0" max="20000" step="500"></td>';
print '<td class="opacitymedium"><small>'.$langs->trans("DalfredToolPayloadMaxTokensHelp").'</small></td>';
print '</tr>';

print '</table>';

print '<br>';

print '<div class="center">';
print '<input type="submit" class="button button-save" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

// Test connection form
print '<br>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test_connection">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("TestConnection").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("TestConnectionDesc").'</td>';
print '<td class="right"><input type="submit" class="button" value="'.$langs->trans("TestConnection").'"></td>';
print '</tr>';

print '</table>';

print '</form>';

// Information box
print '<br>';
print '<div class="info">';
print '<p><strong>'.$langs->trans("ImportantNote").':</strong></p>';
print '<ul>';
print '<li>'.$langs->trans("APIKeyStoredSecurely").'</li>';
print '<li id="info_get_key">'.$langs->trans("GetAPIKeyFrom").' <a href="https://console.anthropic.com/" target="_blank" id="info_key_link">console.anthropic.com</a></li>';
print '<li>'.$langs->trans("CostsApply").'</li>';
print '</ul>';
print '</div>';

// JavaScript for dynamic provider fields
$modelsJsonStr = json_encode($modelsJson);
$providerKeysJson = json_encode(array_map(function($k) { return !empty($k) ? '********' : ''; }, $providerKeys));
$currentModel = dol_escape_js($ai_model);
$currentProvider = dol_escape_js($ai_provider);

print '<script>
var dalfredModels = '.$modelsJsonStr.';
var dalfredProviderKeyStatus = '.$providerKeysJson.';
var dalfredCurrentModel = "'.$currentModel.'";
var dalfredCurrentProvider = "'.$currentProvider.'";

var dalfredProviderLinks = {
    "anthropic": {"url": "https://console.anthropic.com/", "label": "console.anthropic.com"},
    "openai": {"url": "https://platform.openai.com/api-keys", "label": "platform.openai.com"},
    "mistral": {"url": "https://console.mistral.ai/api-keys", "label": "console.mistral.ai"},
    "gemini": {"url": "https://aistudio.google.com/apikey", "label": "aistudio.google.com"},
    "ollama": {"url": "https://ollama.com/", "label": "ollama.com"}
};

var dalfredProviderLabels = {
    "anthropic": "' . dol_escape_js(html_entity_decode($langs->trans("AnthropicAPIKey"), ENT_QUOTES, 'UTF-8')) . '",
    "openai": "' . dol_escape_js(html_entity_decode($langs->trans("OpenAIAPIKey"), ENT_QUOTES, 'UTF-8')) . '",
    "mistral": "' . dol_escape_js(html_entity_decode($langs->trans("MistralAPIKey"), ENT_QUOTES, 'UTF-8')) . '",
    "gemini": "' . dol_escape_js(html_entity_decode($langs->trans("GeminiAPIKey"), ENT_QUOTES, 'UTF-8')) . '"
};

var dalfredProviderDescs = {
    "anthropic": "' . dol_escape_js(html_entity_decode($langs->trans("AnthropicAPIKeyDesc"), ENT_QUOTES, 'UTF-8')) . '",
    "openai": "' . dol_escape_js(html_entity_decode($langs->trans("OpenAIAPIKeyDesc"), ENT_QUOTES, 'UTF-8')) . '",
    "mistral": "' . dol_escape_js(html_entity_decode($langs->trans("MistralAPIKeyDesc"), ENT_QUOTES, 'UTF-8')) . '",
    "gemini": "' . dol_escape_js(html_entity_decode($langs->trans("GeminiAPIKeyDesc"), ENT_QUOTES, 'UTF-8')) . '"
};

function dalfredUpdateProviderFields() {
    var provider = document.getElementById("ai_provider").value;
    var isOllama = (provider === "ollama");

    // Show/hide API key vs all Ollama-specific rows. Toggling every
    // .provider-ollama row by class avoids having to list each row id here
    // when new Ollama settings are added.
    document.getElementById("row_api_key").style.display = isOllama ? "none" : "";
    var ollamaRows = document.querySelectorAll(".provider-ollama");
    for (var i = 0; i < ollamaRows.length; i++) {
        ollamaRows[i].style.display = isOllama ? "" : "none";
    }

    // Update API key label and description
    if (!isOllama) {
        var labelEl = document.getElementById("label_api_key");
        if (labelEl && dalfredProviderLabels[provider]) {
            labelEl.innerHTML = dalfredProviderLabels[provider] + \' <span class="star">*</span>\';
        }
        var descEl = document.getElementById("desc_api_key");
        if (descEl && dalfredProviderDescs[provider]) {
            descEl.textContent = dalfredProviderDescs[provider];
        }
        // Show if key is already configured
        var statusEl = document.getElementById("api_key_status");
        if (statusEl && dalfredProviderKeyStatus[provider]) {
            statusEl.textContent = "' . dol_escape_js(html_entity_decode($langs->trans("APIKeyAlreadyConfigured"), ENT_QUOTES, 'UTF-8')) . '";
        } else if (statusEl) {
            statusEl.textContent = "";
        }
    }

    // Update info box link
    var linkInfo = dalfredProviderLinks[provider];
    if (linkInfo) {
        var linkEl = document.getElementById("info_key_link");
        if (linkEl) {
            linkEl.href = linkInfo.url;
            linkEl.textContent = linkInfo.label;
        }
    }

    // Update model dropdown
    dalfredPopulateModelSelect(provider);
}

function dalfredPopulateModelSelect(provider) {
    var modelSelect = document.getElementById("ai_model");
    var customInput = document.getElementById("custom_model");
    var models = dalfredModels[provider] || {};
    var modelKeys = Object.keys(models);

    modelSelect.innerHTML = "";

    if (modelKeys.length === 0) {
        // No predefined models (Ollama) - hide select, show only custom field
        modelSelect.style.display = "none";
        customInput.style.display = "";
        customInput.placeholder = "' . dol_escape_js(html_entity_decode($langs->trans("OllamaModelPlaceholder"), ENT_QUOTES, 'UTF-8')) . '";
    } else {
        modelSelect.style.display = "";
        customInput.placeholder = "' . dol_escape_js(html_entity_decode($langs->trans("CustomModelPlaceholder"), ENT_QUOTES, 'UTF-8')) . '";

        var currentModelIsCustom = false;
        if (provider === dalfredCurrentProvider && dalfredCurrentModel && !(dalfredCurrentModel in models)) {
            currentModelIsCustom = true;
        }

        for (var i = 0; i < modelKeys.length; i++) {
            var opt = document.createElement("option");
            opt.value = modelKeys[i];
            opt.textContent = models[modelKeys[i]];
            if (provider === dalfredCurrentProvider && modelKeys[i] === dalfredCurrentModel) {
                opt.selected = true;
            }
            modelSelect.appendChild(opt);
        }

        // Add "Other" option
        var otherOpt = document.createElement("option");
        otherOpt.value = "__other__";
        otherOpt.textContent = "' . dol_escape_js(html_entity_decode($langs->trans("OtherCustomModel"), ENT_QUOTES, 'UTF-8')) . '";
        if (currentModelIsCustom) {
            otherOpt.selected = true;
        }
        modelSelect.appendChild(otherOpt);

        // Show/hide custom input based on selection
        if (currentModelIsCustom) {
            customInput.style.display = "";
            customInput.value = dalfredCurrentModel;
        } else {
            customInput.style.display = "none";
            customInput.value = "";
        }
    }
}

function dalfredOnModelChange() {
    var modelSelect = document.getElementById("ai_model");
    var customInput = document.getElementById("custom_model");
    if (modelSelect.value === "__other__") {
        customInput.style.display = "";
        customInput.focus();
    } else {
        customInput.style.display = "none";
        customInput.value = "";
    }
}

// Initialize on page load
document.addEventListener("DOMContentLoaded", function() {
    dalfredUpdateProviderFields();
});
</script>';

print dol_get_fiche_end();

llxFooter();
$db->close();
