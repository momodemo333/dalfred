<?php
/**
 * Dalfred Conversations List Page
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

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check
if (!$user->hasRight('dalfred', 'use')) {
    accessforbidden();
}

// Load autoloader
require_once dol_buildpath('/dalfred/vendor/autoload.php');

use Dalfred\Service\ThreadService;

// Get action
$action = GETPOST('action', 'alphanohtml');
$threadId = GETPOST('thread_id', 'alphanohtml');

// Initialize ThreadService
$threadService = new ThreadService($db, $user->id, $conf->entity);

// Handle actions
if ($action === 'delete' && !empty($threadId)) {
    if ($user->hasRight('dalfred', 'threads', 'manage') || true) { // For now, allow users to delete their own threads
        $result = $threadService->deleteThread($threadId);
        if ($result) {
            setEventMessages($langs->trans("ThreadDeleted"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("ErrorDeletingThread"), null, 'errors');
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'archive' && !empty($threadId)) {
    $result = $threadService->archiveThread($threadId);
    if ($result) {
        setEventMessages($langs->trans("ThreadArchived"), null, 'mesgs');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'reopen' && !empty($threadId)) {
    $result = $threadService->reopenThread($threadId);
    if ($result) {
        setEventMessages($langs->trans("ThreadReopened"), null, 'mesgs');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get filter
$status = GETPOST('status', 'alphanohtml') ?: 'all';

// Get threads
$threads = $threadService->getUserThreads(50, $status);

// Page title
$title = $langs->trans("Conversations") . ' - Dalfred';
$help_url = '';

// Standard Dolibarr header
llxHeader('', $title, $help_url, '', 0, 0, array('/dalfred/js/dalfred.js'), array('/dalfred/css/dalfred.css'));

// Title with back to chat button
print load_fiche_titre(
    '<span style="font-size: 28px; margin-right: 10px;">📋</span> ' . $langs->trans("Conversations"),
    '<a href="' . dol_buildpath('/dalfred/chat.php', 1) . '" class="butAction">' . $langs->trans("BackToChat") . '</a>',
    ''
);

print '<div class="fichecenter">';

// Filter bar
print '<div class="div-table-responsive-no-min" style="margin-bottom: 15px;">';
print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<select name="status" class="flat" onchange="this.form.submit()">';
print '<option value="all"' . ($status === 'all' ? ' selected' : '') . '>' . $langs->trans("AllConversations") . '</option>';
print '<option value="active"' . ($status === 'active' ? ' selected' : '') . '>' . $langs->trans("ActiveConversations") . '</option>';
print '<option value="archived"' . ($status === 'archived' ? ' selected' : '') . '>' . $langs->trans("ArchivedConversations") . '</option>';
print '</select>';
print '</form>';
print '</div>';

// Threads list
if (empty($threads)) {
    print '<div class="opacitymedium" style="padding: 20px; text-align: center;">';
    print $langs->trans("NoConversationsYet");
    print '<br><br>';
    print '<a href="' . dol_buildpath('/dalfred/chat.php', 1) . '" class="butAction">' . $langs->trans("StartNewConversation") . '</a>';
    print '</div>';
} else {
    print '<div class="dalfred-threads-list">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th class="left">' . $langs->trans("Title") . '</th>';
    print '<th class="center" width="120">' . $langs->trans("Status") . '</th>';
    print '<th class="center" width="150">' . $langs->trans("LastMessage") . '</th>';
    print '<th class="center" width="120">' . $langs->trans("Created") . '</th>';
    print '<th class="center" width="150">' . $langs->trans("Actions") . '</th>';
    print '</tr>';

    $i = 0;
    foreach ($threads as $thread) {
        $i++;
        print '<tr class="oddeven">';

        // Title (clickable to open conversation)
        $title = !empty($thread['title']) ? dol_trunc($thread['title'], 60) : '<em>' . $langs->trans("UntitledConversation") . '</em>';
        print '<td class="left">';
        print '<a href="' . dol_buildpath('/dalfred/chat.php', 1) . '?thread_id=' . urlencode($thread['thread_id']) . '" class="dalfred-thread-link">';
        print '<span style="margin-right: 8px;">💬</span>';
        print $title;
        print '</a>';
        print '</td>';

        // Status
        print '<td class="center">';
        if ($thread['status'] === 'active') {
            print '<span class="badge badge-status4">' . $langs->trans("Active") . '</span>';
        } elseif ($thread['status'] === 'archived') {
            print '<span class="badge badge-status8">' . $langs->trans("Archived") . '</span>';
        } else {
            print '<span class="badge badge-status0">' . ucfirst($thread['status']) . '</span>';
        }
        print '</td>';

        // Last message date
        print '<td class="center">';
        if (!empty($thread['date_last_message'])) {
            print dol_print_date(strtotime($thread['date_last_message']), 'dayhour');
        } else {
            print '-';
        }
        print '</td>';

        // Created date
        print '<td class="center">';
        $createdAt = $thread['date_creation'] ?? $thread['created_at'] ?? null;
        print $createdAt ? dol_print_date(strtotime($createdAt), 'day') : '-';
        print '</td>';

        // Actions
        print '<td class="center nowraponall">';

        // Open button
        print '<a href="' . dol_buildpath('/dalfred/chat.php', 1) . '?thread_id=' . urlencode($thread['thread_id']) . '" class="paddingright" title="' . $langs->trans("Open") . '">';
        print img_picto($langs->trans("Open"), 'eye', 'class="pictofixedwidth"');
        print '</a>';

        // Archive/Reopen button
        if ($thread['status'] === 'active') {
            print '<a href="' . $_SERVER['PHP_SELF'] . '?action=archive&thread_id=' . urlencode($thread['thread_id']) . '&token=' . newToken() . '" class="paddingright" title="' . $langs->trans("Archive") . '">';
            print img_picto($langs->trans("Archive"), 'folder', 'class="pictofixedwidth"');
            print '</a>';
        } else {
            print '<a href="' . $_SERVER['PHP_SELF'] . '?action=reopen&thread_id=' . urlencode($thread['thread_id']) . '&token=' . newToken() . '" class="paddingright" title="' . $langs->trans("Reopen") . '">';
            print img_picto($langs->trans("Reopen"), 'folder-open', 'class="pictofixedwidth"');
            print '</a>';
        }

        // Delete button
        print '<a href="' . $_SERVER['PHP_SELF'] . '?action=delete&thread_id=' . urlencode($thread['thread_id']) . '&token=' . newToken() . '" class="paddingright" title="' . $langs->trans("Delete") . '" onclick="return confirm(\'' . $langs->trans("ConfirmDeleteThread") . '\');">';
        print img_picto($langs->trans("Delete"), 'delete', 'class="pictofixedwidth"');
        print '</a>';

        print '</td>';
        print '</tr>';
    }

    print '</table>';
    print '</div>';
}

print '</div>';

// Add some specific styles
print '<style>
.dalfred-thread-link {
    color: var(--colortextlink);
    text-decoration: none;
    display: flex;
    align-items: center;
}
.dalfred-thread-link:hover {
    text-decoration: underline;
}
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 0.85em;
}
.badge-status4 {
    background-color: #28a745;
    color: white;
}
.badge-status8 {
    background-color: #6c757d;
    color: white;
}
.badge-status0 {
    background-color: #e0e0e0;
    color: #333;
}
</style>';

llxFooter();
$db->close();
?>
