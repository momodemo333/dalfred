<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}

/**
 * Fake DoliDB minimal : capture les requêtes SQL émises par les tools
 * SmartQuery pour vérifier le contenu des UPDATE/INSERT sans base réelle.
 * Le vrai \DoliDB n'est jamais chargé dans ce contexte de test.
 */
class DoliDB
{
    /** @var string[] */
    public array $queries = [];

    public function query(string $sql)
    {
        $this->queries[] = $sql;
        return true;
    }

    public function num_rows($resql): int
    {
        return 1; // ownership check passes
    }

    public function fetch_object($resql)
    {
        return (object) ['total' => 0, 'rowid' => 1, 'fk_user' => 1];
    }

    public function escape(string $s): string
    {
        return addslashes($s);
    }

    public function last_insert_id(string $table): int
    {
        return 42;
    }

    public function lasterror(): string
    {
        return '';
    }

    public function lastQuery(): string
    {
        return end($this->queries) ?: '';
    }
}

use Dalfred\Tools\SmartQuerySaveTool;
use Dalfred\Tools\SmartQueryUpdateTool;

$failures = 0;
function assertTrue(bool $cond, string $msg): void
{
    global $failures;
    if (!$cond) { echo "  FAIL  $msg\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertContains(string $needle, string $haystack, string $msg): void
{
    global $failures;
    if (strpos($haystack, $needle) === false) { echo "  FAIL  $msg (needle " . var_export($needle, true) . " not in " . var_export(substr($haystack, 0, 300), true) . ")\n"; $failures++; } else { echo "  OK    $msg\n"; }
}
function assertNotContains(string $needle, string $haystack, string $msg): void
{
    global $failures;
    if (strpos($haystack, $needle) !== false) { echo "  FAIL  $msg (needle " . var_export($needle, true) . " found)\n"; $failures++; } else { echo "  OK    $msg\n"; }
}

echo "== smart_query_update : le paramètre scope est exposé dans le schéma ==\n";
$db = new DoliDB();
$tool = new SmartQueryUpdateTool($db, 1, 1);
$propNames = array_map(fn ($p) => $p->getName(), $tool->properties());
assertTrue(in_array('scope', $propNames, true), 'la propriété scope est déclarée dans properties()');

echo "\n== smart_query_update : passage private -> shared ==\n";
$db = new DoliDB();
$tool = new SmartQueryUpdateTool($db, 1, 1);
$result = json_decode($tool(query_id: 7, scope: 'shared'), true);
assertTrue($result['success'] === true, 'update scope=shared réussit');
assertContains("scope = 'shared'", $db->lastQuery(), "l'UPDATE contient scope = 'shared'");

echo "\n== smart_query_update : retour shared -> private ==\n";
$db = new DoliDB();
$tool = new SmartQueryUpdateTool($db, 1, 1);
$result = json_decode($tool(query_id: 7, scope: 'private'), true);
assertTrue($result['success'] === true, 'update scope=private réussit');
assertContains("scope = 'private'", $db->lastQuery(), "l'UPDATE contient scope = 'private'");

echo "\n== smart_query_update : scope invalide seul -> refus, pas d'UPDATE ==\n";
$db = new DoliDB();
$tool = new SmartQueryUpdateTool($db, 1, 1);
$result = json_decode($tool(query_id: 7, scope: 'public'), true);
assertTrue($result['success'] === false, 'update avec scope invalide échoue proprement');
assertNotContains('UPDATE', $db->lastQuery(), "aucun UPDATE n'est émis");

echo "\n== smart_query_update : titre + scope combinés ==\n";
$db = new DoliDB();
$tool = new SmartQueryUpdateTool($db, 1, 1);
$result = json_decode($tool(query_id: 7, title: 'Nouveau titre', scope: 'shared'), true);
assertTrue($result['success'] === true, 'update title+scope réussit');
assertContains("title = 'Nouveau titre'", $db->lastQuery(), "l'UPDATE contient le titre");
assertContains("scope = 'shared'", $db->lastQuery(), "l'UPDATE contient le scope");

echo "\n== smart_query_save : scope optionnel, défaut private ==\n";
$db = new DoliDB();
$tool = new SmartQuerySaveTool($db, 1, 1);
$result = json_decode($tool(title: 'Test', sql_query: 'SELECT 1'), true);
assertTrue($result['success'] === true, 'save sans scope réussit');
assertContains("'private'", $db->lastQuery(), "l'INSERT contient private par défaut");

echo "\n== smart_query_save : création directe en shared ==\n";
$db = new DoliDB();
$tool = new SmartQuerySaveTool($db, 1, 1);
$result = json_decode($tool(title: 'Test partagé', sql_query: 'SELECT 1', scope: 'shared'), true);
assertTrue($result['success'] === true, 'save scope=shared réussit');
assertContains("'shared'", $db->lastQuery(), "l'INSERT contient shared");

echo "\n";
if ($failures > 0) {
    echo "ÉCHEC : {$failures} assertion(s) en erreur\n";
    exit(1);
}
echo "SUCCÈS : toutes les assertions passent\n";
