<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\UsersLegacyUpgrade;


use function assert;
use function base64_encode;
use function defined;
use function extension_loaded;
use function glob;
use function hash_pbkdf2;
use function is_string;
use function rmdir;
use function str_starts_with;
use function unlink;
use SensitiveParameter;
use SQLite3;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Security\Identity;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Password\Verifier;
use Bootgly\API\Security\Password\Verifiers\PBKDF2;
use Bootgly\API\Security\Users;


/**
 * A policy that counts the hashes it mints.
 */
final class Counter extends Password
{
   // * Metadata
   public private(set) int $hashes = 0;


   public function hash (#[SensitiveParameter] string $password): string
   {
      $this->hashes++;

      return parent::hash($password);
   }
}

/**
 * Verifier that accepts every hash and records the ones it was asked for.
 */
final class Acceptor implements Verifier
{
   // * Metadata
   /** @var array<int,string> */
   public private(set) array $asked = [];


   public function verify (#[SensitiveParameter] string $password, #[SensitiveParameter] string $hash): bool
   {
      $this->asked[] = $hash;

      return true;
   }
}

/**
 * SQLite facade that counts the writes it is handed.
 */
final class CountingSQL extends SQL
{
   // * Metadata
   public private(set) int $writes = 0;


   /**
    * Create the operation, counting every UPDATE.
    *
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Operation = parent::query($query, $parameters, $Scope);

      if (str_starts_with($Operation->SQL, 'UPDATE')) {
         $this->writes++;
      }

      return $Operation;
   }
}


return new Test(
   description: 'Security: an account imported with a legacy hash signs in, is stored upgraded to argon2id, and a second sign-in writes nothing',
   skip: defined('PASSWORD_ARGON2ID') === false || extension_loaded('sqlite3') === false,
   test: function () {
      // ! Two imported accounts holding PBKDF2-SHA256 rows of another stack
      $salt = 'sixteen-byte-key';
      $mint = static function (string $secret) use ($salt): string {
         $encodedSalt = base64_encode($salt);
         $encodedKey = base64_encode(hash_pbkdf2('sha256', $secret, $salt, 1000, 32, true));

         return "v1:1000:{$encodedSalt}:{$encodedKey}";
      };
      $ana = $mint('ana-pass');
      $bia = $mint('bia-pass');
      $root = Temporaries::reserve('users-legacy-upgrade');
      $file = "{$root}/users.sqlite";
      $Seed = new SQLite3($file);
      $Seed->exec(<<<SQL
      CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         email TEXT NOT NULL UNIQUE,
         password TEXT NOT NULL,
         email_verified_at INTEGER DEFAULT NULL
      )
      SQL);
      foreach (['ana@bootgly.com' => $ana, 'bia@bootgly.com' => $bia] as $email => $hash) {
         $Insert = $Seed->prepare('INSERT INTO users (email, password) VALUES (?, ?)');
         $Insert->bindValue(1, $email);
         $Insert->bindValue(2, $hash);
         $Insert->execute();
      }
      $Seed->close();
      // ! What a row holds now, read outside the framework
      $read = static function (int $id) use ($file): null|string {
         $Observer = new SQLite3($file);
         $stored = $Observer->querySingle("SELECT password FROM users WHERE id = {$id}");
         $Observer->close();

         return is_string($stored) ? $stored : null;
      };
      // ! One sign-in on a fresh connection, as a request does
      $sign = static function (Password $Password, string $email, string $password) use ($file): array {
         $Database = new CountingSQL(['driver' => 'sqlite', 'database' => $file]);
         $Identity = new Users($Database, $Password)->verify($email, $password);

         return [$Identity, $Database->writes];
      };

      try {
         // # Without a verifier the imported account cannot sign in
         [$Identity, $writes] = $sign(new Counter(memory: 19456, time: 2, threads: 1), 'ana@bootgly.com', 'ana-pass');

         yield assert(
            assertion: $Identity === null && $writes === 0 && $read(1) === $ana,
            description: 'without a verifier the imported account does not sign in and its row is untouched'
         );

         // # First sign-in: verified by the verifier, stored upgraded
         $Password = new Counter(memory: 19456, time: 2, threads: 1, Verifiers: [new PBKDF2(prefix: 'v1:', ceiling: 1000)]);
         [$Identity, $writes] = $sign($Password, 'ana@bootgly.com', 'ana-pass');
         $stored = (string) $read(1);

         yield assert(
            assertion: $Identity instanceof Identity
               && $Identity->claims['email'] === 'ana@bootgly.com'
               && $writes === 1
               && $Password->hashes === 1,
            description: 'the imported account signs in with its password: one hash minted, one write'
         );

         yield assert(
            assertion: $stored !== $ana
               && str_starts_with($stored, '$argon2id$')
               && $Password->check($stored) === true
               && $Password->verify('ana-pass', $stored) === true,
            description: 'the row now holds an argon2id hash of the current policy that verifies the same password'
         );

         // # Second sign-in: nothing minted, nothing written
         [$Identity, $writes] = $sign($Password, 'ana@bootgly.com', 'ana-pass');

         yield assert(
            assertion: $Identity instanceof Identity
               && $writes === 0
               && $Password->hashes === 1
               && $read(1) === $stored,
            description: 'a second sign-in succeeds, mints no hash, writes nothing and leaves the stored hash as it was'
         );

         // # A wrong password on a legacy row: refused, the row stays legacy
         [$Identity, $writes] = $sign($Password, 'bia@bootgly.com', 'ana-pass');

         yield assert(
            assertion: $Identity === null
               && $writes === 0
               && $Password->hashes === 1
               && $read(2) === $bia,
            description: 'a wrong password on a legacy row is refused and the row is not upgraded'
         );

         // # check(): the current-password gate reads the legacy row, without upgrading it
         $Gate = new Users(new CountingSQL(['driver' => 'sqlite', 'database' => $file]), $Password);

         yield assert(
            assertion: $Gate->check('2', 'bia-pass') === true
               && $Gate->check('2', 'ana-pass') === false
               && $read(2) === $bia,
            description: 'check() accepts the legacy row with its password and leaves it as it is'
         );

         // # An unknown identifier: the decoy never reaches a verifier
         $Acceptor = new Acceptor;
         $Open = new Counter(memory: 19456, time: 2, threads: 1, Verifiers: [$Acceptor]);
         [$Identity, $writes] = $sign($Open, 'nobody@bootgly.com', 'ana-pass');
         $Unknown = new Users(new CountingSQL(['driver' => 'sqlite', 'database' => $file]), $Open);

         yield assert(
            assertion: $Identity === null
               && $writes === 0
               && $Unknown->check('999', 'ana-pass') === false
               && $Acceptor->asked === [],
            description: 'an unknown identifier is refused even with an accept-all verifier: the decoy is never handed to it'
         );
      }
      finally {
         foreach (glob("{$root}/*") ?: [] as $entry) {
            @unlink($entry);
         }
         @rmdir($root);
      }
   }
);
