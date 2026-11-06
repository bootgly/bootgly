<?php

namespace Bootgly\API\Security\Tests\SecurityClockSeams;


use function array_values;
use function assert;
use function defined;
use function extension_loaded;
use Closure;

use Bootgly\ABI\Clock as SystemClock;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\API\Security\JWT\Cache;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Remote;
use Bootgly\API\Security\JWT\Remote\Response;
use Bootgly\API\Security\Password;
use Bootgly\API\Security\Tokens;
use Bootgly\API\Security\Tokens\Purposes;
use Bootgly\API\Security\Tokens\Trust;
use Bootgly\API\Security\Users;


return new Test(
   description: 'Security: token and account time, the remote JWKS origin floor and its shared lifetime read an injected Clock',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      // # Tokens (Clocked): `$Clock` drives token time in whole seconds
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
      $Database->query(<<<SQL
      CREATE TABLE tokens (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         user_id INTEGER NOT NULL,
         purpose TEXT NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP,
         UNIQUE (user_id, purpose)
      )
      SQL);
      $Database->query(<<<SQL
      CREATE TABLE trusts (
         id INTEGER PRIMARY KEY AUTOINCREMENT,
         selector TEXT NOT NULL UNIQUE,
         verifier TEXT NOT NULL,
         previous TEXT DEFAULT NULL,
         rotated INTEGER DEFAULT NULL,
         user_id INTEGER NOT NULL,
         expires INTEGER NOT NULL,
         created_at TEXT DEFAULT CURRENT_TIMESTAMP
      )
      SQL);

      $Tokens = new Tokens($Database);
      yield assert(
         assertion: $Tokens->Clock::class === SystemClock::class && $Tokens->Clock === $Tokens->Clock,
         description: 'Tokens read one system Clock by default'
      );

      // ! Fractional start: token time is whole seconds, `(int) $now`
      $Clock = new Clock(1_000_000.9);
      $Tokens->Clock = $Clock;
      $Recovery = $Tokens->mint('7', Purposes::Recovery, ttl: 60);
      $Verification = $Tokens->mint('7', Purposes::Verification, ttl: 60);
      yield assert(
         assertion: $Tokens->Clock === $Clock && $Recovery->expires === 1_000_060,
         description: 'an assigned Clock stamps expirations in whole seconds'
      );

      $Clock->advance(59);
      yield assert(
         assertion: $Tokens->redeem($Recovery->value, Purposes::Recovery) !== null,
         description: 'a token is live until the Clock reaches its expiry'
      );
      $Clock->advance(1);
      yield assert(
         assertion: $Tokens->redeem($Verification->value, Purposes::Verification) === null,
         description: 'a token expires when the Clock reaches its expiry'
      );

      // @ freeze() still wins over the Clock; resume() returns to it
      $Tokens->freeze(5_000);
      $Frozen = $Tokens->mint('8', Purposes::Recovery, ttl: 10);
      $Tokens->resume();
      $Resumed = $Tokens->mint('9', Purposes::Recovery, ttl: 10);
      yield assert(
         assertion: $Frozen->expires === 5_010 && $Resumed->expires === 1_000_070,
         description: 'freeze() overrides the Clock and resume() reads it again'
      );

      // @ The trait serves Trust too
      $Trust = new Trust($Database);
      $Trust->Clock = new Clock(2_000_000.5);
      yield assert(
         assertion: $Trust->issue('101', ttl: 100)->expires === 2_000_100,
         description: 'Trust reads its assigned Clock'
      );

      // @ ...and Users: confirm() stamps the e-mail confirmation on the Clock
      if (defined('PASSWORD_ARGON2ID') === true) {
         $Database->query(<<<SQL
         CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            email_verified_at INTEGER DEFAULT NULL
         )
         SQL);
         $Users = new Users($Database, new Password(memory: 19456, time: 2, threads: 1));
         $Users->Clock = new Clock(3_000_000.7);
         $user = $Users->enroll('clock@bootgly.com', 'secret-clock');
         $confirmed = $user !== null && $Users->confirm($user);
         $stamp = $Database
            ->query('SELECT email_verified_at FROM users WHERE email = $1', ['clock@bootgly.com'])
            ->Result?->cell;
         yield assert(
            assertion: $confirmed === true && (int) $stamp === 3_000_000,
            description: 'Users stamps confirm() with its Clock time, in whole seconds'
         );
      }

      // # Remote JWKS: freshness on the wall `$time`, origin floor on the `$tick`
      /** @var array{first:array{body:string}} $fixtures */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $body = $fixtures['first']['body'];
      $calls = 0;
      $origin = static function (Response $Answer) use (&$calls): Closure {
         return static function () use (&$calls, $Answer): Response {
            $calls++;

            return $Answer;
         };
      };

      $Remote = new Remote('https://issuer.example/clock/default', $origin(new Response(200, $body)));
      yield assert(
         assertion: $Remote->Clock::class === SystemClock::class,
         description: 'Remote reads the system Clock by default'
      );

      // @ A failure is replayed for one cooldown, in monotonic nanoseconds
      $Clock = new Clock(1_000_000);
      $Remote = new Remote(
         'https://issuer.example/clock/failure',
         $origin(new Response(500, 'unavailable')),
         cooldown: 60,
         Clock: $Clock
      );
      $trace = [];
      $trace[] = $Remote->fetch();
      $trace[] = $Remote->fetch();
      $Clock->advance(59.999);
      $trace[] = $Remote->fetch();
      $Clock->freeze(9_000_000);
      $trace[] = $Remote->fetch();
      $before = $calls;
      $Clock->advance(0.001);
      $trace[] = $Remote->fetch();
      yield assert(
         assertion: $Remote->Clock === $Clock
            && $trace === [Failures::Status, Failures::Status, Failures::Status, Failures::Status, Failures::Status]
            && $before === 1 && $calls === 2,
         description: 'a failure pauses the origin for exactly `cooldown` seconds of Clock ticks — a wall step never ends it'
      );

      // @ A confirmed `no-store` set is held for one cooldown on the tick
      $calls = 0;
      $Clock = new Clock(1_000_000);
      $Remote = new Remote(
         'https://issuer.example/clock/hold',
         $origin(new Response(200, $body, ['Cache-Control: no-store'])),
         cooldown: 30,
         Clock: $Clock
      );
      $First = $Remote->fetch();
      $Clock->advance(29);
      $Held = $Remote->fetch();
      $held = $calls;
      $Clock->advance(1);
      $Remote->fetch();
      yield assert(
         assertion: $First instanceof KeySet && $Held === $First && $held === 1 && $calls === 2,
         description: 'a held set is served until its window of Clock ticks ends, then asked again'
      );

      // @ Freshness (`max-age`) runs on the wall `$time`
      $calls = 0;
      $Clock = new Clock(1_000_000);
      $Remote = new Remote(
         'https://issuer.example/clock/fresh',
         $origin(new Response(200, $body, ['Cache-Control: max-age=120'])),
         cooldown: 0,
         Clock: $Clock
      );
      $Remote->fetch();
      $expires = $Remote->expires;
      $Clock->advance(119);
      $Remote->fetch();
      $fresh = $calls;
      $Clock->freeze(1_000_120);
      $Remote->fetch();
      yield assert(
         assertion: $expires === 1_000_120 && $fresh === 1 && $calls === 2,
         description: 'a fetched set stays fresh until the Clock wall time reaches its expiry'
      );

      // @ The shared-cache (Vault) lifetime is what is left of the expiry on `$time`
      $Vault = new class implements Cache {
         /** @var array<string,int> */
         public array $writes = [];

         public function lock (Closure $Closure): mixed
         {
            return $Closure();
         }
         public function read (string $key): null|string
         {
            return null;
         }
         public function write (string $key, string $value, int $ttl): bool
         {
            $this->writes[$key] = $ttl;

            return true;
         }
         public function claim (string $key, string $value, int $ttl): bool
         {
            return true;
         }
         public function take (string $key): null|string
         {
            return null;
         }
         public function delete (string $key): bool
         {
            return true;
         }
         public function purge (): bool
         {
            return true;
         }
      };
      $Clock = new Clock(1_000_000);
      $Remote = new Remote(
         'https://issuer.example/clock/vault',
         $origin(new Response(200, $body, ['Cache-Control: max-age=120'])),
         cooldown: 0,
         Clock: $Clock
      );
      $Remote->cache($Vault);
      $Remote->fetch();
      yield assert(
         assertion: $Remote->expires === 1_000_120 && array_values($Vault->writes) === [120],
         description: 'a fetched set is shared for what is left of its expiry on the Clock wall time'
      );

      // @ The refresh on an unknown `kid` is spaced by `cooldown` on `$time`
      $calls = 0;
      $Clock = new Clock(1_000_000);
      $Remote = new Remote(
         'https://issuer.example/clock/miss',
         $origin(new Response(200, $body, ['Cache-Control: max-age=3600'])),
         cooldown: 60,
         Clock: $Clock
      );
      $Remote->fetch();
      $trace = [];
      $trace[] = $Remote->resolve('clock-unknown', 'RS256');
      $trace[] = $calls;
      $Clock->advance(59);
      $trace[] = $Remote->resolve('clock-unknown', 'RS256');
      $trace[] = $calls;
      $Clock->advance(1);
      $trace[] = $Remote->resolve('clock-unknown', 'RS256');
      $trace[] = $calls;
      yield assert(
         assertion: $trace === [null, 2, null, 2, null, 3],
         description: 'an unknown `kid` refreshes once, then again only when `cooldown` seconds of Clock time have passed'
      );
   }
);
