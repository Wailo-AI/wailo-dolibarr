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
 * \file    wailo/class/wailosync.class.php
 * \ingroup wailo
 * \brief   Two-way synchronisation of contacts between Dolibarr and Wailo.
 *
 * Order of a pass:
 *   0. bootstrap when there is no cursor: cursor of /changes read BEFORE the import of /contacts, kept in memory,
 *      saved only once the import is complete (an interrupted import starts again at the next pass);
 *   1. Dolibarr -> Wailo: contacts created, modified, deleted in Dolibarr (active public contacts only);
 *   2. Wailo -> Dolibarr: /changes feed from the saved cursor.
 * Safeguards (each needs a human confirmation bound to the exact set of contacts shown):
 *   - at most 5 deletions in Wailo per pass, at most 5 removals in Dolibarr per pass;
 *   - more than 50 operations sent to Wailo;
 *   - first sending of Dolibarr contacts after a change of enterprise or sandbox.
 * A contact removed in Wailo is never deleted from Dolibarr: it is made inactive, with a private note, and its link is
 * kept asleep. If the contact comes back in Wailo, the same Dolibarr contact is made active again (never a second one).
 * If a Dolibarr user makes it active again first, it is sent back to Wailo as a new contact.
 */

require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
dol_include_once('/wailo/class/wailoclient.class.php');
dol_include_once('/wailo/class/wailomapper.class.php');

/**
 * A Dolibarr write refused for one contact: the contact goes to quarantine, the pass goes on.
 */
class WailoContactException extends RuntimeException
{
}

/**
 * Synchronisation engine. Also the target of the scheduled job (method runScheduledSync).
 */
class WailoSync
{
    public const CONFIRMATION_THRESHOLD = 50;
    public const MAX_DELETES_PER_PASS = 5;
    public const LOG_KEEP = 2000;
    public const DEFAULT_API_URL = 'https://api.wailo.app/api/public/v1';
    public const DEFAULT_DIRECTORY = 'Clients';

    public const RESULT_OK = 'ok';
    public const RESULT_WAITING = 'waiting';
    public const RESULT_ERROR = 'error';
    public const RESULT_DEFERRED = 'deferred';
    public const RESULT_LOCKED = 'locked';
    public const RESULT_NOKEY = 'nokey';
    public const RESULT_KEYREFUSED = 'keyrefused';

    /** @var DoliDB */
    public $db;

    /** @var string */
    public $error = '';

    /** @var string[] */
    public $errors = array();

    /** @var string Output shown by the scheduled jobs screen */
    public $output = '';

    /** @var int */
    public $entity;

    /** @var WailoClient|null */
    public $client;

    /** @var User|null */
    private $user;

    /** @var array{reason:string,hash:string}|null Confirmation given by a human for one exact set */
    private $confirmation = null;

    /** @var bool Social networks managed (module "Social networks" of Dolibarr enabled) */
    private $withSocials = true;

    /** @var bool Public note synchronised (setting WAILO_SYNC_NOTE, off by default) */
    private $withNote = false;

    /** @var array<string,array<string,mixed>> wailo_id => link */
    private $links = array();

    /** @var array<string,mixed>|null third parties cache: 'id:N' => name, 'name:lower' => id */
    private $thirdparties = null;

    /** @var array<string,int> */
    public $stats = array();

    /** @var array{reason:string,count:int,hash:string}|null */
    public $waiting = null;

    /** @var bool A setting stops part of the pass (unknown directory): the pass ends in error */
    private $blocked = false;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->entity = (int) $conf->entity;
    }

    // ------------------------------------------------------------------ entry points

    /**
     * Scheduled job (module Cron). Never confirms anything: a pass that needs a human waits for one.
     *
     * @return int 0 if OK or no key, 1 on error, 2 when a human confirmation is awaited (shown as an error in the jobs list)
     */
    public function runScheduledSync()
    {
        global $user;
        $result = $this->runPass($user, null);
        if ($result === self::RESULT_WAITING) {
            $this->output = 'WAITING FOR A CONFIRMATION (Wailo setup page)' . "\n" . $this->output;
            $this->error = 'Waiting for a human confirmation on the Wailo setup page';
            return 2;
        }
        return in_array($result, array(self::RESULT_ERROR, self::RESULT_DEFERRED, self::RESULT_KEYREFUSED), true) ? 1 : 0;
    }

    /**
     * Run one synchronisation pass.
     *
     * @param User       $user         User doing the writes in Dolibarr
     * @param array|null $confirmation array('reason' => ..., 'hash' => ...) of the set shown to and confirmed by a human
     * @return string One of the RESULT_* constants
     */
    public function runPass($user, $confirmation = null)
    {
        global $langs;
        $langs->load('wailo@wailo');

        $this->user = $user;
        $this->confirmation = (is_array($confirmation) && !empty($confirmation['reason']) && !empty($confirmation['hash'])) ? $confirmation : null;
        $this->stats = array(
            'wailo_created' => 0, 'wailo_updated' => 0, 'wailo_deleted' => 0,
            'doli_created' => 0, 'doli_updated' => 0, 'doli_deleted' => 0, 'errors' => 0,
        );
        $this->waiting = null;
        $this->blocked = false;
        $this->thirdparties = null;
        $this->withSocials = isModEnabled('socialnetworks');
        $this->withNote = getDolGlobalInt('WAILO_SYNC_NOTE') > 0;
        $start = microtime(true);

        $key = getDolGlobalString('WAILO_API_KEY');
        if ($key === '') {
            $this->log('info', 'NoKey', array());
            return self::RESULT_NOKEY;
        }
        $url = getDolGlobalString('WAILO_API_URL', self::DEFAULT_API_URL);
        if (!WailoClient::isAllowedUrl($url, getDolGlobalInt('WAILO_ALLOW_ANY_HOST') > 0)) {
            $this->log('error', 'InvalidUrl', array());
            $this->saveLastPass(self::RESULT_ERROR, $start);
            return self::RESULT_ERROR;
        }
        if (!$this->client) {
            $this->client = new WailoClient($url, $key, array($this, 'log'));
            // In a web request, never wait minutes for Wailo: stop and resume at the next pass.
            $this->client->allowSleep = (PHP_SAPI === 'cli');
        }
        unset($key);

        try {
            $lock = $this->acquireLock();
        } catch (Throwable $e) {
            $this->log('error', 'Error', array(dol_trunc($e->getMessage(), 300)));
            return self::RESULT_ERROR;
        }
        if ($lock === null) {
            $this->log('info', 'PassSkippedLocked', array());
            return self::RESULT_LOCKED;
        }
        @set_time_limit(900);

        $result = self::RESULT_OK;
        try {
            $this->loadLinks();
            if ($this->getState('cursor') === '') {
                $this->bootstrap();
            }
            if ($this->getState('cursor') !== '') {
                $this->pushDolibarr();
                $this->pullWailo();
                if ($this->getState('cursor') === '') {
                    $this->bootstrap();
                }
            }
            if ($this->waiting !== null) {
                $result = self::RESULT_WAITING;
                $this->setState('waiting', json_encode($this->waiting));
            } else {
                $this->deleteState('waiting');
            }
            if ($this->blocked) {
                $result = self::RESULT_ERROR;
            }
            if ($result === self::RESULT_OK) {
                $this->log('info', 'PassOk', array(count(array_filter($this->links, function ($l) {
                    return $l['removed'] === null;
                }))));
            }
        } catch (WailoRateLimitedException $e) {
            $result = self::RESULT_DEFERRED;
        } catch (WailoKeyRefusedException $e) {
            $result = self::RESULT_KEYREFUSED;
            $this->stats['errors']++;
            $this->error = $e->getMessage();
            $this->log('error', 'KeyRefused', array());
        } catch (Throwable $e) {
            $result = self::RESULT_ERROR;
            $this->stats['errors']++;
            $this->error = $e->getMessage();
            $this->log('error', 'Error', array(dol_trunc($e->getMessage(), 300)));
        }
        $this->saveLastPass($result, $start);
        $this->pruneLog();
        $this->releaseLock($lock);
        return $result;
    }

    /**
     * Test the connection: GET /me. Returns the useful fields, never the key.
     *
     * @return array{ok:bool,status:int,code:string,enterprise:string,enterprise_status:string,contacts_count:int,sandbox:bool,abilities:string}
     */
    public function testConnection()
    {
        $out = array('ok' => false, 'status' => 0, 'code' => '', 'enterprise' => '', 'enterprise_status' => '', 'contacts_count' => 0, 'sandbox' => false, 'abilities' => '');
        $key = getDolGlobalString('WAILO_API_KEY');
        $url = getDolGlobalString('WAILO_API_URL', self::DEFAULT_API_URL);
        if ($key === '') {
            $out['code'] = 'no_key';
            return $out;
        }
        if (!WailoClient::isAllowedUrl($url, getDolGlobalInt('WAILO_ALLOW_ANY_HOST') > 0)) {
            $out['code'] = 'invalid_url';
            return $out;
        }
        $client = $this->client ? $this->client : new WailoClient($url, $key, array($this, 'log'));
        $client->allowSleep = false;
        unset($key);
        try {
            $r = $client->request('GET', '/me');
        } catch (WailoKeyRefusedException $e) {
            $out['status'] = 401;
            $out['code'] = 'unauthenticated';
            return $out;
        } catch (Throwable $e) {
            $out['code'] = 'network';
            $this->log('error', 'Error', array(dol_trunc($e->getMessage(), 300)));
            return $out;
        }
        $out['status'] = $r['status'];
        if ($r['status'] !== 200 || !isset($r['json']['data']['enterprise'])) {
            $out['code'] = (string) ($r['json']['error']['code'] ?? 'http_' . $r['status']);
            return $out;
        }
        $d = $r['json']['data'];
        $out['ok'] = true;
        $out['enterprise'] = (string) ($d['enterprise']['name'] ?? '');
        $out['enterprise_status'] = (string) ($d['enterprise']['status'] ?? '');
        $out['contacts_count'] = (int) ($d['enterprise']['contacts_count'] ?? 0);
        $out['sandbox'] = !empty($d['key']['is_sandbox']);
        $out['abilities'] = implode(', ', array_map('strval', (array) ($d['key']['abilities'] ?? array())));
        return $out;
    }

    /**
     * Forget every link, the cursor and the flags (the API key now opens another enterprise or the sandbox).
     * Contacts are not touched on either side. Must be called with the lock held (see acquireLock).
     *
     * @return void
     */
    public function resetLinks()
    {
        $this->db->query('DELETE FROM ' . $this->db->prefix() . 'wailo_link WHERE entity = ' . ((int) $this->entity));
        $this->db->query('DELETE FROM ' . $this->db->prefix() . 'wailo_state WHERE entity = ' . ((int) $this->entity)
            . " AND name NOT IN ('install_id', 'last_pass')");
        $this->links = array();
        $this->setState('confirm_first_push', '1');
    }

    // ------------------------------------------------------------------ confirmations

    /**
     * Short fingerprint of a set of ids (order does not matter).
     *
     * @param array<int,string|int> $ids Ids
     * @return string
     */
    public static function hashIds(array $ids)
    {
        $ids = array_map('strval', $ids);
        sort($ids, SORT_STRING);
        return substr(hash('sha256', implode(',', $ids)), 0, 16);
    }

    /**
     * May this set of operations go on? Yes under the limit, or when a human confirmed exactly this set.
     * Otherwise the set is recorded as waiting for a confirmation (the first one of the pass is shown).
     *
     * @param string                $reason   first_push|too_many|deletes|removals
     * @param array<int,string|int> $ids      The set
     * @param bool                  $overLimit Is the set over the limit?
     * @return bool
     */
    private function allowed($reason, array $ids, $overLimit)
    {
        if (!$overLimit || !$ids) {
            return true;
        }
        $hash = self::hashIds($ids);
        if ($this->confirmation && $this->confirmation['reason'] === $reason && hash_equals((string) $this->confirmation['hash'], $hash)) {
            $this->log('info', 'Confirmed', array($reason, count($ids)));
            return true;
        }
        if ($this->waiting === null) {
            $this->waiting = array('reason' => $reason, 'count' => count($ids), 'hash' => $hash);
        }
        $this->log('warning', 'NeedsConfirm_' . $reason, array(count($ids)));
        return false;
    }

    // ------------------------------------------------------------------ bootstrap

    /**
     * Bootstrap: check the key, read the cursor of /changes BEFORE the import (kept in memory), import all
     * contacts, pair existing Dolibarr contacts (same e-mail, or same mobile on 9 digits) without overwriting them,
     * create the others; save the cursor only when everything is done.
     * Reading is never subject to a confirmation (Wailo guide, rule 8).
     *
     * @return void
     * @throws RuntimeException
     */
    private function bootstrap()
    {
        $me = $this->client->request('GET', '/me');
        if ($me['status'] !== 200) {
            throw new RuntimeException('GET /me: ' . $me['status']);
        }
        $d = $me['json']['data'];
        $expected = (int) $d['enterprise']['contacts_count'];
        $this->log('info', 'BootstrapStart', array((string) $d['enterprise']['status'], $expected, !empty($d['key']['is_sandbox']) ? 'yes' : 'no'));
        $ch = $this->client->request('GET', '/changes');
        $cursor = (string) ($ch['json']['next_cursor'] ?? '');
        if ($ch['status'] !== 200 || $cursor === '') {
            throw new RuntimeException('GET /changes (bootstrap): ' . $ch['status']);
        }

        $read = array();
        $next = null;
        do {
            $r = $this->client->request('GET', '/contacts?limit=200' . ($next ? '&cursor=' . rawurlencode($next) : ''));
            if ($r['status'] !== 200) {
                throw new RuntimeException('GET /contacts: ' . $r['status']);
            }
            foreach ($r['json']['data'] as $c) {
                $read[(string) $c['id']] = $c;
            }
            $next = $r['json']['next_cursor'];
        } while (!empty($r['json']['has_more']));
        $this->log('info', 'BootstrapRead', array(count($read), $expected));
        if (count($read) !== $expected) {
            throw new RuntimeException('incomplete import: ' . count($read) . ' read for ' . $expected . ' expected');
        }

        $doli = $this->listDolibarr(null, false, false);
        $linkedDoli = $this->indexDoliToWailo();
        $byMail = array();
        $byPhone = array();
        foreach ($doli as $id => $dc) {
            if (isset($linkedDoli[$id])) {
                continue; // already linked to another Wailo contact: never link it twice
            }
            if (WailoMapper::txt($dc['email']) !== null) {
                $byMail[mb_strtolower(trim($dc['email']))] = $id;
            }
            if (WailoMapper::txt($dc['phone_mobile']) !== null) {
                $byPhone[WailoMapper::digits9($dc['phone_mobile'])] = $id;
            }
        }
        foreach ($read as $c) {
            $pairWith = null;
            if (!isset($this->links[$c['id']])) {
                foreach ($c['emails'] ?? array() as $e) {
                    $k = mb_strtolower((string) $e['value']);
                    if ($pairWith === null && isset($byMail[$k]) && !isset($linkedDoli[$byMail[$k]])) {
                        $pairWith = $byMail[$k];
                    }
                }
                foreach ($c['phones'] ?? array() as $p) {
                    $k = WailoMapper::digits9($p['value']);
                    if ($pairWith === null && $k !== '' && isset($byPhone[$k]) && !isset($linkedDoli[$byPhone[$k]])) {
                        $pairWith = $byPhone[$k];
                    }
                }
                if ($pairWith !== null) {
                    $linkedDoli[$pairWith] = $c['id'];
                }
            }
            try {
                $this->applyWailo($c, $pairWith);
            } catch (WailoContactException $e) {
                $this->quarantine('w:' . $c['id'], $e->getMessage(), (string) $c['etag']);
            }
        }

        // Links whose Wailo contact is gone (left while the change feed was expired): removals, with the same limit.
        $orphans = array();
        foreach (array_keys($this->links) as $wid) {
            if (!isset($read[$wid]) && $this->isAwake((string) $wid)) {
                $orphans[] = (string) $wid;
            }
        }
        if ($orphans) {
            $pending = json_decode($this->getState('pending_removals', '[]'), true);
            $this->setState('pending_removals', json_encode(array_values(array_unique(array_merge(is_array($pending) ? $pending : array(), $orphans)))));
            $this->log('warning', 'OrphanLinks', array(count($orphans)));
        }

        // Only now: the import is complete.
        $this->setState('cursor', $cursor);
        $this->setState('bootstrapped_at', dol_print_date(dol_now(), 'standard', 'gmt'));
        $this->setState('enterprise_id', (string) ($d['enterprise']['id'] ?? ''));
        $this->setState('is_sandbox', !empty($d['key']['is_sandbox']) ? '1' : '0');
        $this->log('info', 'BootstrapDone', array(count(array_filter($this->links, function ($l) {
            return $l['removed'] === null;
        }))));
    }

    // ------------------------------------------------------------------ 1. Dolibarr -> Wailo

    /**
     * Send to Wailo the contacts created, modified and deleted in Dolibarr since the last pass.
     *
     * @return void
     */
    private function pushDolibarr()
    {
        $doli = $this->listDolibarr(null, false, true);
        $ix = $this->indexDoliToWailo();

        $toCreate = array();
        $toUpdate = array();
        $missing = array();
        foreach ($doli as $id => $d) {
            if (!isset($ix[$id])) {
                $view = $this->viewOf($d, null);
                if ($this->getState('skip:d' . $id) === self::hashIds(array(json_encode($view)))) {
                    continue; // refused or without name, unchanged since: not sent again
                }
                $toCreate[] = $id;
                continue;
            }
            $wid = $ix[$id];
            $link = $this->links[$wid];
            if ($link['removed'] !== null) {
                // Removed in Wailo, then made active again by a Dolibarr user (only active contacts are listed here):
                // Dolibarr says « this contact is ours » (decision of Vincent, 20/11/2026). The asleep link is
                // forgotten and the contact is sent again as a new one, with the usual limits and confirmations.
                $this->log('info', 'AsleepReactivatedInDolibarr', array($wid, $id));
                $this->deleteLink($wid);
                unset($ix[$id]);
                $toCreate[] = $id;
                continue;
            }
            $view = $this->viewOf($d, $link['placeholder']);
            if (WailoMapper::viewChanged($link['view'], $view)) {
                if ($this->getState('skip:w' . $wid) === self::hashIds(array(json_encode($view)))) {
                    continue;
                }
                $toUpdate[] = $wid;
            }
        }
        foreach ($ix as $doliId => $wid) {
            if (!isset($doli[$doliId])) {
                $missing[$doliId] = $wid;
            }
        }
        // A linked contact can be absent from the list because it became private or inactive:
        // only a contact really gone from llx_socpeople is a deletion. Refusals of Wailo are not retried.
        $toDelete = array();
        $stillThere = $this->existingContactIds(array_keys($missing));
        foreach ($missing as $doliId => $wid) {
            if (isset($stillThere[$doliId])) {
                continue;
            }
            if ($this->links[$wid]['removed'] !== null) {
                // Removed in Wailo, then deleted in Dolibarr: the link is forgotten, nothing is sent to Wailo.
                $this->log('info', 'AsleepLinkForgotten', array($wid, $doliId));
                $this->deleteLink($wid);
                continue;
            }
            if ($this->getState('refused:' . $wid) === '') {
                $toDelete[] = $wid;
            }
        }

        // First sending after a change of enterprise or sandbox: never without a human.
        if ($this->getState('confirm_first_push') === '1') {
            if (!$toCreate || $this->allowed('first_push', $toCreate, true)) {
                $this->deleteState('confirm_first_push');
            } else {
                $toCreate = array();
            }
        }

        $ops = array();
        foreach ($toCreate as $id) {
            $ops[] = 'c:' . $id;
        }
        foreach ($toUpdate as $wid) {
            $ops[] = 'u:' . $wid;
        }
        foreach ($toDelete as $wid) {
            $ops[] = 'd:' . $wid;
        }
        if (!$this->allowed('too_many', $ops, count($ops) > self::CONFIRMATION_THRESHOLD)) {
            return;
        }
        if (!$this->allowed('deletes', $toDelete, count($toDelete) > self::MAX_DELETES_PER_PASS)) {
            $toDelete = array();
        }

        $this->createInWailo($toCreate, $doli);
        $this->updateInWailo($toUpdate, $doli);
        $this->deleteInWailo($toDelete);
    }

    /**
     * Create in Wailo the new Dolibarr contacts, always in the configured directory.
     *
     * @param int[]                          $ids  Dolibarr contact ids
     * @param array<int,array<string,mixed>> $doli Dolibarr contacts
     * @return void
     */
    private function createInWailo(array $ids, array $doli)
    {
        if (!$ids) {
            return;
        }
        $directoryId = null;
        $directory = getDolGlobalString('WAILO_DIRECTORY', self::DEFAULT_DIRECTORY);
        $r = $this->client->request('GET', '/directories');
        if ($r['status'] !== 200) {
            return;
        }
        foreach ($r['json']['data'] ?? array() as $rep) {
            if (mb_strtolower((string) $rep['name']) === mb_strtolower($directory)) {
                $directoryId = $rep['id'];
            }
        }
        if ($directoryId === null) {
            // Never create a contact outside the directory chosen by the administrator.
            $this->log('error', 'DirectoryNotFound', array($directory));
            $this->blocked = true;
            return;
        }
        $installId = $this->installId();
        foreach ($ids as $doliId) {
            $view = $this->viewOf($doli[$doliId], null);
            $sig = self::hashIds(array(json_encode($view)));
            if ($view['first_name'] === null && $view['last_name'] === null && $view['company'] === null) {
                $this->log('info', 'D2WIgnoredNoName', array($doliId));
                $this->setState('skip:d' . $doliId, $sig);
                continue;
            }
            $body = WailoMapper::createBody($view);
            $body['directory_ids'] = array($directoryId);
            $idemKey = 'dolibarr-' . $installId . '-contact-' . $doliId . ':' . hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $r = $this->client->request('POST', '/contacts', $body, array('Idempotency-Key' => $idemKey));
            $code = (string) ($r['json']['error']['code'] ?? '');
            if ($r['status'] === 201) {
                $wc = $r['json']['data'];
                $this->stats['wailo_created']++;
                $this->log('info', 'D2WCreated', array($wc['id'], $doliId));
            } elseif ($r['status'] === 409 && $code === 'duplicate_contact') {
                $existing = (string) $r['json']['error']['details']['existing_id'];
                $g = $this->client->request('GET', '/contacts/' . rawurlencode($existing));
                if ($g['status'] !== 200) {
                    continue;
                }
                $wc = $g['json']['data'];
                if (isset($this->links[$wc['id']])) {
                    $this->log('warning', 'D2WDuplicateOfLinked', array($doliId, $this->links[$wc['id']]['doli_id']));
                    $this->quarantine('d:' . $doliId, 'duplicate_contact', $sig);
                    $this->setState('skip:d' . $doliId, $sig);
                    continue;
                }
                $this->log('info', 'D2WDuplicateLinked', array($doliId, $existing));
            } else {
                $this->stats['errors']++;
                if ($r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 429) {
                    // Refused by Wailo (422...): not sent again until the contact changes in Dolibarr.
                    $this->quarantine('d:' . $doliId, $code !== '' ? $code : 'http_' . $r['status'], $sig);
                    $this->setState('skip:d' . $doliId, $sig);
                }
                continue;
            }
            $this->links[$wc['id']] = array(
                'rowid' => 0,
                'doli_id' => (int) $doliId,
                'etag' => $wc['etag'],
                'placeholder' => null,
                'digits' => WailoMapper::slotDigits($wc),
                'view' => $view,
                'wview' => WailoMapper::wailoView($wc, $this->withSocials, $this->withNote),
                'removed' => null,
            );
            $this->saveLink($wc['id']);
            $this->deleteState('skip:d' . $doliId);
            $this->unquarantine('d:' . $doliId);
        }
    }

    /**
     * Send to Wailo the fields modified in Dolibarr (PATCH with If-Match, retried on 412).
     * The stored view moves forward only when Wailo accepted the change.
     *
     * @param string[]                       $wids Wailo ids
     * @param array<int,array<string,mixed>> $doli Dolibarr contacts
     * @return void
     */
    private function updateInWailo(array $wids, array $doli)
    {
        foreach ($wids as $wid) {
            $link = $this->links[$wid];
            $d = $doli[(int) $link['doli_id']];
            $after = $this->viewOf($d, $link['placeholder']);
            $sig = self::hashIds(array(json_encode($after)));
            $g = $this->client->request('GET', '/contacts/' . rawurlencode($wid)); // read just before: fresh etag, full lists
            if ($g['status'] !== 200) {
                continue;
            }
            $wc = $g['json']['data'];
            if (!empty($wc['is_livecard'])) {
                // A LiveCard copy is kept up to date by its owner: the Dolibarr change is ignored on purpose.
                $this->log('info', 'D2WLivecardIgnored', array($wid));
                $this->links[$wid]['view'] = $after;
                $this->saveLink($wid);
                continue;
            }
            $accepted = false;
            for ($try = 0; $try < 3; $try++) {
                $body = WailoMapper::patchBody($link['view'], $after, $wc, $link['placeholder'], $link['digits']);
                if (!$body) {
                    $accepted = true;
                    break;
                }
                $r = $this->client->request('PATCH', '/contacts/' . rawurlencode($wid), $body, array('If-Match' => $wc['etag']));
                if ($r['status'] === 200) {
                    $wc = $r['json']['data'];
                    $accepted = true;
                    $this->stats['wailo_updated']++;
                    $this->log('info', 'D2WUpdated', array($wid, implode(',', array_keys($body))));
                    break;
                }
                if ($r['status'] === 412 && isset($r['json']['error']['details']['contact'])) {
                    $wc = $r['json']['error']['details']['contact']; // apply again on the up to date contact
                    continue;
                }
                $this->stats['errors']++;
                if ($r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 429) {
                    $this->quarantine('w:' . $wid, (string) ($r['json']['error']['code'] ?? 'http_' . $r['status']), $sig);
                    $this->setState('skip:w' . $wid, $sig);
                }
                break;
            }
            if (!$accepted) {
                continue; // old view kept: the change is still pending
            }
            $this->links[$wid]['etag'] = $wc['etag'];
            $this->links[$wid]['view'] = $after;
            $this->links[$wid]['wview'] = WailoMapper::wailoView($wc, $this->withSocials, $this->withNote);
            $this->links[$wid]['digits'] = WailoMapper::slotDigits($wc);
            $this->saveLink($wid);
            $this->deleteState('skip:w' . $wid);
            $this->unquarantine('w:' . $wid);
        }
    }

    /**
     * Contacts deleted by the user in Dolibarr: move them to the Wailo trash, one at a time, with If-Match.
     *
     * @param string[] $wids Wailo ids
     * @return void
     */
    private function deleteInWailo(array $wids)
    {
        foreach ($wids as $wid) {
            $g = $this->client->request('GET', '/contacts/' . rawurlencode($wid));
            if ($g['status'] === 404) {
                $this->deleteLink($wid);
                continue;
            }
            if ($g['status'] !== 200) {
                continue;
            }
            $r = $this->client->request('DELETE', '/contacts/' . rawurlencode($wid), null, array('If-Match' => $g['json']['data']['etag']));
            $code = (string) ($r['json']['error']['code'] ?? '');
            if ($r['status'] === 204) {
                $this->stats['wailo_deleted']++;
                $this->log('info', 'D2WDeleted', array($wid));
                $this->deleteLink($wid);
            } elseif ($code === 'delete_limit_reached') {
                break;
            } elseif ($code === 'owned_by_member') {
                // Never retried, outside the per-pass limit, shown in quarantine for a human.
                $this->log('warning', 'D2WOwnedByMember', array($wid));
                $this->setState('refused:' . $wid, $code);
                $this->quarantine('w:' . $wid, $code, '');
            } else {
                $this->stats['errors']++;
            }
        }
    }

    // ------------------------------------------------------------------ 2. Wailo -> Dolibarr

    /**
     * Read the /changes feed from the saved cursor and apply each line to Dolibarr.
     * Removals (and links left orphan by a re-import) are capped at 5 per pass without a confirmation.
     *
     * @return void
     * @throws RuntimeException
     */
    private function pullWailo()
    {
        // Read the pages first (reading does not move the saved cursor), to know the whole set of removals.
        $pages = array();
        $cursor = $this->getState('cursor');
        for ($round = 0; $round < 50; $round++) {
            $r = $this->client->request('GET', '/changes?cursor=' . rawurlencode($cursor));
            if ($r['status'] === 410) {
                $this->log('warning', 'CursorExpired', array());
                $this->deleteState('cursor');
                return;
            }
            if ($r['status'] !== 200) {
                throw new RuntimeException('GET /changes: ' . $r['status']);
            }
            $pages[] = $r['json'];
            $cursor = (string) $r['json']['next_cursor'];
            if (empty($r['json']['has_more'])) {
                break;
            }
        }
        $pending = json_decode($this->getState('pending_removals', '[]'), true);
        $pending = is_array($pending) ? $pending : array();
        $removals = array();
        foreach ($pending as $wid) {
            if ($this->isAwake($wid)) {
                $removals[$wid] = true;
            }
        }
        foreach ($pages as $page) {
            foreach ($page['data'] as $line) {
                if ($line['type'] === 'contact.removed' && $this->isAwake((string) $line['contact_id'])) {
                    $removals[(string) $line['contact_id']] = true;
                }
            }
        }
        $removalsAllowed = $this->allowed('removals', array_keys($removals), count($removals) > self::MAX_DELETES_PER_PASS);

        if ($removalsAllowed) {
            foreach ($pending as $wid) {
                if ($this->isAwake($wid)) {
                    $this->removeFromWailo($wid);
                }
            }
            $this->deleteState('pending_removals');
        }
        foreach ($pages as $page) {
            foreach ($page['data'] as $line) {
                $wid = (string) $line['contact_id'];
                if ($line['type'] === 'contact.removed') {
                    if ($this->isAwake($wid)) { // already asleep: nothing to do again (no second note)
                        if (!$removalsAllowed) {
                            return; // stop here: this page will be read again after the confirmation
                        }
                        $this->removeFromWailo($wid);
                    }
                    continue;
                }
                // contact.created / contact.updated (is_mine ignored): read and apply
                $g = $this->client->request('GET', '/contacts/' . rawurlencode($wid));
                if ($g['status'] === 404) {
                    continue; // contact_not_found: next line, nothing deleted
                }
                if ($g['status'] !== 200) {
                    throw new RuntimeException('GET /contacts/{id}: ' . $g['status']);
                }
                try {
                    $this->applyWailo($g['json']['data'], null);
                } catch (WailoContactException $e) {
                    $this->quarantine('w:' . $wid, $e->getMessage(), (string) $g['json']['data']['etag']);
                    if (isset($this->links[$wid]) && $this->links[$wid]['removed'] !== null) {
                        $pr = json_decode($this->getState('pending_returns', '[]'), true);
                        $pr = is_array($pr) ? $pr : array();
                        $pr[] = $wid;
                        $this->setState('pending_returns', json_encode(array_values(array_unique($pr))));
                    }
                }
            }
            $this->setState('cursor', (string) $page['next_cursor']);
        }
        $this->retryPendingReturns();
    }

    /**
     * A Wailo contact left the enterprise: the Dolibarr contact is made inactive with a private note, never deleted.
     * The link is kept asleep: if the contact comes back in Wailo, the same Dolibarr contact is used again.
     *
     * @param string $wid Wailo id
     * @return void
     */
    private function removeFromWailo($wid)
    {
        $doliId = (int) $this->links[$wid]['doli_id'];
        try {
            $done = $this->deactivateDolibarrContact($doliId);
            if ($done === 0) {
                $this->deleteLink($wid); // the Dolibarr contact is already gone: nothing to keep
                return;
            }
            $this->stats['doli_deleted']++;
            $this->log('info', 'W2DDeactivated', array($wid, $doliId));
            $this->links[$wid]['removed'] = dol_now();
            $this->links[$wid]['bysync'] = ($done === 1); // made inactive by the sync (not already inactive by choice)
            $this->saveLink($wid);
            // Leftovers of before the removal must not block anything after a return.
            $this->unquarantine('w:' . $wid);
            $this->deleteState('skip:w' . $wid);
        } catch (WailoContactException $e) {
            $this->stats['errors']++;
            $this->quarantine('w:' . $wid, $e->getMessage(), '');
        }
    }

    /**
     * Apply a Wailo contact on Dolibarr.
     * - Linked contact: only the fields changed on the Wailo side since the last synchronisation are written
     *   (a change made in Dolibarr and not sent yet is kept). A link without Dolibarr contact is a deletion
     *   waiting to be sent: the contact is never created again.
     * - Pairing (bootstrap): only the EMPTY Dolibarr fields are filled, the third party never changes; what only
     *   Dolibarr knows goes to Wailo through the normal sending (view set to what Wailo has).
     * - Otherwise: creation.
     *
     * @param array<string,mixed> $wc       Wailo contact
     * @param int|null            $pairWith Dolibarr contact to pair with (bootstrap)
     * @return void
     * @throws WailoContactException|RuntimeException
     */
    private function applyWailo(array $wc, $pairWith)
    {
        $wid = (string) $wc['id'];
        $placeholder = WailoMapper::placeholder($wc);
        $wview = WailoMapper::wailoView($wc, $this->withSocials, $this->withNote);
        $link = $this->links[$wid] ?? null;

        if (!$link && $pairWith === null) {
            // Back in Wailo under ANOTHER id (created again instead of restored): same e-mail or mobile as exactly one
            // asleep link: its Dolibarr contact is used again, never a second one.
            $old = $this->asleepMatch($wc);
            if ($old !== null) {
                $moved = $this->links[$old];
                $this->deleteLink($old);
                $moved['rowid'] = 0;
                $this->links[$wid] = $moved;
                $this->saveLink($wid);
                $this->log('info', 'W2DBackNewId', array($wid, $old, $moved['doli_id']));
                $link = $moved;
            }
        }

        if ($link && $link['removed'] !== null) {
            // Removed in Wailo, now back: the same Dolibarr contact is made active again, then updated like any linked
            // contact (only the fields changed in Wailo since it was removed). A field changed on both sides while
            // asleep takes the Wailo value (nothing was sent from Dolibarr meanwhile); the journal lists the fields.
            if ($this->readDolibarr((int) $link['doli_id']) === null) {
                $this->deleteLink($wid); // deleted in Dolibarr meanwhile: created again below, like a new contact
                $link = null;
            } else {
                $this->reactivateDolibarrContact((int) $link['doli_id'], !empty($link['bysync']));
                $this->log('info', !empty($link['bysync']) ? 'W2DReactivated' : 'W2DBackStillInactive', array($wid, $link['doli_id']));
                $this->links[$wid]['removed'] = null;
                $link['removed'] = null;
                $this->saveLink($wid);
            }
        }

        if ($link) {
            $d = $this->readDolibarr((int) $link['doli_id']);
            if ($d === null) {
                $this->log('info', 'PendingDeletion', array($wid, $link['doli_id']));
                return;
            }
            // Fields changed in Wailo since the last Wailo state seen (three-way: a field that differs only because
            // Dolibarr changed it, or because Dolibarr kept its own value at pairing, is left alone).
            $base = $link['wview'] ? $link['wview'] : $link['view'];
            $changed = array();
            foreach ($wview as $f => $v) {
                if (!WailoMapper::sameValue($f, $base[$f] ?? null, $v)) {
                    $changed[] = $f;
                }
            }
            if ($changed) {
                $fields = $this->fieldsFor($changed, $wc);
                $diff = $this->diffFields($d, $fields);
                if ($diff) {
                    $this->updateDolibarrContact((int) $d['id'], $diff);
                    $this->stats['doli_updated']++;
                    $this->log('info', 'W2DUpdated', array($d['id'], implode(',', array_keys($diff))));
                }
                $reread = $this->readDolibarr((int) $d['id']);
                $newView = $this->viewOf($reread ? $reread : $d, $placeholder);
                $view = $link['view'];
                foreach ($changed as $f) {
                    $view[$f] = $newView[$f] ?? null;
                }
                foreach ($wview as $f => $v) {
                    if (!array_key_exists($f, $view)) {
                        $view[$f] = $newView[$f] ?? null;
                    }
                }
                $this->links[$wid]['view'] = $view;
            }
            $this->links[$wid]['etag'] = $wc['etag'];
            $this->links[$wid]['placeholder'] = $placeholder;
            $this->links[$wid]['digits'] = WailoMapper::slotDigits($wc);
            $this->links[$wid]['wview'] = $wview;
            $this->saveLink($wid);
            $this->unquarantine('w:' . $wid);
            return;
        }

        if ($pairWith !== null) {
            $d = $this->readDolibarr((int) $pairWith);
            $all = WailoMapper::wailoToDoli($wc, null, $this->withSocials, $this->withNote);
            $fill = array();
            foreach ($all as $k => $v) {
                if ($k === 'socialnetworks') {
                    $cur = is_array($d['socialnetworks']) ? $d['socialnetworks'] : array();
                    $add = $cur;
                    foreach ($v as $sk => $sv) {
                        if ((string) $sv !== '' && (string) ($cur[$sk] ?? '') === '') {
                            $add[$sk] = $sv;
                        }
                    }
                    if ($add !== $cur) {
                        $fill[$k] = $add;
                    }
                } elseif ($k === 'lastname') {
                    continue; // Dolibarr always has a last name: never replaced at pairing
                } elseif ((string) $v !== '' && trim((string) ($d[$k] ?? '')) === '') {
                    $fill[$k] = $v; // only an empty Dolibarr field is filled, never '' written on a value
                }
            }
            if ($fill) {
                $this->updateDolibarrContact((int) $d['id'], $fill);
                $this->stats['doli_updated']++;
                $this->log('info', 'W2DPaired', array($d['id'], $wid, implode(',', array_keys($fill))));
            } else {
                $this->log('info', 'W2DPaired', array($d['id'], $wid, '-'));
            }
            $reread = $this->readDolibarr((int) $d['id']);
            $dview = $this->viewOf($reread ? $reread : $d, $placeholder);
            // View = what Wailo has: fields only Dolibarr has will be sent by the next sending (normal limits apply).
            foreach ($dview as $f => $v) {
                if (($wview[$f] ?? null) === null) {
                    $dview[$f] = null;
                }
            }
            $this->links[$wid] = array('rowid' => 0, 'doli_id' => (int) $d['id'], 'etag' => $wc['etag'], 'placeholder' => $placeholder,
                'digits' => WailoMapper::slotDigits($wc), 'view' => $dview, 'wview' => $wview, 'removed' => null);
            $this->saveLink($wid);
            $this->unquarantine('w:' . $wid);
            return;
        }

        $fields = WailoMapper::wailoToDoli($wc, $this->thirdpartyForCompany(WailoMapper::txt($wc['company'] ?? null)), $this->withSocials, $this->withNote);
        $doliId = $this->createDolibarrContact($fields);
        $this->stats['doli_created']++;
        $this->log('info', 'W2DCreated', array($doliId, $wid));
        $reread = $this->readDolibarr($doliId);
        $this->links[$wid] = array('rowid' => 0, 'doli_id' => $doliId, 'etag' => $wc['etag'], 'placeholder' => $placeholder,
            'digits' => WailoMapper::slotDigits($wc), 'view' => $this->viewOf($reread ? $reread : array(), $placeholder), 'wview' => $wview, 'removed' => null);
        $this->saveLink($wid);
        $this->unquarantine('w:' . $wid);
    }

    /**
     * Dolibarr fields for some view fields of a Wailo contact.
     *
     * @param string[]            $viewFields View fields changed in Wailo
     * @param array<string,mixed> $wc         Wailo contact
     * @return array<string,mixed>
     */
    private function fieldsFor(array $viewFields, array $wc)
    {
        $needsCompany = in_array('company', $viewFields, true);
        $socid = $needsCompany ? $this->thirdpartyForCompany(WailoMapper::txt($wc['company'] ?? null)) : null;
        $all = WailoMapper::wailoToDoli($wc, $socid, $this->withSocials, $this->withNote);
        $map = array('first_name' => 'firstname', 'last_name' => 'lastname', 'company' => 'socid', 'job_title' => 'poste',
            'email' => 'email', 'mobile' => 'phone_mobile', 'pro' => 'phone_pro', 'address' => 'address', 'about' => 'note_public');
        $fields = array();
        foreach ($viewFields as $f) {
            if (isset($map[$f])) {
                if (array_key_exists($map[$f], $all)) {
                    $fields[$map[$f]] = $all[$map[$f]];
                }
            } elseif (in_array($f, array('linkedin', 'facebook', 'instagram'), true) && isset($all['socialnetworks'])) {
                $fields['socialnetworks'][$f] = $all['socialnetworks'][$f];
            }
        }
        return $fields;
    }

    /**
     * Fields that really differ from the current Dolibarr contact.
     *
     * @param array<string,mixed> $d      Dolibarr contact
     * @param array<string,mixed> $fields Fields to write
     * @return array<string,mixed>
     */
    private function diffFields(array $d, array $fields)
    {
        $diff = array();
        foreach ($fields as $k => $v) {
            $current = $d[$k] ?? null;
            if ($k === 'socialnetworks') {
                $current = is_array($current) ? $current : array();
                foreach ($v as $sk => $sv) {
                    if ((string) ($current[$sk] ?? '') !== (string) $sv) {
                        $diff[$k] = $v;
                    }
                }
            } elseif ((string) $current !== (string) $v) {
                $diff[$k] = $v;
            }
        }
        return $diff;
    }

    /**
     * View of a Dolibarr contact with the current options.
     *
     * @param array<string,mixed> $d           Dolibarr contact
     * @param string|null         $placeholder Placeholder of the last name
     * @return array<string,?string>
     */
    private function viewOf(array $d, $placeholder)
    {
        return WailoMapper::viewDoli($d, $placeholder, $this->thirdpartyName($d['socid'] ?? null), $this->withSocials, $this->withNote);
    }

    // ------------------------------------------------------------------ Dolibarr side

    /**
     * Contacts of the current entity.
     *
     * @param int|null $onlyId      Read only this contact
     * @param bool     $alsoPrivate Also private contacts (an already linked contact made private by its owner)
     * @param bool     $activeOnly  Only active contacts (inactive contacts are never sent to Wailo)
     * @return array<int,array<string,mixed>>
     * @throws RuntimeException
     */
    private function listDolibarr($onlyId = null, $alsoPrivate = false, $activeOnly = false)
    {
        $sql = 'SELECT rowid, lastname, firstname, poste, email, phone, phone_mobile, address, note_public, fk_soc, socialnetworks, statut';
        $sql .= ' FROM ' . $this->db->prefix() . 'socpeople';
        $sql .= ' WHERE entity = ' . ((int) $this->entity);
        if (!$alsoPrivate) {
            $sql .= ' AND priv = 0';
        }
        if ($activeOnly) {
            $sql .= ' AND statut = 1';
        }
        if ($onlyId !== null) {
            $sql .= ' AND rowid = ' . ((int) $onlyId);
        }
        $sql .= ' ORDER BY rowid';
        $res = $this->db->query($sql);
        if (!$res) {
            throw new RuntimeException('Dolibarr contacts: ' . $this->db->lasterror());
        }
        $all = array();
        while ($o = $this->db->fetch_object($res)) {
            $sn = $o->socialnetworks ? json_decode($o->socialnetworks, true) : array();
            $all[(int) $o->rowid] = array(
                'id' => (int) $o->rowid,
                'lastname' => (string) $o->lastname,
                'firstname' => (string) $o->firstname,
                'poste' => (string) $o->poste,
                'email' => (string) $o->email,
                'phone_pro' => (string) $o->phone,
                'phone_mobile' => (string) $o->phone_mobile,
                'address' => (string) $o->address,
                'note_public' => (string) $o->note_public,
                'socid' => (int) $o->fk_soc,
                'socialnetworks' => is_array($sn) ? $sn : array(),
                'status' => (int) $o->statut,
            );
        }
        $this->db->free($res);
        return $all;
    }

    /**
     * Read one Dolibarr contact of the current entity (public or private, active or not).
     *
     * @param int $id Contact id
     * @return array<string,mixed>|null
     */
    private function readDolibarr($id)
    {
        $l = $this->listDolibarr((int) $id, true, false);
        return $l[(int) $id] ?? null;
    }

    /**
     * Which of these contact ids still exist in llx_socpeople for the current entity?
     *
     * @param int[] $ids Ids
     * @return array<int,bool>
     */
    private function existingContactIds(array $ids)
    {
        $found = array();
        if (!$ids) {
            return $found;
        }
        $sql = 'SELECT rowid FROM ' . $this->db->prefix() . 'socpeople WHERE entity = ' . ((int) $this->entity)
            . ' AND rowid IN (' . $this->db->sanitize(implode(',', array_map('intval', $ids))) . ')';
        $res = $this->db->query($sql);
        if (!$res) {
            throw new RuntimeException('Dolibarr contacts: ' . $this->db->lasterror());
        }
        while ($o = $this->db->fetch_object($res)) {
            $found[(int) $o->rowid] = true;
        }
        return $found;
    }

    /**
     * Create a Dolibarr contact.
     *
     * @param array<string,mixed> $fields Fields
     * @return int Id
     * @throws WailoContactException
     */
    private function createDolibarrContact(array $fields)
    {
        $c = new Contact($this->db);
        $this->setContactFields($c, $fields);
        $c->priv = 0;
        $c->status = 1;
        $c->statut = 1;
        $c->entity = $this->entity;
        $id = $c->create($this->user);
        if ($id <= 0) {
            throw new WailoContactException('Dolibarr contact creation failed: ' . dol_trunc($c->error . ' ' . implode(',', (array) $c->errors), 200));
        }
        return (int) $id;
    }

    /**
     * Update the given fields of a Dolibarr contact. Other fields (private note, civility, birthday...) are kept.
     *
     * @param int                 $id     Contact id
     * @param array<string,mixed> $fields Fields
     * @return void
     * @throws WailoContactException
     */
    private function updateDolibarrContact($id, array $fields)
    {
        $c = new Contact($this->db);
        if ($c->fetch($id) <= 0) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' not found');
        }
        $this->setContactFields($c, $fields);
        if ($c->update($id, $this->user) <= 0) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' update failed: ' . dol_trunc($c->error . ' ' . implode(',', (array) $c->errors), 200));
        }
    }

    /**
     * Copy fields on a Contact object. Social networks are merged (other networks of Dolibarr are kept).
     *
     * @param Contact             $c      Contact
     * @param array<string,mixed> $fields Fields
     * @return void
     */
    private function setContactFields(Contact $c, array $fields)
    {
        foreach ($fields as $k => $v) {
            if ($k === 'socialnetworks') {
                $current = is_array($c->socialnetworks) ? $c->socialnetworks : array();
                foreach ($v as $sk => $sv) {
                    if ((string) $sv === '') {
                        unset($current[$sk]);
                    } else {
                        $current[$sk] = $sv;
                    }
                }
                $c->socialnetworks = $current;
            } elseif ($k === 'socid') {
                $c->socid = (int) $v;
            } else {
                $c->$k = $v;
            }
        }
    }

    /**
     * Status of a fetched Dolibarr contact: Dolibarr 24 fills ->status (and ->statut), Dolibarr 20 only ->statut.
     *
     * @param Contact $c Contact
     * @return int 1 active, 0 inactive
     */
    private static function contactStatus($c)
    {
        return (int) (isset($c->status) ? $c->status : $c->statut);
    }

    /**
     * Make a Dolibarr contact inactive and add a private note (on contact.removed). Never deleted:
     * its links with proposals, orders, categories and notifications stay.
     *
     * @param int $id Contact id
     * @return int 0 = the contact does not exist anymore, 1 = made inactive now, 2 = it was already inactive
     * @throws WailoContactException
     */
    private function deactivateDolibarrContact($id)
    {
        global $langs;
        $c = new Contact($this->db);
        $r = $c->fetch($id);
        if ($r === 0) {
            return 0;
        }
        if ($r < 0 || (int) $c->entity !== (int) $this->entity) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' cannot be read');
        }
        $wasActive = self::contactStatus($c) !== 0;
        if ($wasActive && $c->setstatus(0) < 0) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' cannot be made inactive: ' . dol_trunc((string) $c->error, 200));
        }
        $line = $langs->transnoentitiesnoconv('WailoRemovedNote', dol_print_date(dol_now(), 'dayhour', 'gmt') . ' UTC');
        $note = trim((string) $c->note_private);
        $note = ($note !== '' ? $note . "\n" : '') . $line;
        if ($c->update_note($note, '_private') < 0) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' note failed: ' . dol_trunc((string) $c->error, 200));
        }
        return $wasActive ? 1 : 2;
    }

    /**
     * Contact back in Wailo after a removal: add a private note, and make the Dolibarr contact active again only if the
     * sync had made it inactive (a contact made inactive by a Dolibarr user stays inactive).
     *
     * @param int  $id       Contact id
     * @param bool $activate Make it active again
     * @return void
     * @throws WailoContactException
     */
    private function reactivateDolibarrContact($id, $activate)
    {
        global $langs;
        $c = new Contact($this->db);
        $r = $c->fetch($id);
        if ($r <= 0 || (int) $c->entity !== (int) $this->entity) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' cannot be read');
        }
        if ($activate && self::contactStatus($c) !== 1 && $c->setstatus(1) < 0) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' cannot be made active: ' . dol_trunc((string) $c->error, 200));
        }
        $line = $langs->transnoentitiesnoconv($activate ? 'WailoBackNote' : 'WailoBackNoteInactive', dol_print_date(dol_now(), 'dayhour', 'gmt') . ' UTC');
        $note = trim((string) $c->note_private);
        $note = ($note !== '' ? $note . "\n" : '') . $line;
        if ($c->update_note($note, '_private') < 0) {
            throw new WailoContactException('Dolibarr contact ' . $id . ' note failed: ' . dol_trunc((string) $c->error, 200));
        }
    }

    /**
     * Load the third parties cache (name <-> id) of the current entity.
     *
     * @return void
     */
    private function loadThirdparties()
    {
        $this->thirdparties = array();
        $sql = 'SELECT rowid, nom FROM ' . $this->db->prefix() . 'societe WHERE entity = ' . ((int) $this->entity);
        $res = $this->db->query($sql);
        if (!$res) {
            throw new RuntimeException('Dolibarr third parties: ' . $this->db->lasterror());
        }
        while ($o = $this->db->fetch_object($res)) {
            $this->thirdparties['id:' . ((int) $o->rowid)] = (string) $o->nom;
            $k = 'name:' . mb_strtolower(trim((string) $o->nom));
            if (!isset($this->thirdparties[$k])) {
                $this->thirdparties[$k] = (int) $o->rowid;
            }
        }
    }

    /**
     * Third party for a Wailo company: found by name (case insensitive), created if missing (neither customer nor supplier).
     *
     * @param string|null $company Company name
     * @return int|null
     * @throws WailoContactException
     */
    private function thirdpartyForCompany($company)
    {
        if ($company === null) {
            return null;
        }
        $company = WailoMapper::cut($company, 128);
        if ($this->thirdparties === null) {
            $this->loadThirdparties();
        }
        $k = 'name:' . mb_strtolower($company);
        if (isset($this->thirdparties[$k])) {
            return $this->thirdparties[$k];
        }
        $s = new Societe($this->db);
        $s->name = $company;
        $s->client = 0;
        $s->fournisseur = 0;
        $s->status = 1;
        $s->entity = $this->entity;
        $id = $s->create($this->user);
        if ($id <= 0) {
            throw new WailoContactException('Dolibarr third party creation failed: ' . dol_trunc($s->error . ' ' . implode(',', (array) $s->errors), 200));
        }
        $this->thirdparties[$k] = (int) $id;
        $this->thirdparties['id:' . ((int) $id)] = $company;
        $this->log('info', 'ThirdpartyCreated', array((int) $id));
        return (int) $id;
    }

    /**
     * Name of a third party.
     *
     * @param int|null $socid Third party id
     * @return string|null
     */
    private function thirdpartyName($socid)
    {
        if (empty($socid) || (int) $socid <= 0) {
            return null;
        }
        if ($this->thirdparties === null) {
            $this->loadThirdparties();
        }
        return WailoMapper::txt($this->thirdparties['id:' . ((int) $socid)] ?? null);
    }

    // ------------------------------------------------------------------ quarantine

    /**
     * Put a contact in quarantine (shown on the setup page) and log it.
     *
     * @param string $key    'w:<wailo id>' or 'd:<dolibarr id>'
     * @param string $reason Short reason (error code or message, never a secret)
     * @param string $sig    Signature of the version refused (etag, view hash)
     * @return void
     */
    private function quarantine($key, $reason, $sig)
    {
        $this->stats['errors']++;
        $this->setState('quarantine:' . $key, json_encode(array('reason' => dol_trunc($reason, 200), 'date' => dol_now(), 'sig' => $sig)));
        $this->log('error', 'Quarantine', array($key, dol_trunc($reason, 200)));
    }

    /**
     * Remove a contact from quarantine.
     *
     * @param string $key Key
     * @return void
     */
    private function unquarantine($key)
    {
        $this->deleteState('quarantine:' . $key);
    }

    /**
     * Contacts in quarantine.
     *
     * @return array<string,array{reason:string,date:int}>
     */
    public function quarantined()
    {
        $out = array();
        $res = $this->db->query('SELECT name, value FROM ' . $this->db->prefix() . 'wailo_state WHERE entity = ' . ((int) $this->entity) . " AND name LIKE 'quarantine:%' ORDER BY rowid DESC");
        while ($res && ($o = $this->db->fetch_object($res))) {
            $v = json_decode((string) $o->value, true);
            $out[substr((string) $o->name, 11)] = is_array($v) ? $v : array('reason' => '', 'date' => 0);
        }
        return $out;
    }

    // ------------------------------------------------------------------ links and state (module tables)

    /**
     * Load all links of the entity.
     *
     * @return void
     */
    private function loadLinks()
    {
        $this->links = array();
        $sql = 'SELECT rowid, wailo_id, fk_socpeople, etag, placeholder, phone_digits, doli_view, wailo_view, removed_at, deactivated_by_sync FROM ' . $this->db->prefix() . 'wailo_link';
        $sql .= ' WHERE entity = ' . ((int) $this->entity);
        $res = $this->db->query($sql);
        if (!$res) {
            throw new RuntimeException('wailo_link: ' . $this->db->lasterror());
        }
        while ($o = $this->db->fetch_object($res)) {
            $digits = json_decode((string) $o->phone_digits, true);
            $view = json_decode((string) $o->doli_view, true);
            $wview = json_decode((string) $o->wailo_view, true);
            $this->links[(string) $o->wailo_id] = array(
                'rowid' => (int) $o->rowid,
                'doli_id' => (int) $o->fk_socpeople,
                'etag' => (string) $o->etag,
                'placeholder' => $o->placeholder !== null && $o->placeholder !== '' ? (string) $o->placeholder : null,
                'digits' => is_array($digits) ? $digits : array(),
                'view' => is_array($view) ? $view : array(),
                'wview' => is_array($wview) ? $wview : array(),
                'removed' => $o->removed_at !== null && $o->removed_at !== '' ? $this->db->jdate($o->removed_at) : null,
                'bysync' => (int) $o->deactivated_by_sync === 1,
            );
        }
    }

    /**
     * The asleep link whose Dolibarr contact has the same e-mail or mobile (9 last digits) as this Wailo contact,
     * when there is exactly one; null otherwise.
     *
     * @param array<string,mixed> $wc Wailo contact
     * @return string|null Wailo id of the asleep link
     */
    private function asleepMatch(array $wc)
    {
        $byDoli = array();
        foreach ($this->links as $wid => $l) {
            if ($l['removed'] !== null) {
                $byDoli[(int) $l['doli_id']] = (string) $wid;
            }
        }
        if (!$byDoli) {
            return null;
        }
        $mails = array();
        foreach ($wc['emails'] ?? array() as $e) {
            $k = mb_strtolower(trim((string) ($e['value'] ?? '')));
            if ($k !== '') {
                $mails[$k] = true;
            }
        }
        $phones = array();
        foreach ($wc['phones'] ?? array() as $p) {
            $k = WailoMapper::digits9((string) ($p['value'] ?? ''));
            if ($k !== '') {
                $phones[$k] = true;
            }
        }
        if (!$mails && !$phones) {
            return null;
        }
        $sql = 'SELECT rowid, email, phone_mobile FROM ' . $this->db->prefix() . 'socpeople WHERE entity = ' . ((int) $this->entity)
            . ' AND rowid IN (' . implode(',', array_map('intval', array_keys($byDoli))) . ')';
        $res = $this->db->query($sql);
        if (!$res) {
            throw new RuntimeException('Dolibarr contacts: ' . $this->db->lasterror());
        }
        $found = array();
        while ($o = $this->db->fetch_object($res)) {
            $mail = mb_strtolower(trim((string) $o->email));
            $mobile = WailoMapper::txt($o->phone_mobile) !== null ? WailoMapper::digits9((string) $o->phone_mobile) : '';
            if (($mail !== '' && isset($mails[$mail])) || ($mobile !== '' && isset($phones[$mobile]))) {
                $found[$byDoli[(int) $o->rowid]] = true;
            }
        }
        $this->db->free($res);
        return count($found) === 1 ? (string) key($found) : null;
    }

    /**
     * Returns that failed (the Dolibarr contact could not be made active again): tried again at each pass, so that a
     * contact active in Wailo does not stay asleep until its next change.
     *
     * @return void
     * @throws RuntimeException
     */
    private function retryPendingReturns()
    {
        $list = json_decode($this->getState('pending_returns', '[]'), true);
        if (!is_array($list) || !$list) {
            return;
        }
        $keep = array();
        foreach ($list as $wid) {
            $wid = (string) $wid;
            if (!isset($this->links[$wid]) || $this->links[$wid]['removed'] === null) {
                continue;
            }
            $g = $this->client->request('GET', '/contacts/' . rawurlencode($wid));
            if ($g['status'] === 404) {
                continue;
            }
            if ($g['status'] !== 200) {
                $keep[] = $wid;
                continue;
            }
            try {
                $this->applyWailo($g['json']['data'], null);
            } catch (WailoContactException $e) {
                $this->quarantine('w:' . $wid, $e->getMessage(), (string) $g['json']['data']['etag']);
            }
            if (isset($this->links[$wid]) && $this->links[$wid]['removed'] !== null) {
                $keep[] = $wid;
            }
        }
        if ($keep) {
            $this->setState('pending_returns', json_encode(array_values(array_unique($keep))));
        } else {
            $this->deleteState('pending_returns');
        }
    }

    /**
     * Is this Wailo id linked and not removed in Wailo (link not asleep)?
     *
     * @param string $wid Wailo id
     * @return bool
     */
    private function isAwake($wid)
    {
        return isset($this->links[$wid]) && $this->links[$wid]['removed'] === null;
    }

    /**
     * Index Dolibarr id => Wailo id.
     *
     * @return array<int,string>
     */
    private function indexDoliToWailo()
    {
        $ix = array();
        foreach ($this->links as $wid => $l) {
            $ix[(int) $l['doli_id']] = (string) $wid;
        }
        return $ix;
    }

    /**
     * Save one link (insert or update). A database failure here stops the pass (not a contact problem).
     *
     * @param string $wid Wailo id
     * @return void
     * @throws RuntimeException
     */
    private function saveLink($wid)
    {
        $l = $this->links[$wid];
        $t = $this->db->prefix() . 'wailo_link';
        $values = 'fk_socpeople = ' . ((int) $l['doli_id'])
            . ", etag = '" . $this->db->escape((string) $l['etag']) . "'"
            . ', placeholder = ' . ($l['placeholder'] !== null ? "'" . $this->db->escape($l['placeholder']) . "'" : 'NULL')
            . ", phone_digits = '" . $this->db->escape(json_encode($l['digits'])) . "'"
            . ", doli_view = '" . $this->db->escape(json_encode($l['view'], JSON_UNESCAPED_UNICODE)) . "'"
            . ", wailo_view = '" . $this->db->escape(json_encode($l['wview'] ?? array(), JSON_UNESCAPED_UNICODE)) . "'"
            . ', removed_at = ' . (!empty($l['removed']) ? "'" . $this->db->idate($l['removed']) . "'" : 'NULL')
            . ', deactivated_by_sync = ' . (!empty($l['removed']) && !empty($l['bysync']) ? 1 : 0);
        if (empty($l['rowid'])) {
            $res = $this->db->query('SELECT rowid FROM ' . $t . ' WHERE entity = ' . ((int) $this->entity) . " AND wailo_id = '" . $this->db->escape($wid) . "'");
            if ($res && ($o = $this->db->fetch_object($res))) {
                $this->links[$wid]['rowid'] = (int) $o->rowid;
                $l['rowid'] = (int) $o->rowid;
            }
        }
        if (!empty($l['rowid'])) {
            $sql = 'UPDATE ' . $t . ' SET ' . $values . ' WHERE rowid = ' . ((int) $l['rowid']);
            if (!$this->db->query($sql)) {
                throw new RuntimeException('wailo_link: ' . $this->db->lasterror());
            }
            return;
        }
        $sql = 'INSERT INTO ' . $t . ' (entity, wailo_id, fk_socpeople, etag, placeholder, phone_digits, doli_view, wailo_view, removed_at, deactivated_by_sync, datec) VALUES ('
            . ((int) $this->entity) . ", '" . $this->db->escape($wid) . "', " . ((int) $l['doli_id'])
            . ", '" . $this->db->escape((string) $l['etag']) . "', "
            . ($l['placeholder'] !== null ? "'" . $this->db->escape($l['placeholder']) . "'" : 'NULL') . ', '
            . "'" . $this->db->escape(json_encode($l['digits'])) . "', "
            . "'" . $this->db->escape(json_encode($l['view'], JSON_UNESCAPED_UNICODE)) . "', "
            . "'" . $this->db->escape(json_encode($l['wview'] ?? array(), JSON_UNESCAPED_UNICODE)) . "', "
            . (!empty($l['removed']) ? "'" . $this->db->idate($l['removed']) . "'" : 'NULL') . ', '
            . (!empty($l['removed']) && !empty($l['bysync']) ? 1 : 0) . ', '
            . "'" . $this->db->idate(dol_now()) . "')";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('wailo_link: ' . $this->db->lasterror());
        }
        $this->links[$wid]['rowid'] = (int) $this->db->last_insert_id($t);
    }

    /**
     * Forget one link.
     *
     * @param string $wid Wailo id
     * @return void
     */
    private function deleteLink($wid)
    {
        $this->db->query('DELETE FROM ' . $this->db->prefix() . 'wailo_link WHERE entity = ' . ((int) $this->entity) . " AND wailo_id = '" . $this->db->escape($wid) . "'");
        unset($this->links[$wid]);
    }

    /**
     * Number of linked contacts (contacts removed in Wailo, whose link is asleep, are not counted).
     *
     * @return int
     */
    public function countLinks()
    {
        $res = $this->db->query('SELECT COUNT(*) as nb FROM ' . $this->db->prefix() . 'wailo_link WHERE entity = ' . ((int) $this->entity) . ' AND removed_at IS NULL');
        if ($res && ($o = $this->db->fetch_object($res))) {
            return (int) $o->nb;
        }
        return 0;
    }

    /**
     * Read a state value.
     *
     * @param string $name    Name
     * @param string $default Default
     * @return string
     */
    public function getState($name, $default = '')
    {
        $res = $this->db->query('SELECT value FROM ' . $this->db->prefix() . 'wailo_state WHERE entity = ' . ((int) $this->entity) . " AND name = '" . $this->db->escape($name) . "'");
        if ($res && ($o = $this->db->fetch_object($res))) {
            return (string) $o->value;
        }
        return $default;
    }

    /**
     * Write a state value.
     *
     * @param string $name  Name
     * @param string $value Value
     * @return void
     * @throws RuntimeException
     */
    public function setState($name, $value)
    {
        $t = $this->db->prefix() . 'wailo_state';
        $where = ' WHERE entity = ' . ((int) $this->entity) . " AND name = '" . $this->db->escape($name) . "'";
        $res = $this->db->query('SELECT rowid FROM ' . $t . $where);
        if ($res && $this->db->fetch_object($res)) {
            $ok = $this->db->query('UPDATE ' . $t . " SET value = '" . $this->db->escape($value) . "'" . $where);
        } else {
            $ok = $this->db->query('INSERT INTO ' . $t . ' (entity, name, value) VALUES (' . ((int) $this->entity) . ", '" . $this->db->escape($name) . "', '" . $this->db->escape($value) . "')");
        }
        if (!$ok) {
            throw new RuntimeException('wailo_state: ' . $this->db->lasterror());
        }
    }

    /**
     * Delete a state value.
     *
     * @param string $name Name
     * @return void
     */
    public function deleteState($name)
    {
        $this->db->query('DELETE FROM ' . $this->db->prefix() . 'wailo_state WHERE entity = ' . ((int) $this->entity) . " AND name = '" . $this->db->escape($name) . "'");
    }

    /**
     * Random id of this installation, used in idempotency keys (never derived from a secret).
     *
     * @return string
     */
    private function installId()
    {
        $id = $this->getState('install_id');
        if ($id === '') {
            $id = bin2hex(random_bytes(8));
            $this->setState('install_id', $id);
        }
        return $id;
    }

    /**
     * Save the summary of the pass (shown on the setup page).
     *
     * @param string $result RESULT_* constant
     * @param float  $start  microtime of the start
     * @return void
     */
    private function saveLastPass($result, $start)
    {
        $summary = array(
            'date' => dol_now(),
            'result' => $result,
            'duration' => round(microtime(true) - $start, 1),
            'links' => count(array_filter($this->links, function ($l) {
                return $l['removed'] === null;
            })),
            'stats' => $this->stats,
            'waiting' => $this->waiting,
        );
        try {
            $this->setState('last_pass', json_encode($summary));
        } catch (Throwable $e) {
            dol_syslog('wailo: cannot save last pass', LOG_ERR);
        }
    }

    // ------------------------------------------------------------------ journal

    /**
     * Write a journal line (translation code + parameters). Never a secret: only ids, HTTP codes, request_id.
     *
     * @param string           $severity info|warning|error
     * @param string           $code     Code (translation key WailoLog<Code>)
     * @param array<int,mixed> $params   Parameters
     * @return void
     */
    public function log($severity, $code, array $params = array())
    {
        global $langs;
        $params = array_values(array_map(function ($p) {
            return is_scalar($p) || $p === null ? (string) $p : json_encode($p);
        }, $params));
        $sql = 'INSERT INTO ' . $this->db->prefix() . 'wailo_log (entity, datec, severity, code, params) VALUES ('
            . ((int) $this->entity) . ", '" . $this->db->idate(dol_now()) . "', '" . $this->db->escape($severity) . "', '"
            . $this->db->escape($code) . "', '" . $this->db->escape(json_encode($params, JSON_UNESCAPED_UNICODE)) . "')";
        $this->db->query($sql);
        $text = self::translateLog($langs, $code, $params);
        $this->output .= $text . "\n";
        if ($severity === 'error') {
            $this->errors[] = $text;
        }
        dol_syslog('wailo: ' . $code . ' ' . implode(' ', $params), $severity === 'error' ? LOG_ERR : ($severity === 'warning' ? LOG_WARNING : LOG_INFO));
    }

    /**
     * Plain text of a journal line (not HTML: escape before display).
     *
     * @param Translate        $langs  Translation handler
     * @param string           $code   Code
     * @param array<int,mixed> $params Parameters
     * @return string
     */
    public static function translateLog($langs, $code, array $params)
    {
        $p = array_pad(array_values($params), 5, '');
        if (!is_object($langs)) {
            return $code . ' ' . implode(' ', $params);
        }
        return $langs->transnoentitiesnoconv('WailoLog' . $code, $p[0], $p[1], $p[2], $p[3], $p[4]);
    }

    /**
     * Last journal lines.
     *
     * @param int $limit Number of lines
     * @return array<int,array{date:int,severity:string,code:string,params:array}>
     */
    public function lastLogs($limit = 30)
    {
        $out = array();
        $sql = 'SELECT datec, severity, code, params FROM ' . $this->db->prefix() . 'wailo_log WHERE entity = ' . ((int) $this->entity);
        $sql .= ' ORDER BY rowid DESC' . $this->db->plimit((int) $limit);
        $res = $this->db->query($sql);
        while ($res && ($o = $this->db->fetch_object($res))) {
            $params = json_decode((string) $o->params, true);
            $out[] = array('date' => $this->db->jdate($o->datec), 'severity' => (string) $o->severity, 'code' => (string) $o->code, 'params' => is_array($params) ? $params : array());
        }
        return $out;
    }

    /**
     * Keep only the last LOG_KEEP journal lines.
     *
     * @return void
     */
    private function pruneLog()
    {
        $t = $this->db->prefix() . 'wailo_log';
        $res = $this->db->query('SELECT rowid FROM ' . $t . ' WHERE entity = ' . ((int) $this->entity) . ' ORDER BY rowid DESC' . $this->db->plimit(1, self::LOG_KEEP));
        if ($res && ($o = $this->db->fetch_object($res))) {
            $this->db->query('DELETE FROM ' . $t . ' WHERE entity = ' . ((int) $this->entity) . ' AND rowid <= ' . ((int) $o->rowid));
        }
    }

    // ------------------------------------------------------------------ lock

    /**
     * One pass (or one change of key) at a time: exclusive lock on a file in documents/wailo/temp.
     *
     * @return resource|null Lock handle, or null if a pass runs
     * @throws RuntimeException
     */
    public function acquireLock()
    {
        global $conf;
        $dir = !empty($conf->wailo->dir_temp) ? $conf->wailo->dir_temp : DOL_DATA_ROOT . '/wailo/temp';
        if (!is_dir($dir)) {
            dol_mkdir($dir);
        }
        $fh = @fopen($dir . '/sync-' . ((int) $this->entity) . '.lock', 'c');
        if (!$fh) {
            throw new RuntimeException('cannot open lock file in ' . $dir);
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return null;
        }
        return $fh;
    }

    /**
     * Release the lock.
     *
     * @param resource $fh Lock handle
     * @return void
     */
    public function releaseLock($fh)
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
