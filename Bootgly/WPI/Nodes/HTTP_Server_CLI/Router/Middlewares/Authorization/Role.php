<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;


use function in_array;
use function is_array;
use function is_string;
use InvalidArgumentException;

use Bootgly\API\Security\Identity;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing\Gate;


/**
 * Claim-role authorization gate.
 *
 * Accepts a singular `role` claim and a string/list `roles` claim for JWT and
 * provider compatibility. Role names are matched exactly and case-sensitively.
 */
class Role extends Gate
{
   // * Config
   /**
    * Required role grants.
    *
    * @var array<int,string>
    */
   public private(set) array $roles;
   /**
    * Whether all configured roles are required.
    */
   public private(set) bool $all;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Configure required role grants.
    *
    * @param string|array<int,string> $roles
    */
   public function __construct (string|array $roles, bool $all = false)
   {
      // * Config
      $this->roles = $this->normalize($roles);
      $this->all = $all;
   }

   /**
    * Authorize a request by Identity role claims.
    */
   public function authorize (object $Request): bool
   {
      $Identity = $this->resolve($Request);
      if ($Identity === null) {
         return false;
      }

      $grants = $this->extract($Identity);

      if ($this->all) {
         foreach ($this->roles as $role) {
            if (in_array($role, $grants, true) === false) {
               return false;
            }
         }

         return true;
      }

      foreach ($this->roles as $role) {
         if (in_array($role, $grants, true)) {
            return true;
         }
      }

      return false;
   }

   /**
    * Extract role grants from the authenticated identity claims.
    *
    * @return array<int,string>
    */
   private function extract (Identity $Identity): array
   {
      $roles = [];

      $role = $Identity->claims['role'] ?? null;
      if (is_string($role) && $role !== '') {
         $roles[] = $role;
      }

      $claim = $Identity->claims['roles'] ?? [];
      if (is_string($claim) && $claim !== '') {
         $roles[] = $claim;
      }

      if (is_array($claim)) {
         foreach ($claim as $grant) {
            if (is_string($grant) && $grant !== '') {
               $roles[] = $grant;
            }
         }
      }

      return $roles;
   }

   /**
    * Normalize a configured role list.
    *
    * @param string|array<int|string,mixed> $roles
    * @return array<int,string>
    */
   private function normalize (string|array $roles): array
   {
      if (is_string($roles)) {
         if ($roles === '') {
            throw new InvalidArgumentException('Authorization role must not be empty.');
         }

         return [$roles];
      }

      $Roles = [];
      foreach ($roles as $role) {
         if (is_string($role) === false || $role === '') {
            throw new InvalidArgumentException('Authorization roles must be non-empty strings.');
         }

         $Roles[] = $role;
      }

      if ($Roles === []) {
         throw new InvalidArgumentException('Authorization roles must not be empty.');
      }

      return $Roles;
   }
}
