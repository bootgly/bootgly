<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;


use function is_object;
use function is_string;
use function method_exists;

use Bootgly\API\Security\Tokens\Theft;
use Bootgly\API\Security\Tokens\Token;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * Persistent-login (remember-me) authentication guard.
 *
 * The guard revives a session from the remember cookie: it validates and
 * rotates the trusted-device token through the `Trust` store, regenerates
 * the session id (fixation defense), installs the identity in the session
 * and re-emits the rotated cookie. It is the single owner of the remember
 * cookie — login/logout flows reuse `emit()`/`forget()` on the same guard.
 *
 * Canonical composition places it after the session guard, so the cheap
 * session check wins and rotation only runs on session misses:
 * `new Authenticating(new Session($key), new Remember($Trust, $key))`.
 */
class Remember extends Guard
{
   // * Config
   // # Cookie policy — framework-owned, mirrors `Request\Session` (audit F-9):
   //   stock php.ini would silently downgrade `secure`/`httpOnly`.
   public static string $name = 'remember';
   public static int $lifetime = 2592000;
   public static string $path = '/';
   public static string $domain = '';
   public static bool $secure = true;
   public static bool $httpOnly = true;
   public static string $sameSite = 'Lax';

   // * Data
   /**
    * Trusted-device token store.
    */
   public private(set) Trust $Trust;
   /**
    * Session key that carries the authenticated identity.
    */
   public private(set) string $key;

   // * Metadata
   // ...


   /**
    * Configure the trusted-device store and the session identity key.
    */
   public function __construct (Trust $Trust, string $key = 'identity', string $realm = 'Protected area')
   {
      parent::__construct($realm);

      // * Data
      $this->Trust = $Trust;
      $this->key = $key;
   }

   /**
    * Re-login a request from its remember cookie.
    */
   public function authenticate (object $Request): bool
   {
      // ! Session object.
      $Session = $Request->Session ?? null;
      if (is_object($Session) === false || method_exists($Session, 'set') === false) {
         return false;
      }

      // ! Remember cookie.
      $Cookies = $Request->Cookies ?? null;
      if (is_object($Cookies) === false || method_exists($Cookies, 'get') === false) {
         return false;
      }
      $cookie = $Cookies->get(static::$name);
      // ?
      if (is_string($cookie) === false || $cookie === '') {
         return false;
      }

      // @ Validate + rotate the trusted-device token.
      $Result = $this->Trust->rotate($cookie, static::$lifetime);

      // ? Stolen-cookie signature — every device was revoked; drop the cookie
      if ($Result instanceof Theft) {
         $this->forget();

         return false;
      }
      // ? Unknown, expired or raced series
      if ($Result === null) {
         return false;
      }

      // @ Re-login: fixation defense, identity, rotated cookie.
      if (method_exists($Session, 'regenerate')) {
         $Session->regenerate();
      }
      $Session->set($this->key, $Result->user);
      $this->emit($Result);
      $this->expose($Request, 'identity', $Result->user);

      return true;
   }

   /**
    * Reject unauthorized requests without an HTTP auth challenge.
    */
   public function challenge (object $Response): object
   {
      // : Browser/session flows do not require a WWW-Authenticate challenge.
      return $this->reject($Response);
   }

   /**
    * Append the remember Set-Cookie for a trusted-device token.
    *
    * Called on revival (rotated token) and by login flows after
    * `Trust->issue()` — one canonical cookie owner.
    */
   public function emit (Token $Token): void
   {
      // ? Unit contexts run without the server response singleton
      if (isSet(HTTP_Server_CLI::$Response) === false) {
         return;
      }

      $Cookie = new Cookie(static::$name, $Token->value);
      // ? Lifetime 0 = browser-session cookie (omit Max-Age)
      $Cookie->expiration = static::$lifetime > 0 ? static::$lifetime : null;
      $Cookie->path = static::$path;
      $Cookie->domain = static::$domain;
      $Cookie->secure = static::$secure;
      $Cookie->HTTP_only = static::$httpOnly;
      $Cookie->same_site = static::$sameSite;

      HTTP_Server_CLI::$Response->Header->Cookies->append($Cookie);
   }

   /**
    * Append an expiring remember Set-Cookie (logout / theft).
    */
   public function forget (): void
   {
      // ? Unit contexts run without the server response singleton
      if (isSet(HTTP_Server_CLI::$Response) === false) {
         return;
      }

      $Cookie = new Cookie(static::$name, '');
      // ! Max-Age=0 deletes the cookie on the client.
      $Cookie->expiration = 0;
      $Cookie->path = static::$path;
      $Cookie->domain = static::$domain;
      $Cookie->secure = static::$secure;
      $Cookie->HTTP_only = static::$httpOnly;
      $Cookie->same_site = static::$sameSite;

      HTTP_Server_CLI::$Response->Header->Cookies->append($Cookie);
   }
}
