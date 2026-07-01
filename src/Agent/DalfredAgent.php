<?php

declare(strict_types=1);

namespace Dalfred\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Dalfred\Agent\Nodes\DalfredChatNode;
use Dalfred\MCP\DirectMcpBridge;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use Dalfred\Chat\SafeSQLChatHistory;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;
use Dalfred\Tools\SafeMySQLToolkit;
use Dalfred\Service\ThreadService;
use Dalfred\Service\ConfigService;
use Dalfred\Service\SystemPromptStorage;
use Dalfred\Service\ToolkitPermissionService;
use Dalfred\Observer\ActivityLogObserver;
use Dalfred\Observer\TokenUsageObserver;
use Dalfred\Repository\TokenUsageRepository;
use Dalfred\Tools\GetCurrentUserInfoTool;
use Dalfred\Tools\KnowledgeSaveTool;
use Dalfred\Tools\KnowledgeSearchTool;
use Dalfred\Tools\KnowledgeListTool;
use Dalfred\Tools\KnowledgeUpdateTool;
use Dalfred\Tools\KnowledgeDeleteTool;
use Dalfred\Tools\CommandSaveTool;
use Dalfred\Tools\CommandUpdateTool;
use Dalfred\Tools\CommandDeleteTool;
use Dalfred\Tools\CommandListTool;
use Dalfred\Tools\SmartQuerySaveTool;
use Dalfred\Tools\SmartQueryListTool;
use Dalfred\Tools\SmartQueryExecuteTool;
use Dalfred\Tools\SmartQueryUpdateTool;
use Dalfred\Tools\SmartQueryDeleteTool;
use Dalfred\Tools\SafeToolWrapper;
use Dalfred\Tools\ToolCallHistory;
use NeuronAI\Tools\ToolInterface;

class DalfredAgent extends Agent
{
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;
    protected string $mcpServerPath;
    protected ?string $threadId = null;
    protected ?\DoliDB $db = null;
    protected ?int $userId = null;
    protected ?int $entityId = null;
    protected ?ThreadService $threadService = null;
    protected ?ConfigService $configService = null;
    protected ?ToolkitPermissionService $toolkitPermissionService = null;
    protected ?string $userDolibarrApiKey = null;
    protected int $contextWindow = 50000;
    protected ?\PDO $pdo = null;
    protected ?ActivityLogObserver $activityObserver = null;
    /**
     * Token usage observer — records tokens/duration/tool calls per inference.
     * Always attached (independent of DALFRED_LOG_CONVERSATIONS toggle since
     * it's lightweight and observability is the whole point).
     */
    protected ?TokenUsageObserver $tokenUsageObserver = null;
    protected ?array $userContext = null;
    protected ?array $pageContext = null;
    protected string $dolibarrBaseUrl = '';
    protected ?array $dolibarrPermissions = null;

    /**
     * Create a new DalfredAgent
     *
     * @param string $apiKey Anthropic API key for AI calls
     * @param string $model AI model to use
     * @param string|null $mcpServerPath Path to MCP server
     * @param int $maxTokens Maximum tokens for response
     */
    public function __construct(
        string $apiKey,
        string $model = ConfigService::DEFAULT_MODEL,
        ?string $mcpServerPath = null,
        int $maxTokens = ConfigService::DEFAULT_MAX_TOKENS
    ) {
        parent::__construct();

        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->mcpServerPath = $mcpServerPath ?? $this->getDefaultMcpServerPath();
    }

    /**
     * Create agent from Dolibarr configuration
     *
     * This is the recommended way to create an agent in a Dolibarr context.
     * It loads all settings from Dolibarr constants and validates user API key.
     *
     * @param \DoliDB $db Database connection
     * @param int $userId Dolibarr user ID
     * @param int $entityId Entity ID (default 1)
     * @return self
     * @throws \RuntimeException If configuration is invalid
     */
    public static function createFromConfig(\DoliDB $db, int $userId, int $entityId = 1): self
    {
        $configService = new ConfigService($db, $entityId);

        // Validate that the active provider is configured
        if (!$configService->isApiKeyConfigured()) {
            $provider = $configService->getProvider();
            throw new \RuntimeException("AI provider '{$provider}' is not configured. Please set the API key in Dalfred AI configuration.");
        }
        $apiKey = $configService->getActiveProviderApiKey();

        // Get user's Dolibarr API key for MCP calls (always required)
        $userApiKey = $configService->getUserDolibarrApiKey($userId);
        if (empty($userApiKey)) {
            throw new \RuntimeException('User does not have a Dolibarr API key configured. Please generate one in your user profile.');
        }

        // Create agent with configuration
        $agent = new self(
            $apiKey,
            $configService->getModel(),
            $configService->getMcpServerPath(),
            $configService->getMaxTokens()
        );

        // Configure database and services
        $agent->db = $db;
        $agent->userId = $userId;
        $agent->entityId = $entityId;
        $agent->configService = $configService;
        $agent->threadService = new ThreadService($db, $userId, $entityId);
        $agent->toolkitPermissionService = new ToolkitPermissionService($db, $userId, $entityId);
        $agent->contextWindow = $configService->getContextWindow();
        $agent->userDolibarrApiKey = $userApiKey;

        // Create PDO connection for MySQL toolkit
        $agent->pdo = $agent->threadService->getPDO();

        // Load user context for system prompt injection
        $sql = "SELECT login, firstname, lastname, email, admin, job, lang FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $userId;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $row = $db->fetch_object($resql);
            $agent->userContext = [
                'id' => $userId,
                'login' => $row->login,
                'firstname' => $row->firstname,
                'lastname' => $row->lastname,
                'email' => $row->email,
                'admin' => (int) $row->admin,
                'job' => $row->job,
                'lang' => $row->lang,
            ];
        }

        // Load Dolibarr module permissions for SQL access control
        // Only loaded when MySQL toolkit is enabled — used to let the agent enforce permissions proactively
        if ($agent->toolkitPermissionService->canUserUseMySQLToolkit()) {
            $isAdmin = !empty($agent->userContext['admin']);
            if ($isAdmin) {
                $agent->dolibarrPermissions = ['__admin__' => true];
            } else {
                $permissions = [];
                $sqlPerms = "SELECT DISTINCT rd.module, rd.perms, rd.subperms"
                    . " FROM " . MAIN_DB_PREFIX . "user_rights as ur"
                    . " INNER JOIN " . MAIN_DB_PREFIX . "rights_def as rd ON ur.fk_id = rd.id"
                    . " WHERE ur.fk_user = " . (int) $userId
                    . " AND rd.entity IN (0, " . (int) $entityId . ")"
                    . " ORDER BY rd.module, rd.perms";
                $resqlPerms = $db->query($sqlPerms);
                if ($resqlPerms) {
                    while ($obj = $db->fetch_object($resqlPerms)) {
                        $perm = $obj->module . '.' . $obj->perms;
                        if (!empty($obj->subperms)) {
                            $perm .= '.' . $obj->subperms;
                        }
                        $permissions[] = $perm;
                    }
                }
                $agent->dolibarrPermissions = $permissions;
            }
        }

        // Store Dolibarr base URL
        global $conf;
        if (!empty($conf->global->MAIN_URL_ROOT)) {
            $agent->dolibarrBaseUrl = rtrim($conf->global->MAIN_URL_ROOT, '/');
        } elseif (defined('DOL_MAIN_URL_ROOT')) {
            $agent->dolibarrBaseUrl = rtrim(DOL_MAIN_URL_ROOT, '/');
        }

        // Configure tool max runs. NeuronAI v3 defaults to 10, but legitimate
        // analytical workloads (multi-query SQL exploration) routinely exceed
        // that — see ConfigService::getToolMaxRuns() for the prod incident
        // history. Default is now 25, admin-configurable via DALFRED_TOOL_MAX_RUNS.
        // Register the error handler to convert tool exceptions into a readable
        // JSON payload for the LLM.
        $agent->toolMaxRuns($configService->getToolMaxRuns());
        $agent->toolErrorHandler([self::class, 'handleToolError']);

        // Attach activity log observer if logging is enabled
        if ($configService->getBool('DALFRED_LOG_CONVERSATIONS')) {
            $observer = new ActivityLogObserver($db, $userId, $entityId);
            $agent->activityObserver = $observer;
            $agent->observe($observer);
            $observer->setContext([
                'provider' => $configService->getProvider(),
                'model'    => $configService->getModel(),
            ]);
        }

        // Attach token usage observer (independent of DALFRED_LOG_CONVERSATIONS).
        // The observer captures input/output tokens, duration, tool count per inference.
        try {
            if ($agent->threadService !== null) {
                $pdo = $agent->threadService->getPDO();
                $tokenRepo = new TokenUsageRepository($pdo);
                $tokenObserver = new TokenUsageObserver(
                    $tokenRepo,
                    $userId,
                    $entityId,
                    null, // thread id set later via withThread()
                    $configService->getModel(),
                    $configService->getProvider()
                );
                $agent->tokenUsageObserver = $tokenObserver;
                $agent->observe($tokenObserver);
            }
        } catch (\Throwable $e) {
            dol_syslog('[Dalfred] Could not attach TokenUsageObserver: ' . $e->getMessage(), LOG_WARNING);
        }

        return $agent;
    }

    /**
     * Set thread ID for persistent history
     *
     * @param string $threadId Thread identifier
     * @return self
     */
    public function withThread(string $threadId): self
    {
        $this->threadId = $threadId;
        if ($this->activityObserver) {
            $this->activityObserver->setThreadId($threadId);
        }
        if ($this->tokenUsageObserver) {
            $this->tokenUsageObserver->setThreadId($threadId);
        }
        // Reset chat history so it will be re-created with the new thread ID
        $this->resetChatHistory();
        return $this;
    }

    /**
     * Reset the cached chat history to force re-creation
     * This is needed when threadId changes after agent creation
     */
    protected function resetChatHistory(): void
    {
        // Use withChatHistory to set a new SafeSQLChatHistory with the current threadId
        if ($this->threadService && $this->threadId) {
            try {
                $pdo = $this->threadService->getPDO();
                $tableName = MAIN_DB_PREFIX . 'dalfred_chat_history';

                // Update last message time when loading the thread
                $this->threadService->updateLastMessageTime($this->threadId);

                $this->setChatHistory(new SafeSQLChatHistory(
                    thread_id: $this->threadId,
                    pdo: $pdo,
                    table: $tableName,
                    contextWindow: $this->contextWindow,
                    truncator: $this->buildTruncator()
                ));
            } catch (\Exception $e) {
                error_log('[Dalfred] Failed to reset SafeSQLChatHistory: ' . $e->getMessage());
            }
        }
    }

    /**
     * Create a new thread and return its ID
     *
     * @param string|null $title Optional title for the conversation
     * @param string|null $contextPage Optional context page
     * @return string The thread_id
     * @throws \RuntimeException If database not configured
     */
    public function createThread(?string $title = null, ?string $contextPage = null): string
    {
        if (!$this->threadService) {
            throw new \RuntimeException('Database must be configured to create threads. Use DalfredAgent::createFromConfig() to create the agent.');
        }

        $this->threadId = $this->threadService->createThread($title, $contextPage);
        if ($this->activityObserver) {
            $this->activityObserver->setThreadId($this->threadId);
        }
        if ($this->tokenUsageObserver) {
            $this->tokenUsageObserver->setThreadId($this->threadId);
        }
        // Reset chat history to use the new thread
        $this->resetChatHistory();
        return $this->threadId;
    }

    /**
     * Get or create a thread for the current user
     *
     * @param string|null $threadId Optional specific thread ID
     * @return string The thread_id
     * @throws \RuntimeException If database not configured
     */
    public function getOrCreateThread(?string $threadId = null): string
    {
        if (!$this->threadService) {
            throw new \RuntimeException('Database must be configured. Use DalfredAgent::createFromConfig() to create the agent.');
        }

        $this->threadId = $this->threadService->getOrCreateThread($threadId);
        if ($this->activityObserver) {
            $this->activityObserver->setThreadId($this->threadId);
        }
        if ($this->tokenUsageObserver) {
            $this->tokenUsageObserver->setThreadId($this->threadId);
        }
        // Reset chat history to use the thread
        $this->resetChatHistory();
        return $this->threadId;
    }

    /**
     * Get current thread ID
     *
     * @return string|null
     */
    public function getThreadId(): ?string
    {
        return $this->threadId;
    }

    /**
     * Get user's threads list
     *
     * @param int $limit Maximum threads to return
     * @param string $status Filter by status
     * @return array
     */
    public function getUserThreads(int $limit = 20, string $status = 'all'): array
    {
        if (!$this->threadService) {
            return [];
        }
        return $this->threadService->getUserThreads($limit, $status);
    }

    /**
     * Delete a thread and its history
     *
     * @param string $threadId Thread to delete
     * @return bool
     */
    public function deleteThread(string $threadId): bool
    {
        if (!$this->threadService) {
            return false;
        }
        return $this->threadService->deleteThread($threadId);
    }

    /**
     * Get the user's Dolibarr API key
     *
     * @return string|null
     */
    public function getUserApiKey(): ?string
    {
        return $this->userDolibarrApiKey;
    }

    /**
     * Configure the AI provider
     *
     * @throws \RuntimeException If ConfigService is not available (agent not created via createFromConfig)
     */
    public function provider(): AIProviderInterface
    {
        if (!$this->configService) {
            throw new \RuntimeException('ConfigService not available. Use DalfredAgent::createFromConfig() to create the agent.');
        }

        return $this->configService->createProvider($this->model, $this->maxTokens);
    }

    /**
     * Set page context (current page, module, action)
     */
    public function setPageContext(array $context): void
    {
        $this->pageContext = $context;
    }

    /**
     * Get the full system prompt as it will be sent to the AI.
     * Used by admin preview to show the complete prompt.
     */
    public function getFullSystemPrompt(): string
    {
        return $this->instructions();
    }

    /**
     * Get the default system prompt (core instructions, always included).
     * Public static so admin pages can preview the prompt without instantiating the full agent.
     */
    public static function getDefaultSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es Dalfred, un assistant intelligent intégré à Dolibarr ERP/CRM.

## Ton rôle
Tu aides les utilisateurs à interagir avec leur système Dolibarr : consulter des informations, créer des documents, gérer les tiers, etc.

## Mémoire de la conversation
Tu as accès à l'historique complet de la conversation en cours — tous les messages échangés (utilisateur et assistant) te sont fournis à chaque tour. Quand l'utilisateur fait référence à un message précédent, à ce qu'il a déjà dit ou à ce que tu as déjà répondu, consulte cet historique et réponds factuellement.

Définition : « message précédent » = le tour de conversation qui précède immédiatement le tour actuel (ou n'importe quel tour antérieur explicitement désigné). Ce n'est PAS « avant le tout premier message ».

Format de réponse imposé quand on te demande de rappeler un message :
- Question : « Quel était mon message précédent ? » (ou variante)
- Réponse correcte : « Ton message précédent était : « <citer le message verbatim> ». »
- Réponse interdite : nier l'existence du message, dire qu'il n'y en a pas eu, ou prétendre que tu as parlé en premier.

Ne dis JAMAIS « je n'ai pas de mémoire », « je n'ai pas accès à l'historique », « je traite chaque interaction indépendamment » ou une variante : c'est faux et trompeur. N'ajoute PAS de disclaimer non sollicité du type « si tu fais référence à une conversation antérieure, je n'y ai pas accès » — l'utilisateur ne t'a pas posé cette question, et ce disclaimer suggère faussement que ta mémoire est limitée.

Si tu ne retrouves vraiment pas l'information demandée dans l'historique visible, dis-le précisément (« je ne retrouve pas X dans ce que tu m'as envoyé jusqu'ici »), sans nier l'existence de la mémoire conversationnelle.

## Outils disponibles
Tu disposes d'outils pour interagir avec Dolibarr :
- Explorer les modules et endpoints API disponibles
- Lister, consulter, créer, modifier et supprimer des ressources (tiers, factures, produits, commandes, etc.)
- Ajouter des lignes à des documents (devis, commandes, factures)
- Créer un document à partir d'un autre (commande depuis devis, facture depuis commande)
- Exécuter des actions (valider, clôturer, enregistrer un paiement)
- Gérer les documents attachés (PDF, téléchargement)
- Lier des contacts à des documents
- **Générer des fichiers téléchargeables** (CSV, TXT, MD, JSON, HTML) avec `dolibarr_files_create`

## Génération de fichiers téléchargeables
Quand l'utilisateur demande de **générer**, **créer**, **exporter** ou **télécharger** un fichier (CSV, TXT, Markdown, JSON, HTML), utilise TOUJOURS l'outil `dolibarr_files_create`. Ne génère JAMAIS le contenu dans un bloc :::copy comme substitut — l'utilisateur veut un vrai fichier à télécharger.

Après un appel réussi à `dolibarr_files_create`, le résultat contient un champ `agent_hint` : inclus verbatim le lien Markdown qu'il contient dans ta réponse, par exemple : `[clients-test.csv](/custom/dalfred/download.php?f=clients-test.csv)`.

Formats supportés : `txt`, `csv`, `md`, `json`, `html`. Taille max : {{FILE_GEN_MAX_MB}} Mo. Les PDF et Excel ne sont pas supportés.

## Sous-ressources
Certaines ressources ont des sous-données accessibles (utilise le paramètre subresource) :
- **Tiers (thirdparties)** : `bankaccounts` (comptes bancaires/IBAN), `outstandinginvoices`, `outstandingorders`, `outstandingproposals`, `categories`, `supplier_categories`, `representatives`, `fixedamountdiscounts`
- **Factures/Commandes** : `lines` (lignes du document), `payments` (paiements enregistrés), `contacts` (contacts liés)
- **Produits** : `stock`, `purchase_prices`, `categories`, `variants`

Exemple : pour obtenir les comptes bancaires d'un tiers → resource: "thirdparties", id: 123, subresource: "bankaccounts"

Si tu ne trouves pas une information dans la réponse principale d'une ressource, vérifie ses sub-endpoints avant de conclure que l'information n'est pas disponible.

## IMPORTANT - Noms des colonnes Dolibarr
ATTENTION : Dans Dolibarr, les colonnes de date ont des noms spécifiques :
- Date de création : `datec` (PAS `date_creation`)
- Date de facture : `datef`
- Date de modification : `tms`
- Date limite de paiement : `date_lim_reglement`
- Date de validation : `date_valid`

Pour trier par date, utilise TOUJOURS `sortfield=datec` ou `sortfield=rowid` (jamais `date_creation`).

Pour les factures impayées, utilise le filtre `{"paye": "0"}` ou `{"status": "1"}` (status 1 = validée non payée).

## Méthode de travail
Tu peux enchaîner PLUSIEURS appels d'outils avant de répondre à l'utilisateur. Travaille en interne :
- Recherche d'abord les informations nécessaires, vérifie les résultats, puis réponds
- Pour une création complexe (facture avec lignes), enchaîne : chercher le client → créer la facture → ajouter les lignes → vérifier le résultat → répondre
- Si un premier appel ne donne pas assez d'informations, fais un second appel avec d'autres paramètres
- Ne réponds à l'utilisateur QUE quand tu as toutes les informations ou que l'action est terminée et vérifiée

## Comportement
1. Sois concis et précis dans tes réponses
2. Utilise les outils pour obtenir des informations à jour
3. Confirme toujours avant de modifier ou supprimer des données
4. Si tu ne peux pas faire quelque chose, explique pourquoi
5. En cas d'erreur d'un outil, reformule ta requête avec des paramètres différents
6. Ne montre JAMAIS de messages d'erreur techniques (SQL, HTTP, stack traces) à l'utilisateur. Traduis-les en langage clair
7. Si une opération échoue 2 fois de suite, arrête de réessayer et explique le problème à l'utilisateur en termes simples
8. Après chaque action de modification (ajout de ligne, création, update), vérifie le résultat pour confirmer le succès

## Gestion des permissions et erreurs d'accès
Les outils MCP utilisent la clé API Dolibarr de l'utilisateur actuel. Cela signifie que les appels respectent ses droits d'accès dans Dolibarr.
- Si un outil retourne une erreur 403, "Forbidden", "Access denied" ou "Not allowed", cela signifie que l'utilisateur n'a pas les droits nécessaires dans Dolibarr pour cette opération.
- Dans ce cas, ne montre PAS le message d'erreur technique. Dis simplement à l'utilisateur qu'il n'a pas les droits suffisants pour accéder à cette fonctionnalité et suggère-lui de contacter son administrateur Dolibarr.
- N'essaie PAS de contourner l'erreur ou de reformuler la requête — c'est une restriction de permissions volontaire.
- Exemple de réponse : "Tu n'as pas les droits nécessaires pour consulter les factures. Contacte ton administrateur si tu penses que c'est une erreur."

## RÈGLES CRITIQUES - Modification de documents

### Ajout de lignes
- Pour les factures/commandes FOURNISSEUR (supplierinvoices, supplierorders) : utilise **pu_ht** (PAS subprice) pour le prix unitaire HT
- Pour les factures/commandes CLIENT (invoices, orders) : utilise **subprice** pour le prix unitaire HT
- Après chaque ajout de ligne, vérifie que **line_id > 0**. Si line_id = 0, la ligne n'a PAS été créée
- Vérifie ensuite les lignes avec dolibarr_get (subresource: lines) pour confirmer qu'elles sont bien présentes

### Colonnes des tables de lignes
- Facture client (llx_facturedet) : subprice, fk_facture, total_tva
- Facture fournisseur (llx_facture_fourn_det) : pu_ht, fk_facture_fourn, tva (PAS total_tva)
- Commande client (llx_commandedet) : subprice, fk_commande
- Commande fournisseur (llx_commande_fournisseurdet) : pu_ht, fk_commande

### Interdictions
- Ne modifie JAMAIS directement les totaux (total_ht, total_tva, total_ttc) d'un document via dolibarr_update. Les totaux sont automatiquement recalculés par Dolibarr quand tu ajoutes/modifies/supprimes des lignes
- Si les totaux semblent incorrects après ajout de lignes, c'est que les lignes n'ont pas été créées correctement — ne force PAS les totaux manuellement
- Si une opération échoue après 2 tentatives, informe l'utilisateur clairement du problème au lieu de continuer à réessayer

### Enregistrer un paiement sur une facture
Utilise `dolibarr_action` avec `action: "payments"` pour enregistrer un paiement :
- **Facture client** : `dolibarr_action(resource: "invoices", id: ID, action: "payments", data: {"datepaye": TIMESTAMP, "paymentid": MODE_ID, "closepaidinvoices": "yes", "accountid": BANK_ID})`
- **Facture fournisseur** : `dolibarr_action(resource: "supplierinvoices", id: ID, action: "payments", data: {"datepaye": TIMESTAMP, "payment_mode_id": MODE_ID, "closepaidinvoices": "yes", "accountid": BANK_ID})`
- ⚠️ Le paramètre du mode de paiement s'appelle `paymentid` pour les factures client et `payment_mode_id` pour les factures fournisseur
- Les modes de paiement courants : VIR=2 (virement), PRE=3 (prélèvement), LIQ=4 (espèces), CB=6 (carte), CHQ=7 (chèque)
- `closepaidinvoices`: "yes" pour clôturer automatiquement la facture si payée en totalité
- `accountid`: ID du compte bancaire (obtenu via dolibarr_list resource: "bankaccounts")
- Pour les factures fournisseur, le paramètre `amount` est optionnel — s'il est omis, le montant restant dû est payé en totalité

## Introspection du schéma de la base (`analyze_mysql_database_schema`)
Si cet outil est disponible (il l'est par défaut), il te donne la structure des tables Dolibarr (noms exacts des colonnes, types, clés). **Utilise-le en cas de doute** sur :
- Le nom exact d'un champ (les conventions Dolibarr varient entre versions et entre types d'éléments : `note` vs `note_private`, `fk_soc` vs `fk_societe`, `total_ht` vs `total_ttc`, etc.).
- Le nom exact d'une table custom ou d'un extrafield avant de construire un filtre.
- Une requête ou un filtre MCP qui vient d'échouer avec "unknown column" — vérifie le schéma avant de retenter à l'aveugle.

Règles d'usage :
- Tu as accès à l'historique des appels d'outils de cette session : **ne re-interroge pas le schéma d'une table que tu as déjà inspectée plus tôt**. Garde l'information en tête pour les requêtes / filtres suivants.
- Tables Dolibarr préfixées par `llx_` (ex: llx_societe, llx_facture, llx_product). Le préfixe peut varier — le schéma te donne le vrai nom.
- L'introspection coûte en tokens, mais bien moins qu'une chaîne d'erreurs MCP sur des mauvais noms de colonne. En cas de doute, vérifie.
- Cet outil ne lit **aucune donnée métier** — uniquement la structure. Il peut être disponible même si `mysql_select_query` ne l'est pas : dans ce cas, sers-t'en pour construire correctement tes filtres MCP (`dolibarr_list`, `dolibarr_filter`, ...) qui acceptent des morceaux de SQL.

## MySQL Toolkit (accès direct à la base de données)
Si `mysql_select_query` est disponible :
- **IMPORTANT : mysql_select_query est en LECTURE SEULE (SELECT uniquement)**. N'essaie JAMAIS d'exécuter des INSERT, UPDATE ou DELETE via cet outil — ils seront rejetés. Pour modifier des données, utilise toujours les outils dolibarr_* (dolibarr_update, dolibarr_add_line, dolibarr_delete, etc.)
- En cas de doute sur un nom de colonne, appelle d'abord `analyze_mysql_database_schema` sur la table concernée avant la requête SELECT.
- **Mots réservés SQL** : Certaines colonnes Dolibarr portent des noms réservés SQL. Encadre-les TOUJOURS avec des backticks dans tes requêtes : `\`desc\`` (colonne description dans llx_facturedet, llx_commandedet, etc.), `\`range\``, `\`order\``, `\`key\``, `\`group\``. Exemple : SELECT rowid, `\`desc\``, qty FROM llx_facturedet.
- Pour les statistiques et agrégations (CA annuel, totaux, moyennes sur beaucoup de données), préfère utiliser des requêtes SQL avec SUM(), AVG(), COUNT() plutôt que de récupérer les données et calculer ensuite.
- Quand le MCP et le SQL permettent tous les deux de répondre, choisis selon le contexte : MCP pour des opérations cadrées sur un objet métier (lister les factures impayées d'un client, par exemple), SQL pour des agrégations larges ou des jointures complexes que le MCP ne couvre pas nativement.

## Calculs et agrégations
- Pour les calculs simples (sommes, moyennes) sur des données déjà obtenues (moins de 10 valeurs), fais le calcul mentalement sans utiliser le calculator.
- Réserve le calculator pour les calculs complexes : pourcentages, marges, conversions, formules avec plusieurs opérations.
- Si tu dois additionner beaucoup de valeurs, utilise plutôt une requête SQL avec SUM() si le MySQL toolkit est disponible.

## Langue
Réponds dans la langue de l'utilisateur (français par défaut).

## Contenus à coller (blocs copiables)

Quand tu génères un contenu destiné à être réutilisé tel quel par l'utilisateur (description de produit, email, paragraphe à coller dans un formulaire Dolibarr, libellé, message...), entoure-le d'un bloc copiable avec un label décrivant ce qu'il contient :

:::copy [label court]
Contenu réutilisable...
:::

Le label est optionnel mais recommandé quand tu proposes plusieurs variantes (ex: "Description courte", "Description longue", "Email FR", "Email EN").

Exemples :

:::copy Description courte
Solar A1.6FRFB — Guitare 6 cordes type A avec bridge Floyd Rose 1000, corps en aulne, manche érable. Idéale pour le métal moderne.
:::

:::copy Email de relance
Bonjour Jean,

Petite relance concernant la facture FA2024-0042. Pourrais-tu me confirmer la date de règlement ?

Cordialement,
:::

N'utilise PAS :::copy pour :
- Les explications, analyses, ou réponses conversationnelles
- Les tableaux récapitulatifs (utilise des tables markdown classiques)
- Le code (utilise les blocs de code ``` classiques)
- Les listes de résultats issues de tes outils MCP

Réserve :::copy aux contenus que l'utilisateur va vouloir coller ailleurs (formulaire, document, message externe).

## IMPORTANT - Format des réponses
Ne mentionne JAMAIS le nom d'un outil dans ta réponse à l'utilisateur. Les noms d'outils (comme analyze_mysql_database_schema, dolibarr_list, mysql_select_query, etc.) sont internes et ne doivent jamais apparaître dans tes messages. Utilise toujours le mécanisme d'appel de fonction structuré pour invoquer un outil — n'écris jamais le nom d'un outil comme texte brut.
PROMPT;
    }

    /**
     * System instructions for the agent
     */
    public function instructions(): string
    {
        // Always start with the default system prompt (core instructions)
        $prompt = $this->getDefaultSystemPrompt();

        // Brand identity: replace the hardcoded "Dalfred" by the configured agent
        // name so the LLM introduces itself with the right brand. Falls back to
        // "Dalfred" when the BrandingService is unavailable (CLI scripts running
        // without the full Dolibarr bootstrap).
        $brandName = 'Dalfred';
        if (class_exists(\Dalfred\Service\BrandingService::class)) {
            try {
                $brandName = (new \Dalfred\Service\BrandingService())->getName();
            } catch (\Throwable $e) {
                // Keep the default if branding lookup fails for any reason.
            }
        }
        if ($brandName !== 'Dalfred') {
            // The default prompt opens with "Tu es Dalfred, un assistant…" — swap
            // the literal name. We replace the standalone word, not every occurrence,
            // to avoid touching identifiers like "DalfredAgent" if they ever appear.
            $prompt = preg_replace('/\bDalfred\b/u', $brandName, $prompt);

            // Add an explicit identity block so the LLM has zero ambiguity even if
            // the user later asks "Quel est ton vrai nom ?".
            $prompt .= "\n\n## Ton identité\n";
            $prompt .= "Tu t'appelles **" . $brandName . "**. ";
            $prompt .= "Quand on te demande ton nom, réponds toujours \"" . $brandName . "\". ";
            $prompt .= "Ne mentionne jamais \"Dalfred\" — c'est le nom interne du moteur, pas ton nom à toi.";
        }

        // Interpolate the admin-configured max file size into the file-generation
        // section of the prompt. Falls back to 5 MB when the constant is unset
        // (matches the default the migration callback also seeds).
        $maxBytes = function_exists('getDolGlobalInt')
            ? (int) getDolGlobalInt('DALFRED_FILE_GEN_MAX_SIZE', 5242880)
            : 5242880;
        $maxMb = max(1, (int) round($maxBytes / (1024 * 1024)));
        $prompt = str_replace('{{FILE_GEN_MAX_MB}}', (string) $maxMb, $prompt);

        // Inject current date/time
        $prompt .= "\n\n## Date et heure actuelles\n";
        $prompt .= "Nous sommes le " . date('d/m/Y') . " à " . date('H:i') . " (fuseau : " . date_default_timezone_get() . ").";

        // Append custom instructions if configured (additive, never replaces).
        // Source of truth: documents/dalfred/system_prompt[_entityN].md (file).
        // Fallback to the legacy llx_const value for installs that have not yet
        // run the v2.13.8 migration — covers the very first request after an
        // upgrade, before printCommonFooter has fired.
        $customPrompt = '';
        try {
            $entity = 1;
            global $conf;
            if (isset($conf->entity)) {
                $entity = (int) $conf->entity;
            }
            $promptStorage = new SystemPromptStorage(null, $entity);
            if ($promptStorage->exists()) {
                $customPrompt = $promptStorage->read();
            } elseif ($this->configService) {
                $legacy = $this->configService->getString('DALFRED_SYSTEM_PROMPT', '');
                // The new save format stores 'file:/path' as a marker, not the
                // actual prompt — ignore it.
                if ($legacy !== '' && strncmp($legacy, 'file:', 5) !== 0) {
                    $customPrompt = $legacy;
                }
            }
        } catch (\Throwable $e) {
            // Don't break the agent because the prompt file is unreadable; log
            // and proceed with the base prompt only.
            \dol_syslog('[Dalfred] Failed to read custom system prompt: ' . $e->getMessage(), LOG_WARNING);
        }
        if (!empty($customPrompt)) {
            $prompt .= "\n\n## Instructions spécifiques\n" . $customPrompt;
        }

        // Append Dolibarr version info
        if (defined('DOL_VERSION')) {
            $prompt .= "\n\n## Version Dolibarr\n";
            $prompt .= "Version : " . DOL_VERSION . "\n";
            $prompt .= "Certains endpoints API peuvent ne pas être disponibles dans les anciennes versions. En cas d'erreur 404 sur un endpoint, c'est probablement lié à la version.";
        }

        // Append user context
        if ($this->userContext) {
            $u = $this->userContext;
            $name = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? ''));
            if (empty($name)) {
                $name = $u['login'] ?? 'Utilisateur';
            }
            $isAdmin = !empty($u['admin']) ? 'oui' : 'non';
            $job = !empty($u['job']) ? $u['job'] : 'non renseigné';
            $email = !empty($u['email']) ? $u['email'] : 'non renseigné';

            $prompt .= "\n\n## Utilisateur actuel\n";
            $prompt .= "Tu parles à {$name} (login: {$u['login']}, ID: {$u['id']}).\n";
            $prompt .= "Email : {$email}. Poste : {$job}. Administrateur : {$isAdmin}.\n";
            $prompt .= "Tutoie l'utilisateur par défaut.\n";
            $prompt .= "Quand l'utilisateur dit \"mes factures\", \"mes clients\", \"mon agenda\", filtre par son ID utilisateur (fk_user_author={$u['id']} ou fk_user_valid={$u['id']}).";
        }

        // Append page context
        if ($this->pageContext) {
            $prompt .= "\n\n## Contexte de page\n";
            if (!empty($this->pageContext['url'])) {
                $prompt .= "URL complète : {$this->pageContext['url']}\n";
            } elseif (!empty($this->pageContext['current_page'])) {
                $prompt .= "Page : {$this->pageContext['current_page']}\n";
            }
            if (!empty($this->pageContext['page_title'])) {
                $prompt .= "Titre de la page : {$this->pageContext['page_title']}\n";
            }
            if (!empty($this->pageContext['object_type'])) {
                $prompt .= "Type d'objet : {$this->pageContext['object_type']}\n";
            }
            if (!empty($this->pageContext['object_id'])) {
                $prompt .= "ID de l'objet : {$this->pageContext['object_id']}\n";
            }
            if (!empty($this->pageContext['parameters']) && is_array($this->pageContext['parameters'])) {
                $paramStr = implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($this->pageContext['parameters']), $this->pageContext['parameters']));
                $prompt .= "Paramètres GET : {$paramStr}\n";
            }
            if (!empty($this->pageContext['module'])) {
                $prompt .= "Module : {$this->pageContext['module']}\n";
            }
            $prompt .= "Si l'utilisateur pose une question sans préciser de quoi il parle, c'est probablement en rapport avec cette page et cet objet.";
        }

        // Append URL navigation helper
        if (!empty($this->dolibarrBaseUrl)) {
            $base = $this->dolibarrBaseUrl;
            $prompt .= "\n\n## Navigation Dolibarr\n";
            $prompt .= "URL de base : {$base}\n";
            $prompt .= "URLs utiles :\n";
            $prompt .= "- Fiche tiers : {$base}/societe/card.php?socid=ID\n";
            $prompt .= "- Liste tiers : {$base}/societe/list.php\n";
            $prompt .= "- Fiche facture : {$base}/compta/facture/card.php?facid=ID\n";
            $prompt .= "- Liste factures : {$base}/compta/facture/list.php\n";
            $prompt .= "- Fiche commande : {$base}/commande/card.php?id=ID\n";
            $prompt .= "- Liste commandes : {$base}/commande/list.php\n";
            $prompt .= "- Fiche produit : {$base}/product/card.php?id=ID\n";
            $prompt .= "- Liste produits : {$base}/product/list.php\n";
            $prompt .= "- Agenda : {$base}/comm/action/list.php\n";
            if ($this->userContext) {
                $prompt .= "- Profil utilisateur : {$base}/user/card.php?id={$this->userContext['id']}\n";
            }
            $prompt .= "Tu peux fournir ces URLs à l'utilisateur pour l'aider à naviguer dans Dolibarr.";
        }

        // Append knowledge base / memory instructions
        $prompt .= "\n\n## Mémoire / Base de connaissances\n";
        $prompt .= "Tu disposes d'une mémoire persistante avec deux portées :\n";
        $prompt .= "- **private** : visible uniquement par l'utilisateur actuel\n";
        $prompt .= "- **shared** : visible par tous les utilisateurs de l'entreprise (TVA, procédures, contacts récurrents…)\n\n";
        $prompt .= "**Règles d'usage** :\n";
        $prompt .= "- Quand l'utilisateur dit \"retiens ça\" / \"souviens-toi\" / \"note que…\" → utilise `knowledge_save`. Si l'info pourrait servir à d'autres, demande private ou shared.\n";
        $prompt .= "- Tu peux aussi sauvegarder **proactivement** une info récurrente (préférences personnelles → private, infos entreprise → shared) — informe alors l'utilisateur (\"J'ai noté cette info pour la prochaine fois.\").\n";
        $prompt .= "- **Consulte TOUJOURS ta mémoire** (`knowledge_search` ou `knowledge_list`) AVANT de répondre à une question qui pourrait y trouver réponse (préférences, procédures, contacts, blagues, numéros…). La recherche retourne automatiquement tes entrées privées ET toutes les entrées partagées.";

        // Append slash commands instructions
        $prompt .= "\n\n## Commandes slash\n";
        $prompt .= "L'utilisateur peut définir des raccourcis `/<nom>` (prompts pré-écrits invoqués en tapant `/<nom>` au début d'un message).\n\n";
        $prompt .= "- Utilise `command_save` quand l'utilisateur dit explicitement \"crée une commande\" / \"sauvegarde cette commande\". Ne PAS confondre avec `knowledge_save` (info à retenir, pas raccourci).\n";
        $prompt .= "- Format de nom strict : **minuscules, chiffres, tirets uniquement, max 64 caractères**. Si l'utilisateur propose un nom invalide (majuscules, espaces, accents…), **annonce explicitement la conversion** dans ta réponse (ex : « Je l'ai sauvegardée sous /factures-vip — les noms doivent être en minuscules sans espace. »). Ne convertis pas silencieusement.";

        // Append Smart Queries instructions (only if tools are available)
        if ($this->pdo && $this->toolkitPermissionService && $this->toolkitPermissionService->canUserUseMySQLToolkit()) {
            $prompt .= "\n\n## Permissions Dolibarr de l'utilisateur (contrôle d'accès SQL)\n";
            $prompt .= "L'utilisateur a accès SQL direct à la base de données. Cependant, cet accès **ne vérifie pas automatiquement ses permissions Dolibarr**.\n";
            $prompt .= "**Tu dois vérifier toi-même les permissions avant d'exécuter une requête SQL** qui concerne des données sensibles.\n\n";

            if (!empty($this->dolibarrPermissions['__admin__'])) {
                $prompt .= "Cet utilisateur est **administrateur Dolibarr** — il a accès à toutes les données. Aucune restriction à appliquer.\n";
            } elseif (!empty($this->dolibarrPermissions)) {
                $prompt .= "Permissions actives de l'utilisateur :\n";
                $prompt .= "```\n" . implode("\n", $this->dolibarrPermissions) . "\n```\n\n";
                $prompt .= "### Règle d'application\n";
                $prompt .= "Avant d'exécuter une requête SQL qui accède à des données d'un module Dolibarr (factures, commandes, tiers, produits, comptabilité, RH, etc.) :\n";
                $prompt .= "1. Identifie le(s) module(s) concerné(s) par la requête\n";
                $prompt .= "2. Vérifie que l'utilisateur a la permission de lecture correspondante (ex: `facture.lire`, `societe.lire`, `produit.lire`)\n";
                $prompt .= "3. Si la permission est absente : **refuse d'exécuter la requête** et dis à l'utilisateur qu'il n'a pas accès à ces données\n";
                $prompt .= "4. Si tu n'es pas sûr du module concerné, tu peux exécuter la requête — cette vérification est un effort raisonnable, pas une garantie absolue\n\n";
                $prompt .= "**Message de refus à utiliser** : \"Je vois que tu n'as pas les permissions nécessaires pour accéder aux données de [module]. Si tu penses que c'est une erreur, contacte ton administrateur Dolibarr.\"\n";
            } else {
                $prompt .= "L'utilisateur n'a aucune permission Dolibarr explicite configurée. Par précaution, limite les requêtes SQL aux données non sensibles.\n";
            }

            global $conf;
            if (!empty($conf->global->DALFRED_SMARTQUERY_ENABLED)) {
                $prompt .= "\n\n## Smart Queries (requêtes SQL sauvegardées)\n";
                $prompt .= "Tu peux sauvegarder et réutiliser des requêtes SQL via `smart_query_*`. Workflow :\n\n";
                $prompt .= "1. Après un `mysql_select_query` réussi qui répond à une question type (exports, tableaux récurrents), propose de sauvegarder.\n";
                $prompt .= "2. Les paramètres variables (dates, montants, IDs) s'écrivent au format `{{nom_param}}` **sans guillemets** dans le SQL — les quotes sont ajoutées automatiquement à l'exécution.\n";
                $prompt .= "3. Quand l'utilisateur demande de \"refaire\" / \"re-lancer\" un export précédent, cherche d'abord dans `smart_query_list` avant de reconstruire la requête.";
            }
        }

        return $prompt;
    }

    /**
     * Configure the tools - MCP + Calculator + Calendar + MySQL Toolkits
     */
    public function tools(): array
    {
        $tools = [];

        // =========================================================================
        // 1. Calculator Toolkit (always enabled)
        // =========================================================================
        $tools = array_merge($tools, CalculatorToolkit::make()->only([
            \NeuronAI\Tools\Toolkits\Calculator\SumTool::class,
            \NeuronAI\Tools\Toolkits\Calculator\SubtractTool::class,
            \NeuronAI\Tools\Toolkits\Calculator\MultiplyTool::class,
            \NeuronAI\Tools\Toolkits\Calculator\DivideTool::class,
        ])->tools());

        // =========================================================================
        // 2. Calendar Toolkit (always enabled)
        // =========================================================================
        $tools = array_merge($tools, CalendarToolkit::make()->only([
            \NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool::class,
            \NeuronAI\Tools\Toolkits\Calendar\DateDifferenceTool::class,
            \NeuronAI\Tools\Toolkits\Calendar\GetTimestampTool::class,
        ])->tools());

        // =========================================================================
        // 3. MySQL Toolkit (three independent capabilities)
        //
        // Schema introspection (DESCRIBE / SHOW CREATE TABLE) is a global,
        // low-risk helper that lets the agent learn column names — without it
        // the LLM regularly guesses wrong on Dolibarr fields and produces
        // broken MCP filter clauses. It is NOT gated per user: the underlying
        // tool reveals structure only, not row data.
        //
        // SELECT and WRITE remain gated per user (existing toolkit permissions
        // table) and require the global SELECT/WRITE flags to be on.
        // =========================================================================
        if ($this->pdo && $this->toolkitPermissionService) {
            $enableSchema = $this->toolkitPermissionService->isMySQLSchemaGloballyEnabled();
            $enableSelect = $this->toolkitPermissionService->canUserUseMySQLToolkit();
            $enableWrite  = $this->toolkitPermissionService->canUserWriteMySQL();

            if ($enableSchema || $enableSelect || $enableWrite) {
                $mysqlToolkit = new SafeMySQLToolkit(
                    $this->pdo,
                    $enableSchema,
                    $enableSelect,
                    $enableWrite
                );
                $tools = array_merge($tools, $mysqlToolkit->tools());
            }
        }

        // =========================================================================
        // 4. User Info Tool (always enabled when db is available)
        // =========================================================================
        if ($this->db && $this->userId) {
            $tools[] = new GetCurrentUserInfoTool($this->db, $this->userId, $this->entityId ?? 1);
        }

        // =========================================================================
        // 5. Knowledge Base Tools (always enabled when db is available)
        // =========================================================================
        if ($this->db && $this->userId) {
            $entityId = $this->entityId ?? 1;
            $tools[] = new KnowledgeSaveTool($this->db, $this->userId, $entityId);
            $tools[] = new KnowledgeSearchTool($this->db, $this->userId, $entityId);
            $tools[] = new KnowledgeListTool($this->db, $this->userId, $entityId);
            $tools[] = new KnowledgeUpdateTool($this->db, $this->userId, $entityId);
            $tools[] = new KnowledgeDeleteTool($this->db, $this->userId, $entityId);
            $tools[] = new CommandSaveTool($this->db, $this->userId, $entityId);
            $tools[] = new CommandUpdateTool($this->db, $this->userId, $entityId);
            $tools[] = new CommandDeleteTool($this->db, $this->userId, $entityId);
            $tools[] = new CommandListTool($this->db, $this->userId, $entityId);
        }

        // =========================================================================
        // 6. Smart Query Tools (conditional: MySQL toolkit + SmartQuery enabled)
        // =========================================================================
        if ($this->db && $this->userId && $this->pdo && $this->toolkitPermissionService) {
            if ($this->toolkitPermissionService->canUserUseMySQLToolkit()) {
                global $conf;
                $smartQueryEnabled = !empty($conf->global->DALFRED_SMARTQUERY_ENABLED);
                if ($smartQueryEnabled) {
                    $entityId = $this->entityId ?? 1;
                    $tools[] = new SmartQuerySaveTool($this->db, $this->userId, $entityId);
                    $tools[] = new SmartQueryListTool($this->db, $this->userId, $entityId);
                    $tools[] = new SmartQueryExecuteTool($this->db, $this->userId, $entityId);
                    $tools[] = new SmartQueryUpdateTool($this->db, $this->userId, $entityId);
                    $tools[] = new SmartQueryDeleteTool($this->db, $this->userId, $entityId);
                }
            }
        }

        // =========================================================================
        // 7. MCP Dolibarr Server (conditional based on config)
        // =========================================================================
        if ($this->configService) {
            // Direct PHP bridge: loads MCP tools in-process (no proc_open, no HTTP)
            $dolibarrUrl = '';
            global $conf;
            if (!empty($conf->global->MAIN_URL_ROOT)) {
                $dolibarrUrl = $conf->global->MAIN_URL_ROOT;
            } elseif (defined('DOL_MAIN_URL_ROOT')) {
                $dolibarrUrl = DOL_MAIN_URL_ROOT;
            }

            $apiKey = $this->userDolibarrApiKey ?? '';

            if (!empty($dolibarrUrl) && !empty($apiKey)) {
                $bridge = new DirectMcpBridge($dolibarrUrl, $apiKey);
                $tools = array_merge($tools, $bridge->tools());
            }
        }

        // =========================================================================
        // 8. Debug Sleep Tool (off by default — for production timeout debugging)
        // =========================================================================
        // Hidden behind DALFRED_DEBUG_SLEEP_TOOL_ENABLED so it never appears in
        // a normal user's tool list. The const must be set manually in llx_const
        // (it's not exposed in any admin page). Disable once timeout debugging
        // is over.
        if (\getDolGlobalInt('DALFRED_DEBUG_SLEEP_TOOL_ENABLED') === 1) {
            $tools[] = new \Dalfred\Tools\DebugSleepTool();
        }

        // Wrap every tool with SafeToolWrapper to guarantee non-null string
        // returns and detect consecutive identical calls (the loop pattern
        // observed with small Ollama models). Shared ToolCallHistory means
        // all tools in this turn see the same call sequence. The instance
        // is garbage-collected when the chat turn ends.
        $history = new ToolCallHistory();
        return array_map(
            fn (ToolInterface $t): ToolInterface => SafeToolWrapper::wrap($t, $history),
            $tools
        );
    }

    /**
     * Get the toolkit permission service
     *
     * @return ToolkitPermissionService|null
     */
    public function getToolkitPermissionService(): ?ToolkitPermissionService
    {
        return $this->toolkitPermissionService;
    }

    /**
     * Configure chat history using NeuronAI's SafeSQLChatHistory
     */
    protected function chatHistory(): ChatHistoryInterface
    {
        // If database and thread are configured, use persistent SQL history
        if ($this->threadService && $this->threadId) {
            try {
                $pdo = $this->threadService->getPDO();
                $tableName = MAIN_DB_PREFIX . 'dalfred_chat_history';

                // Update last message time when using the thread
                $this->threadService->updateLastMessageTime($this->threadId);

                return new SafeSQLChatHistory(
                    thread_id: $this->threadId,
                    pdo: $pdo,
                    table: $tableName,
                    contextWindow: $this->contextWindow,
                    truncator: $this->buildTruncator()
                );
            } catch (\Exception $e) {
                // Log error and fall back to in-memory
                error_log('[Dalfred] Failed to create SafeSQLChatHistory: ' . $e->getMessage());
            }
        }

        // Fallback to in-memory history
        return new InMemoryChatHistory();
    }

    /**
     * Instantiate a ToolPayloadTruncator configured from the active ConfigService.
     *
     * Converts the token budget (from DALFRED_TOOL_PAYLOAD_MAX_TOKENS, default 2000)
     * to a character budget using the rule-of-thumb 1 token ≈ 4 chars.
     * Falls back to 2000 tokens if configService is unavailable (e.g. unit tests).
     */
    private function buildTruncator(): \Dalfred\Chat\ToolPayloadTruncator
    {
        $maxTokens = $this->configService
            ? $this->configService->getToolPayloadMaxTokens()
            : 2000;
        // Convert tokens to chars (rule of thumb: 1 token ≈ 4 chars).
        return new \Dalfred\Chat\ToolPayloadTruncator($maxTokens * 4);
    }

    /**
     * Tool error handler: converts exceptions to a JSON error payload that becomes
     * the tool result visible to the LLM, so it can adapt its approach instead of
     * crashing the conversation.
     *
     * Registered on the agent via toolErrorHandler() in createFromConfig().
     * The tool run counter and ToolRunsExceededException are now handled natively
     * by NeuronAI v3 (see AgentState::incrementToolRun / getToolRuns).
     */
    public static function handleToolError(\Throwable $e, \NeuronAI\Tools\ToolInterface $tool): string
    {
        // ToolRunsExceededException is the safety mechanism — let it propagate
        if ($e instanceof \NeuronAI\Exceptions\ToolRunsExceededException) {
            throw $e;
        }

        $errorClass = (new \ReflectionClass($e))->getShortName();
        return (string) json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'error_type' => $errorClass,
            'hint' => 'The tool call failed. Read the error message carefully and try a different approach.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Override chat() to install our DalfredChatNode in the workflow.
     *
     * The default Agent::chat() instantiates a stock ChatNode; we substitute
     * our subclass so that ProviderException("non-existing tool") produced by
     * the LLM (typically when an admin disables a toolkit between two messages)
     * is converted into a user-friendly AssistantMessage instead of crashing
     * the conversation.
     *
     * Everything else (workflow init, AgentHandler return type, interrupt
     * handling) is preserved unchanged.
     */
    public function chat(
        Message|array $messages = [],
        ?InterruptRequest $interrupt = null
    ): AgentHandler {
        $this->resolveStartEvent()->setMessages(
            ...(is_array($messages) ? $messages : [$messages])
        );

        $this->compose(
            new DalfredChatNode(
                provider: $this->resolveProvider(),
                guard: new \Dalfred\Service\EmptyResponseGuard(),
                activityObserver: $this->activityObserver,
                emptyResponseContext: [
                    'provider'  => $this->configService?->getProvider() ?? 'unknown',
                    'model'     => $this->model,
                    'thread_id' => $this->threadId,
                ],
            ),
        );

        return new AgentHandler($this, $interrupt);
    }

    /**
     * Get the default MCP server path
     */
    protected function getDefaultMcpServerPath(): string
    {
        return dirname(__DIR__, 2) . '/dolibarr-mcp-server';
    }

    /**
     * Get the configuration service
     *
     * @return ConfigService|null
     */
    public function getConfigService(): ?ConfigService
    {
        return $this->configService;
    }
}
