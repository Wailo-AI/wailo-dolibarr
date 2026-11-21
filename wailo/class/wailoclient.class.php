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
 * \file    wailo/class/wailoclient.class.php
 * \ingroup wailo
 * \brief   Minimal client of the Wailo public API (v1).
 *
 * The API key is only sent in the Authorization header. It is never logged, never put in an URL,
 * never returned by any method of this class.
 */

/**
 * Wailo asked to slow down (429) and waiting is not possible here (web request): the pass stops and resumes later.
 */
class WailoRateLimitedException extends RuntimeException
{
}

/**
 * Wailo answered 401: the API key was revoked or is no longer valid. The pass stops at once (nothing is quarantined).
 */
class WailoKeyRefusedException extends RuntimeException
{
}

/**
 * Minimal client of the Wailo public API (v1).
 */
class WailoClient
{
    public const USER_AGENT = 'WailoForDolibarr/0.2.0';
    public const MAX_RETRIES = 5;
    public const MAX_WAIT = 300;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var callable|null function (string $severity, string $code, array $params) */
    private $logger;

    /** @var callable Sleep function (seconds), replaceable for tests */
    public $sleeper = 'sleep';

    /** @var bool Wait on 429 (command line, scheduled job); false in a web request: stop and resume at the next pass */
    public $allowSleep = true;

    /**
     * Constructor
     *
     * @param string        $baseUrl Base URL of the API, for example https://api.wailo.app/api/public/v1
     * @param string        $apiKey  API key (wlo_test_ or wlo_live_)
     * @param callable|null $logger  Logger
     */
    public function __construct(
        $baseUrl,
        #[\SensitiveParameter]
        $apiKey,
        $logger = null
    ) {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->apiKey = (string) $apiKey;
        $this->logger = $logger;
    }

    /**
     * Is the base URL acceptable (https only, so the key never travels in clear)?
     *
     * @param string $url URL
     * @return bool
     */
    public static function isValidBaseUrl($url)
    {
        if (!preg_match('~^https://[^/\s?#@]+(/[^\s?#]*)?$~i', (string) $url)) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Is the host of this URL a Wailo host (wailo.app or *.wailo.app)? The key is only ever sent to such a host,
     * unless the hidden constant WAILO_ALLOW_ANY_HOST is set (test servers).
     *
     * @param string $url     URL
     * @param bool   $anyHost Any https host allowed
     * @return bool
     */
    public static function isAllowedUrl($url, $anyHost = false)
    {
        if (!self::isValidBaseUrl($url)) {
            return false;
        }
        if ($anyHost) {
            return true;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return (bool) preg_match('/(^|\.)wailo\.app$/', $host) && strpos((string) $url, '@') === false && parse_url($url, PHP_URL_PORT) === null;
    }

    /**
     * Call the Wailo API, with the 429 rule (Retry-After, capped, 5 attempts).
     *
     * @param string               $method  HTTP method
     * @param string               $path    Path below the base URL (starting with /)
     * @param array|null           $body    JSON body
     * @param array<string,string> $headers Extra headers (If-Match, Idempotency-Key)
     * @return array{status:int,headers:array<string,string>,json:mixed}
     * @throws RuntimeException On network error or repeated 429
     * @throws WailoKeyRefusedException On 401 (key revoked or invalid)
     */
    public function request($method, $path, $body = null, array $headers = array())
    {
        $base = array(
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        );
        if ($body !== null) {
            $base['Content-Type'] = 'application/json';
        }
        for ($attempt = 0;; $attempt++) {
            $r = $this->send($method, $this->baseUrl . $path, $base + $headers, $body);
            if ($r['status'] !== 429) {
                if ($r['status'] >= 400) {
                    $e = (is_array($r['json']) && isset($r['json']['error']) && is_array($r['json']['error'])) ? $r['json']['error'] : array();
                    $this->log('warning', 'WailoHttpError', array(
                        $method,
                        $path,
                        $r['status'],
                        (string) ($e['code'] ?? '?'),
                        (string) ($e['request_id'] ?? ($r['headers']['wailo-request-id'] ?? '?')),
                    ));
                }
                if ($r['status'] === 401) {
                    throw new WailoKeyRefusedException('401 unauthenticated: the Wailo API key was revoked or is no longer valid');
                }
                return $r;
            }
            $code = (string) ($r['json']['error']['code'] ?? '');
            if ($code === 'delete_limit_reached') {
                $this->log('warning', 'DeleteLimitReached', array());
                return $r;
            }
            if ($attempt >= self::MAX_RETRIES) {
                throw new RuntimeException('429 repeated: stopping, a human should check');
            }
            $wait = (int) ($r['headers']['retry-after'] ?? 0);
            $wait = $wait > 0 ? min($wait, self::MAX_WAIT) : 60;
            if (!$this->allowSleep) {
                $this->log('warning', 'RateLimitedDeferred', array($wait));
                throw new WailoRateLimitedException('429 rate_limited: pass stopped, it resumes at the next run');
            }
            $this->log('warning', 'RateLimited', array($wait));
            call_user_func($this->sleeper, $wait);
        }
    }

    /**
     * Raw HTTP call with curl.
     *
     * @param string               $method  Method
     * @param string               $url     Full URL
     * @param array<string,string> $headers Headers
     * @param array|null           $body    JSON body
     * @return array{status:int,headers:array<string,string>,json:mixed}
     * @throws RuntimeException On network error
     */
    protected function send(
        $method,
        $url,
        #[\SensitiveParameter]
        array $headers,
        $body
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP curl extension is missing');
        }
        $ch = curl_init($url);
        $h = array();
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $opts = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            // Some Windows PHP builds (DoliWamp) have no CA bundle: use the Windows store.
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('network: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $respHeaders = array();
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (strpos($line, ':') !== false) {
                list($k, $v) = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        $text = substr($raw, $headerSize);
        $json = ($text !== '' && $text !== false) ? json_decode($text, true) : null;
        return array('status' => $status, 'headers' => $respHeaders, 'json' => $json);
    }

    /**
     * Log through the logger given to the constructor.
     *
     * @param string            $severity info|warning|error
     * @param string            $code     Translation code
     * @param array<int,mixed>  $params   Parameters (never a secret)
     * @return void
     */
    private function log($severity, $code, array $params)
    {
        if ($this->logger) {
            call_user_func($this->logger, $severity, $code, $params);
        }
    }
}
