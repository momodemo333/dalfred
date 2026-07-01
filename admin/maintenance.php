<?php
/**
 * Dalfred Maintenance Page
 *
 * Database schema verification and migration management.
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    2.8.0
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
require_once '../vendor/autoload.php';

use Dalfred\Service\DalfredMigrations;
use Dalfred\Service\SystemPromptStorage;

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check - only admins can access
if (!$user->admin) {
    accessforbidden();
}

/*
 * Actions
 */

$action = GETPOST('action', 'aZ09');
$migrationResult = null;
$schemaResult = null;
$promptMigrationResult = null;

if ($action === 'verify_db') {
    // Force run all migrations (idempotent)
    $helper = DalfredMigrations::createHelper($db);
    $migrationResult = $helper->forceRunAll();

    // Then verify schema
    $schemaResult = $helper->verifySchema(DalfredMigrations::getExpectedSchema());

    if (empty($migrationResult['errors']) && $schemaResult['ok']) {
        setEventMessages('Base de données vérifiée et à jour (v' . DalfredMigrations::MODULE_VERSION . ')', null, 'mesgs');
    } elseif (!empty($migrationResult['errors'])) {
        setEventMessages('Des erreurs ont été rencontrées lors de la migration', $migrationResult['errors'], 'errors');
    }
}

if ($action === 'migrate_system_prompt') {
    // Manually move DALFRED_SYSTEM_PROMPT from llx_const to file. Idempotent.
    // Useful when the auto-migration in printCommonFooter has not run yet, or
    // when the user wants to re-trigger it after restoring a backup.
    $storage = new SystemPromptStorage(null, (int) $conf->entity);
    $promptMigrationResult = $storage->migrateFromLegacyConstant($db);

    switch ($promptMigrationResult['status']) {
        case 'migrated':
            // Replace the legacy constant value with a marker so the new layout
            // is detectable from outside (and so utf8mb3 is no longer a concern
            // — the marker is plain ASCII).
            dolibarr_set_const(
                $db,
                SystemPromptStorage::LEGACY_CONST,
                'file:' . $storage->getFilePath(),
                'chaine',
                0,
                '',
                $conf->entity
            );
            setEventMessages(
                'Prompt système migré vers le fichier (' . $promptMigrationResult['bytes'] . ' octets)',
                null,
                'mesgs'
            );
            break;
        case 'already':
            setEventMessages(
                'Le prompt système est déjà stocké dans un fichier (' . $promptMigrationResult['bytes'] . ' octets)',
                null,
                'mesgs'
            );
            break;
        case 'no_legacy':
            setEventMessages('Aucun prompt système à migrer (la base est vide).', null, 'mesgs');
            break;
        case 'error':
            setEventMessages(
                'Échec de la migration du prompt : ' . ($promptMigrationResult['message'] ?? 'erreur inconnue'),
                null,
                'errors'
            );
            break;
    }
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

print dol_get_fiche_head($head, 'maintenance', $langs->trans("DalfredSetup"), -1, 'fa-robot');

// Title
print load_fiche_titre($langs->trans("Maintenance"), '', 'title_setup');

// --- Current state ---
$helper = DalfredMigrations::createHelper($db);
$storedVersion = $helper->getStoredVersion();
$moduleVersion = DalfredMigrations::MODULE_VERSION;
$needsMigration = ($storedVersion !== $moduleVersion);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">État de la base de données</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Version du module (fichiers)</td>';
print '<td><strong>' . $moduleVersion . '</strong></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Version de la base de données</td>';
print '<td>';
if ($needsMigration) {
    print '<span class="badge badge-warning">' . ($storedVersion === '0.0.0' ? 'Non initialisée' : $storedVersion) . '</span>';
    print ' <span class="opacitymedium">→ mise à jour nécessaire</span>';
} else {
    print '<span class="badge badge-status4">' . $storedVersion . '</span>';
    print ' <span class="opacitymedium">✓ à jour</span>';
}
print '</td>';
print '</tr>';

print '</table>';

print '<br>';

// --- Verify button ---
print '<div class="center">';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="verify_db">';
print '<input type="submit" class="button" value="🔧 Vérifier et mettre à jour la base de données">';
print '</form>';
print '</div>';

print '<br>';

// --- Migration results ---
if ($migrationResult !== null) {
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>Résultat de la migration</td>';
    print '</tr>';

    print '<tr class="oddeven">';
    print '<td>';

    if (!$migrationResult['needed'] && empty($migrationResult['errors'])) {
        print '<p>✅ <strong>La base de données était déjà à jour.</strong></p>';
    } else {
        print '<p>📋 <strong>' . $migrationResult['executed'] . ' opération(s) exécutée(s)</strong>';
        if (!empty($migrationResult['errors'])) {
            print ' — <span class="error">' . count($migrationResult['errors']) . ' erreur(s)</span>';
        }
        print '</p>';
    }

    // Show log
    if (!empty($migrationResult['log'])) {
        print '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px; margin-top: 10px; font-family: monospace; font-size: 12px; max-height: 300px; overflow-y: auto;">';
        foreach ($migrationResult['log'] as $line) {
            $color = '#333';
            if (strpos($line, 'ERROR') !== false) {
                $color = '#dc3545';
            } elseif (strpos($line, 'OK') !== false) {
                $color = '#28a745';
            } elseif (strpos($line, 'SKIP') !== false) {
                $color = '#6c757d';
            }
            print '<div style="color: ' . $color . '">' . dol_escape_htmltag($line) . '</div>';
        }
        print '</div>';
    }

    print '</td>';
    print '</tr>';
    print '</table>';

    print '<br>';
}

// --- System prompt storage state + manual migration button ---
$promptStorage = new SystemPromptStorage(null, (int) $conf->entity);
$promptOnDisk = $promptStorage->exists();
$legacyValue = getDolGlobalString(SystemPromptStorage::LEGACY_CONST, '');
$legacyHasContent = ($legacyValue !== '' && strncmp($legacyValue, 'file:', 5) !== 0);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">Stockage du prompt système</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>Emplacement actuel</td>';
print '<td>';
if ($promptOnDisk) {
    print '<span class="badge badge-status4">Fichier</span> ';
    print '<code>' . dol_escape_htmltag($promptStorage->getFilePath()) . '</code>';
    print ' <span class="opacitymedium">(' . $promptStorage->size() . ' octets)</span>';
    if ($legacyHasContent) {
        print '<br><span class="opacitymedium">⚠️ Une copie reste dans <code>llx_const</code>. Cliquez sur Migrer pour normaliser.</span>';
    }
} elseif ($legacyHasContent) {
    print '<span class="badge badge-warning">Base de données (legacy)</span> ';
    print '<span class="opacitymedium">' . strlen($legacyValue) . ' octets dans <code>llx_const</code></span>';
} else {
    print '<span class="badge badge-status0">Vide</span> <span class="opacitymedium">aucun prompt configuré</span>';
}
print '</td>';
print '</tr>';

print '</table>';

// Manual migration button — always shown so the admin can re-trigger after a
// restore from backup, even when the storage looks already migrated.
print '<br>';
print '<div class="center">';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" style="display:inline-block;">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="migrate_system_prompt">';
print '<input type="submit" class="button" value="📂 Migrer le prompt système vers un fichier">';
print '</form>';
print '</div>';

// Result of last manual run
if ($promptMigrationResult !== null) {
    print '<br>';
    print '<div class="opacitymedium center">';
    print 'Statut : <code>' . dol_escape_htmltag($promptMigrationResult['status']) . '</code>';
    if (isset($promptMigrationResult['bytes'])) {
        print ' — ' . $promptMigrationResult['bytes'] . ' octets';
    }
    if (isset($promptMigrationResult['message'])) {
        print ' — ' . dol_escape_htmltag($promptMigrationResult['message']);
    }
    print '</div>';
}

print '<br>';

// --- Schema verification results ---
if ($schemaResult !== null) {
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td colspan="2">Vérification du schéma</td>';
    print '</tr>';

    if ($schemaResult['ok']) {
        print '<tr class="oddeven">';
        print '<td colspan="2">✅ <strong>Toutes les tables et colonnes sont présentes.</strong></td>';
        print '</tr>';
    } else {
        if (!empty($schemaResult['missing_tables'])) {
            print '<tr class="oddeven">';
            print '<td>❌ Tables manquantes</td>';
            print '<td><span class="error">' . implode(', ', $schemaResult['missing_tables']) . '</span></td>';
            print '</tr>';
        }

        if (!empty($schemaResult['missing_columns'])) {
            foreach ($schemaResult['missing_columns'] as $table => $columns) {
                print '<tr class="oddeven">';
                print '<td>⚠️ Colonnes manquantes dans <code>' . dol_escape_htmltag($table) . '</code></td>';
                print '<td><span class="error">' . implode(', ', $columns) . '</span></td>';
                print '</tr>';
            }
        }
    }

    print '</table>';

    print '<br>';
}

// --- Schema overview (always shown) ---
$currentSchema = $helper->verifySchema(DalfredMigrations::getExpectedSchema());

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>Table</td>';
print '<td>État</td>';
print '</tr>';

$expectedSchema = DalfredMigrations::getExpectedSchema();
foreach ($expectedSchema as $tableName => $columns) {
    $isMissing = in_array($tableName, $currentSchema['missing_tables']);
    $missingCols = $currentSchema['missing_columns'][$tableName] ?? [];

    print '<tr class="oddeven">';
    print '<td><code>' . MAIN_DB_PREFIX . dol_escape_htmltag($tableName) . '</code></td>';
    print '<td>';

    if ($isMissing) {
        print '<span class="badge badge-danger">Table manquante</span>';
    } elseif (!empty($missingCols)) {
        print '<span class="badge badge-warning">' . count($missingCols) . ' colonne(s) manquante(s): ' . implode(', ', $missingCols) . '</span>';
    } else {
        print '<span class="badge badge-status4">OK</span> (' . count($columns) . ' colonnes vérifiées)';
    }

    print '</td>';
    print '</tr>';
}

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
