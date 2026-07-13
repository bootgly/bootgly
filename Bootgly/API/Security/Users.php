<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security;


use function is_numeric;
use function is_string;
use RuntimeException;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
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

      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->select(
               new Identifier($this->key),
               new Identifier($this->identifier),
               new Identifier($this->secret),
               new Identifier($this->verified)
            )
            ->filter(new Identifier($this->identifier), Operators::Equal, $email)
            ->limit(1)
      );

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

      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->select(
               new Identifier($this->key),
               new Identifier($this->identifier),
               new Identifier($this->secret),
               new Identifier($this->verified)
            )
            ->filter(new Identifier($this->key), Operators::Equal, $user)
            ->limit(1)
      );

      // :
      return $this->cast($Operation);
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
    * Errors stay on the returned Operation — `await()` throws on errored
    * operations, and this store fails closed instead.
    */
   private function execute (Builder $Builder): Operation
   {
      $Operation = $this->Database->query($Builder);
      // ?
      if ($Operation->error !== null) {
         return $Operation;
      }

      try {
         return $this->Database->await($Operation);
      }
      catch (RuntimeException) {
         // : The error is recorded on the Operation itself.
         return $Operation;
      }
   }
}
