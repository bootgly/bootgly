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


use function bin2hex;
use function count;
use function ctype_xdigit;
use function explode;
use function hash;
use function hash_equals;
use function is_numeric;
use function is_string;
use function random_bytes;
use function random_int;
use function strlen;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ADI\Databases\SQL as SQLDatabase;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\API\Security\Tokens\Clocked;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Token;


/**
 * Database-backed single-use action token store (selector + verifier).
 *
 * Backs e-mail verification and password-recovery links: `mint()` exposes
 * the raw "selector.verifier" value exactly once, only the sha256 digest
 * of the verifier is persisted, and `redeem()` consumes atomically.
 *
 * Distinct concern from `JWT\Tokens` (cache-backed refresh-token families
 * with rotation): this store is SQL-backed and every token dies on first
 * use or expiration.
 */
class Tokens
{
   use Clocked;

   // * Config
   public private(set) SQLDatabase|Transaction $Database;
   public private(set) string $table;
   /**
    * Probabilistic expired-row sweep on mint() — [chance, divisor]
    * (the `Session::$gcProbability` precedent; no scheduler required).
    *
    * @var array{int,int}
    */
   public static array $gcProbability = [1, 1000];

   // * Data
   // ...

   // * Metadata
   /**
    * sha256 digest compared when a selector misses — uniform timing.
    */
   private const string DECOY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';


   public function __construct (SQLDatabase|Transaction $Database, string $table = 'tokens')
   {
      // * Config
      $this->Database = $Database;
      $this->table = $table;
   }

   /**
    * Mint a single-use token, superseding any live token of the same
    * user + purpose (a newer link always kills the previous one).
    *
    * @throws InvalidArgumentException When the user is empty or the ttl is not positive.
    * @throws RuntimeException When the token cannot be stored.
    */
   public function mint (string $user, Purposes $Purpose, int $ttl = 3600): Token
   {
      // ?
      if ($user === '') {
         throw new InvalidArgumentException('Token user must not be empty.');
      }
      if ($ttl < 1) {
         throw new InvalidArgumentException('Token ttl must be positive.');
      }

      // ! One live token per user + purpose.
      $this->revoke($user, $Purpose);

      // ?: Probabilistic GC — expired rows also purge on contact
      if (random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
         $this->sweep();
      }

      // !
      $selector = bin2hex(random_bytes(8));
      $verifier = bin2hex(random_bytes(32));
      $expires = $this->time + $ttl;

      // @
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->insert()
            ->set(new Identifier('selector'), $selector)
            ->set(new Identifier('verifier'), hash('sha256', $verifier))
            ->set(new Identifier('user_id'), $user)
            ->set(new Identifier('purpose'), $Purpose->value)
            ->set(new Identifier('expires'), $expires)
      );

      // ?
      if ($Operation->error !== null) {
         throw new RuntimeException('Token could not be stored.');
      }

      // :
      return new Token("{$selector}.{$verifier}", $selector, $user, $Purpose, $expires);
   }

   /**
    * Consume a token exactly once.
    *
    * A tampered verifier never consumes the row — the legitimate link
    * stays redeemable. Expired rows are purged on contact.
    *
    * @return null|string The owner user id, or `null` on any failure.
    */
   public function redeem (string $token, Purposes $Purpose): null|string
   {
      // !
      $parts = $this->split($token);
      // ? Malformed token — burn an equivalent digest compare (uniform timing)
      if ($parts === null) {
         hash_equals(self::DECOY, hash('sha256', $token)); // @phpstan-ignore function.resultUnused (timing decoy)

         return null;
      }
      [$selector, $verifier] = $parts;
      $digest = hash('sha256', $verifier);

      // @ Locate the live token by its public selector.
      $row = $this->select($selector, $Purpose);
      // ? Unknown selector — decoy compare (uniform timing)
      if ($row === null) {
         hash_equals(self::DECOY, $digest); // @phpstan-ignore function.resultUnused (timing decoy)

         return null;
      }

      // ? Expired — purge the dead row and reject
      if ($row['expires'] <= $this->time) {
         $this->execute(
            $this->Database
               ->table(new Identifier($this->table))
               ->delete()
               ->filter(new Identifier('id'), Operators::Equal, $row['id'])
         );

         return null;
      }

      // ? Tampered verifier — reject; the row survives
      if (hash_equals($row['verifier'], $digest) === false) {
         return null;
      }

      // @ Atomic single-use gate — the digest recheck makes concurrent redeems lose.
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->delete()
            ->filter(new Identifier('id'), Operators::Equal, $row['id'])
            ->filter(new Identifier('verifier'), Operators::Equal, $digest)
      );
      // ? Fail closed on database errors
      if ($Operation->error !== null) {
         return null;
      }

      // :
      return $Operation->affected === 1 ? $row['user'] : null;
   }

   /**
    * Check a token without consuming it (e.g. rendering a reset form).
    */
   public function check (string $token, Purposes $Purpose): bool
   {
      // !
      $parts = $this->split($token);
      // ? Malformed token — decoy compare (uniform timing)
      if ($parts === null) {
         hash_equals(self::DECOY, hash('sha256', $token)); // @phpstan-ignore function.resultUnused (timing decoy)

         return false;
      }
      [$selector, $verifier] = $parts;
      $digest = hash('sha256', $verifier);

      // @
      $row = $this->select($selector, $Purpose);
      // ? Unknown selector — decoy compare (uniform timing)
      if ($row === null) {
         hash_equals(self::DECOY, $digest); // @phpstan-ignore function.resultUnused (timing decoy)

         return false;
      }

      // ?
      if ($row['expires'] <= $this->time) {
         return false;
      }

      // :
      return hash_equals($row['verifier'], $digest);
   }

   /**
    * Drop live tokens for a user — optionally scoped to one purpose.
    *
    * @return int Revoked rows.
    */
   public function revoke (string $user, null|Purposes $Purpose = null): int
   {
      // ?
      if ($user === '') {
         return 0;
      }

      // !
      $Builder = $this->Database
         ->table(new Identifier($this->table))
         ->delete()
         ->filter(new Identifier('user_id'), Operators::Equal, $user);
      if ($Purpose !== null) {
         $Builder->filter(new Identifier('purpose'), Operators::Equal, $Purpose->value);
      }

      // @
      $Operation = $this->execute($Builder);
      // ?
      if ($Operation->error !== null) {
         return 0;
      }

      // :
      return $Operation->affected;
   }

   /**
    * Drop expired tokens.
    *
    * @return int Swept rows.
    */
   public function sweep (): int
   {
      // @
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->delete()
            ->filter(new Identifier('expires'), Operators::LessOrEqual, $this->time)
      );
      // ?
      if ($Operation->error !== null) {
         return 0;
      }

      // :
      return $Operation->affected;
   }

   /**
    * Split a wire token into its selector and verifier halves.
    *
    * @return null|array{0:string,1:string}
    */
   private function split (string $token): null|array
   {
      $parts = explode('.', $token, 2);
      // ?
      if (count($parts) !== 2) {
         return null;
      }

      [$selector, $verifier] = $parts;
      // ?
      if (strlen($selector) !== 16 || ctype_xdigit($selector) === false) {
         return null;
      }
      // ?
      if (strlen($verifier) !== 64 || ctype_xdigit($verifier) === false) {
         return null;
      }

      // :
      return [$selector, $verifier];
   }

   /**
    * Select a live token row by its public selector.
    *
    * @return null|array{id:int|string, user:string, verifier:string, expires:int}
    */
   private function select (string $selector, Purposes $Purpose): null|array
   {
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->select(
               new Identifier('id'),
               new Identifier('verifier'),
               new Identifier('user_id'),
               new Identifier('expires')
            )
            ->filter(new Identifier('selector'), Operators::Equal, $selector)
            ->filter(new Identifier('purpose'), Operators::Equal, $Purpose->value)
            ->limit(1)
      );

      // ?
      if ($Operation->error !== null) {
         return null;
      }

      $row = $Operation->rows[0] ?? null;
      // ?
      if ($row === null) {
         return null;
      }

      $id = $row['id'] ?? null;
      $verifier = $row['verifier'] ?? null;
      $user = $row['user_id'] ?? null;
      $expires = $row['expires'] ?? null;
      // ? Malformed row — fail closed
      if (
         (is_numeric($id) === false && is_string($id) === false)
         || is_string($verifier) === false
         || (is_numeric($user) === false && is_string($user) === false)
         || is_numeric($expires) === false
      ) {
         return null;
      }

      // :
      return [
         'id' => is_string($id) ? $id : (int) $id,
         'user' => (string) $user,
         'verifier' => $verifier,
         'expires' => (int) $expires,
      ];
   }

   /**
    * Execute one token query through the configured async SQL surface.
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
