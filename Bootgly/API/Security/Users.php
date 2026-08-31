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


use function in_array;
use function is_numeric;
use function is_string;
use RuntimeException;
use stdClass;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite as SQLiteDialect;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Events;
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
    * duplicates fail there (no read-then-write race). Under a `Transaction`
    * that anticipated failure is fenced by a savepoint, so a duplicate stays
    * a duplicate instead of aborting the caller's whole unit of work.
    *
    * @return null|string The new account id — `null` on a duplicate identifier.
    * @throws RuntimeException When the database fails.
    */
   public function enroll (string $email, #[\SensitiveParameter] string $password): null|string
   {
      // ?
      if ($email === '' || $password === '') {
         return null;
      }

      // ! Transaction arm — fence the write the store expects to fail
      $Transaction = $this->Database instanceof Transaction ? $this->Database : null;
      if ($Transaction !== null) {
         $this->Database->await($Transaction->save());
      }

      // @
      $Operation = $this->attempt(
         $this->Database
            ->table(new Identifier($this->table))
            ->insert()
            ->set(new Identifier($this->identifier), $email)
            ->set(new Identifier($this->secret), $this->Password->hash($password))
      );
      // ?
      if ($Operation->error !== null) {
         // ? Only a recorded unique-key collision is the documented duplicate
         //   answer. Anything else is a database failure the caller must see.
         if ($this->collide($Operation) === false) {
            // ! Best-effort localization — the original cause outranks a
            //   teardown that cannot make progress on a dead connection.
            if ($Transaction !== null) {
               try {
                  $this->Database->await($Transaction->rollback());
               }
               catch (RuntimeException) {
               }
            }

            throw new RuntimeException($Operation->error);
         }

         // ? Duplicate — unwind to the fence (PostgreSQL aborts the whole
         //   block on any statement failure; the savepoint rollback is what
         //   revives it). A teardown failure here must throw: with the write
         //   state unknown, "already registered" would be a false verdict.
         if ($Transaction !== null) {
            $this->Database->await($Transaction->rollback());
         }

         return null;
      }
      // ?: The fence is no longer needed — drop it
      if ($Transaction !== null) {
         $this->Database->await($Transaction->release());
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
    * @throws RuntimeException When the database fails.
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

      // ?: Rehash-on-verify — persist the upgraded hash. Best-effort by
      //   contract: the credential fact is already proven, so an upgrade that
      //   does not land must not fail the login — but it must not be invisible
      //   either. A recorded driver error already announced itself through
      //   `Events::Failed`; a write that landed on no row is announced here.
      if ($Verification->hash !== null) {
         $Operation = $this->attempt(
            $this->Database
               ->table(new Identifier($this->table))
               ->update()
               ->set(new Identifier($this->secret), $Verification->hash)
               ->filter(new Identifier($this->key), Operators::Equal, $row['id'])
         );

         if ($Operation->error === null && $Operation->affected !== 1) {
            $Emitter = Emitter::$Instance;
            $Emitter->check(Events::Failed) && $Emitter->emit(Events::Failed, $Operation);
         }
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
    * @throws RuntimeException When the database fails.
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
    * @throws RuntimeException When the database fails.
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
    * @throws RuntimeException When the database fails.
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

      // :
      return $Operation->affected === 1;
   }

   /**
    * Stamp the account e-mail as verified (idempotent).
    *
    * @throws RuntimeException When the database fails.
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
    * A database failure — recorded on the Operation or raised by the wait —
    * always propagates: an outage must stay distinguishable from a wrong
    * password, an unknown account or a duplicate identifier, or an honest
    * caller reads infrastructure trouble as a credential fact.
    *
    * @throws RuntimeException When the database fails.
    */
   private function execute (Builder|Query $Query, null|object $Scope = null): Operation
   {
      $Operation = $this->attempt($Query, $Scope);
      // ?
      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }

      // :
      return $Operation;
   }

   /**
    * Attempt one query whose recorded failure is a tolerable outcome.
    *
    * Serves the writes the store treats as best-effort or classifies itself:
    * `enroll()`'s fenced insert and `verify()`'s rehash persistence. Recorded
    * database errors stay on the returned Operation; an infrastructure
    * failure with no recorded outcome still propagates.
    */
   private function attempt (Builder|Query $Query, null|object $Scope = null): Operation
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
         if ($this->detect($Operation) === false) {
            throw $Exception;
         }

         return $Operation;
      }
   }

   /**
    * Detect a unique-key collision recorded on the operation.
    *
    * The codes are the drivers' machine identities for a violated unique
    * index: PostgreSQL SQLSTATE `23505`, MySQL errno `1062`, SQLite extended
    * result codes `2067` (UNIQUE) and `1555` (PRIMARY KEY). An operation
    * without a code — a refusal, a transport loss — never classifies as a
    * duplicate, so an outage cannot answer as "already registered".
    */
   private function collide (Operation $Operation): bool
   {
      return in_array($Operation->code, ['23505', '1062', '2067', '1555'], true);
   }

   /**
    * Detect a failure the driver recorded on the operation.
    */
   private function detect (Operation $Operation): bool
   {
      return $Operation->error !== null;
   }
}
