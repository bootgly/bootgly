<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tokens;


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


/**
 * Database-backed persistent-login (remember-me) token store.
 *
 * Each trusted device holds one series: the selector stays stable while
 * the validator rotates on every successful use. A known series presented
 * with a wrong validator is the stolen-cookie signature — every trusted
 * device of the user is revoked and a `Theft` incident is returned.
 */
class Trust
{
   use Clocked;

   // * Config
   public private(set) SQLDatabase|Transaction $Database;
   public private(set) string $table;
   /**
    * Probabilistic expired-row sweep on issue() — [chance, divisor]
    * (the `Session::$gcProbability` precedent; no scheduler required).
    *
    * @var array{int,int}
    */
   public static array $gcProbability = [1, 1000];

   // * Data
   // ...

   // * Metadata
   /**
    * sha256 digest compared when a series misses — uniform timing.
    */
   private const string DECOY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';


   public function __construct (SQLDatabase|Transaction $Database, string $table = 'trusts')
   {
      // * Config
      $this->Database = $Database;
      $this->table = $table;
   }

   /**
    * Start a new trusted-device series.
    *
    * @throws InvalidArgumentException When the user is empty or the ttl is not positive.
    * @throws RuntimeException When the token cannot be stored.
    */
   public function issue (string $user, int $ttl = 2592000): Token
   {
      // ?
      if ($user === '') {
         throw new InvalidArgumentException('Trust user must not be empty.');
      }
      if ($ttl < 1) {
         throw new InvalidArgumentException('Trust ttl must be positive.');
      }

      // ?: Probabilistic GC — expired rows also purge on contact
      if (random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
         $this->sweep();
      }

      // !
      $selector = bin2hex(random_bytes(8));
      $validator = bin2hex(random_bytes(32));
      $expires = $this->time + $ttl;

      // @
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->insert()
            ->set(new Identifier('selector'), $selector)
            ->set(new Identifier('verifier'), hash('sha256', $validator))
            ->set(new Identifier('user_id'), $user)
            ->set(new Identifier('expires'), $expires)
      );

      // ?
      if ($Operation->error !== null) {
         throw new RuntimeException('Trust token could not be stored.');
      }

      // :
      return new Token("{$selector}.{$validator}", $selector, $user, null, $expires);
   }

   /**
    * Validate a trusted-device token and rotate its validator.
    *
    * The series (selector) stays stable; a fresh validator replaces the
    * presented one atomically. A known series with a wrong validator
    * revokes ALL of the user's devices and returns a `Theft` incident.
    * A concurrent rotation losing the conditional-update race returns
    * `null` — deliberately NOT `Theft`, so a benign double submit cannot
    * revoke every session.
    *
    * @return null|Theft|Token
    */
   public function rotate (string $token, int $ttl = 2592000): null|Theft|Token
   {
      // ?
      if ($ttl < 1) {
         throw new InvalidArgumentException('Trust ttl must be positive.');
      }

      // !
      $parts = $this->split($token);
      // ? Malformed token — burn an equivalent digest compare (uniform timing)
      if ($parts === null) {
         hash_equals(self::DECOY, hash('sha256', $token)); // @phpstan-ignore function.resultUnused (timing decoy)

         return null;
      }
      [$selector, $validator] = $parts;
      $digest = hash('sha256', $validator);

      // @ Locate the series by its public selector.
      $row = $this->select($selector);
      // ? Unknown series — decoy compare (uniform timing)
      if ($row === null) {
         hash_equals(self::DECOY, $digest); // @phpstan-ignore function.resultUnused (timing decoy)

         return null;
      }

      // ? Expired series — purge the dead row and reject
      if ($row['expires'] <= $this->time) {
         $this->execute(
            $this->Database
               ->table(new Identifier($this->table))
               ->delete()
               ->filter(new Identifier('id'), Operators::Equal, $row['id'])
         );

         return null;
      }

      // ? Stolen-cookie signature — known series, wrong validator
      if (hash_equals($row['verifier'], $digest) === false) {
         $this->revoke($row['user']);

         return new Theft($row['user']);
      }

      // !
      $fresh = bin2hex(random_bytes(32));
      $expires = $this->time + $ttl;

      // @ Atomic rotation gate — the digest recheck makes concurrent rotations lose.
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->update()
            ->set(new Identifier('verifier'), hash('sha256', $fresh))
            ->set(new Identifier('expires'), $expires)
            ->filter(new Identifier('id'), Operators::Equal, $row['id'])
            ->filter(new Identifier('verifier'), Operators::Equal, $digest)
      );
      // ? Fail closed on database errors
      if ($Operation->error !== null) {
         return null;
      }
      // ? Lost a concurrent rotation race — benign, not theft
      if ($Operation->affected !== 1) {
         return null;
      }

      // :
      return new Token("{$selector}.{$fresh}", $selector, $row['user'], null, $expires);
   }

   /**
    * Drop the presented device series (single-device logout).
    */
   public function forget (string $token): bool
   {
      // !
      $parts = $this->split($token);
      // ?
      if ($parts === null) {
         return false;
      }
      [$selector, $validator] = $parts;

      // @ The digest condition keeps forget() unusable as a revocation oracle.
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->delete()
            ->filter(new Identifier('selector'), Operators::Equal, $selector)
            ->filter(new Identifier('verifier'), Operators::Equal, hash('sha256', $validator))
      );
      // ?
      if ($Operation->error !== null) {
         return false;
      }

      // :
      return $Operation->affected === 1;
   }

   /**
    * Drop all device series of a user (logout everywhere / password change).
    *
    * @return int Revoked rows.
    */
   public function revoke (string $user): int
   {
      // ?
      if ($user === '') {
         return 0;
      }

      // @
      $Operation = $this->execute(
         $this->Database
            ->table(new Identifier($this->table))
            ->delete()
            ->filter(new Identifier('user_id'), Operators::Equal, $user)
      );
      // ?
      if ($Operation->error !== null) {
         return 0;
      }

      // :
      return $Operation->affected;
   }

   /**
    * Drop expired device series.
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
    * Split a wire token into its selector and validator halves.
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

      [$selector, $validator] = $parts;
      // ?
      if (strlen($selector) !== 16 || ctype_xdigit($selector) === false) {
         return null;
      }
      // ?
      if (strlen($validator) !== 64 || ctype_xdigit($validator) === false) {
         return null;
      }

      // :
      return [$selector, $validator];
   }

   /**
    * Select a live series row by its public selector.
    *
    * @return null|array{id:int|string, user:string, verifier:string, expires:int}
    */
   private function select (string $selector): null|array
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
    * Execute one trust query through the configured async SQL surface.
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
