<?php

declare(strict_types=1);

/**
 * Branding admin page — set agent name, logo, primary/secondary colors.
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php"))       $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php"))    $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res) die("Main include failed");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once dirname(__DIR__) . '/lib/dalfred.lib.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dalfred\Service\BrandingService;
use Dalfred\Icon;

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array('admin', 'dalfred@dalfred'));

$action      = GETPOST('action', 'aZ09');
$brandingDir = DOL_DATA_ROOT . '/dalfred/branding';

// ----- Save -----
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Dolibarr CSRF protection: compare submitted token to session token.
    // (main.inc.php already enforces this globally when main_csrf_protection
    // is enabled, but we double-check to avoid silent failures.)
    if (GETPOST('token') !== '' && GETPOST('token') !== $_SESSION['token']) {
        setEventMessages('Invalid token', null, 'errors');
    } else {
        $name      = trim(GETPOST('brand_name', 'alphanohtml'));
        $name      = mb_substr($name, 0, 50);
        $primary   = GETPOST('brand_primary', 'alphanohtml');
        $secondary = GETPOST('brand_secondary', 'alphanohtml');

        // Persist scalars (empty = use default).
        dolibarr_set_const($db, 'DALFRED_BRAND_NAME', $name, 'chaine', 0, '', $conf->entity);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) {
            dolibarr_set_const($db, 'DALFRED_BRAND_COLOR_PRIMARY', $primary, 'chaine', 0, '', $conf->entity);
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) {
            dolibarr_set_const($db, 'DALFRED_BRAND_COLOR_SECONDARY', $secondary, 'chaine', 0, '', $conf->entity);
        }

        // Logo upload.
        if (isset($_FILES['brand_logo']) && $_FILES['brand_logo']['error'] === UPLOAD_ERR_OK) {
            $uploaded = $_FILES['brand_logo'];
            if ($uploaded['size'] > 2 * 1024 * 1024) {
                setEventMessages($langs->trans('DalfredBrandLogoTooLarge'), null, 'errors');
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($uploaded['tmp_name']);
                $allowed = [
                    'image/png'     => 'logo.png',
                    'image/svg+xml' => 'logo.svg',
                    'image/jpeg'    => 'logo.jpg',
                ];
                if (!isset($allowed[$mime])) {
                    setEventMessages($langs->trans('DalfredBrandLogoBadFormat'), null, 'errors');
                } else {
                    // Extra SVG safety: forbid embedded scripts.
                    if ($mime === 'image/svg+xml') {
                        $content = (string) file_get_contents($uploaded['tmp_name']);
                        if (preg_match('/<script\b/i', $content)) {
                            setEventMessages($langs->trans('DalfredBrandLogoBadContent'), null, 'errors');
                            $mime = null; // skip persist
                        }
                    }
                    if ($mime !== null) {
                        // Ensure the branding directory exists AND is writable by the
                        // PHP process. dol_mkdir returns 0/-1; we double-check with
                        // is_writable() and surface a clear error to the admin if not.
                        if (!is_dir($brandingDir)) {
                            @mkdir($brandingDir, 0755, true);
                        }
                        if (!is_dir($brandingDir) || !is_writable($brandingDir)) {
                            $diag = is_dir($brandingDir)
                                ? "Le dossier {$brandingDir} existe mais n'est pas accessible en écriture par le process PHP. Corrigez les permissions (chown www-data ou chmod 775)."
                                : "Le dossier {$brandingDir} n'a pas pu être créé. Vérifiez les permissions du dossier parent.";
                            setEventMessages($diag, null, 'errors');
                            dol_syslog('[Dalfred] Branding logo upload — directory not writable: ' . $brandingDir, LOG_ERR);
                        } else {
                            // Remove any previous logo (any extension).
                            foreach (glob($brandingDir . '/logo.*') ?: [] as $old) {
                                @unlink($old);
                            }
                            $target = $brandingDir . '/' . $allowed[$mime];
                            if (move_uploaded_file($uploaded['tmp_name'], $target)) {
                                @chmod($target, 0644);
                                dolibarr_set_const($db, 'DALFRED_BRAND_LOGO_PATH', $allowed[$mime], 'chaine', 0, '', $conf->entity);
                            } else {
                                $err = error_get_last();
                                $msg = "Échec de l'écriture du logo dans {$target}." . ($err ? ' Détail : ' . $err['message'] : '');
                                setEventMessages($msg, null, 'errors');
                                dol_syslog('[Dalfred] Branding logo upload — move_uploaded_file failed: ' . ($err['message'] ?? 'unknown'), LOG_ERR);
                            }
                        }
                    }
                }
            }
        }

        setEventMessages($langs->trans('DalfredBrandSaved'), null, 'mesgs');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ----- Remove logo -----
if ($action === 'remove_logo') {
    foreach (glob($brandingDir . '/logo.*') ?: [] as $old) {
        @unlink($old);
    }
    dolibarr_set_const($db, 'DALFRED_BRAND_LOGO_PATH', '', 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans('DalfredBrandSaved'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ----- Reset to defaults -----
if ($action === 'reset') {
    dolibarr_set_const($db, 'DALFRED_BRAND_NAME', '', 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'DALFRED_BRAND_COLOR_PRIMARY', BrandingService::DEFAULT_PRIMARY, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'DALFRED_BRAND_COLOR_SECONDARY', BrandingService::DEFAULT_SECONDARY, 'chaine', 0, '', $conf->entity);
    foreach (glob($brandingDir . '/logo.*') ?: [] as $old) {
        @unlink($old);
    }
    dolibarr_set_const($db, 'DALFRED_BRAND_LOGO_PATH', '', 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans('DalfredBrandSaved'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ----- View -----
$svc = new BrandingService();

llxHeader('', $langs->trans('DalfredBrandingTitle'));

$head = dalfred_admin_prepare_head();

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('DalfredBrandingTitle'), $linkback, 'fa-robot');

print dol_get_fiche_head($head, 'branding', $langs->trans('DalfredBrandingTitle'), -1, 'fa-robot');

print '<style>' . $svc->getCssVariables() . '</style>';

print '<p class="opacitymedium">' . $langs->trans('DalfredBrandingDesc') . '</p>';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<div style="display: flex; gap: 30px; align-items: flex-start;">';

// Left column: form
print '<div style="flex: 1;">';
print '<table class="noborder centpercent">';

// Name
print '<tr class="oddeven"><td class="titlefield"><label for="brand_name">' . $langs->trans('DalfredBrandName') . '</label></td>';
print '<td><input type="text" id="brand_name" name="brand_name" maxlength="50" value="' . dol_escape_htmltag(getDolGlobalString('DALFRED_BRAND_NAME', '')) . '" placeholder="Dalfred">';
print '<br><span class="opacitymedium">' . $langs->trans('DalfredBrandNameDesc') . '</span></td></tr>';

// Logo
print '<tr class="oddeven"><td><label for="brand_logo">' . $langs->trans('DalfredBrandLogo') . '</label></td>';
print '<td>';
$logoUrl = $svc->getLogoUrl();
if ($logoUrl !== null) {
    print '<div style="margin-bottom: 8px;"><strong>' . $langs->trans('DalfredBrandLogoCurrent') . ':</strong>';
    print ' <img src="' . dol_escape_htmltag($logoUrl) . '" alt="logo" style="max-height: 40px; vertical-align: middle; margin-left: 8px;">';
    print ' <a href="?action=remove_logo&token=' . newToken() . '" class="butActionDelete" onclick="return confirm(\'' . dol_escape_js($langs->trans('DalfredBrandLogoRemove') . ' ?') . '\');">' . $langs->trans('DalfredBrandLogoRemove') . '</a></div>';
}
print '<input type="file" id="brand_logo" name="brand_logo" accept="image/png,image/svg+xml,image/jpeg">';
print '<br><span class="opacitymedium">' . $langs->trans('DalfredBrandLogoDesc') . '</span></td></tr>';

// Primary color
print '<tr class="oddeven"><td><label for="brand_primary">' . $langs->trans('DalfredBrandColorPrimary') . '</label></td>';
print '<td><input type="color" id="brand_primary" name="brand_primary" value="' . dol_escape_htmltag($svc->getPrimaryColor()) . '"></td></tr>';

// Secondary color
print '<tr class="oddeven"><td><label for="brand_secondary">' . $langs->trans('DalfredBrandColorSecondary') . '</label></td>';
print '<td><input type="color" id="brand_secondary" name="brand_secondary" value="' . dol_escape_htmltag($svc->getSecondaryColor()) . '"></td></tr>';

print '</table>';

print '<div class="center" style="margin-top: 16px;">';
print '<button type="submit" class="button">' . $langs->trans('Save') . '</button> ';
print '<a href="?action=reset&token=' . newToken() . '" class="button" onclick="return confirm(\'' . dol_escape_js($langs->trans('DalfredBrandReset') . ' ?') . '\');">' . $langs->trans('DalfredBrandReset') . '</a>';
print '</div>';

print '</form>';
print '</div>';

// Right column: live preview
print '<div style="width: 320px;">';
print '<h4>' . $langs->trans('DalfredBrandPreview') . '</h4>';
print '<div id="dalfred-brand-preview" style="border: 1px solid #ddd; border-radius: 12px; overflow: hidden;">';
print '<div id="dalfred-brand-preview-header" style="background: linear-gradient(135deg, ' . dol_escape_htmltag($svc->getPrimaryColor()) . ', ' . dol_escape_htmltag($svc->getSecondaryColor()) . '); color: white; padding: 12px; display: flex; align-items: center; gap: 8px;">';
print '<span id="dalfred-brand-preview-icon">' . $svc->getAgentIconHtml('dalfred-icon-md') . '</span>';
print '<strong id="dalfred-brand-preview-name">' . dol_escape_htmltag($svc->getName()) . '</strong>';
print '</div>';
print '<div style="padding: 12px; font-size: 13px;">Comment puis-je vous aider ?</div>';
print '</div>';
print '</div>';

print '</div>'; // end flex

// Live preview JS
print <<<'JS'
<script>
(function () {
    var nameInput = document.getElementById('brand_name');
    var primaryInput = document.getElementById('brand_primary');
    var secondaryInput = document.getElementById('brand_secondary');
    var previewName = document.getElementById('dalfred-brand-preview-name');
    var previewHeader = document.getElementById('dalfred-brand-preview-header');

    function update() {
        previewName.textContent = nameInput.value || 'Dalfred';
        previewHeader.style.background = 'linear-gradient(135deg, ' + primaryInput.value + ', ' + secondaryInput.value + ')';
    }

    nameInput.addEventListener('input', update);
    primaryInput.addEventListener('input', update);
    secondaryInput.addEventListener('input', update);
})();
</script>
JS;

print dol_get_fiche_end();
llxFooter();
$db->close();
