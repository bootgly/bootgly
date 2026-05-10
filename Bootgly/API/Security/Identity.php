<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function in_array;


/**
 * Authenticated principal value object.
 *
 * `Identity` carries the stable subject identifier plus optional claims and
 * scopes produced by authentication guards. It is intentionally transport-
 * agnostic, so it can represent a session user, an opaque bearer token owner,
 * or a JWT subject without depending on any HTTP classes.
 */
class Identity
{
   // * Config
   /**
    * Stable application identifier for the authenticated principal.
    *
    * For JWT credentials this commonly maps to the `sub` claim. For session
    * and opaque token flows it can be any application-defined user/client id.
    */
   public string $id;

   // * Data
   /**
    * Authentication claims associated with the principal.
    *
    * @var array<string,mixed>
    */
   public array $claims;
   /**
    * Authorization scopes granted to the principal.
    *
    * @var array<int,string>
    */
   public array $scopes;

   // * Metadata
   // ...


   /**
    * Create a generic authenticated identity.
    *
    * @param string $id Stable principal identifier.
    * @param array<string,mixed> $claims
    * @param array<int,string> $scopes
    */
   public function __construct (string $id, array $claims = [], array $scopes = [])
   {
      // * Config
      $this->id = $id;

      // * Data
      $this->claims = $claims;
      $this->scopes = $scopes;
   }

   /**
    * Check if the identity has an exact scope grant.
    *
    * Scope comparison is strict and case-sensitive. Wildcards and scope
    * hierarchy are intentionally left to application policy layers.
    */
   public function check (string $scope): bool
   {
      // :
      return in_array($scope, $this->scopes, true);
   }
}
