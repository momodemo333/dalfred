<?php
/* Copyright (C) 2024 Morgan - Dalfred Module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \defgroup   dalfred     Module Dalfred
 * \brief      Dalfred module descriptor - AI Assistant for Dolibarr
 *
 * \file       htdocs/custom/dalfred/core/modules/modDalfred.class.php
 * \ingroup    dalfred
 * \brief      Description and activation file for module Dalfred
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Dalfred
 */
class modDalfred extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;

        // Module ID (must be unique) - Use a free ID > 100000 for custom modules
        $this->numero = 491408;

        // Key text used to identify module (for permissions, menus, etc...)
        $this->rights_class = 'dalfred';

        // Family can be 'crm','financial','hr','projects','products','ecm','technic','interface','other'
        $this->family = "technic";

        // Module position in the family
        $this->module_position = '90';

        // Module label (no space allowed)
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        // Module description
        $this->description = "Dalfred - AI Assistant for Dolibarr with MCP integration";
        $this->descriptionlong = "Dalfred is an intelligent AI assistant that helps users interact with their Dolibarr ERP/CRM system using natural language. It uses Claude AI and MCP (Model Context Protocol) to execute actions.";

        // Version
        $this->version = '2.25.0';
        $this->url_last_version = 'https://www.e-dem.com/dolibarr/dalfred/last_version.php';

        // Const name for module status
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

        // Module icon
        $this->picto = 'fa-robot';

        // Module parts
        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'css' => array('/dalfred/css/dalfred.css'),
            'js' => array(
                '/dalfred/js/lib/marked.min.js',
                '/dalfred/js/lib/purify.min.js',
                '/dalfred/js/dalfred-icons.js',
                '/dalfred/js/dalfred-markdown.js',
                '/dalfred/js/dalfred-attachments.js',
                '/dalfred/js/dalfred-copyable.js',
                '/dalfred/js/dalfred-commands.js',
                '/dalfred/js/dalfred.js',
            ),
            'hooks' => array(
                'data' => array(
                    'main',
                )
            ),
            'moduleforexternal' => 0,
        );

        // Hooks - list of hook contexts managed by this module
        $this->hooks = array(
            'printCommonFooter',
            'printTopRightMenu',
        );

        // Data directories to create
        $this->dirs = array("/dalfred/temp");

        // Config page
        $this->config_page_url = array("setup.php@dalfred");

        // Dependencies
        $this->hidden = false;
        $this->depends = array('modApi'); // Requires API module for user API keys
        $this->requiredby = array();
        $this->conflictwith = array();

        // Language files
        $this->langfiles = array("dalfred@dalfred");

        // Prerequisites
        $this->phpmin = array(8, 1);

        // Warnings
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // Constants
        // List of particular constants to add when module is enabled
        // key, type, value, desc, visible, entity, deleteonunactive
        $this->const = array(
            // AI Provider settings
            1 => array('DALFRED_AI_PROVIDER', 'chaine', 'anthropic', 'AI Provider (anthropic, openai, mistral, gemini, ollama)', 1, 'current', 0),
            2 => array('DALFRED_MODEL', 'chaine', 'claude-sonnet-4-20250514', 'AI Model to use', 1, 'current', 0),
            3 => array('DALFRED_MAX_TOKENS', 'int', '16384', 'Maximum tokens for AI response', 1, 'current', 0),

            // Chat history settings
            4 => array('DALFRED_CONTEXT_WINDOW', 'int', '150000', 'Context window size in tokens for chat history', 1, 'current', 0),
            5 => array('DALFRED_MAX_THREADS_PER_USER', 'int', '10', 'Maximum conversation threads per user', 1, 'current', 0),

            // MCP Server settings
            6 => array('DALFRED_MCP_ENABLED', 'int', '1', 'Enable MCP Server integration', 1, 'current', 0),

            // Debug and logging
            7 => array('DALFRED_DEBUG', 'int', '0', 'Enable debug mode', 1, 'current', 0),
            8 => array('DALFRED_LOG_CONVERSATIONS', 'int', '1', 'Log conversations for analysis', 1, 'current', 0),

            // Rate limiting
            10 => array('DALFRED_RATE_LIMIT_REQUESTS', 'int', '100', 'Maximum requests per hour per user', 1, 'current', 0),
            11 => array('DALFRED_RATE_LIMIT_TOKENS', 'int', '500000', 'Maximum tokens per hour per user', 1, 'current', 0),

            // Activity log retention
            12 => array('DALFRED_LOG_RETENTION_DAYS', 'int', '30', 'Days to keep activity logs', 1, 'current', 0),

            // System prompt customization
            13 => array('DALFRED_SYSTEM_PROMPT', 'text', '', 'Custom system prompt (empty = use default)', 0, 'current', 0),

            // Attachments (text/images in chat)
            14 => array('DALFRED_ATTACH_ENABLED', 'int', '1', 'Enable file attachments in chat', 1, 'current', 0),

            // File generation toolkit
            15 => array('DALFRED_FILE_GEN_ENABLED', 'chaine', '0', 'Enable file generation by the agent', 1, 'current', 0),
            16 => array('DALFRED_FILE_GEN_MAX_SIZE', 'chaine', '5242880', 'Max file size in bytes for generated files', 1, 'current', 0),

            // MySQL schema introspection (DESCRIBE / SHOW CREATE TABLE) — global, independent of SELECT/WRITE.
            // Default ON: helps the agent use correct Dolibarr column names without exposing row data.
            17 => array('DALFRED_MYSQL_SCHEMA_ENABLED', 'chaine', '1', 'Enable MySQL schema introspection for Dalfred', 1, 'current', 0),

            // Tool payload truncation in chat history (see ToolPayloadTruncator).
            18 => array('DALFRED_TOOL_PAYLOAD_MAX_TOKENS', 'int', '2000', 'Token threshold above which tool inputs/results are elided from chat history (0=disabled)', 1, 'current', 0),
        );

        // Initialize module state
        if (!isset($conf->dalfred) || !isset($conf->dalfred->enabled)) {
            $conf->dalfred = new stdClass();
            $conf->dalfred->enabled = 0;
        }

        // Tabs
        $this->tabs = array();

        // Dictionaries
        $this->dictionaries = array();

        // Boxes/Widgets
        $this->boxes = array();

        // Cron jobs registered by this module (Dolibarr Cron module must be enabled).
        $this->cronjobs = array(
            array(
                'label'         => 'DalfredPurgeAttachments',
                'jobtype'       => 'method',
                'class'         => 'custom/dalfred/class/attachmentpurgeservice.class.php',
                'objectname'    => 'AttachmentPurgeService',
                'method'        => 'purgeExpired',
                'parameters'    => '',
                'comment'       => 'Purge attached files older than 7 days from chat conversations',
                'frequency'     => 1,
                'unitfrequency' => 86400,
                'priority'      => 50,
                'status'        => 1,
                'test'          => '$conf->dalfred->enabled',
            ),
            array(
                'label'         => 'DalfredPurgeActivityLog',
                'jobtype'       => 'method',
                'class'         => 'custom/dalfred/class/activitylogpurgeservice.class.php',
                'objectname'    => 'ActivityLogPurgeService',
                'method'        => 'purgeExpired',
                'parameters'    => '',
                'comment'       => 'Purge activity log rows older than DALFRED_LOG_RETENTION_DAYS',
                'frequency'     => 1,
                'unitfrequency' => 86400,
                'priority'      => 50,
                'status'        => 1,
                'test'          => '$conf->dalfred->enabled',
            ),
        );

        // Permissions
        $this->rights = array();
        $r = 0;

        // Permission to use Dalfred
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 49140801
        $this->rights[$r][1] = 'Use Dalfred AI Assistant';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'use';
        $r++;

        // Permission to manage own threads
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 49140802
        $this->rights[$r][1] = 'Manage own conversation threads';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'threads';
        $this->rights[$r][5] = 'manage';
        $r++;

        // Permission to view all threads (admin)
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 49140803
        $this->rights[$r][1] = 'View all users conversation threads';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'threads';
        $this->rights[$r][5] = 'viewall';
        $r++;

        // Permission to configure module
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 49140804
        $this->rights[$r][1] = 'Configure Dalfred module';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'admin';
        $r++;

        // Permission to use Smart Queries
        $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // 49140805
        $this->rights[$r][1] = 'Use Smart Queries (saved SQL queries)';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'smartquery';
        $r++;

        // Main menu entries
        $this->menu = array();
        $r = 0;

        // Top menu entry
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=tools',
            'type' => 'left',
            'titre' => 'Dalfred',
            'prefix' => img_picto('', 'fa-robot', 'class="paddingright pictofixedwidth"'),
            'mainmenu' => 'tools',
            'leftmenu' => 'dalfred',
            'url' => '/dalfred/chat.php',
            'langs' => 'dalfred@dalfred',
            'position' => 1000,
            'enabled' => '$conf->dalfred->enabled',
            'perms' => '$user->hasRight("dalfred", "use")',
            'target' => '',
            'user' => 0,
        );

        // Chat submenu
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=dalfred',
            'type' => 'left',
            'titre' => 'Chat',
            'mainmenu' => 'tools',
            'leftmenu' => 'dalfred_chat',
            'url' => '/dalfred/chat.php',
            'langs' => 'dalfred@dalfred',
            'position' => 1001,
            'enabled' => '$conf->dalfred->enabled',
            'perms' => '$user->hasRight("dalfred", "use")',
            'target' => '',
            'user' => 0,
        );

        // Threads submenu
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=dalfred',
            'type' => 'left',
            'titre' => 'Conversations',
            'mainmenu' => 'tools',
            'leftmenu' => 'dalfred_threads',
            'url' => '/dalfred/threads.php',
            'langs' => 'dalfred@dalfred',
            'position' => 1002,
            'enabled' => '$conf->dalfred->enabled',
            'perms' => '$user->hasRight("dalfred", "threads", "manage")',
            'target' => '',
            'user' => 0,
        );

        // My Files submenu
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=dalfred',
            'type' => 'left',
            'titre' => 'MyFiles',
            'mainmenu' => 'tools',
            'leftmenu' => 'dalfred_files',
            'url' => '/dalfred/files.php',
            'langs' => 'dalfred@dalfred',
            'position' => 1002,
            'enabled' => '$conf->dalfred->enabled && (getDolGlobalString("DALFRED_FILE_GEN_ENABLED") === "1")',
            'perms' => '$user->hasRight("dalfred", "use")',
            'target' => '',
            'user' => 0,
        );

        // Knowledge/Memory submenu
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=dalfred',
            'type' => 'left',
            'titre' => 'KnowledgeMemory',
            'mainmenu' => 'tools',
            'leftmenu' => 'dalfred_knowledge',
            'url' => '/dalfred/knowledge.php',
            'langs' => 'dalfred@dalfred',
            'position' => 1003,
            'enabled' => '$conf->dalfred->enabled',
            'perms' => '$user->hasRight("dalfred", "use")',
            'target' => '',
            'user' => 0,
        );

        // Smart Queries submenu
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=dalfred',
            'type' => 'left',
            'titre' => 'SmartQueries',
            'mainmenu' => 'tools',
            'leftmenu' => 'dalfred_smartquery',
            'url' => '/dalfred/smartquery_list.php',
            'langs' => 'dalfred@dalfred',
            'position' => 1004,
            'enabled' => '$conf->dalfred->enabled && getDolGlobalString("DALFRED_SMARTQUERY_ENABLED") && getDolGlobalString("DALFRED_MYSQL_TOOLKIT_ENABLED")',
            'perms' => '$user->hasRight("dalfred", "smartquery")',
            'target' => '',
            'user' => 0,
        );
    }

    /**
     * Function called when module is enabled.
     *
     * @param string $options Options when enabling module
     * @return int 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        global $conf;

        // Load SQL tables
        $result = $this->_load_tables('/dalfred/sql/');
        if ($result < 0) {
            return -1;
        }

        // Run all database migrations (idempotent — safe to run on every enable)
        require_once dol_buildpath('/dalfred/vendor/autoload.php');
        $migrationHelper = \Dalfred\Service\DalfredMigrations::createHelper($this->db);
        $migrationHelper->forceRunAll();

        // Create data directories
        $sql = array();

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     *
     * @param string $options Options when disabling module
     * @return int 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }

    /**
     * Override Dolibarr's default permission insertion behavior.
     *
     * Dolibarr's `_init()` calls `insert_permissions(1, ...)`, which re-grants
     * every module permission to every admin user via `User::addrights()`.
     * That is fine on the very first install — admins do need access — but on
     * a re-enable (typically after a module update where the customer
     * disables/enables to refresh the descriptor), it silently restores
     * permissions the admin had explicitly revoked. Customers see this as a
     * regression on every update.
     *
     * We detect a re-enable by reading a Dolibarr constant that survives
     * disable cycles (it is intentionally NOT registered in `$this->const`, so
     * `delete_const()` cannot remove it). On the first install the constant
     * is absent → we let the parent grant admins as usual, then mark the
     * constant. On every subsequent enable the constant is present → we force
     * `$reinitadminperms = 0` so existing user_rights stay exactly as the
     * customer configured them.
     *
     * @param  int<0,1> $reinitadminperms If 1, also grant the permissions to admins
     * @param  int|null $force_entity     Force entity (null = current)
     * @param  int<0,1> $notrigger        Disable triggers
     * @return int                        Number of errors
     */
    public function insert_permissions($reinitadminperms = 0, $force_entity = null, $notrigger = 0)
    {
        $isReEnable = $this->detectReEnable();

        if ($isReEnable && $reinitadminperms) {
            // Drop the auto-grant-to-admins flag while preserving the rest of
            // the parent behavior (rights_def insertion remains untouched).
            $reinitadminperms = 0;
            dol_syslog('[Dalfred] insert_permissions: re-enable detected, preserving existing user rights (skipping admin auto-grant)', LOG_INFO);
        }

        $err = parent::insert_permissions($reinitadminperms, $force_entity, $notrigger);

        if ($err === 0) {
            // Set or refresh the install marker either way: this both records
            // the first install and migrates legacy installs that pre-date
            // the marker (so the *next* enable on those is correctly seen as
            // a re-enable). The constant is intentionally not registered in
            // $this->const, so delete_const() cannot remove it on disable.
            require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
            dolibarr_set_const($this->db, 'DALFRED_PERMS_INITIALIZED', '1', 'chaine', 0, 'Dalfred initial permissions installation marker', 0);
            if (!$isReEnable) {
                dol_syslog('[Dalfred] insert_permissions: first install marker set (DALFRED_PERMS_INITIALIZED=1)', LOG_INFO);
            }
        }

        return $err;
    }

    /**
     * Decide whether this `insert_permissions()` call is a re-enable (i.e. the
     * module has already been installed on this site before) or a fresh
     * install. Re-enables must NOT re-grant permissions to admins, otherwise
     * customer-side revocations are silently undone on every module update.
     *
     * Two signals are used so that customers who upgrade FROM a version
     * pre-dating this fix are still treated correctly:
     *
     *  - DALFRED_PERMS_INITIALIZED constant exists → the module has been
     *    initialized at least once since this fix shipped.
     *  - Any user_rights row exists for a fk_id in this module's deterministic
     *    rights ID range (numero*100+01 .. numero*100+99) → some user already
     *    has, or had, Dalfred permissions assigned. Right_def rows are
     *    deleted on disable but user_rights rows are not, so they survive
     *    disable/enable cycles.
     */
    private function detectReEnable(): bool
    {
        if ((int) getDolGlobalInt('DALFRED_PERMS_INITIALIZED') === 1) {
            return true;
        }

        $minId = (int) ($this->numero . '01');
        $maxId = (int) ($this->numero . '99');
        $sql = 'SELECT COUNT(*) AS nb FROM ' . MAIN_DB_PREFIX . 'user_rights'
            . ' WHERE fk_id BETWEEN ' . $minId . ' AND ' . $maxId;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($obj && (int) $obj->nb > 0) {
                dol_syslog('[Dalfred] insert_permissions: legacy install detected via user_rights (nb=' . (int) $obj->nb . ')', LOG_INFO);
                return true;
            }
        }

        return false;
    }
}
