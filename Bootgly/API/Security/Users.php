<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function is_numeric;
use function is_string;
use RuntimeException;
use stdClass;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite as SQLiteDialect;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Tokens\Clocked;


/**
 * Database-backed credential store for session/cookie authentication.
 *
 * Owns the credentials contract only: enrollment, verification with
 * rehash-on-verify persistence and password rotation. It never touches
 * sessions, cookies, mail or HTTP — that orchestration belongs to the
 * platform (WPI guards and application controllers).
 */
class Users
{
   use Clocked;

   // * Config
   public private(set) SQLDatabase|Transaction $Database;
   public private(set) Password $Password;
   public private(set) string $table;
   /**
    * Primary key column.
    */
   public private(set) string $key;
   /**
    * Login identifier column (unique).
    */
   public private(set) string $identifier;
   /**
    * Password-hash column.
    */
   public private(set) string $secret;
   /**
    * E-mail confirmation column (epoch seconds, nullable).
    */
   public private(set) string $verified;

   // * Data
   // ...

   // * Metadata
   /**
    * Lazy decoy hash — burned on unknown-identifier verifies so the
    * response time does not reveal whether an account exists.
    */
   private string $decoy {
      get {
         if (isSet($this->decoy) === false) {
            $this->decoy = $this->Password->hash('bootgly-decoy');
         }

         return $this->decoy;
      }
   }


   public function __construct (
      SQLDatabase|Transaction $Database,
      Password $Password,
      string $table = 'users',
      string $key = 'id',
      string $identifier = 'email',
      string $secret = 'password',
      string $verified = 'email_verified_at'
   )
   {
      // * Config
      $this->Database = $Database;
      $this->Password = $Password;
      $this->table = $table;
      $this->key = $key;
      $this->identifier = $identifier;
      $this->secret = $secret;
      $this->verified = $verified;
   }

   /**
    * Register credentials for a new account.
    *
    * The insert relies on the unique index of the identifier column —
    * duplicates fail there (no read-then-write race).
    *
    * @return null|string The new account id — `null` on duplicate or database error.
    * @throws RuntimeException When a database wait fails without a recorded Operation error.
    */
   public function enroll (string $email, #[\SensitiveParameter] string $password): null|string
   {
      // ?
      if ($email === '' || $password === '') {
         return null;
      }

      // @
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->insert()
            ->set(new Identifier($this->identifier), $email)
            ->set(new Identifier($this->secret), $this->Password->hash($password))
      );
      // ? Duplicate identifier or database error
      if ($Operation->error !== null) {
         return null;
      }

      // @ Portable id resolution (no RETURNING on MySQL).
      $row = $this->select($email);
      // ?
      if ($row === null) {
         return null;
      }

      // :
      return $row['id'];
   }

   /**
    * Verify credentials with uniform timing and rehash-on-verify.
    *
    * Unknown identifiers burn a decoy argon2 hash so timing does not
    * reveal account existence. Legacy hashes upgrade to the current
    * policy transparently on successful verification.
    *
    * @return null|Identity Claims: `email` (string), `verified` (bool).
    * @throws RuntimeException When a database wait fails without a recorded Operation error.
    */
   public function verify (string $email, #[\SensitiveParameter] string $password): null|Identity
   {
      // !
      $row = $this->select($email);
      // ? Unknown identifier — burn a decoy verify (uniform timing)
      if ($row === null) {
         $this->Password->verify($password, $this->decoy);

         return null;
      }

      // @
      $Verification = $this->Password->inspect($password, $row['secret']);
      // ?
      if ($Verification->valid === false) {
         return null;
      }

      // ?: Rehash-on-verify — persist the upgraded hash
      if ($Verification->hash !== null) {
         $this->execute(
            $this->Database
               ->table(new Identifier($this->table))
               ->update()
               ->set(new Identifier($this->secret), $Verification->hash)
               ->filter(new Identifier($this->key), Operators::Equal, $row['id'])
         );
      }

      // :
      return new Identity($row['id'], claims: [
         'email' => $row['email'],
         'verified' => $row['verified'],
      ]);
   }

   /**
    * Check a password for an account id (current-password gate).
    *
    * @throws RuntimeException When a database wait fails without a recorded Operation error.
    */
   public function check (string $user, #[\SensitiveParameter] string $password): bool
   {
      // !
      $row = $this->locate($user);
      // ? Unknown account — burn a decoy verify (uniform timing)
      if ($row === null) {
         $this->Password->verify($password, $this->decoy);

         return false;
      }

      // :
      return $this->Password->verify($password, $row['secret']);
   }

   /**
    * Look up an account by identifier WITHOUT credentials (reset-request flow).
    *
    * @return null|Identity Claims: `email` (string), `verified` (bool).
    * @throws RuntimeException When a database wait fails without a recorded Operation error.
    */
   public function fetch (string $email): null|Identity
   {
      // !
      $row = $this->select($email);
      // ?
      if ($row === null) {
         return null;
      }

      // :
      return new Identity($row['id'], claims: [
         'email' => $row['email'],
         'verified' => $row['verified'],
      ]);
   }

   /**
    * Replace the stored password hash (reset completion / password change).
    *
    * Callers MUST follow a successful rotation with `Tokens->revoke()`,
    * `Trust->revoke()` and session regeneration — this store owns the
    * hash only, not the surrounding invalidation orchestration.
    *
    * @throws RuntimeException When a database wait fails without a recorded Operation error.
    */
   public function rotate (string $user, #[\SensitiveParameter] string $password): bool
   {
      // ?
      if ($user === '' || $password === '') {
         return false;
      }

      // @
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->update()
            ->set(new Identifier($this->secret), $this->Password->hash($password))
            ->filter(new Identifier($this->key), Operators::Equal, $user)
      );
      // ?
      if ($Operation->error !== null) {
         return false;
      }

      // :
      return $Operation->affected === 1;
   }

   /**
    * Stamp the account e-mail as verified (idempotent).
    *
    * @throws RuntimeException When a database wait fails without a recorded Operation error.
    */
   public function confirm (string $user): bool
   {
      // ?
      if ($user === '') {
         return false;
      }

      // @ Only unverified accounts are stamped — repeat confirms are no-ops.
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->update()
            ->set(new Identifier($this->verified), $this->time)
            ->filter(new Identifier($this->key), Operators::Equal, $user)
            ->filter(new Identifier($this->verified), Operators::IsNull, null)
      );
      // ?
      if ($Operation->error !== null) {
         return false;
      }

      // : Idempotent — already-verified accounts also report success.
      return $this->locate($user) !== null;
   }

   /**
    * Select an account row by its login identifier.
    *
    * @return null|array{id:string, email:string, secret:string, verified:bool}
    */
   private function select (string $email): null|array
   {
      // ?
      if ($email === '') {
         return null;
      }

      $Builder = $this->Database
         ->table(new Identifier($this->table))
         ->select(
            new Identifier($this->key),
            new Identifier($this->identifier),
            new Identifier($this->secret),
            new Identifier($this->verified)
         )
         ->filter(new Identifier($this->identifier), Operators::Equal, $email)
         ->limit(1);
      $Operation = $this->read($Builder);

      // :
      return $this->cast($Operation);
   }

   /**
    * Select an account row by its primary key.
    *
    * @return null|array{id:string, email:string, secret:string, verified:bool}
    */
   private function locate (string $user): null|array
   {
      // ?
      if ($user === '') {
         return null;
      }

      $Builder = $this->Database
         ->table(new Identifier($this->table))
         ->select(
            new Identifier($this->key),
            new Identifier($this->identifier),
            new Identifier($this->secret),
            new Identifier($this->verified)
         )
         ->filter(new Identifier($this->key), Operators::Equal, $user)
         ->limit(1);
      $Operation = $this->read($Builder);

      // :
      return $this->cast($Operation);
   }

   /**
    * Execute one credential decision read without replica routing.
    *
    * Transactions take a current locking read, or a zero-row SQLite writer
    * barrier that rejects stale WAL snapshots before selection. A standalone
    * SQL facade receives a write-classified compiled query so replica lag
    * cannot decide credentials; one-use scopes keep both classifications from
    * extending sticky routing to unrelated reads in the worker.
    */
   private function read (Builder $Builder): Operation
   {
      if ($this->Database instanceof Transaction) {
         $Scope = new stdClass;

         if ($Builder->Dialect instanceof SQLiteDialect) {
            // ! SQLite has no locking SELECT. A zero-row DML statement either
            //   reserves the writer before the read or rejects an already stale
            //   WAL snapshot with BUSY_SNAPSHOT; never continue after failure.
            $Barrier = $this->execute(
               $this->Database
                  ->table(new Identifier($this->table))
                  ->delete()
                  ->filter(new Expression('1'), Operators::Equal, new Expression('0')),
               $Scope
            );
            if ($Barrier->error !== null) {
               return $Barrier;
            }
            if ($Barrier->affected !== 0) {
               throw new RuntimeException('Database current-read barrier modified rows.');
            }
         }
         else {
            // ! A locking read is current in MySQL and either current or
            //   serialization-failing (fail-closed) under PostgreSQL isolation.
            $Builder->lock(Locks::Update);
         }

         return $this->execute($Builder, $Scope);
      }

      $Compiled = $Builder->compile();

      return $this->execute(
         new Query($Compiled->SQL, $Compiled->parameters, reading: false),
         new stdClass
      );
   }

   /**
    * Cast an account row from an executed select operation.
    *
    * @return null|array{id:string, email:string, secret:string, verified:bool}
    */
   private function cast (Operation $Operation): null|array
   {
      // ?
      if ($Operation->error !== null) {
         return null;
      }

      $row = $Operation->rows[0] ?? null;
      // ?
      if ($row === null) {
         return null;
      }

      $id = $row[$this->key] ?? null;
      $email = $row[$this->identifier] ?? null;
      $secret = $row[$this->secret] ?? null;
      $verified = $row[$this->verified] ?? null;
      // ? Malformed row — fail closed
      if (
         (is_numeric($id) === false && is_string($id) === false)
         || is_string($email) === false
         || is_string($secret) === false
      ) {
         return null;
      }

      // :
      return [
         'id' => (string) $id,
         'email' => $email,
         'secret' => $secret,
         'verified' => $verified !== null,
      ];
   }

   /**
    * Execute one credential query through the configured async SQL surface.
    *
    * Recorded database errors stay on the returned Operation so this store
    * can fail closed. An infrastructure failure with no Operation error
    * propagates instead of becoming a credential verdict.
    */
   private function execute (Builder|Query $Query, null|object $Scope = null): Operation
   {
      $Operation = $this->Database->query($Query, Scope: $Scope);
      // ?
      if ($Operation->error !== null) {
         return $Operation;
      }

      try {
         $Operation = $this->Database->await($Operation);

         if ($Operation->finished === false && $Operation->error === null) {
            throw new RuntimeException('Database operation did not finish.');
         }

         return $Operation;
      }
      catch (RuntimeException $Exception) {
         // ? Only a recorded driver/database failure belongs to this store's
         //   fail-closed return contract. Infrastructure failures without an
         //   Operation error must stay distinguishable from a credential fact.
         if ($this->detect($Operation) === false) {
            throw $Exception;
         }

         return $Operation;
      }
   }

   /**
    * Detect a failure the driver recorded on the operation.
    */
   private function detect (Operation $Operation): bool
   {
      return $Operation->error !== null;
   }
}
