<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;


use function is_object;
use function is_string;
use function method_exists;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session as Store;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * Session-backed authentication guard.
 *
 * The guard checks an application-defined key in the request session and
 * exposes its non-null, non-false value as the route identity. Session flows
 * do not emit `WWW-Authenticate`; they fail with a generic unauthorized
 * response.
 */
class Session extends Guard
{
   // * Config
   // ...

   // * Data
   /**
    * Session key expected to contain an authenticated identity.
    */
   public private(set) string $key;

   // * Metadata
   // ...


   /**
    * Configure the session identity key.
    */
   public function __construct (string $key = 'identity', string $realm = 'Protected area')
   {
      parent::__construct($realm);

      // * Data
      $this->key = $key;
   }

   /**
    * Authenticate a request by checking its session identity key.
    */
   public function authenticate (object $Request): bool
   {
      // ! Session object.
      $Session = $Request->Session ?? null;
      if (
         is_object($Session) === false
         || method_exists($Session, 'check') === false
         || method_exists($Session, 'get') === false
      ) {
         return false;
      }

      // ? Missing identity.
      if ($Session->check($this->key) === false) {
         return false;
      }

      // ! A present falsey sentinel is not an authenticated identity. Fetch
      //   once so the value validated here is exactly the value exposed.
      $identity = $Session->get($this->key);
      if ($identity === null || $identity === false) {
         return false;
      }

      // @ Expose identity to handlers.
      $this->expose($Request, 'identity', $identity);

      return true;
   }

   /**
    * Tell whether the request may carry a session identity, without I/O.
    *
    * A Session already built for this request (`sessioned`) is answered
    * exactly — the identity key holds a value other than `null`/`false`,
    * as `authenticate()` requires; otherwise the request must carry a
    * non-empty session cookie (`Request\Session::$name`). The Session is
    * never built here: building it reads the session store.
    */
   public function detect (object $Request): bool
   {
      // ?: A Session already built for this request — answer from it
      if (($Request->sessioned ?? false) === true) {
         $Session = $Request->Session ?? null;
         if (
            is_object($Session) === false
            || method_exists($Session, 'check') === false
            || method_exists($Session, 'get') === false
            || $Session->check($this->key) === false
         ) {
            return false;
         }

         $identity = $Session->get($this->key);

         return $identity !== null && $identity !== false;
      }

      // ! Session cookie.
      $Cookies = $Request->Cookies ?? null;
      if (is_object($Cookies) === false || method_exists($Cookies, 'get') === false) {
         return false;
      }
      $cookie = $Cookies->get(Store::$name);

      // :
      return is_string($cookie) && $cookie !== '';
   }

   /**
    * Reject unauthorized session requests without an HTTP auth challenge.
    */
   public function challenge (object $Response): object
   {
      // : Browser/session flows do not require a WWW-Authenticate challenge.
      return $this->reject($Response);
   }
}
