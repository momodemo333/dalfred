<?php
/**
 * Dalfred Actions Hook Class
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.0.0
 * @license    GPL-3.0+
 */

/**
 * Actions class for Dalfred hooks
 */
class ActionsDalfred
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * @var array Results
     */
    public $results = array();

    /**
     * @var string Hook return
     */
    public $resprints = '';

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook to add menu entries in top right menu
     *
     * @param array $parameters Hook parameters
     * @param object $object Current object
     * @param string $action Current action
     * @param HookManager $hookmanager Hook manager
     * @return int Return code (0 for normal)
     */
    public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;

        if (!isModEnabled('dalfred')) {
            return 0;
        }

        // Check user permissions
        if (!$user->hasRight('dalfred', 'use')) {
            return 0;
        }

        // Check if API key is configured via centralized ConfigService
        require_once dirname(__DIR__).'/vendor/autoload.php';
        $configService = new \Dalfred\Service\ConfigService($this->db, $conf->entity);
        if (!$configService->isApiKeyConfigured()) {
            return 0;
        }

        $langs->load('dalfred@dalfred');

        require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
        $form = new Form($this->db);

        // Link to fullscreen chat. We deliberately do NOT set an inline color
        // on the <span> — the surrounding top-right toolbar inherits the theme
        // color (white on the dark Dolibarr top bar), so omitting the override
        // keeps Dalfred icons visually consistent with the print/help icons.
        $fullscreenLabel = $langs->trans('Chat') . ' (' . $langs->trans('WidgetFullscreenLabel') . ')';
        $fullChatLink = '<a href="' . dol_buildpath('/dalfred/chat.php', 1) . '" ';
        $fullChatLink .= 'title="' . dol_escape_htmltag($fullscreenLabel) . '" class="">';
        $fullChatLink .= '<span class="fa fa-comments valignmiddle"></span>';
        $fullChatLink .= '</a>';

        $this->resprints .= $form->textwithtooltip('', $fullscreenLabel, 2, 1, $fullChatLink, 'login_block_elem', 2);

        // Toggle floating chat (same color-inheritance reasoning as above).
        // Use a brand-aware icon: custom logo if uploaded, otherwise the Lucide bot SVG.
        $widgetLabel = $langs->trans('Dalfred') . ' (' . $langs->trans('WidgetWidgetLabel') . ')';
        $floatingChatLink = '<a href="#" onclick="if(window.dalfredChat) window.dalfredChat.toggleChat(); return false;" ';
        $floatingChatLink .= 'title="' . dol_escape_htmltag($widgetLabel) . '" class="">';
        $brandingService = new \Dalfred\Service\BrandingService();
        $logoUrl = $brandingService->getLogoUrl();
        if ($logoUrl !== null) {
            $floatingChatLink .= '<span class="dalfred-brand-icon valignmiddle" style="background-image: url(\'' . dol_escape_htmltag($logoUrl) . '\');"></span>';
        } else {
            $floatingChatLink .= '<span class="dalfred-brand-icon valignmiddle">' . \Dalfred\Icon::render('bot') . '</span>';
        }
        $floatingChatLink .= '</a>';

        $this->resprints .= $form->textwithtooltip('', $widgetLabel, 2, 1, $floatingChatLink, 'login_block_elem', 2);

        return 0;
    }

    /**
     * Hook to add CSS and JS files to HTML header via main hook
     * This injects the chat widget into every page
     *
     * @param array $parameters Hook parameters
     * @param object $object Current object
     * @param string $action Current action
     * @param HookManager $hookmanager Hook manager
     * @return int Return code (0 for normal)
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        // This hook is for object pages, not needed for our purpose
        return 0;
    }

    /**
     * Hook executed in llxFooter() before printCommonFooter
     * Used to inject the floating chat widget
     *
     * @param array $parameters Hook parameters
     * @param object $object Current object
     * @param string $action Current action
     * @param HookManager $hookmanager Hook manager
     * @return int Return code
     */
    public function llxFooter($parameters, &$object, &$action, $hookmanager)
    {
        return $this->injectChatWidget();
    }

    /**
     * Hook executed in the printCommonFooter function
     * Used to inject the floating chat widget (fallback)
     *
     * @param array $parameters Hook parameters
     * @param object $object Current object
     * @param string $action Current action
     * @param HookManager $hookmanager Hook manager
     * @return int Return code
     */
    public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
    {
        return $this->injectChatWidget();
    }

    /**
     * Auto-migrate database if module files were updated without re-enabling
     *
     * Runs once per session after a version change. Lightweight check:
     * compares stored DB version constant with module code version.
     */
    private function autoMigrateIfNeeded()
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        require_once dirname(__DIR__).'/vendor/autoload.php';

        $helper = \Dalfred\Service\DalfredMigrations::createHelper($this->db);
        $storedVersion = $helper->getStoredVersion();

        if ($storedVersion !== \Dalfred\Service\DalfredMigrations::MODULE_VERSION) {
            $result = $helper->migrateIfNeeded();
            if (!empty($result['errors'])) {
                dol_syslog('[Dalfred] Auto-migration errors: ' . implode(', ', $result['errors']), LOG_ERR);
            }
        }
    }

    /**
     * Common method to inject the chat widget JavaScript
     *
     * @return int Return code
     */
    private function injectChatWidget()
    {
        global $conf, $user, $langs;

        // Avoid double injection
        static $alreadyInjected = false;
        if ($alreadyInjected) {
            return 0;
        }

        if (!isModEnabled('dalfred')) {
            return 0;
        }

        // Don't show widget when page is loaded inside a Dolibarr popup/iframe
        // (matches the core pattern used in main.inc.php to hide topmenu/leftmenu)
        if (GETPOST('dol_openinpopup', 'aZ09')) {
            return 0;
        }

        // Auto-migrate database if module was updated without re-enabling
        $this->autoMigrateIfNeeded();

        // Check user permissions
        if (!$user->hasRight('dalfred', 'use')) {
            return 0;
        }

        // Check if API key is configured via centralized ConfigService
        require_once dirname(__DIR__).'/vendor/autoload.php';
        $configService = new \Dalfred\Service\ConfigService($this->db, $conf->entity);
        if (!$configService->isApiKeyConfigured()) {
            return 0;
        }

        // Inject branding: CSS variables and window.DalfredConfig for the JS widget
        $brandingService = new \Dalfred\Service\BrandingService();
        $this->resprints .= '<style>' . $brandingService->getCssVariables() . '</style>';
        // Attachments: respect admin toggle AND provider capability for images.
        $attachmentsEnabled = $configService->isAttachmentsEnabled();
        $accepted = ($attachmentsEnabled && \Dalfred\Service\ProviderCapabilities::supportsImages($configService->getProvider(), $configService->getModel()))
            ? '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp,.pdf'
            : '.txt,.md,.log,.csv,.pdf';
        $this->resprints .= '<script>window.DalfredConfig = '
            . json_encode([
                'agentName'                 => $brandingService->getName(),
                'agentIconHtml'             => $brandingService->getAgentIconHtml('dalfred-icon-md'),
                'attachmentsEnabled'        => $attachmentsEnabled,
                'attachmentsAcceptedTypes'  => $attachmentsEnabled ? $accepted : '',
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
            . ';</script>';

        // Don't show on login page, install pages or fullscreen chat page
        $currentPage = $_SERVER['PHP_SELF'] ?? '';
        $excludedPages = array(
            '/user/passwordforgotten.php',
            '/install/',
            '/admin/system/',
            '/dalfred/chat.php'  // Don't load floating chat on full chat page (matches both /custom/dalfred/ and alternate module directories)
        );

        foreach ($excludedPages as $excluded) {
            if (strpos($currentPage, $excluded) !== false) {
                return 0;
            }
        }

        $langs->load('dalfred@dalfred');

        // Inject markdown rendering dependencies if not already loaded via module_parts
        // Fallback: ensures libs are available even if module wasn't re-enabled after update
        // (the module_parts JS array is cached at module-enable time; new scripts added
        // in a release won't load until re-enable, so each script is gated independently).
        $jsBase = dol_buildpath('/dalfred/js', 1);
        $this->resprints .= '<script>';
        $this->resprints .= 'if(typeof marked==="undefined"){';
        $this->resprints .= 'document.write(\'<script src="'.$jsBase.'/lib/marked.min.js"><\/script>\');';
        $this->resprints .= 'document.write(\'<script src="'.$jsBase.'/lib/purify.min.js"><\/script>\');';
        $this->resprints .= 'document.write(\'<script src="'.$jsBase.'/dalfred-icons.js"><\/script>\');';
        $this->resprints .= 'document.write(\'<script src="'.$jsBase.'/dalfred-markdown.js"><\/script>\');';
        $this->resprints .= '}';
        // dalfred-copyable.js was added in 2.15.0; gate it independently so it loads
        // on installs that haven't re-enabled the module after upgrade.
        $this->resprints .= 'if(typeof window.DalfredCopyable==="undefined"){';
        $this->resprints .= 'document.write(\'<script src="'.$jsBase.'/dalfred-copyable.js"><\/script>\');';
        $this->resprints .= '}';
        $this->resprints .= '</script>';

        // Inject JavaScript configuration and translations for the chat widget
        $pageContext = array(
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'script' => $_SERVER['PHP_SELF'] ?? '',
            'referer' => $_SERVER['HTTP_REFERER'] ?? ''
        );

        // Substitute the live agent name (white-label) in the user-facing
        // strings so a Dalfred renamed e.g. "Chuggy" actually shows as
        // Chuggy in the welcome message. Lang strings carry a %s placeholder
        // and Dolibarr's transnoentities() does the sprintf-style substitution
        // internally — passing the value as the second argument is enough,
        // wrapping it in our own sprintf() would substitute against an
        // already-substituted (and now empty) placeholder.
        $agentName = $brandingService->getName();

        $translations = array(
            'widgetTitle' => $langs->transnoentities('WidgetTitle', $agentName),
            'online' => $langs->transnoentities('WidgetOnline'),
            'memory' => $langs->transnoentities('WidgetMemory'),
            'fullscreen' => $langs->transnoentities('WidgetFullscreen'),
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
        );

        $this->resprints .= '<script>';
        $this->resprints .= 'var DALFRED_ENABLED = true;';
        $this->resprints .= 'var DALFRED_BASE_URL = ' . json_encode(dol_buildpath('/dalfred/', 1)) . ';';
        $this->resprints .= 'var DALFRED_PAGE_CONTEXT = ' . json_encode($pageContext) . ';';
        $this->resprints .= 'var DALFRED_TRANSLATIONS = ' . json_encode($translations) . ';';
        $this->resprints .= '</script>';

        $alreadyInjected = true;

        return 0;
    }
}
