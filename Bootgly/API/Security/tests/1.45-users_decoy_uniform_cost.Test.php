<?php

namespace Bootgly\API\Security\Tests\UsersDecoyUniformCost;


use function assert;
use function defined;
use function extension_loaded;
use function json_encode;
use function password_get_info;
use function password_needs_rehash;
use function password_verify;
use function strlen;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Users;


/**
 * A policy that counts the Argon2 work the store asks for.
 */
final class Spy extends Password
{
   // * Metadata
   public private(set) int $hashes = 0;
   public private(set) int $verifies = 0;


   public function hash (#[\SensitiveParameter] string $password): string
   {
      $this->hashes++;

      return parent::hash($password);
   }

   public function verify (#[\SensitiveParameter] string $password, string $hash): bool
   {
      $this->verifies++;

      return parent::verify($password, $hash);
   }
}


/**
 * HARDENIZE H-11 (security report M8) — an unknown identifier costs exactly
 * the work of a wrong password.
 *
 * The decoy verified on unknown-identifier paths used to be a real hash,
 * minted on the first miss of every `Users` instance: built per request (the
 * kit pattern), each unknown e-mail cost a hash and a verify against one
 * verify for a wrong password — twice the time, a silent enumeration oracle.
 * Pinned deterministically, on Argon2 operation counts rather than clocks:
 *
 * - `verify()` and `check()` of an unknown identifier, on a fresh instance and
 *   on a warmed one, ask for 0 hashes and 1 verify — the same work as a wrong
 *   password (the control);
 * - the decoy is a hash of the current policy — same algorithm and options as
 *   `Password::hash()` (so it verifies at the same cost), conforming to the
 *   policy, the same length — for the default policy and a non-default one,
 *   and no password verifies against it.
 */
return new Test(
   description: 'Security: an unknown identifier costs the work of a wrong password (decoy of the policy, never a hash)',
   skip: defined('PASSWORD_ARGON2ID') === false || extension_loaded('sqlite3') === false,
   test: function () {
      // ! One store with one account
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE users (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         email TEXT NOT NULL UNIQUE,
         password TEXT NOT NULL,
         email_verified_at INTEGER DEFAULT NULL
      )
      SQL);
      $Seed = new Users($Database, new Password(memory: 19456, time: 2, threads: 1));
      $user = $Seed->enroll('ana@bootgly.com', 'secret-one');

      // @ The Argon2 work of each shape, on a fresh instance per request
      $rows = [];
      $shapes = [
         'verify unknown' => static fn (Users $Users) => $Users->verify('nobody@bootgly.com', 'guess'),
         'verify wrong password' => static fn (Users $Users) => $Users->verify('ana@bootgly.com', 'guess'),
         'check unknown' => static fn (Users $Users) => $Users->check('999', 'guess'),
         'check wrong password' => static fn (Users $Users) => $Users->check((string) $user, 'guess'),
      ];
      foreach ($shapes as $shape => $Call) {
         foreach (['fresh', 'warmed'] as $state) {
            $Spy = new Spy(memory: 19456, time: 2, threads: 1);
            $Users = new Users($Database, $Spy);
            if ($state === 'warmed') {
               $Call($Users);
            }
            $hashes = $Spy->hashes;
            $verifies = $Spy->verifies;
            $result = $Call($Users);

            $rows["{$shape} ({$state})"] = [
               'hashes' => $Spy->hashes - $hashes,
               'verifies' => $Spy->verifies - $verifies,
               'denied' => $result === null || $result === false,
            ];
         }
      }
      $evidence = json_encode($rows);
      $work = ['hashes' => 0, 'verifies' => 1, 'denied' => true];

      yield assert(
         assertion: $rows === [
            'verify unknown (fresh)' => $work,
            'verify unknown (warmed)' => $work,
            'verify wrong password (fresh)' => $work,
            'verify wrong password (warmed)' => $work,
            'check unknown (fresh)' => $work,
            'check unknown (warmed)' => $work,
            'check wrong password (fresh)' => $work,
            'check wrong password (warmed)' => $work,
         ],
         description: "An unknown identifier asks for no hash and one verify — the work of a wrong password — fresh or warmed: {$evidence}"
      );

      // @ The decoy is a hash of the policy, for the default and another one
      $Decoy = new ReflectionProperty(Users::class, 'decoy');
      $rows = [];
      foreach (['default' => new Password, 'custom' => new Password(memory: 32768, time: 3, threads: 2)] as $policy => $Password) {
         $Users = new Users($Database, $Password);
         $decoy = $Decoy->getValue($Users);
         $real = $Password->hash('secret-one');
         $options = [
            'memory_cost' => $Password->memory,
            'time_cost' => $Password->time,
            'threads' => $Password->threads,
         ];

         $rows[$policy] = [
            'info' => password_get_info($decoy) === password_get_info($real),
            'conforms' => password_needs_rehash($decoy, 'argon2id', $options) === false,
            'length' => strlen($decoy) === strlen($real),
            'stable' => $Decoy->getValue($Users) === $decoy,
            'verifies' => password_verify('secret-one', $decoy) || password_verify('', $decoy) || password_verify('bootgly-decoy', $decoy),
         ];
      }
      $evidence = json_encode($rows);
      $shape = ['info' => true, 'conforms' => true, 'length' => true, 'stable' => true, 'verifies' => false];

      yield assert(
         assertion: $rows === ['default' => $shape, 'custom' => $shape],
         description: "The decoy is a hash of the current policy — same algorithm, options and length as Password::hash() — and no password verifies against it: {$evidence}"
      );
   }
);
