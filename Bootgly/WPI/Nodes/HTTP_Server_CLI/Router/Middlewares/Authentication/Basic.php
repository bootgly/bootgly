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


use function method_exists;
use Closure;

use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication\Basic as BasicChallenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications\Basic as Credentials;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * HTTP Basic authentication guard.
 *
 * The guard parses Basic credentials from the request, delegates credential
 * verification to an application resolver, and exposes the authenticated
 * identity to route handlers. It is primarily for compatibility and simple
 * protected demos; application policy decides how credentials are verified.
 */
class Basic extends Guard
{
   // * Config
   /**
    * Application callback that validates username and password credentials.
    *
    * The callback receives `(string $username, string $password, Request $Request)`
    * and should return `false`/`null` for denial, `true` to expose the username,
    * or a custom identity value to expose to handlers.
    */
   public private(set) Closure $Resolver;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Configure Basic credential resolution.
    *
    * @param Closure(string,string,Request):mixed $Resolver
    */
   public function __construct (Closure $Resolver, string $realm = 'Protected area')
   {
      parent::__construct($realm);

      // * Config
      $this->Resolver = $Resolver;
   }

   /**
    * Authenticate request credentials using the resolver callback.
    */
   public function authenticate (object $Request): bool
   {
      // ? Missing HTTP authentication parser.
      if (method_exists($Request, 'authenticate') === false) {
         return false;
      }

      // ! Credentials.
      $Credentials = $Request->authenticate();
      if (($Credentials instanceof Credentials) === false) {
         return false;
      }

      // @ Resolve credentials.
      $identity = ($this->Resolver)($Credentials->username, $Credentials->password, $Request);
      if ($identity === null || $identity === false) {
         return false;
      }

      // @ Expose resolver result to handlers.
      $this->expose($Request, 'identity', $identity === true ? $Credentials->username : $identity);

      return true;
   }

   /**
    * Challenge the client with `WWW-Authenticate: Basic`.
    */
   public function challenge (object $Response): object
   {
      // :
      return $this->respond($Response, new BasicChallenge($this->realm));
   }
}