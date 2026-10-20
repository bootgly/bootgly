<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\UsersRehashCompareAndSet;


use const PASSWORD_BCRYPT;
use function assert;
use function count;
use function defined;
use function extension_loaded;
use function glob;
use function implode;
use function is_string;
use function password_hash;
use function password_verify;
use function rmdir;
use function str_starts_with;
use function unlink;
use Closure;
use RuntimeException;
use SQLite3;
use Throwable;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verification;
use Bootgly\API\Security\Users;


/**
 * Password seam that runs one injected action inside the rehash window —
 * after the stored hash was verified and the upgrade hashed, before
 * `verify()` writes the upgrade.
 */
final class RacedPassword extends Password
{
   // * Data
   /** One-shot action run inside the window (cleared once it runs). */
   public null|Closure $Hook = null;


   /**
    * Inspect, then run the pending action once.
    */
   public function inspect (#[\SensitiveParameter] string $password, string $hash): Verification
   {
      $Verification = parent::inspect($password, $hash);

      // @
      $Hook = $this->Hook;
      if ($Hook !== null) {
         $this->Hook = null;
         $Hook();
      }

      // :
      return $Verification;
   }
}

/**
 * SQLite facade that fails the first read issued after an UPDATE — the
 * re-read `verify()` makes when its upgrade landed on no row.
 */
final class RereadSQL extends SQL
{
   /** Failure raised by await() without being recorded on the Operation. */
   public const string UNRECORDED = 'Injected unrecorded re-read failure.';
   /** Failure recorded on the Operation once the read finished. */
   public const string RECORDED = 'Injected recorded re-read failure.';

   // * Data
   private bool $recorded;

   // * Metadata
   /** Re-reads failed so far. */
   public private(set) int $failures = 0;
   private bool $updated = false;
   private null|Operation $Doomed = null;


   /**
    * Build the facade in unrecorded (thrown) or recorded failure mode.
    *
    * @param array<string,mixed> $config
    */
   public function __construct (array $config, bool $recorded)
   {
      // * Data
      $this->recorded = $recorded;

      parent::__construct($config);
   }

   /**
    * Create the operation, marking the first read after an UPDATE.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Operation = parent::query($query, $parameters, $Scope);

      if (str_starts_with($Operation->SQL, 'UPDATE')) {
         $this->updated = true;
      }
      elseif ($this->updated && $this->Doomed === null && str_starts_with($Operation->SQL, 'SELECT')) {
         $this->Doomed = $Operation;
      }

      return $Operation;
   }

   /**
    * Await the operation through the real pool, then fail the marked read.
    */
   public function await (Operation $Operation): Operation
   {
      parent::await($Operation);

      // ?
      if ($Operation !== $this->Doomed) {
         return $Operation;
      }

      // @
      $this->failures++;
      if ($this->recorded) {
         $Operation->fail(self::RECORDED);

         return $Operation;
      }

      throw new RuntimeException(self::UNRECORDED);
   }
}

/**
 * Connection-free facade of one dialect that records each statement it is
 * handed and answers the account read with a legacy row.
 */
final class RecordingSQL extends SQL
{
   // * Data
   /** @var array<string,mixed> */
   private array $row;

   // * Metadata
   /** @var array<int,array{SQL:string,parameters:array<int|string,mixed>}> */
   public private(set) array $statements = [];


   /**
    * Build a facade of the dialect without any connection.
    *
    * @param array<string,mixed> $row
    */
   public function __construct (string $driver, array $row)
   {
      // * Data
      $this->row = $row;

      parent::__construct([
         'driver' => $driver,
         'pool' => ['min' => 0, 'max' => 0],
      ]);
   }

   /**
    * Record the compiled statement and return a synthetic operation.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $this->statements[] = [
         'SQL' => $Normalized->SQL,
         'parameters' => $Normalized->parameters,
      ];

      return new Operation(null, $Normalized->SQL, $Normalized->parameters);
   }

   /**
    * Finish the operation: the read returns the row, a write lands on it.
    */
   public function await (Operation $Operation): Operation
   {
      if (str_starts_with($Operation->SQL, 'SELECT')) {
         $Operation->rows = [$this->row];
         $Operation->resolve(new Result('SELECT 1', [$this->row]));
      }
      else {
         $Operation->affected = 1;
         $Operation->resolve(new Result('UPDATE 1', affected: 1));
      }

      return $Operation;
   }
}


return new Test(
   description: 'Security: the rehash-on-verify upgrade is a compare-and-set on the verified hash — a concurrent reset or upgrade wins (H6C-4/USR-11)',
   skip: defined('PASSWORD_ARGON2ID') === false || extension_loaded('sqlite3') === false,
   test: function () {
      // ! The minimum argon2id policy and a cheap legacy bcrypt hash: every
      //   login below verifies the legacy hash and owes an upgrade
      $Policy = new Password(memory: 19456, time: 2, threads: 1);
      $legacy = password_hash('old-pass', PASSWORD_BCRYPT, ['cost' => 4]);
      $root = Temporaries::reserve('users-rehash-cas');

      // ! One SQLite file per leg, seeded with the legacy account (id 1)
      $boot = static function (string $leg, string $trigger = '', string $collation = '') use ($root, $legacy): string {
         $file = "{$root}/{$leg}.sqlite";
         $Seed = new SQLite3($file);
         $Seed->exec(<<<SQL
         CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE{$collation},
            password TEXT NOT NULL,
            email_verified_at INTEGER DEFAULT NULL
         )
         SQL);
         $Insert = $Seed->prepare('INSERT INTO users (email, password) VALUES (?, ?)');
         $Insert->bindValue(1, 'ana@bootgly.com');
         $Insert->bindValue(2, $legacy);
         $Insert->execute();
         if ($trigger !== '') {
            $Seed->exec($trigger);
         }
         $Seed->close();

         return $file;
      };
      // ! A fresh connection to the leg's file
      $connect = static function (string $file): SQL {
         return new SQL(['driver' => 'sqlite', 'database' => $file]);
      };
      // ! What the row holds now, read outside the framework
      $read = static function (string $file): null|string {
         $Observer = new SQLite3($file);
         $stored = $Observer->querySingle('SELECT password FROM users WHERE id = 1');
         $Observer->close();

         return is_string($stored) ? $stored : null;
      };
      // ! Failed legs, appended to a description
      $list = static function (array $legs): string {
         $failed = implode(', ', $legs);

         return $legs === [] ? '' : " — failed: {$failed}";
      };
      $suppressor = <<<SQL
      CREATE TRIGGER keep_password BEFORE UPDATE OF password ON users
      BEGIN
         SELECT RAISE(IGNORE);
      END
      SQL;

      // ! The Emitter instance is process-wide: observe on a private one and
      //   restore the previous one whatever happens below
      $PreviousEmitter = Emitter::$Instance;
      Emitter::$Instance = new Emitter;
      $observed = [];
      Emitter::$Instance->listen(Events::Failed, function (object $Emission) use (&$observed): void {
         $Operation = $Emission->payload[0] ?? null;

         if ($Operation instanceof Operation) {
            $observed[] = [
               'SQL' => $Operation->SQL,
               'error' => $Operation->error,
               'affected' => $Operation->affected,
            ];
         }
      });
      // ! Announcements of the upgrade write itself
      $upgrades = static function (array $observed): array {
         $writes = [];
         foreach ($observed as $announced) {
            if (str_starts_with($announced['SQL'], 'UPDATE')) {
               $writes[] = $announced;
            }
         }

         return $writes;
      };

      try {
         // # A) A reset committed inside the window stands
         $file = $boot('a');
         $Reset = new Users($connect($file), $Policy);
         $Raced = new RacedPassword(memory: 19456, time: 2, threads: 1);
         $rotated = null;
         $Raced->Hook = function () use ($Reset, &$rotated): void {
            $rotated = $Reset->rotate('1', 'new-pass');
         };
         $observed = [];
         $Identity = new Users($connect($file), $Raced)->verify('ana@bootgly.com', 'old-pass');
         $Check = new Users($connect($file), $Policy);

         yield assert(
            assertion: $Identity === null
               && $Raced->Hook === null
               && $rotated === true
               && $Check->check('1', 'old-pass') === false
               && $Check->check('1', 'new-pass') === true
               && $observed === [],
            description: 'a reset committed inside the rehash window stands and the racing login fails: rotate() is true, the old password is refused, the new one accepted, nothing announced'
         );

         // # B) A sibling upgrade inside the window is a lost race
         $file = $boot('b');
         $Sibling = new Users($connect($file), $Policy);
         $Raced = new RacedPassword(memory: 19456, time: 2, threads: 1);
         $upgraded = null;
         $Raced->Hook = function () use ($Sibling, $read, $file, &$upgraded): void {
            $Sibling->verify('ana@bootgly.com', 'old-pass');
            $upgraded = $read($file);
         };
         $observed = [];
         $Identity = new Users($connect($file), $Raced)->verify('ana@bootgly.com', 'old-pass');
         $stored = $read($file);

         yield assert(
            assertion: $Identity instanceof Identity
               && is_string($upgraded)
               && $upgraded !== $legacy
               && $Policy->check($upgraded)
               && $stored === $upgraded
               && password_verify('old-pass', $stored)
               && $observed === [],
            description: 'a sibling upgrade inside the window stands and is silent: the stored hash is the sibling\'s, conforms to the policy and still verifies'
         );

         // # B') The same race under a case-insensitive email, typed in
         //   another case — the account's own email is what is compared —
         //   with the account verified meanwhile: the claims are the row's
         //   as it is now
         $file = $boot('b-nocase', '', ' COLLATE NOCASE');
         $Sibling = new Users($connect($file), $Policy);
         $Raced = new RacedPassword(memory: 19456, time: 2, threads: 1);
         $Raced->Hook = function () use ($Sibling, $file): void {
            $Sibling->verify('ana@bootgly.com', 'old-pass');
            $Verifier = new SQLite3($file);
            $Verifier->exec('UPDATE users SET email_verified_at = 1760000000 WHERE id = 1');
            $Verifier->close();
         };
         $observed = [];
         $Identity = new Users($connect($file), $Raced)->verify('ANA@bootgly.com', 'old-pass');

         yield assert(
            assertion: $Identity instanceof Identity
               && $Raced->Hook === null
               && ($Identity->claims['email'] ?? null) === 'ana@bootgly.com'
               && ($Identity->claims['verified'] ?? null) === true
               && $observed === [],
            description: 'a sibling upgrade under a case-insensitive email typed in another case stands, with the claims the account holds now'
         );

         // # C) Without a race the upgrade lands
         $file = $boot('c');
         $observed = [];
         $Identity = new Users($connect($file), $Policy)->verify('ana@bootgly.com', 'old-pass');
         $stored = $read($file);

         yield assert(
            assertion: $Identity instanceof Identity
               && is_string($stored)
               && $stored !== $legacy
               && $Policy->check($stored)
               && password_verify('old-pass', $stored)
               && $observed === [],
            description: 'without a race the legacy hash is upgraded to the current policy, silently'
         );

         // # D) A suppressed write while the verified hash is still stored
         $file = $boot('d', $suppressor);
         $observed = [];
         $Identity = new Users($connect($file), $Policy)->verify('ana@bootgly.com', 'old-pass');

         yield assert(
            assertion: $Identity instanceof Identity
               && count($observed) === 1
               && str_starts_with($observed[0]['SQL'], 'UPDATE')
               && $observed[0]['error'] === null
               && $observed[0]['affected'] === 0
               && $read($file) === $legacy,
            description: 'an upgrade suppressed while the verified hash is still stored is announced exactly once, with the zero-row write'
         );

         // # E) An account removed inside the window
         $file = $boot('e');
         $Raced = new RacedPassword(memory: 19456, time: 2, threads: 1);
         $Raced->Hook = function () use ($file): void {
            $Remover = new SQLite3($file);
            $Remover->exec('DELETE FROM users WHERE id = 1');
            $Remover->close();
         };
         $observed = [];
         $Identity = new Users($connect($file), $Raced)->verify('ana@bootgly.com', 'old-pass');
         $Observer = new SQLite3($file);
         $rows = $Observer->querySingle('SELECT count(*) FROM users');
         $Observer->close();

         yield assert(
            assertion: $Identity === null
               && $Raced->Hook === null
               && $rows === 0
               && $observed === [],
            description: 'an account removed inside the window is a lost race: the login fails, nothing is announced, no row comes back'
         );

         // # H) Another account under the same id, created inside the window
         //   with the same password, is not the account the login proved
         $file = $boot('h');
         $Raced = new RacedPassword(memory: 19456, time: 2, threads: 1);
         $Raced->Hook = function () use ($file, $Policy): void {
            $Replacer = new SQLite3($file);
            $Replacer->exec('DELETE FROM users WHERE id = 1');
            $Insert = $Replacer->prepare('INSERT INTO users (id, email, password) VALUES (1, ?, ?)');
            $Insert->bindValue(1, 'bob@bootgly.com');
            $Insert->bindValue(2, $Policy->hash('old-pass'));
            $Insert->execute();
            $Replacer->close();
         };
         $observed = [];
         $Identity = new Users($connect($file), $Raced)->verify('ana@bootgly.com', 'old-pass');

         yield assert(
            assertion: $Identity === null
               && $Raced->Hook === null
               && $observed === [],
            description: 'another account re-created under the id inside the window fails the login, even with the same password'
         );

         // # I) The same account re-created under a new id inside the window
         //   (same email, same password): the id the login proved is gone
         $file = $boot('i');
         $Raced = new RacedPassword(memory: 19456, time: 2, threads: 1);
         $Raced->Hook = function () use ($file, $Policy): void {
            $Replacer = new SQLite3($file);
            $Replacer->exec('DELETE FROM users WHERE id = 1');
            $Insert = $Replacer->prepare('INSERT INTO users (id, email, password) VALUES (7, ?, ?)');
            $Insert->bindValue(1, 'ana@bootgly.com');
            $Insert->bindValue(2, $Policy->hash('old-pass'));
            $Insert->execute();
            $Replacer->close();
         };
         $observed = [];
         $Identity = new Users($connect($file), $Raced)->verify('ana@bootgly.com', 'old-pass');

         yield assert(
            assertion: $Identity === null
               && $Raced->Hook === null
               && $observed === [],
            description: 'the account re-created under a new id inside the window fails the login: the proven id is gone'
         );

         // # F) A re-read that fails after a missed upgrade proves nothing:
         //   the miss is announced and the failure propagates
         $legs = [];
         foreach (['unrecorded' => false, 'recorded' => true] as $mode => $recorded) {
            $file = $boot("f-{$mode}", $suppressor);
            $Database = new RereadSQL(['driver' => 'sqlite', 'database' => $file], $recorded);
            $observed = [];
            $escaped = null;
            $Identity = null;
            try {
               $Identity = new Users($Database, $Policy)->verify('ana@bootgly.com', 'old-pass');
            }
            catch (Throwable $Thrown) {
               $escaped = $Thrown->getMessage();
            }
            $writes = $upgrades($observed);
            $expected = $recorded ? RereadSQL::RECORDED : RereadSQL::UNRECORDED;

            if (
               $escaped !== $expected
               || $Identity !== null
               || $Database->failures !== 1
               || count($writes) !== 1
               || $writes[0]['error'] !== null
               || $writes[0]['affected'] !== 0
               || $read($file) !== $legacy
            ) {
               $legs[] = $mode;
            }
         }

         yield assert(
            assertion: $legs === [],
            description: "a re-read that fails after a missed upgrade (thrown or recorded) is announced once and propagates — the login fails closed{$list($legs)}"
         );

         // # G) The write binds the verified hash in an AND-joined filter
         $expected = [
            'pgsql' => 'UPDATE "users" SET "password" = $1 WHERE "id" = $2 AND "password" = $3',
            'mysql' => 'UPDATE `users` SET `password` = ? WHERE `id` = ? AND `password` = ?',
         ];
         $legs = [];
         foreach ($expected as $driver => $SQL) {
            $Database = new RecordingSQL($driver, [
               'id' => 1,
               'email' => 'ana@bootgly.com',
               'password' => $legacy,
               'email_verified_at' => null,
            ]);
            $observed = [];
            $Identity = new Users($Database, $Policy)->verify('ana@bootgly.com', 'old-pass');
            $statements = $Database->statements;
            $write = $statements[1] ?? null;
            $bound = $write['parameters'] ?? [];

            if (
               ($Identity instanceof Identity) === false
               || count($statements) !== 2
               || $write === null
               || $write['SQL'] !== $SQL
               || count($bound) !== 3
               || ! is_string($bound[0] ?? null)
               || $bound[0] === $legacy
               || $Policy->check($bound[0]) === false
               || ($bound[1] ?? null) !== '1'
               || ($bound[2] ?? null) !== $legacy
               || $observed !== []
            ) {
               $legs[] = $driver;
            }
         }

         yield assert(
            assertion: $legs === [],
            description: "on pgsql and mysql the upgrade binds the verified hash as a parameter of a WHERE joined with AND{$list($legs)}"
         );
      }
      finally {
         Emitter::$Instance = $PreviousEmitter;

         foreach (glob("{$root}/*") ?: [] as $entry) {
            @unlink($entry);
         }
         @rmdir($root);
      }
   }
);
