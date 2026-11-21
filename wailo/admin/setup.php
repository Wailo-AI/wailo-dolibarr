<?php

/* Copyright (C) 2026 Wailo <https://wailo.ai>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    wailo/admin/setup.php
 * \ingroup wailo
 * \brief   Wailo setup page: API key, API URL, directory, connection test, manual sync, status and journal.
 */

// Every action of this page needs a valid CSRF token.
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    $res = @include str_replace('..', '', $_SERVER['CONTEXT_DOCUMENT_ROOT']) . '/main.inc.php';
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
    $res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
// Try main.inc.php using relative path
if (!$res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
    $res = @include '../../../main.inc.php';
}
if (!$res) {
    die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/wailo/lib/wailo.lib.php');
dol_include_once('/wailo/class/wailosync.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'wailo@wailo'));

// Access control: Dolibarr administrators only.
if (!$user->admin) {
    accessforbidden();
}

$hookmanager->initHooks(array('wailosetup', 'globalsetup'));

$action = GETPOST('action', 'aZ09');
$sync = new WailoSync($db);

/*
 * Actions
 */

if ($action === 'save') {
    $error = 0;
    $newUrl = trim(GETPOST('wailo_api_url', 'alphanohtml'));
    $newDirectory = trim(GETPOST('wailo_directory', 'alphanohtml'));
    // 'alphanohtml', not 'password': Dolibarr 20 does not know the 'password' filter and returns ''. The key is then
    // checked against its exact shape (wailoIsKeyShape: digits|wlo_test_ or wlo_live_ and [A-Za-z0-9_-]).
    $newKey = trim((string) GETPOST('wailo_api_key', 'alphanohtml'));
    $newNote = GETPOST('wailo_sync_note', 'int') ? 1 : 0;
    $oldKey = getDolGlobalString('WAILO_API_KEY');
    $oldUrl = getDolGlobalString('WAILO_API_URL', WailoSync::DEFAULT_API_URL);

    if ($newUrl === '') {
        $newUrl = WailoSync::DEFAULT_API_URL;
    }
    if (!WailoClient::isValidBaseUrl($newUrl)) {
        $error++;
        setEventMessages(wailoMsg('WailoErrorUrl'), null, 'errors');
    } elseif (!WailoClient::isAllowedUrl($newUrl, getDolGlobalInt('WAILO_ALLOW_ANY_HOST') > 0)) {
        // The key is only ever sent to a Wailo host.
        $error++;
        setEventMessages(wailoMsg('WailoErrorHostNotAllowed'), null, 'errors');
    }
    if ($newDirectory === '' || dol_strlen($newDirectory) > 120) {
        $error++;
        setEventMessages(wailoMsg('WailoErrorDirectory'), null, 'errors');
    }
    if ($newKey !== '' && !wailoIsKeyShape($newKey)) {
        $error++;
        setEventMessages(wailoMsg('WailoErrorKeyShape'), null, 'errors');
    }
    $hostChanged = strtolower((string) parse_url($newUrl, PHP_URL_HOST)) !== strtolower((string) parse_url($oldUrl, PHP_URL_HOST));
    if (!$error && $hostChanged && $newKey === '' && $oldKey !== '') {
        // The saved key is never sent to a new host on its own: the administrator types it again.
        $error++;
        setEventMessages(wailoMsg('WailoErrorKeyRequiredForHost'), null, 'errors');
    }

    $keyToCheck = $newKey !== '' ? $newKey : $oldKey;
    $changesTarget = ($newKey !== '' && $newKey !== $oldKey) || ($newUrl !== $oldUrl);
    $me = null;
    $lock = null;
    if (!$error && $changesTarget && $keyToCheck !== '') {
        // No change of key while a pass runs (the links could be reset under its feet).
        $lock = $sync->acquireLock();
        if ($lock === null) {
            $error++;
            setEventMessages(wailoMsg('WailoErrorPassRunning'), null, 'errors');
        }
    }
    if (!$error && $changesTarget && $keyToCheck !== '') {
        // Check the new key (or the key on the new URL) with Wailo before saving anything.
        $client = new WailoClient($newUrl, $keyToCheck, array($sync, 'log'));
        $client->allowSleep = false;
        try {
            $me = $client->request('GET', '/me');
        } catch (WailoKeyRefusedException $e) {
            $me = array('status' => 401, 'json' => array('error' => array('code' => 'unauthenticated')));
        } catch (Throwable $e) {
            $me = array('status' => 0, 'json' => null);
        }
        if ($me['status'] !== 200 || !isset($me['json']['data']['enterprise']['id'])) {
            $error++;
            $code = (string) ($me['json']['error']['code'] ?? ($me['status'] ? 'http_' . $me['status'] : 'network'));
            setEventMessages(wailoMsg('WailoErrorKeyRefused', $code), null, 'errors');
        }
    }
    unset($keyToCheck, $oldKey);

    if (!$error) {
        $db->begin();
        $ok = dolibarr_set_const($db, 'WAILO_API_URL', $newUrl, 'chaine', 0, '', $conf->entity) > 0;
        $ok = $ok && dolibarr_set_const($db, 'WAILO_DIRECTORY', $newDirectory, 'chaine', 0, '', $conf->entity) > 0;
        $ok = $ok && dolibarr_set_const($db, 'WAILO_SYNC_NOTE', (string) $newNote, 'chaine', 0, '', $conf->entity) > 0;
        if ($ok && $newKey !== '') {
            // Name ending with _KEY: Dolibarr stores the value encrypted (dolEncrypt) in llx_const.
            $ok = dolibarr_set_const($db, 'WAILO_API_KEY', $newKey, 'chaine', 0, '', $conf->entity) > 0;
        }
        if ($ok) {
            $db->commit();
            if ($me !== null) {
                $d = $me['json']['data'];
                $newEnterprise = (string) $d['enterprise']['id'] . '/' . (!empty($d['key']['is_sandbox']) ? '1' : '0');
                $oldEnterprise = $sync->getState('enterprise_id') . '/' . $sync->getState('is_sandbox');
                if ($newEnterprise !== $oldEnterprise) {
                    // Another enterprise, or sandbox <-> real contacts: old links mean nothing anymore.
                    $sync->resetLinks();
                    $sync->log('warning', 'KeyChangedReset', array());
                    setEventMessages(wailoMsg('WailoKeyChangedReset'), null, 'warnings');
                }
            }
            setEventMessages(wailoMsg('SetupSaved'), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages(wailoMsg('Error'), null, 'errors');
        }
    }
    unset($newKey);
    if ($lock) {
        $sync->releaseLock($lock);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'deletekey') {
    dolibarr_del_const($db, 'WAILO_API_KEY', $conf->entity);
    $sync->log('info', 'KeyDeleted', array());
    setEventMessages(wailoMsg('WailoKeyDeleted'), null, 'mesgs');
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'test') {
    $t = $sync->testConnection();
    if ($t['ok']) {
        // Text from Wailo (enterprise name) is escaped: wailoMsg never lets a parameter become HTML.
        setEventMessages(wailoMsg(
            'WailoTestOk',
            $t['enterprise'],
            $t['enterprise_status'],
            (int) $t['contacts_count'],
            $langs->transnoentitiesnoconv($t['sandbox'] ? 'WailoSandboxYes' : 'WailoSandboxNo')
        ), array(wailoMsg('WailoTestRights', $t['abilities'])), 'mesgs');
    } else {
        setEventMessages($t['code'] === 'unauthenticated' ? wailoMsg('WailoKeyRevoked') : wailoMsg('WailoTestKo', $t['code']), null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($action === 'syncnow' || $action === 'syncconfirm') {
    $confirmation = null;
    if ($action === 'syncconfirm') {
        // Only the exact set shown on the page is confirmed (reason + fingerprint of the ids).
        $confirmation = array('reason' => GETPOST('confirm_reason', 'aZ09'), 'hash' => GETPOST('confirm_hash', 'alphanohtml'));
    }
    $result = $sync->runPass($user, $confirmation);
    $style = $result === WailoSync::RESULT_OK ? 'mesgs' : (in_array($result, array(WailoSync::RESULT_ERROR, WailoSync::RESULT_KEYREFUSED), true) ? 'errors' : 'warnings');
    setEventMessages(wailoMsg('WailoSyncResult_' . $result), null, $style);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/*
 * View
 */

$title = 'WailoSetup';
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-wailo page-admin');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"') . '<span class="hideonsmartphone">' . $langs->trans('BackToModuleList') . '</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = wailoAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'wailo@wailo');

print '<span class="opacitymedium">' . $langs->trans('WailoSetupIntro') . '</span><br><br>';

$storedKey = getDolGlobalString('WAILO_API_KEY');
$hasKey = $storedKey !== '';
$kind = $hasKey ? wailoKeyKind($storedKey) : '';
unset($storedKey);

// Connection settings
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" autocomplete="off">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('WailoApiKey') . '</td><td>';
// The key is never sent back to the browser: the field is always empty.
$placeholder = $hasKey ? $langs->trans('WailoKeyStored_' . $kind) : $langs->trans('WailoKeyNone');
print '<input type="password" name="wailo_api_key" value="" class="minwidth400" autocomplete="new-password" spellcheck="false" placeholder="' . dol_escape_htmltag($placeholder) . '">';
print '<br><span class="opacitymedium small">' . $langs->trans('WailoApiKeyHelp') . '</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('WailoApiUrl') . '</td><td>';
print '<input type="text" name="wailo_api_url" class="minwidth400" value="' . dol_escape_htmltag(getDolGlobalString('WAILO_API_URL', WailoSync::DEFAULT_API_URL)) . '">';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('WailoDirectory') . '</td><td>';
print '<input type="text" name="wailo_directory" class="minwidth200" maxlength="120" value="' . dol_escape_htmltag(getDolGlobalString('WAILO_DIRECTORY', WailoSync::DEFAULT_DIRECTORY)) . '">';
print '<br><span class="opacitymedium small">' . $langs->trans('WailoDirectoryHelp') . '</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans('WailoSyncNote') . '</td><td>';
print '<input type="checkbox" name="wailo_sync_note" value="1"' . (getDolGlobalInt('WAILO_SYNC_NOTE') ? ' checked' : '') . '>';
print '<br><span class="opacitymedium small">' . $langs->trans('WailoSyncNoteHelp', WailoMapper::ABOUT_MAX) . '</span>';
print '</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="' . $langs->trans('Save') . '"></div>';
print '</form>';

if ($hasKey) {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" class="center">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="deletekey">';
    print '<input type="submit" class="button button-cancel small" value="' . $langs->trans('WailoDeleteKey') . '">';
    print '</form>';
}

print '<br>';

// Actions: test, sync now, confirm
print load_fiche_titre($langs->trans('WailoSyncTitle'), '', '');
print '<div class="tabsAction" style="text-align: left">';
foreach (array('test' => 'WailoTestConnection', 'syncnow' => 'WailoSyncNow') as $act => $label) {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" style="display: inline-block">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="' . $act . '">';
    print '<input type="submit" class="butAction"' . ($hasKey ? '' : ' disabled') . ' value="' . dol_escape_htmltag($langs->trans($label)) . '">';
    print '</form>';
}
print '</div>';

$waiting = json_decode($sync->getState('waiting'), true);
if (is_array($waiting) && !empty($waiting['reason'])) {
    print '<div class="warning">' . $langs->trans('WailoWaiting_' . preg_replace('/[^a-z_]/', '', (string) $waiting['reason']), (int) $waiting['count']) . '</div>';
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="syncconfirm">';
    print '<input type="hidden" name="confirm_reason" value="' . dol_escape_htmltag(preg_replace('/[^a-z_]/', '', (string) $waiting['reason'])) . '">';
    print '<input type="hidden" name="confirm_hash" value="' . dol_escape_htmltag(preg_replace('/[^a-f0-9]/', '', (string) ($waiting['hash'] ?? ''))) . '">';
    print '<input type="submit" class="butActionDelete" value="' . dol_escape_htmltag($langs->trans('WailoSyncConfirm', (int) $waiting['count'])) . '">';
    print '</form><br>';
}

// Status
print load_fiche_titre($langs->trans('WailoStatusTitle'), '', '');
$last = json_decode($sync->getState('last_pass'), true);
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
if (is_array($last)) {
    $st = is_array($last['stats'] ?? null) ? $last['stats'] : array();
    print '<tr class="oddeven"><td>' . $langs->trans('WailoLastPass') . '</td><td>' . dol_print_date((int) $last['date'], 'dayhoursec', 'tzuser') . ' (' . ((float) $last['duration']) . ' s) : ' . $langs->trans('WailoSyncResult_' . preg_replace('/[^a-z]/', '', (string) $last['result'])) . '</td></tr>';
    print '<tr class="oddeven"><td>' . $langs->trans('WailoToWailo') . '</td><td>' . $langs->trans('WailoCounters', (int) ($st['wailo_created'] ?? 0), (int) ($st['wailo_updated'] ?? 0), (int) ($st['wailo_deleted'] ?? 0)) . '</td></tr>';
    print '<tr class="oddeven"><td>' . $langs->trans('WailoToDolibarr') . '</td><td>' . $langs->trans('WailoCounters', (int) ($st['doli_created'] ?? 0), (int) ($st['doli_updated'] ?? 0), (int) ($st['doli_deleted'] ?? 0)) . '</td></tr>';
    print '<tr class="oddeven"><td>' . $langs->trans('Errors') . '</td><td>' . ((int) ($st['errors'] ?? 0)) . '</td></tr>';
} else {
    print '<tr class="oddeven"><td>' . $langs->trans('WailoLastPass') . '</td><td>' . $langs->trans('WailoNeverRun') . '</td></tr>';
}
print '<tr class="oddeven"><td>' . $langs->trans('WailoLinkedContacts') . '</td><td>' . $sync->countLinks() . '</td></tr>';
$bootstrapped = $sync->getState('bootstrapped_at');
print '<tr class="oddeven"><td>' . $langs->trans('WailoBootstrappedAt') . '</td><td>' . ($bootstrapped !== '' ? dol_escape_htmltag($bootstrapped) . ' UTC' : '-') . ($sync->getState('is_sandbox') === '1' ? ' (' . $langs->trans('WailoSandboxYes') . ')' : '') . '</td></tr>';

// Scheduled job
$sql = 'SELECT rowid, status, datelastrun, datenextrun, lastresult FROM ' . $db->prefix() . "cronjob WHERE module_name = 'wailo' AND entity IN (0, " . ((int) $conf->entity) . ')';
$resql = $db->query($sql);
$cron = $resql ? $db->fetch_object($resql) : null;
print '<tr class="oddeven"><td>' . $langs->trans('WailoCronJob') . '</td><td>';
if (!isModEnabled('cron')) {
    print $langs->trans('WailoCronModuleOff');
} elseif ($cron) {
    print ($cron->status ? $langs->trans('Enabled') : $langs->trans('Disabled'));
    print ' - ' . $langs->trans('WailoCronLastRun') . ' ' . ($cron->datelastrun ? dol_print_date($db->jdate($cron->datelastrun), 'dayhour', 'tzuser') : '-');
    print ' - ' . $langs->trans('WailoCronNextRun') . ' ' . ($cron->datenextrun ? dol_print_date($db->jdate($cron->datenextrun), 'dayhour', 'tzuser') : '-');
    print ' - <a href="' . DOL_URL_ROOT . '/cron/card.php?id=' . ((int) $cron->rowid) . '">' . $langs->trans('WailoCronOpen') . '</a>';
} else {
    print '-';
}
print '<br><span class="opacitymedium small">' . $langs->trans('WailoCronHelp') . '</span>';
print '</td></tr>';
print '</table><br>';

// Quarantine: contacts refused by one side, not retried until they change
$quarantined = $sync->quarantined();
if ($quarantined) {
    print load_fiche_titre($langs->trans('WailoQuarantineTitle'), '', '');
    print '<span class="opacitymedium">' . $langs->trans('WailoQuarantineHelp') . '</span>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td class="nowraponall">' . $langs->trans('Date') . '</td><td>' . $langs->trans('Contact') . '</td><td>' . $langs->trans('Description') . '</td></tr>';
    foreach ($quarantined as $qkey => $q) {
        if (strpos($qkey, 'd:') === 0) {
            $label = '<a href="' . DOL_URL_ROOT . '/contact/card.php?id=' . ((int) substr($qkey, 2)) . '">Dolibarr ' . ((int) substr($qkey, 2)) . '</a>';
        } else {
            $label = 'Wailo ' . dol_escape_htmltag(substr($qkey, 2));
        }
        print '<tr class="oddeven"><td class="nowraponall">' . dol_print_date((int) ($q['date'] ?? 0), 'dayhour', 'tzuser') . '</td><td>' . $label . '</td><td>' . dol_escape_htmltag((string) ($q['reason'] ?? '')) . '</td></tr>';
    }
    print '</table><br>';
}

// Journal
print load_fiche_titre($langs->trans('WailoJournalTitle'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="nowraponall">' . $langs->trans('Date') . '</td><td>' . $langs->trans('Type') . '</td><td>' . $langs->trans('Description') . '</td></tr>';
$logs = $sync->lastLogs(40);
if (!$logs) {
    print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">' . $langs->trans('None') . '</span></td></tr>';
}
foreach ($logs as $l) {
    $cls = $l['severity'] === 'error' ? ' class="error"' : ($l['severity'] === 'warning' ? ' class="warning"' : '');
    print '<tr class="oddeven"><td class="nowraponall">' . dol_print_date($l['date'], 'dayhoursec', 'tzuser') . '</td>';
    print '<td' . $cls . '>' . dol_escape_htmltag($l['severity']) . '</td>';
    print '<td>' . dol_escape_htmltag(WailoSync::translateLog($langs, $l['code'], $l['params'])) . '</td></tr>';
}
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
