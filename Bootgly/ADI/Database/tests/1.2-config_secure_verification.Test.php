<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use function array_keys;
use function assert;
use function is_string;
use function json_encode;
use function str_contains;
use InvalidArgumentException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


/**
 * Verification is what `verify-ca` and `verify-full` ADD to TLS. `prefer` and
 * `require` encrypt without it unless `verify`/`name` opt in — the libpq and
 * MySQL `sslmode` contract the mode names were borrowed from. Under the old
 * derivation (`verify = mode !== 'disable'`) the shipped default `prefer`
 * verified the chain and the peer name, so a stock MySQL 8 — TLS on, with a
 * self-signed certificate — refused the very first connection.
 */
$derive = static function (array $secure): array {
   $Config = new Config($secure === [] ? [] : ['secure' => $secure]);

   return [$Config->secure['mode'], $Config->secure['verify'], $Config->secure['name']];
};

return new Test(
   description: 'Database: Config derives certificate verification from the strict modes only — prefer and require encrypt unverified unless verify/name opt in',
   test: function () use ($derive) {
      // # Defaults per mode
      $expected = [
         Config::SECURE_PREFER => [false, false],
         Config::SECURE_REQUIRE => [false, false],
         Config::SECURE_VERIFY_CA => [true, false],
         Config::SECURE_VERIFY_FULL => [true, true],
         Config::SECURE_DISABLE => [false, false],
      ];
      $default = $derive([]);

      yield assert(
         assertion: $default === [Config::SECURE_PREFER, false, false],
         description: 'The shipped default (prefer) verifies neither the chain nor the peer name — ' . json_encode($default)
      );

      foreach ($expected as $mode => [$verify, $name]) {
         $derived = $derive(['mode' => $mode]);

         yield assert(
            assertion: $derived === [$mode, $verify, $name],
            description: "`{$mode}` derives verify=" . json_encode($verify) . ', name=' . json_encode($name) . ' — ' . json_encode($derived)
         );
      }

      // # Explicit opt-ins, and the modes that force their flags
      $optin = $derive(['mode' => Config::SECURE_PREFER, 'verify' => true]);
      $named = $derive(['mode' => Config::SECURE_REQUIRE, 'verify' => true, 'name' => false]);
      $forcedCA = $derive(['mode' => Config::SECURE_VERIFY_CA, 'verify' => false, 'name' => true]);
      $forcedFull = $derive(['mode' => Config::SECURE_VERIFY_FULL, 'verify' => false, 'name' => false]);
      $forcedOff = $derive(['mode' => Config::SECURE_DISABLE, 'verify' => true, 'name' => true]);

      yield assert(
         assertion: $optin === [Config::SECURE_PREFER, true, true] && $named === [Config::SECURE_REQUIRE, true, false],
         description: 'An explicit `verify` still opts prefer/require into verification, with `name` following it unless set — ' . json_encode([$optin, $named])
      );
      yield assert(
         assertion: $forcedCA === [Config::SECURE_VERIFY_CA, true, false]
            && $forcedFull === [Config::SECURE_VERIFY_FULL, true, true]
            && $forcedOff === [Config::SECURE_DISABLE, false, false],
         description: 'verify-ca, verify-full and disable keep forcing their flags over any explicit value — ' . json_encode([$forcedCA, $forcedFull, $forcedOff])
      );

      // # A `cafile` needs a verifying handshake: refused under prefer/require
      //   unless `verify` opts in; irrelevant under disable
      $refusals = [];
      $accepted = [];
      foreach ([
         'prefer' => ['mode' => Config::SECURE_PREFER, 'cafile' => '/etc/ssl/ca.pem'],
         'require' => ['mode' => Config::SECURE_REQUIRE, 'cafile' => '/etc/ssl/ca.pem'],
         'prefer + verify' => ['mode' => Config::SECURE_PREFER, 'cafile' => '/etc/ssl/ca.pem', 'verify' => true],
         'verify-ca' => ['mode' => Config::SECURE_VERIFY_CA, 'cafile' => '/etc/ssl/ca.pem'],
         'verify-full' => ['mode' => Config::SECURE_VERIFY_FULL, 'cafile' => '/etc/ssl/ca.pem'],
         'disable' => ['mode' => Config::SECURE_DISABLE, 'cafile' => '/etc/ssl/ca.pem'],
      ] as $label => $secure) {
         try {
            $accepted[$label] = (new Config(['secure' => $secure]))->secure['cafile'];
         }
         catch (InvalidArgumentException $Exception) {
            $refusals[$label] = $Exception->getMessage();
         }
      }

      yield assert(
         assertion: array_keys($refusals) === ['prefer', 'require']
            && str_contains($refusals['prefer'], 'cafile requires certificate verification')
            && $accepted === [
               'prefer + verify' => '/etc/ssl/ca.pem',
               'verify-ca' => '/etc/ssl/ca.pem',
               'verify-full' => '/etc/ssl/ca.pem',
               'disable' => '/etc/ssl/ca.pem',
            ],
         description: 'A cafile under prefer/require without `verify` is refused at config time naming the way out; verifying modes, an explicit verify and disable keep it — ' . json_encode([$refusals, $accepted])
      );

      $declared = null;
      try {
         new Config([
            'secure' => ['mode' => Config::SECURE_VERIFY_FULL, 'cafile' => '/etc/ssl/ca.pem'],
            'replicas' => [['host' => 'r1', 'secure' => ['mode' => Config::SECURE_PREFER, 'cafile' => '/etc/ssl/replica.pem']]],
         ]);
      }
      catch (InvalidArgumentException $Exception) {
         $declared = $Exception->getMessage();
      }
      $Inherited = new Config([
         'secure' => ['mode' => Config::SECURE_VERIFY_FULL, 'cafile' => '/etc/ssl/ca.pem'],
         'replicas' => [['host' => 'r1', 'secure' => ['mode' => Config::SECURE_PREFER]]],
      ]);

      yield assert(
         assertion: is_string($declared)
            && str_contains($declared, 'cafile requires certificate verification')
            && str_contains($declared, 'replica r1')
            && $Inherited->replicas[0]['secure']['verify'] === false
            && $Inherited->replicas[0]['secure']['cafile'] === '',
         description: 'A replica resolving unverified is refused only for a cafile it declared itself; the primary\'s inherited cafile is dropped, since no handshake would read it — ' . json_encode([$declared, $Inherited->replicas[0]['secure']])
      );

      // # Boolean flags are read, never guessed: boolean-like scalars parse,
      //   anything else is refused instead of resolving to "off"
      $parsed = [
         $derive(['mode' => Config::SECURE_PREFER, 'verify' => 'true']),
         $derive(['mode' => Config::SECURE_PREFER, 'verify' => 1, 'name' => 0]),
         $derive(['mode' => Config::SECURE_PREFER, 'verify' => 'yes', 'name' => 'off']),
      ];
      $unreadable = [];
      foreach ([['verify', 'maybe'], ['name', [true]], ['verify', ''], ['verify', '  '], ['name', "\n"]] as [$key, $garbage]) {
         try {
            $derive(['mode' => Config::SECURE_PREFER, $key => $garbage]);
            $unreadable[] = null;
         }
         catch (InvalidArgumentException $Exception) {
            $unreadable[] = $Exception->getMessage();
         }
      }
      $replica = null;
      try {
         new Config(['replicas' => [['host' => 'r2', 'secure' => ['verify' => 'maybe']]]]);
      }
      catch (InvalidArgumentException $Exception) {
         $replica = $Exception->getMessage();
      }

      yield assert(
         assertion: $parsed === [
            [Config::SECURE_PREFER, true, true],
            [Config::SECURE_PREFER, true, false],
            [Config::SECURE_PREFER, true, false],
         ]
            && $unreadable === [
               'Database TLS `verify` must be a boolean.',
               'Database TLS `name` must be a boolean.',
               'Database TLS `verify` must be a boolean.',
               'Database TLS `verify` must be a boolean.',
               'Database TLS `name` must be a boolean.',
            ]
            && $replica === 'Database TLS `verify` must be a boolean (replica r2).',
         description: 'Boolean-like `verify`/`name` values parse; an unreadable one — empty and blank strings included — is refused at config time rather than failing open, naming the replica — ' . json_encode([$parsed, $unreadable, $replica])
      );

      // # Replicas: a declared `mode` derives its own flags (POOL-8), an
      //   undeclared one inherits the primary's resolved flags
      $flags = static function (array $replicas): array {
         $resolved = [];
         foreach ($replicas as $replica) {
            $resolved[] = [$replica['secure']['mode'], $replica['secure']['verify'], $replica['secure']['name']];
         }

         return $resolved;
      };
      $Preferred = new Config([
         'replicas' => [
            ['host' => 'r1'],
            ['host' => 'r2', 'secure' => ['mode' => Config::SECURE_VERIFY_FULL]],
            ['host' => 'r3', 'secure' => ['mode' => Config::SECURE_REQUIRE, 'verify' => true]],
         ],
      ]);
      $preferred = $flags($Preferred->replicas);

      yield assert(
         assertion: $preferred === [
            [Config::SECURE_PREFER, false, false],
            [Config::SECURE_VERIFY_FULL, true, true],
            [Config::SECURE_REQUIRE, true, true],
         ],
         description: 'Replicas under a prefer primary stay unverified unless their own mode is strict or `verify` opts in — ' . json_encode($preferred)
      );

      $Strict = new Config([
         'secure' => ['mode' => Config::SECURE_VERIFY_FULL],
         'replicas' => [
            ['host' => 'r1'],
            ['host' => 'r2', 'secure' => ['mode' => Config::SECURE_PREFER]],
            ['host' => 'r3', 'secure' => ['mode' => Config::SECURE_REQUIRE]],
            ['host' => 'r4', 'secure' => ['verify' => false]],
         ],
      ]);
      $strict = $flags($Strict->replicas);

      yield assert(
         assertion: $strict === [
            [Config::SECURE_VERIFY_FULL, true, true],
            [Config::SECURE_PREFER, false, false],
            [Config::SECURE_REQUIRE, false, false],
            [Config::SECURE_VERIFY_FULL, true, true],
         ],
         description: 'A replica that declares prefer/require under a verify-full primary derives its own unverified flags; an undeclared mode inherits and is re-forced — ' . json_encode($strict)
      );

      $Plain = new Config([
         'secure' => ['mode' => Config::SECURE_DISABLE],
         'replicas' => [
            ['host' => 'r1', 'secure' => ['mode' => Config::SECURE_REQUIRE]],
            ['host' => 'r2', 'secure' => ['mode' => Config::SECURE_VERIFY_CA]],
         ],
      ]);
      $plain = $flags($Plain->replicas);

      yield assert(
         assertion: $plain === [
            [Config::SECURE_REQUIRE, false, false],
            [Config::SECURE_VERIFY_CA, true, false],
         ],
         description: 'A replica declaring its mode under a disable primary resolves exactly as the same fragment would at top level — ' . json_encode($plain)
      );

      // # Inheritance: a replica that declares nothing — or a mode that is not
      //   a scalar — takes the primary's RESOLVED flags, opt-ins included
      $Opted = new Config([
         'secure' => ['mode' => Config::SECURE_PREFER, 'verify' => true],
         'replicas' => [
            ['host' => 'r1'],
            ['host' => 'r2', 'secure' => ['mode' => ['garbage']]],
         ],
      ]);
      $Named = new Config([
         'secure' => ['mode' => Config::SECURE_REQUIRE, 'verify' => true, 'name' => false],
         'replicas' => [['host' => 'r1']],
      ]);
      $opted = $flags($Opted->replicas);
      $named = $flags($Named->replicas);

      yield assert(
         assertion: $opted === [[Config::SECURE_PREFER, true, true], [Config::SECURE_PREFER, true, true]]
            && $named === [[Config::SECURE_REQUIRE, true, false]],
         description: 'A replica declaring nothing inherits the primary\'s resolved verify AND name, explicit opt-ins included — ' . json_encode([$opted, $named])
      );

      // # The normalized endpoint is fed back through this constructor by
      //   `SQL` (one Config per replica pool): every replica-plus-cafile shape
      //   the primary accepts must survive that second pass
      $CA = '/etc/ssl/ca.pem';
      $matrix = [
         'A require+verify+CA / replica require' => [['mode' => Config::SECURE_REQUIRE, 'verify' => true, 'cafile' => $CA], ['mode' => Config::SECURE_REQUIRE]],
         'C disable+CA / replica require' => [['mode' => Config::SECURE_DISABLE, 'cafile' => $CA], ['mode' => Config::SECURE_REQUIRE]],
         'D require+verify+CA / replica verify=false' => [['mode' => Config::SECURE_REQUIRE, 'verify' => true, 'cafile' => $CA], ['verify' => false]],
         'E verify-full+CA / replica prefer' => [['mode' => Config::SECURE_VERIFY_FULL, 'cafile' => $CA], ['mode' => Config::SECURE_PREFER]],
         'F verify-full+CA / replica nothing' => [['mode' => Config::SECURE_VERIFY_FULL, 'cafile' => $CA], []],
         'G verify-full+CA / replica verify-ca+verify=false' => [['mode' => Config::SECURE_VERIFY_FULL, 'cafile' => $CA], ['mode' => Config::SECURE_VERIFY_CA, 'verify' => false]],
         'H disable+CA / replica nothing' => [['mode' => Config::SECURE_DISABLE, 'cafile' => $CA], []],
      ];
      $survived = [];
      foreach ($matrix as $label => [$primary, $replica]) {
         $endpoint = ['host' => 'r1'];
         if ($replica !== []) {
            $endpoint['secure'] = $replica;
         }
         try {
            $SQL = new SQL(['secure' => $primary, 'replicas' => [$endpoint], 'pool' => ['min' => 0, 'max' => 1]]);
            $secure = $SQL->ReplicaPools[0]->Config->secure;
            $survived[$label] = [$secure['verify'], $secure['cafile']];
         }
         catch (Throwable $Throwable) {
            $survived[$label] = $Throwable->getMessage();
         }
      }

      yield assert(
         assertion: $survived === [
            'A require+verify+CA / replica require' => [false, ''],
            'C disable+CA / replica require' => [false, ''],
            'D require+verify+CA / replica verify=false' => [false, ''],
            'E verify-full+CA / replica prefer' => [false, ''],
            'F verify-full+CA / replica nothing' => [true, $CA],
            'G verify-full+CA / replica verify-ca+verify=false' => [true, $CA],
            'H disable+CA / replica nothing' => [false, ''],
         ],
         description: 'Every replica-plus-cafile shape survives `new SQL()`: an unverified replica drops the inherited cafile, a verifying one — forced by its mode over an explicit false included — keeps it — ' . json_encode($survived)
      );
   }
);
