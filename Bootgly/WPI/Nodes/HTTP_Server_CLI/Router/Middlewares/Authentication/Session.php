<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;


use function is_object;
use function method_exists;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * Session-backed authentication guard.
 *
 * The guard checks an application-defined key in the request session and
 * exposes its value as the route identity when available. Session flows do not
 * emit `WWW-Authenticate`; they fail with a generic unauthorized response.
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
      if (is_object($Session) === false || method_exists($Session, 'check') === false) {
         return false;
      }

      // ? Missing identity.
      if ($Session->check($this->key) === false) {
         return false;
      }

      // @ Expose identity to handlers.
      if (method_exists($Session, 'get')) {
         $this->expose($Request, 'identity', $Session->get($this->key));
      }

      return true;
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