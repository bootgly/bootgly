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


use Closure;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;


/**
 * Opaque HTTP Bearer token authentication guard.
 *
 * The guard extracts a Bearer token from the request and lets application code
 * resolve it. It does not inspect token structure, making it suitable for API
 * keys, database-backed access tokens, and other opaque credential schemes.
 */
class Bearer extends Guard
{
   // * Config
   /**
    * Application callback that resolves the opaque token.
    *
    * The callback receives `(string $token, Request $Request)` and should
    * return `false`/`null` for denial, `true` to expose the token, or a custom
    * identity value to expose to handlers.
    */
   public private(set) Closure $Resolver;

   // * Data
   /**
    * RFC 6750 error code emitted in failed Bearer challenges.
    */
   public private(set) string $error;
   /**
    * Human-readable Bearer challenge error description.
    */
   public private(set) string $description;
   /**
    * Bearer challenge documentation URI.
    */
   public private(set) string $URI;
   /**
    * Required authorization scope advertised by the challenge.
    */
   public private(set) string $scope;

   // * Metadata
   // ...


   /**
    * Configure opaque Bearer token resolution.
    *
    * @param Closure(string,Request):mixed $Resolver
    */
   public function __construct (
      Closure $Resolver,
      string $realm = 'Protected area',
      string $error = 'invalid_token',
      string $description = '',
      string $URI = '',
      string $scope = ''
   )
   {
      parent::__construct($realm);

      // * Config
      $this->Resolver = $Resolver;

      // * Data
      $this->error = $error;
      $this->description = $description;
      $this->URI = $URI;
      $this->scope = $scope;
   }

   /**
    * Authenticate a request with an opaque Bearer token.
    */
   public function authenticate (object $Request): bool
   {
      // ! Bearer token.
      $token = $this->extract($Request);
      if ($token === '') {
         return false;
      }

      // @ Resolve opaque token.
      $identity = ($this->Resolver)($token, $Request);
      if ($identity === null || $identity === false) {
         return false;
      }

      // @ Expose resolver result to handlers.
      $this->expose($Request, 'identity', $identity === true ? $token : $identity);

      return true;
   }

   /**
    * Challenge the client with `WWW-Authenticate: Bearer`.
    */
   public function challenge (object $Response): object
   {
      return $this->announce($Response, $this->format('Bearer', [
         'realm' => $this->realm,
         'error' => $this->error,
         'error_description' => $this->description,
         'error_uri' => $this->URI,
         'scope' => $this->scope,
      ]));
   }
}