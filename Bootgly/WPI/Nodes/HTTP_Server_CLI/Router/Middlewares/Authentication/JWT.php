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


use function explode;
use function is_array;
use function is_string;
use function trim;

use Bootgly\API\Security\Identity;
use Bootgly\API\Security\JWT as Token;
use Bootgly\API\Security\JWT\Policies;


/**
 * JWT authentication guard using Bearer transport.
 *
 * The guard extracts the Bearer token, verifies it with the configured
 * `Bootgly\API\Security\JWT` primitive, exposes trusted claims to handlers,
 * and creates an `Identity` when the `sub` claim is present. It inherits from
 * `Bearer` only for extraction and RFC 6750 challenge formatting; its resolver
 * is intentionally inert because JWT validation is handled by `$Token`.
 */
class JWT extends Bearer
{
   // * Config
   /**
    * JWT signer/verifier used to validate incoming tokens.
    */
   public private(set) Token $Token;
   /**
    * Optional claim policies enforced after token verification.
    */
   public private(set) null|Policies $Policies;

   // * Data
   // ...inherited Bearer challenge data

   // * Metadata
   // ...


   /**
    * Configure JWT verification for protected routes.
    */
   public function __construct (
      Token $Token,
      null|Policies $Policies = null,
      string $realm = 'Protected area',
      string $error = 'invalid_token',
      string $description = '',
      string $URI = '',
      string $scope = ''
   )
   {
      parent::__construct(
         Resolver: static function (): bool {
            return false;
         },
         realm: $realm,
         error: $error,
         description: $description,
         URI: $URI,
         scope: $scope
      );

      // * Config
      $this->Token = $Token;
      $this->Policies = $Policies;
   }

   /**
    * Authenticate a request by verifying its Bearer JWT.
    */
   public function authenticate (object $Request): bool
   {
      // ! Bearer token.
      $token = $this->extract($Request);
      if ($token === '') {
         return false;
      }

      // @ Verify JWT.
      $Verification = $this->Token->inspect($token, $this->Policies);
      if ($Verification->valid === false) {
         return false;
      }

      $claims = $Verification->claims;

      // @ Expose claims and identity to handlers.
      $this->expose($Request, 'claims', $claims);
      if ($Verification->Header !== null) {
         $this->expose($Request, 'tokenHeaders', $Verification->Header->fields);
      }

      if (isset($claims['sub']) && is_string($claims['sub'])) {
         $this->expose($Request, 'identity', new Identity(
            id: $claims['sub'],
            claims: $claims,
            scopes: $this->normalize($claims['scope'] ?? $claims['scp'] ?? [])
         ));
      }

      return true;
   }

   /**
    * Normalize JWT `scope`/`scp` claims into exact `Identity` scope grants.
    *
    * OAuth-style JWTs commonly encode scopes as a single space-separated
      * string in the `scope` claim. Bootgly gives `scope` precedence over `scp`
      * when both are present. Arrays of string grants are accepted for `scp` or
      * application-defined tokens.
    *
    * @return array<int,string>
    */
   private function normalize (mixed $scope): array
   {
      if (is_string($scope)) {
         $Scopes = [];
         foreach (explode(' ', trim($scope)) as $grant) {
            if ($grant !== '') {
               $Scopes[] = $grant;
            }
         }

         return $Scopes;
      }

      if (is_array($scope)) {
         $Scopes = [];
         foreach ($scope as $grant) {
            if (is_string($grant) && $grant !== '') {
               $Scopes[] = $grant;
            }
         }

         return $Scopes;
      }

      return [];
   }
}