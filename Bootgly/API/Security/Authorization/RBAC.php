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
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;

use Bootgly\ABI\Resources\Cache;
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
   /**
    * Optional permission-list cache. When set, `load()` and `check()` are
    * served from one cached entry per identity (`rbac:{id}`, tag `rbac`)
    * instead of querying the database on every authorization-bearing
    * request. Call `invalidate()` after role/permission writes.
    */
   public private(set) null|Cache $Cache;
   /**
    * Cached permission-list TTL in seconds (bounds staleness when a
    * mutation path misses an `invalidate()` call).
    */
   public private(set) int $lifetime;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (
      SQLDatabase|Transaction $Database,
      string $roles = 'roles',
      string $permissions = 'permissions',
      string $rolePermissions = 'role_permissions',
      string $userRoles = 'user_roles',
      null|Cache $Cache = null,
      int $lifetime = 300
   )
   {
      // * Config
      $this->Database = $Database;
      $this->roles = $roles;
      $this->permissions = $permissions;
      $this->rolePermissions = $rolePermissions;
      $this->userRoles = $userRoles;
      $this->Cache = $Cache;
      $this->lifetime = $lifetime;
   }

   /**
    * Check whether an identity has a persisted permission through its roles.
    */
   public function check (Identity $Identity, string $permission): bool
   {
      if ($Identity->id === '' || $permission === '') {
         return false;
      }

      // ?: Cached permission list serves single checks without a query
      if ($this->Cache !== null) {
         $permissions = $this->fetch($Identity);

         // : Fail closed on database errors (null = uncached failure)
         return $permissions !== null && in_array($permission, $permissions, true);
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
      $permissions = $this->fetch($Identity);
      // ? Database error — keep the identity untouched
      if ($permissions === null) {
         return $Identity;
      }

      // : Keep existing token/session scopes and append DB-backed permissions.
      $Identity->scopes = array_values(array_unique(
         [...$Identity->scopes, ...$permissions]
      ));

      return $Identity;
   }

   /**
    * Drop cached permission lists after role/permission writes.
    *
    * With an Identity, only that identity's entry is dropped; without one,
    * every RBAC entry is invalidated through the `rbac` tag. No-op when no
    * cache is configured.
    */
   public function invalidate (null|Identity $Identity = null): bool
   {
      // ?
      if ($this->Cache === null) {
         return true;
      }

      // ?: Single identity — direct key delete
      if ($Identity !== null) {
         return $this->Cache->delete("rbac:{$Identity->id}");
      }

      // : All identities — tag invalidation
      return $this->Cache->invalidate('rbac');
   }

   /**
    * Fetch the identity's permission names — through the cache when one is
    * configured (stored `null` / database errors are never cached, so
    * failures retry on the next request), straight from the database
    * otherwise.
    *
    * @return null|array<int,string> `null` on database error.
    */
   private function fetch (Identity $Identity): null|array
   {
      // ?: No cache configured — query directly
      if ($this->Cache === null) {
         return $this->select($Identity);
      }

      // @ Get-or-compute (a `null` compute result is a non-hit on re-fetch)
      /** @var null|array<int,string> $permissions Entries are written exclusively by select() */
      $permissions = $this->Cache->resolve(
         "rbac:{$Identity->id}",
         $this->lifetime,
         fn (): null|array => $this->select($Identity),
         ['rbac']
      );

      // :
      return is_array($permissions) === true ? $permissions : null;
   }

   /**
    * Select the identity's distinct permission names from the database.
    *
    * @return null|array<int,string> `null` on database error.
    */
   private function select (Identity $Identity): null|array
   {
      $Operation = $this->execute(
         $this->build($Identity)
            ->distinct()
            ->select(new Identifier("{$this->permissions}.name"))
      );

      // ?
      if ($Operation->error !== null) {
         return null;
      }

      $Result = $Operation->Result;
      // ?
      if ($Result === null) {
         return null;
      }

      $permissions = [];
      foreach ($Result->rows as $row) {
         $name = $row['name'] ?? null;
         if (is_string($name) && $name !== '') {
            $permissions[] = $name;
         }
      }

      // :
      return $permissions;
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
