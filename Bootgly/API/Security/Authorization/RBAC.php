<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Authorization;


use function array_unique;
use function array_values;
use function is_numeric;
use function is_string;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Identity;


/**
 * Database-backed role-based access control permission resolver.
 */
class RBAC
{
   // * Config
   public private(set) SQLDatabase|Transaction $Database;
   public private(set) string $roles;
   public private(set) string $permissions;
   public private(set) string $rolePermissions;
   public private(set) string $userRoles;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (
      SQLDatabase|Transaction $Database,
      string $roles = 'roles',
      string $permissions = 'permissions',
      string $rolePermissions = 'role_permissions',
      string $userRoles = 'user_roles'
   )
   {
      // * Config
      $this->Database = $Database;
      $this->roles = $roles;
      $this->permissions = $permissions;
      $this->rolePermissions = $rolePermissions;
      $this->userRoles = $userRoles;
   }

   /**
    * Check whether an identity has a persisted permission through its roles.
    */
   public function check (Identity $Identity, string $permission): bool
   {
      if ($Identity->id === '' || $permission === '') {
         return false;
      }

      // @
      $Operation = $this->execute(
         $this->build($Identity)
            ->count(new Identifier('allowed'))
            ->filter(new Identifier("{$this->permissions}.name"), Operators::Equal, $permission)
      );

      if ($Operation->error !== null) {
         return false;
      }

      $Result = $Operation->Result;
      if ($Result === null) {
         return false;
      }

      $rows = $Result->rows;
      $allowed = $rows[0]['allowed'] ?? 0;

      // : Strict positive count.
      return is_numeric($allowed) && (int) $allowed > 0;
   }

   /**
    * Load persisted permissions into identity scopes.
    */
   public function load (Identity $Identity): Identity
   {
      if ($Identity->id === '') {
         return $Identity;
      }

      // @
      $Operation = $this->execute(
         $this->build($Identity)
            ->distinct()
            ->select(new Identifier("{$this->permissions}.name"))
      );

      if ($Operation->error !== null) {
         return $Identity;
      }

      $Result = $Operation->Result;
      if ($Result === null) {
         return $Identity;
      }

      $scopes = $Identity->scopes;
      $rows = $Result->rows;

      foreach ($rows as $row) {
         $scope = $row['name'] ?? null;
         if (is_string($scope) && $scope !== '') {
            $scopes[] = $scope;
         }
      }

      // : Keep existing token/session scopes and append DB-backed permissions.
      $Identity->scopes = array_values(array_unique($scopes));

      return $Identity;
   }

   /**
    * Build the shared RBAC role-permission query skeleton.
    */
   private function build (Identity $Identity): Builder
   {
      return $this->Database
         ->table(new Identifier($this->userRoles))
         ->join(
            new Identifier($this->roles),
            new Identifier("{$this->roles}.id"),
            Operators::Equal,
            new Identifier("{$this->userRoles}.role_id")
         )
         ->join(
            new Identifier($this->rolePermissions),
            new Identifier("{$this->rolePermissions}.role_id"),
            Operators::Equal,
            new Identifier("{$this->roles}.id")
         )
         ->join(
            new Identifier($this->permissions),
            new Identifier("{$this->permissions}.id"),
            Operators::Equal,
            new Identifier("{$this->rolePermissions}.permission_id")
         )
         ->filter(new Identifier("{$this->userRoles}.user_id"), Operators::Equal, $Identity->id);
   }

   /**
    * Execute one RBAC query through the configured async SQL surface.
    */
   private function execute (Builder $Builder): Operation
   {
      $Operation = $this->Database->query($Builder);

      return $this->Database->await($Operation);
   }
}
