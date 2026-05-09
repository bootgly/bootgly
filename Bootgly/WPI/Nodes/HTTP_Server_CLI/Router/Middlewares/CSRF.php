<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use const PHP_URL_HOST;
use function bin2hex;
use function explode;
use function hash_equals;
use function in_array;
use function is_string;
use function max;
use function parse_url;
use function random_bytes;
use Closure;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;


/**
 * Synchronizer-token CSRF middleware.
 *
 * Stores a per-session token in `$Request->Session` under `$sessionKey`.
 * Validates unsafe HTTP methods (POST/PUT/PATCH/DELETE) against a token
 * supplied via the request header `$headerName` or the form field
 * `$formField` (read from `$Request->fields`).
 *
 * Safe methods (GET/HEAD/OPTIONS) bypass validation but still ensure the
 * token exists in session so templates can render it via:
 *
 *   $Request->Session->get('_csrf_token')
 *
 * Token rotation is per-session: the token is generated once when the
 * session is first touched and rotated only by `Session::regenerate()`
 * (login or privilege escalation).
 *
 * Optional defense-in-depth: when `$checkOrigin = true`, the middleware
 * compares the `Origin` (or fallback `Referer`) host against the request
 * `Host` header, or against `$allowedOrigins` when supplied.
 */
class CSRF implements Middleware
{
   // * Config
   public private(set) string $sessionKey;
   public private(set) string $headerName;
   public private(set) string $formField;
   public private(set) bool $checkOrigin;
   /** @var array<int,string> */
   public private(set) array $allowedOrigins;
   public private(set) int $tokenBytes;

   // * Data
   // ...

   // * Metadata
   /** @var array<int,string> */
   private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];


   /**
    * @param string $sessionKey Session key under which the CSRF token is stored.
    * @param string $headerName Request header name carrying the submitted token.
    * @param string $formField Form field name (in `$Request->fields`) carrying the submitted token.
    * @param bool $checkOrigin When true, validates Origin/Referer host before token check.
    * @param array<int,string> $allowedOrigins Allowlist of origin hostnames (used only when `$checkOrigin` is true).
    * @param int $tokenBytes Number of random bytes to generate (token will be twice this in hex).
    */
   public function __construct (
      string $sessionKey = '_csrf_token',
      string $headerName = 'X-CSRF-Token',
      string $formField = '_token',
      bool $checkOrigin = false,
      array $allowedOrigins = [],
      int $tokenBytes = 32
   )
   {
      // * Config
      $this->sessionKey = $sessionKey;
      $this->headerName = $headerName;
      $this->formField = $formField;
      $this->checkOrigin = $checkOrigin;
      $this->allowedOrigins = $allowedOrigins;
      $this->tokenBytes = $tokenBytes;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // ! Session
      $Session = $Request->Session;
      /** @var Session $Session */

      // @ Ensure token exists (covers safe + unsafe paths)
      if ($Session->has($this->sessionKey) === false) {
         $Session->set($this->sessionKey, bin2hex(random_bytes(max(1, $this->tokenBytes))));
      }

      // ? Safe method — bypass validation
      if (in_array($Request->method, self::SAFE_METHODS, true)) {
         return $next($Request, $Response);
      }

      // ---

      // ? Origin/Referer check
      if ($this->checkOrigin && $this->validate($Request) === false) {
         return $Response(code: 403, body: 'Invalid CSRF origin');
      }

      // @ Validate token
      $expected = $Session->get($this->sessionKey, '');
      $submitted = $this->extract($Request);

      if (
         is_string($expected) === false
         || $expected === ''
         || hash_equals($expected, $submitted) === false
      ) {
         return $Response(code: 403, body: 'Invalid CSRF token');
      }

      // :
      return $next($Request, $Response);
   }

   /**
    * Extract the submitted token from the request header or form field.
    *
    * @param Request $Request
    */
   private function extract (object $Request): string
   {
      // ! Header
      $header = $Request->Header->get($this->headerName);
      if (is_string($header) && $header !== '') {
         return $header;
      }

      // ! Form field
      $fields = $Request->fields;
      if (isset($fields[$this->formField]) && is_string($fields[$this->formField])) {
         return $fields[$this->formField];
      }

      // :
      return '';
   }

   /**
    * Validate Origin (or fallback Referer) host against request Host or allowlist.
    *
    * @param Request $Request
    */
   private function validate (object $Request): bool
   {
      // ! Read Origin or Referer
      $origin = $Request->Header->get('Origin') ?? $Request->Header->get('Referer');

      // ?
      if (is_string($origin) === false || $origin === '') {
         return false;
      }

      // @ Extract host from URL
      $originHost = parse_url($origin, PHP_URL_HOST);

      // ?
      if (is_string($originHost) === false || $originHost === '') {
         return false;
      }

      // ? Allowlist takes precedence
      if ($this->allowedOrigins !== []) {
         return in_array($originHost, $this->allowedOrigins, true);
      }

      // @ Same-origin: compare against Host header (strip port)
      $host = $Request->Header->get('Host');
      if (is_string($host) === false || $host === '') {
         return false;
      }

      $hostOnly = explode(':', $host, 2)[0];

      // :
      return $originHost === $hostOnly;
   }
}
