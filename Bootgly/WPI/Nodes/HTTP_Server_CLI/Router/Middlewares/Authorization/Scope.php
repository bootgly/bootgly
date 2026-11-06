<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;


use function is_object;
use function is_string;
use function preg_match;
use InvalidArgumentException;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;


/**
 * Exact-scope authorization gate.
 */
class Scope extends Gate
{
   // * Config
   /**
    * Required exact scope grants.
    *
    * @var array<int,string>
    */
   public private(set) array $scopes;
   /**
    * Whether all configured scopes are required.
    */
   public private(set) bool $all;
   /**
    * Optional Bearer guard whose challenge answers a denial.
    *
    * With it, a denial answers `403 Forbidden` plus `WWW-Authenticate: Bearer
    * realm="…", error="insufficient_scope", scope="…"` (RFC 6750 §3.1) and the
    * guard's `resource_metadata` when set — or, when the request carries no
    * identity at all, the guard's own `401 Unauthorized` challenge, as the
    * authentication would answer. Without it, a denial is a bare
    * `403 Forbidden`.
    */
   public private(set) null|Bearer $Bearer;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Configure required scope grants.
    *
    * With a `Bearer`, every scope must also be an RFC 6749 §3.3 `scope-token`
    * (printable ASCII without space, `"` and `\`), because the scopes are
    * sent space-separated in the challenge.
    *
    * @param string|array<int,string> $scopes
    *
    * @throws InvalidArgumentException When a scope is empty, or is not a
    *         `scope-token` while a Bearer challenge is configured.
    */
   public function __construct (string|array $scopes, bool $all = true, null|Bearer $Bearer = null)
   {
      // * Config
      $this->scopes = $this->normalize($scopes);
      $this->all = $all;
      $this->Bearer = $Bearer;

      // ? Challenged scopes are sent space-separated: each must be a scope-token.
      if ($Bearer !== null) {
         foreach ($this->scopes as $scope) {
            if (preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $scope) !== 1) {
               throw new InvalidArgumentException(
                  'Authorization scopes challenged by a Bearer must be RFC 6749 scope-tokens.'
               );
            }
         }
      }
   }

   /**
    * Authorize a request by exact Identity scope grants.
    */
   public function authorize (object $Request): bool
   {
      $Identity = $this->resolve($Request);
      if ($Identity === null) {
         return false;
      }

      if ($this->all) {
         foreach ($this->scopes as $scope) {
            if ($Identity->check($scope) === false) {
               return false;
            }
         }

         return true;
      }

      foreach ($this->scopes as $scope) {
         if ($Identity->check($scope)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Build the forbidden response for a missing scope grant.
    *
    * With a configured `Bearer`, the `403 Forbidden` also carries the
    * `insufficient_scope` challenge listing the required scopes; a request
    * that carried no identity at all gets the Bearer's `401 Unauthorized`
    * challenge instead (`Bearer::challenge()`). The identity is read from the
    * Request the Response answers (`$Response->Request`), so the gate keeps
    * no per-request state and concurrent requests never share a verdict; a
    * response-like object without a Request answers `403`.
    */
   public function deny (object $Response): object
   {
      // ?: No identity at all → the Bearer's 401 authentication challenge.
      if ($this->Bearer !== null) {
         $Request = $Response->Request ?? null;

         if (is_object($Request) && ($Request->identity ?? null) === null) {
            return $this->Bearer->challenge($Response);
         }
      }

      // ! Bare 403 Forbidden.
      $Result = parent::deny($Response);

      // ?:
      if ($this->Bearer === null) {
         return $Result;
      }

      // : 403 + insufficient_scope challenge.
      return $this->Bearer->forbid($Result, $this->scopes);
   }

   /**
    * Normalize a configured scope list.
    *
    * @param string|array<int|string,mixed> $scopes
    * @return array<int,string>
    */
   private function normalize (string|array $scopes): array
   {
      if (is_string($scopes)) {
         if ($scopes === '') {
            throw new InvalidArgumentException('Authorization scope must not be empty.');
         }

         return [$scopes];
      }

      $Scopes = [];
      foreach ($scopes as $scope) {
         if (is_string($scope) === false || $scope === '') {
            throw new InvalidArgumentException('Authorization scopes must be non-empty strings.');
         }

         $Scopes[] = $scope;
      }

      if ($Scopes === []) {
         throw new InvalidArgumentException('Authorization scopes must not be empty.');
      }

      return $Scopes;
   }
}
