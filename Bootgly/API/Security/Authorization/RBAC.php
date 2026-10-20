<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Authorization;


use const PHP_INT_MAX;
use function array_unique;
use function array_values;
use function basename;
use function bin2hex;
use function dirname;
use function getmypid;
use function hash;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_numeric;
use function is_string;
use function random_bytes;
use function realpath;
use RuntimeException;
use stdClass;
use WeakMap;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Identity;


/**
 * Database-backed role-based access control permission resolver.
 *
 * Over an SQL facade every permission read goes to the primary — replica lag
 * never decides an authorization, nor what the cache keeps.
 */
class RBAC
{
   /**
    * Seconds a resolver's global cache generation lives before it is
    * minted again (every cached list then misses once).
    */
   private const int GENERATION = 86400;

   // * Config
   public private(set) SQLDatabase|Transaction $Database;
   public private(set) string $roles;
   public private(set) string $permissions;
   public private(set) string $rolePermissions;
   public private(set) string $userRoles;
   /**
    * Optional permission-list cache. When set, `load()` and `check()` are
    * served from one cached entry per identity instead of querying the
    * database on every authorization-bearing request. Each entry is bound to
    * two generations — the resolver's and the identity's — read before the
    * database, so a check that starts after `invalidate()` returns never
    * serves a list computed before it. Call `invalidate()` after the
    * role/permission write commits. A check costs three cache reads. A
    * resolver over a Transaction reads the transaction's own view and never
    * caches it (its `invalidate()` still reaches the database's realm).
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
   /**
    * The resolver's identity in the cache: a hash of the connection as it is
    * spelled (driver, host, port, database, username — an SQLite file by its
    * canonical directory and file name alone; a private SQLite database,
    * `:memory:` or the empty name, by its SQL object) and the four table
    * names. A `realm` given replaces the connection part (the table names
    * still apply), when the writers and the readers of one permission set
    * reach the database under different spellings. Two resolvers share cached
    * lists, and invalidations, only within one realm.
    */
   public private(set) string $realm;
   /**
    * Seconds an identity generation lives: twice `lifetime` up to
    * `PHP_INT_MAX / 4`, `lifetime` itself above — so a list usually expires
    * before the generation it was computed under.
    */
   private int $span;
   /**
    * Whether the realm names a private SQLite database, whose lists stay in
    * the process that holds it (a fork copies the database and its token).
    */
   private bool $local = false;
   /**
    * The cache key prefix of this resolver's generations and lists.
    */
   private string $prefix {
      get {
         // ?: A private database — one process
         if ($this->local) {
            $process = getmypid();

            return "rbac:v2:{$this->realm}:{$process}";
         }

         // :
         return "rbac:v2:{$this->realm}";
      }
   }
   /**
    * The realm of each private SQLite database, by its SQL object.
    *
    * @var null|WeakMap<SQLDatabase,string>
    */
   private static null|WeakMap $Handles = null;


   /**
    * @param null|string $realm An explicit cache realm, shared by every
    *    resolver of one permission set: it replaces the connection part of
    *    the realm, and the table names still apply (default: derived from
    *    the connection).
    */
   public function __construct (
      SQLDatabase|Transaction $Database,
      string $roles = 'roles',
      string $permissions = 'permissions',
      string $rolePermissions = 'role_permissions',
      string $userRoles = 'user_roles',
      null|Cache $Cache = null,
      int $lifetime = 300,
      null|string $realm = null
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

      // * Metadata
      if ($realm === null) {
         $SQL = $Database instanceof Transaction ? $Database->Database : $Database;
         $Config = $SQL->SQLConfig;
         $database = $Config->database;

         if ($Config->driver === 'sqlite') {
            // ! A private database (`:memory:`, the empty name) is the one
            //   handle its SQL object holds — never another object's, nor
            //   another run's
            if ($database === ':memory:' || $database === '') {
               self::$Handles ??= new WeakMap;
               $database = self::$Handles[$SQL] ??= bin2hex(random_bytes(16));
               $this->local = true;
            }
            // ! A file by its canonical directory — the same realm before and
            //   after the file exists
            else {
               $directory = realpath(dirname($database));
               if ($directory !== false) {
                  $name = basename($database);
                  $database = "{$directory}/{$name}";
               }
            }

            // ! The file names the database: host, port and username select nothing
            $realm = implode("\0", [$Config->driver, $database]);
         }
         else {
            $realm = implode("\0", [
               $Config->driver, $Config->host, (string) $Config->port, $database, $Config->username,
            ]);
         }
      }
      $this->realm = hash('xxh128', implode("\0", [$realm, $roles, $permissions, $rolePermissions, $userRoles]));
      $this->span = $lifetime > intdiv(PHP_INT_MAX, 4) ? $lifetime : $lifetime * 2;
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
    * Drop cached permission lists after role/permission writes commit.
    *
    * With an Identity, only that identity's lists are dropped; without one,
    * every list of this resolver's realm. A fresh generation is stored — or,
    * when the cache refuses the write (returning `false`, or throwing as
    * Redis does at maxmemory), the old one dropped — so a check already in
    * flight cannot store the list it read before the write as current.
    * No-op when no cache is configured.
    *
    * @return bool `false` when the cache could neither store nor drop the
    *    generation (the lists stay current until `lifetime`).
    */
   public function invalidate (null|Identity $Identity = null): bool
   {
      // ?
      if ($this->Cache === null) {
         return true;
      }

      // ?: Single identity — a fresh identity generation
      if ($Identity !== null) {
         $rotated = $this->rotate("{$this->prefix}:s:{$Identity->id}", $this->span);
         // ! Entries of the previous key layout (until 1.1.0), best-effort
         try {
            $this->Cache->delete("rbac:{$Identity->id}");
         }
         catch (RuntimeException) {
            // ...
         }

         return $rotated;
      }

      // : All identities — a fresh resolver generation
      $rotated = $this->rotate("{$this->prefix}:gen", self::GENERATION);
      // ! Entries of the previous key layout (until 1.1.0), best-effort
      try {
         $this->Cache->invalidate('rbac');
      }
      catch (RuntimeException) {
         // ...
      }

      return $rotated;
   }

   /**
    * Fetch the identity's permission names — through the cache when one is
    * configured (database errors are never cached, so failures retry on the
    * next request), straight from the database otherwise.
    *
    * A cached list is current only under the two generations it was computed
    * under; both are read before the database, so a list read before an
    * `invalidate()` never passes as current after it.
    *
    * @return null|array<int,string> `null` on database error.
    */
   private function fetch (Identity $Identity): null|array
   {
      // ?: No cache configured, or a transaction's own view (uncommitted
      //   writes, an older snapshot) — query directly, never cache
      if ($this->Cache === null || $this->Database instanceof Transaction) {
         return $this->select($Identity);
      }

      // ! The generations this check runs under — before the database
      $prefix = $this->prefix;
      $resolver = $this->stamp("{$prefix}:gen", self::GENERATION);
      $subject = $this->stamp("{$prefix}:s:{$Identity->id}", $this->span);
      // ? A generation the cache cannot keep: this check goes uncached
      if ($resolver === null || $subject === null) {
         return $this->select($Identity);
      }

      // ?: A list computed under both (an unreadable entry is a miss)
      $key = "{$prefix}:u:{$Identity->id}";
      try {
         $entry = $this->Cache->fetch($key);
      }
      catch (RuntimeException) {
         $entry = null;
      }
      if (
         is_array($entry)
         && ($entry['g'] ?? null) === $resolver
         && ($entry['s'] ?? null) === $subject
         && is_array($entry['p'] ?? null)
      ) {
         /** @var array<int,string> $permissions Entries are written exclusively below */
         $permissions = $entry['p'];

         return $permissions;
      }

      // @ The database, then the list under the generations read first (an
      //   entry the cache refuses only costs the next check a query)
      $permissions = $this->select($Identity);
      if ($permissions !== null) {
         try {
            $this->Cache->store($key, ['g' => $resolver, 's' => $subject, 'p' => $permissions], $this->lifetime);
         }
         catch (RuntimeException) {
            // ...
         }
      }

      // :
      return $permissions;
   }

   /**
    * Read a cache generation, minting one when it is missing.
    *
    * @return null|string `null` when the generation cannot be read, or a
    *    missing one cannot be stored (refused or thrown).
    */
   private function stamp (string $key, int $TTL): null|string
   {
      /** @var Cache $Cache */
      $Cache = $this->Cache;

      try {
         $token = $Cache->fetch($key);
         // ?:
         if (is_string($token) && $token !== '') {
            return $token;
         }

         // :
         $token = bin2hex(random_bytes(16));

         return $Cache->store($key, $token, $TTL) ? $token : null;
      }
      catch (RuntimeException) {
         return null;
      }
   }

   /**
    * Replace a cache generation with a fresh token — or drop it when the
    * cache refuses the write (a full disk returns `false`, Redis at
    * maxmemory throws); a dropped generation is minted afresh by the next
    * check.
    *
    * @return bool Whether the old generation no longer stands.
    */
   private function rotate (string $key, int $TTL): bool
   {
      /** @var Cache $Cache */
      $Cache = $this->Cache;
      $token = bin2hex(random_bytes(16));

      try {
         // ?: A fresh generation
         if ($Cache->store($key, $token, $TTL)) {
            return true;
         }
      }
      catch (RuntimeException) {
         // ...
      }

      // : The old generation dropped — or judged by what the cache holds
      //   after (a driver may report a missing key as a failed delete)
      try {
         if ($Cache->delete($key)) {
            return true;
         }
      }
      catch (RuntimeException) {
         // ...
      }

      try {
         return $Cache->check($key) === false;
      }
      catch (RuntimeException) {
         return false;
      }
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
    *
    * An SQL facade receives a write-classified compiled query, so replica lag
    * cannot decide an authorization (nor feed the cache); a one-use scope
    * keeps it from extending sticky routing to unrelated reads in the worker.
    * A Transaction already holds its primary connection.
    *
    * A database failure — recorded on the Operation, or thrown while it is
    * awaited — comes back as the Operation, so every caller fails closed
    * (denies) instead of throwing.
    */
   private function execute (Builder $Builder): Operation
   {
      if ($this->Database instanceof Transaction) {
         $Operation = $this->Database->query($Builder);
      }
      else {
         $Compiled = $Builder->compile();
         $Operation = $this->Database->query(
            new Query($Compiled->SQL, $Compiled->parameters, reading: false),
            Scope: new stdClass
         );
      }
      // ? A failure already recorded
      if ($Operation->error !== null) {
         return $Operation;
      }

      try {
         return $this->Database->await($Operation);
      }
      catch (RuntimeException) {
         // : The callers deny on an unfinished or failed Operation
         return $Operation;
      }
   }
}
