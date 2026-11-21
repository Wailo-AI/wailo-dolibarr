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
 * \file    wailo/class/wailomapper.class.php
 * \ingroup wailo
 * \brief   Field mapping between a Wailo contact and a Dolibarr contact (pure functions, no database).
 *
 * Mapping (same as the Wailo connector kit, CORRESPONDANCE.md):
 *   first_name <-> firstname, last_name <-> lastname, company <-> third party (socid),
 *   job_title <-> poste, emails[0] <-> email, phones -> phone_mobile / phone_pro,
 *   addresses[0].address <-> address, socials <-> socialnetworks, about <-> note_public (escaped).
 * A Dolibarr contact as handled here is an array with keys:
 *   id, lastname, firstname, poste, email, phone_pro, phone_mobile, address, note_public, socid, socialnetworks.
 */
class WailoMapper
{
    /** Fields of the "view": what the Dolibarr contact says, expressed in Wailo fields managed by the module. */
    public const VIEW_FIELDS = array(
        'first_name', 'last_name', 'company', 'job_title', 'email', 'mobile', 'pro',
        'address', 'about', 'linkedin', 'facebook', 'instagram',
    );

    /** Sizes of the llx_socpeople columns written by the module (Dolibarr 20 to 24). */
    public const COLUMN_SIZES = array(
        'lastname' => 50, 'firstname' => 50, 'poste' => 255, 'email' => 255,
        'phone_mobile' => 30, 'phone_pro' => 30, 'address' => 255,
    );

    /** Maximum length of "about" in the Wailo API (ContactPatch.about.maxLength). */
    public const ABOUT_MAX = 1900;

    /**
     * Cut a text to a maximum number of characters (UTF-8).
     *
     * @param string|null $v   Text
     * @param int         $max Maximum length
     * @return string|null
     */
    public static function cut($v, $max)
    {
        if ($v === null) {
            return null;
        }
        return mb_strlen($v, 'UTF-8') > $max ? mb_substr($v, 0, $max, 'UTF-8') : $v;
    }

    /**
     * Last 9 digits of a phone number (Wailo compares numbers on their digits).
     *
     * @param string|null $number Phone number as typed
     * @return string
     */
    public static function digits9($number)
    {
        $d = preg_replace('/\D+/', '', (string) $number);
        return substr($d, -9);
    }

    /**
     * Trimmed text, or null when empty.
     *
     * @param mixed $v Value
     * @return string|null
     */
    public static function txt($v)
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /**
     * Untrusted text from Wailo stored into an HTML field of Dolibarr (note_public): escaped.
     *
     * @param string|null $t Plain text
     * @return string
     */
    public static function toNote($t)
    {
        return $t === null ? '' : nl2br(htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
    }

    /**
     * Note of Dolibarr (HTML) back to plain text.
     *
     * @param string|null $h HTML
     * @return string|null
     */
    public static function fromNote($h)
    {
        if ($h === null || $h === '') {
            return null;
        }
        $t = preg_replace('#<br\s*/?>\r?\n?#i', "\n", $h);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return self::txt($t);
    }

    /**
     * Is this Wailo phone a mobile (label starting with "Mobile", or French 06/07 number)?
     *
     * @param array<string,mixed> $p Phone {value, label}
     * @return bool
     */
    public static function isMobile(array $p)
    {
        if (stripos((string) ($p['label'] ?? ''), 'mobile') === 0) {
            return true;
        }
        $d = preg_replace('/\D+/', '', (string) ($p['value'] ?? ''));
        return (bool) preg_match('/^(0|33|0033)[67]\d{8}$/', $d);
    }

    /**
     * Dispatch Wailo phones into the 2 phone slots of a Dolibarr contact.
     * Main slot (Wailo guide): first phone labelled Mobile, otherwise the first one.
     *
     * @param array<int,array<string,mixed>> $phones Wailo phones
     * @return array{mobile:?array,pro:?array}
     */
    public static function phoneSlots(array $phones)
    {
        $slots = array('mobile' => null, 'pro' => null);
        $phones = array_values($phones);
        if (!$phones) {
            return $slots;
        }
        $main = 0;
        foreach ($phones as $i => $p) {
            if (stripos((string) ($p['label'] ?? ''), 'mobile') === 0) {
                $main = $i;
                break;
            }
        }
        $slots[self::isMobile($phones[$main]) ? 'mobile' : 'pro'] = $phones[$main];
        foreach ($phones as $i => $p) {
            if ($i === $main) {
                continue;
            }
            $free = $slots['mobile'] === null ? 'mobile' : ($slots['pro'] === null ? 'pro' : null);
            if ($free === null) {
                break;
            }
            $slots[$free] = $p;
        }
        return $slots;
    }

    /**
     * Digits of the numbers held by the Dolibarr slots, to find them back in the Wailo list.
     *
     * @param array<string,mixed> $c Wailo contact
     * @return array{mobile:?string,pro:?string}
     */
    public static function slotDigits(array $c)
    {
        $slots = self::phoneSlots($c['phones'] ?? array());
        return array(
            'mobile' => isset($slots['mobile']) ? self::digits9($slots['mobile']['value']) : null,
            'pro' => isset($slots['pro']) ? self::digits9($slots['pro']['value']) : null,
        );
    }

    /**
     * Placeholder kept in Dolibarr when Wailo has no last name (Dolibarr requires one). Never sent back to Wailo.
     *
     * @param array<string,mixed> $c Wailo contact
     * @return string|null Placeholder, or null when Wailo has a last name
     */
    public static function placeholder(array $c)
    {
        if (self::txt($c['last_name'] ?? null) !== null) {
            return null;
        }
        $p = self::txt($c['company'] ?? null);
        if ($p === null) {
            $p = self::txt($c['first_name'] ?? null);
        }
        return $p === null ? 'Sans nom' : self::cut($p, self::COLUMN_SIZES['lastname']);
    }

    /**
     * Dolibarr fields to write, from a Wailo contact.
     * Without company in Wailo, socid is not written: the link to the third party is never removed (same as the kit).
     *
     * @param array<string,mixed> $c           Wailo contact
     * @param int|null            $socid       Third party id for the Wailo company (null if no company)
     * @param bool                $withSocials Social networks managed (module "Social networks" of Dolibarr enabled)
     * @param bool                $withNote    Public note synchronised (setting, off by default)
     * @return array<string,mixed> Values cut to the sizes of the Dolibarr columns
     */
    public static function wailoToDoli(array $c, $socid, $withSocials = true, $withNote = true)
    {
        $slots = self::phoneSlots($c['phones'] ?? array());
        $placeholder = self::placeholder($c);
        $sn = is_array($c['socials'] ?? null) ? $c['socials'] : array();
        $fields = array(
            'lastname' => $placeholder !== null ? $placeholder : self::txt($c['last_name']),
            'firstname' => (string) self::txt($c['first_name'] ?? null),
            'poste' => (string) self::txt($c['job_title'] ?? null),
            'email' => (string) ($c['emails'][0]['value'] ?? ''),
            'phone_mobile' => (string) ($slots['mobile']['value'] ?? ''),
            'phone_pro' => (string) ($slots['pro']['value'] ?? ''),
            'address' => (string) ($c['addresses'][0]['address'] ?? ''),
            'note_public' => self::toNote(self::txt($c['about'] ?? null)),
            'socialnetworks' => array(
                'linkedin' => (string) ($sn['linkedin'] ?? ''),
                'facebook' => (string) ($sn['facebook'] ?? ''),
                'instagram' => (string) ($sn['instagram'] ?? ''),
            ),
        );
        foreach (self::COLUMN_SIZES as $k => $max) {
            $fields[$k] = self::cut($fields[$k], $max);
        }
        if (!$withSocials) {
            unset($fields['socialnetworks']);
        }
        if (!$withNote) {
            unset($fields['note_public']);
        }
        if (!empty($socid)) {
            $fields['socid'] = (int) $socid;
        }
        return $fields;
    }

    /**
     * What the Dolibarr contact says, in Wailo fields managed by the module.
     *
     * @param array<string,mixed> $d           Dolibarr contact
     * @param string|null         $placeholder Placeholder of the last name (never sent back)
     * @param string|null         $companyName Name of the linked third party
     * @param bool                $withSocials Social networks managed (module "Social networks" of Dolibarr enabled)
     * @param bool                $withNote    Public note synchronised (setting, off by default)
     * @return array<string,?string>
     */
    public static function viewDoli(array $d, $placeholder, $companyName, $withSocials = true, $withNote = true)
    {
        $name = self::txt($d['lastname'] ?? null);
        if ($placeholder !== null && $name !== null && mb_strtolower($name) === mb_strtolower($placeholder)) {
            $name = null;
        }
        $sn = is_array($d['socialnetworks'] ?? null) ? $d['socialnetworks'] : array();
        $view = array(
            'first_name' => self::txt($d['firstname'] ?? null),
            'last_name' => $name,
            'company' => self::txt($companyName),
            'job_title' => self::txt($d['poste'] ?? null),
            'email' => self::txt($d['email'] ?? null),
            'mobile' => self::txt($d['phone_mobile'] ?? null),
            'pro' => self::txt($d['phone_pro'] ?? null),
            'address' => self::txt($d['address'] ?? null),
            'about' => self::cut(self::fromNote($d['note_public'] ?? null), self::ABOUT_MAX),
            'linkedin' => self::txt($sn['linkedin'] ?? null),
            'facebook' => self::txt($sn['facebook'] ?? null),
            'instagram' => self::txt($sn['instagram'] ?? null),
        );
        if (!$withSocials) {
            // Without the module, Dolibarr forms do not show social networks and empty them on save:
            // they are left out of the synchronisation, so Wailo keeps its values.
            unset($view['linkedin'], $view['facebook'], $view['instagram']);
        }
        if (!$withNote) {
            unset($view['about']);
        }
        return $view;
    }

    /**
     * What a Wailo contact would look like once written in Dolibarr (same space as viewDoli, values cut alike).
     *
     * @param array<string,mixed> $c           Wailo contact
     * @param bool                $withSocials Social networks managed
     * @param bool                $withNote    Public note synchronised
     * @return array<string,?string>
     */
    public static function wailoView(array $c, $withSocials = true, $withNote = true)
    {
        $d = self::wailoToDoli($c, null, $withSocials, $withNote);
        return self::viewDoli($d, self::placeholder($c), self::cut(self::txt($c['company'] ?? null), 128), $withSocials, $withNote);
    }

    /**
     * Compare two values of a view field, the way Wailo normalises them.
     *
     * @param string      $field Field name
     * @param string|null $a     Value A
     * @param string|null $b     Value B
     * @return bool
     */
    public static function sameValue($field, $a, $b)
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (in_array($field, array('mobile', 'pro'), true)) {
            return preg_replace('/\D+/', '', $a) === preg_replace('/\D+/', '', $b);
        }
        if (in_array($field, array('first_name', 'last_name', 'company', 'email'), true)) {
            return mb_strtolower($a) === mb_strtolower($b);
        }
        return $a === $b;
    }

    /**
     * Has the view changed?
     *
     * @param array<string,?string> $before Previous view
     * @param array<string,?string> $after  Current view
     * @return bool
     */
    public static function viewChanged(array $before, array $after)
    {
        foreach ($after as $f => $v) {
            if (!self::sameValue($f, $before[$f] ?? null, $v)) {
                return true;
            }
        }
        return false;
    }

    /**
     * PATCH body: only the fields changed in Dolibarr, applied on the freshly read Wailo contact.
     * Lists (emails, phones, addresses) are rebuilt from the full Wailo list: only the entry held
     * by the Dolibarr slot changes, the rest is kept as is.
     *
     * @param array<string,?string> $before      Previous view
     * @param array<string,?string> $after       Current view
     * @param array<string,mixed>   $wc          Wailo contact, read just before
     * @param string|null           $placeholder Last name placeholder of the link
     * @param array<string,?string> $digits      Digits of the numbers held by the slots
     * @return array<string,mixed>
     */
    public static function patchBody(array $before, array $after, array $wc, $placeholder, array $digits)
    {
        $body = array();
        foreach (array('first_name', 'last_name', 'company', 'job_title', 'about') as $f) {
            if (!array_key_exists($f, $after)) {
                continue; // field not managed (public note off)
            }
            if (!self::sameValue($f, $before[$f] ?? null, $after[$f])) {
                $body[$f] = $after[$f];
            }
        }
        if (array_key_exists('last_name', $body) && $body['last_name'] === null && !empty($placeholder)) {
            unset($body['last_name']);
        }
        if (!self::sameValue('email', $before['email'] ?? null, $after['email'] ?? null)) {
            $emails = array();
            foreach ($wc['emails'] ?? array() as $e) {
                $emails[] = array('value' => $e['value'], 'label' => $e['label'] ?? null);
            }
            if ($after['email'] === null) {
                array_shift($emails);
            } elseif (!$emails) {
                $emails = array(array('value' => $after['email'], 'label' => 'Pro'));
            } else {
                $emails[0]['value'] = $after['email'];
            }
            $seen = array();
            $unique = array();
            foreach ($emails as $e) {
                $k = mb_strtolower($e['value']);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $unique[] = $e;
                }
            }
            $body['emails'] = $unique;
        }
        $phones = array();
        foreach ($wc['phones'] ?? array() as $p) {
            $phones[] = array('value' => $p['value'], 'label' => $p['label'] ?? null);
        }
        $phonesChanged = false;
        foreach (array('mobile' => 'Mobile', 'pro' => 'Pro') as $slot => $label) {
            if (self::sameValue($slot, $before[$slot] ?? null, $after[$slot] ?? null)) {
                continue;
            }
            $phonesChanged = true;
            $target = $digits[$slot] ?? null;
            $idx = null;
            if ($target) {
                foreach ($phones as $i => $p) {
                    if (self::digits9($p['value']) === $target) {
                        $idx = $i;
                        break;
                    }
                }
            }
            if ($after[$slot] === null) {
                if ($idx !== null) {
                    array_splice($phones, $idx, 1);
                }
            } elseif ($idx !== null) {
                $phones[$idx]['value'] = $after[$slot];
            } else {
                $phones[] = array('value' => $after[$slot], 'label' => $label);
            }
        }
        if ($phonesChanged) {
            $body['phones'] = array_values($phones);
        }
        if (!self::sameValue('address', $before['address'] ?? null, $after['address'] ?? null)) {
            $addresses = array();
            foreach ($wc['addresses'] ?? array() as $a) {
                $addresses[] = array('label' => $a['label'] ?? null, 'address' => $a['address']);
            }
            if ($after['address'] === null) {
                array_shift($addresses);
            } elseif (!$addresses) {
                $addresses = array(array('label' => null, 'address' => $after['address']));
            } else {
                $addresses[0]['address'] = $after['address'];
            }
            $body['addresses'] = array_values($addresses);
        }
        $socials = array();
        foreach (array('linkedin', 'facebook', 'instagram') as $s) {
            if (!array_key_exists($s, $after)) {
                continue; // social networks not managed
            }
            if (!self::sameValue($s, $before[$s] ?? null, $after[$s] ?? null)) {
                $socials[$s] = $after[$s];
            }
        }
        if ($socials) {
            $body['socials'] = $socials;
        }
        return $body;
    }

    /**
     * POST body from a new Dolibarr contact. No type (Dolibarr has none on a contact): Wailo sets professional.
     *
     * @param array<string,?string> $v View of the Dolibarr contact
     * @return array<string,mixed>
     */
    public static function createBody(array $v)
    {
        $c = array();
        foreach (array('first_name', 'last_name', 'company', 'job_title', 'about') as $f) {
            if (($v[$f] ?? null) !== null) {
                $c[$f] = $v[$f];
            }
        }
        if ($v['email'] !== null) {
            $c['emails'] = array(array('value' => $v['email'], 'label' => 'Pro'));
        }
        $ph = array();
        if ($v['mobile'] !== null) {
            $ph[] = array('value' => $v['mobile'], 'label' => 'Mobile');
        }
        if ($v['pro'] !== null) {
            $ph[] = array('value' => $v['pro'], 'label' => 'Pro');
        }
        if ($ph) {
            $c['phones'] = $ph;
        }
        if ($v['address'] !== null) {
            $c['addresses'] = array(array('label' => null, 'address' => $v['address']));
        }
        $s = array();
        foreach (array('linkedin', 'facebook', 'instagram') as $k) {
            if (($v[$k] ?? null) !== null) {
                $s[$k] = $v[$k];
            }
        }
        if ($s) {
            $c['socials'] = $s;
        }
        return $c;
    }
}
