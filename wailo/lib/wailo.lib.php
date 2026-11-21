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
 * \file    wailo/lib/wailo.lib.php
 * \ingroup wailo
 * \brief   Library functions for module Wailo
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{0:string,1:string,2:string}>
 */
function wailoAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load('wailo@wailo');

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/wailo/admin/setup.php', 1);
    $head[$h][1] = $langs->trans('Settings');
    $head[$h][2] = 'settings';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'wailo@wailo');
    complete_head_from_modules($conf, $langs, null, $head, $h, 'wailo@wailo', 'remove');

    return $head;
}

/**
 * Is this string shaped like a Wailo API key (<id>|wlo_test_... or <id>|wlo_live_...)?
 * Only the shape is checked here; the key itself is checked by Wailo (GET /me).
 *
 * @param string $key Key
 * @return bool
 */
function wailoIsKeyShape(
    #[\SensitiveParameter]
    $key
) {
    return (bool) preg_match('/^[0-9]+\|wlo_(test|live)_[A-Za-z0-9_-]{16,128}$/', (string) $key);
}

/**
 * Kind of the stored key, without revealing any character of it.
 *
 * @param string $key Key
 * @return string 'test', 'live' or 'unknown'
 */
function wailoKeyKind(
    #[\SensitiveParameter]
    $key
) {
    if (strpos((string) $key, '|wlo_test_') !== false) {
        return 'test';
    }
    if (strpos((string) $key, '|wlo_live_') !== false) {
        return 'live';
    }
    return 'unknown';
}

/**
 * Translated message for setEventMessages, safe with untrusted parameters (text sent by the Wailo server):
 * the parameters are escaped and never turned into HTML tags (unlike trans(), which restores some tags).
 *
 * @param string $key       Translation key
 * @param mixed  ...$params Parameters (4 at most)
 * @return string HTML
 */
function wailoMsg($key, ...$params)
{
    global $langs;
    // Raw translation (with its %s), escaped as text; then the escaped parameters take the place of each %s.
    $raw = isset($langs->tab_translate[$key]) ? (string) $langs->tab_translate[$key] : (string) $key;
    $parts = explode('%s', dol_escape_htmltag($raw));
    $out = array_shift($parts);
    foreach ($parts as $i => $part) {
        $out .= (isset($params[$i]) ? dol_escape_htmltag((string) $params[$i]) : '') . $part;
    }
    return $out;
}
