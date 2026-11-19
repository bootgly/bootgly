<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database\Tests\RedactionDumps;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function sort;
use function str_contains;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Data\RESP\Encoder as RESPEncoder;
use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\KV\Operation as KVOperation;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder as PostgreSQLEncoder;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


/**
 * Every ADI object that stores a secret — the configuration's passwords (the
 * primary's and each replica's), an operation's wire buffer (the Redis `AUTH`
 * preamble, the PostgreSQL password message), the PostgreSQL cancellation key
 * and the expected SCRAM server signature — shows it masked in every debug
 * dump, while the live value keeps the secret and the other fields show.
 */
return new Test(
   description: 'Database: debug dumps mask the passwords, the wire buffers, the cancellation key and the SCRAM signature',
   test: function () {
      // ! Each debug channel
      $Dumper = new Dumper('plain');
      $Channels = [
         'var_dump' => static function (object $Object): string {
            ob_start();
            var_dump($Object);

            return (string) ob_get_clean();
         },
         'print_r' => static fn (object $Object): string => print_r($Object, true),
         'debug_zval_dump' => static function (object $Object): string {
            ob_start();
            debug_zval_dump($Object);

            return (string) ob_get_clean();
         },
         'Dumper' => static fn (object $Object): string => $Dumper->dump($Object),
      ];

      // ! Holders, each built with SYNTH secrets
      $KVConfig = new DatabaseConfig([
         'driver' => 'redis',
         'host' => 'kv.invalid',
         'password' => 'SYNTH-DUMP-KV-PRIMARY',
         'replicas' => [['host' => 'kv-replica.invalid', 'password' => 'SYNTH-DUMP-KV-REPLICA']],
      ]);
      $SQLConfig = new Config([
         'host' => 'db.invalid',
         'username' => 'reader',
         'password' => 'SYNTH-DUMP-SQL-PRIMARY',
         'replicas' => [
            ['host' => 'replica-a.invalid', 'password' => 'SYNTH-DUMP-SQL-REPLICA'],
            // # A replica without its own password inherits the primary's
            ['host' => 'replica-b.invalid'],
         ],
      ]);
      $preamble = (new RESPEncoder)->encode(['AUTH', 'SYNTH-DUMP-KV-AUTH']);
      $KVOperation = new KVOperation(null, 'PING');
      $KVOperation->write = $preamble;
      $packet = (new PostgreSQLEncoder)->encode(PostgreSQLEncoder::PASSWORD, 'SYNTH-DUMP-SQL-WIRE');
      $SQLOperation = new SQLOperation(null, 'SELECT 1');
      $SQLOperation->write = $packet;
      // # The BackendKeyData secret is an integer
      $cancel = 271828182;
      $Driver = new PostgreSQL($SQLConfig, new Connection($SQLConfig));
      $Driver->identify(4242, $cancel);
      $Authentication = new Authentication($SQLConfig);
      $Authentication->clientNonce = 'nonce-dump';
      $Authentication->start(['SCRAM-SHA-256']);
      $Authentication->serverSignature = 'SYNTH-DUMP-SIGNATURE';

      // ! holder => [object, its secrets, what still shows, the properties marked #[Secret]]
      $Holders = [
         'Database\Config' => [
            $KVConfig,
            ['SYNTH-DUMP-KV-PRIMARY', 'SYNTH-DUMP-KV-REPLICA'],
            ['kv.invalid', 'kv-replica.invalid'],
            ['password', 'replicas'],
         ],
         'SQL\Config' => [
            $SQLConfig,
            ['SYNTH-DUMP-SQL-PRIMARY', 'SYNTH-DUMP-SQL-REPLICA'],
            ['db.invalid', 'reader', 'replica-a.invalid', 'replica-b.invalid'],
            ['password', 'replicas'],
         ],
         'KV\Operation' => [
            $KVOperation,
            ['SYNTH-DUMP-KV-AUTH'],
            ['PING'],
            ['write'],
         ],
         'SQL\Operation' => [
            $SQLOperation,
            ['SYNTH-DUMP-SQL-WIRE'],
            ['SELECT 1'],
            ['write'],
         ],
         'PostgreSQL' => [
            $Driver,
            ['271828182', 'SYNTH-DUMP-SQL-PRIMARY', 'SYNTH-DUMP-SQL-REPLICA'],
            ['4242', 'db.invalid'],
            ['backendSecret'],
         ],
         'PostgreSQL\Authentication' => [
            $Authentication,
            ['SYNTH-DUMP-SIGNATURE', 'SYNTH-DUMP-SQL-PRIMARY'],
            ['n=reader,r=nonce-dump'],
            ['serverSignature'],
         ],
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Object, $secrets, $shown]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Object);
            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$holder} {$channel}: {$secret}";
               }
            }
            foreach ([...$shown, Secret::MASK] as $value) {
               if (str_contains($output, $value) === false) {
                  $missing[] = "{$holder} {$channel}: {$value}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No SYNTH secret reaches var_dump, print_r, debug_zval_dump or Dumper — nested holders included — "
            . "while the other fields and the mask show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ The live values keep the secrets
      yield assert(
         assertion: $KVConfig->password === 'SYNTH-DUMP-KV-PRIMARY'
            && $KVConfig->replicas[0]['password'] === 'SYNTH-DUMP-KV-REPLICA'
            && $SQLConfig->password === 'SYNTH-DUMP-SQL-PRIMARY'
            && $SQLConfig->replicas[0]['password'] === 'SYNTH-DUMP-SQL-REPLICA'
            && $SQLConfig->replicas[1]['password'] === 'SYNTH-DUMP-SQL-PRIMARY'
            && $KVOperation->write === $preamble
            && $SQLOperation->write === $packet
            && $Driver->backendSecret === $cancel
            && $Driver->backendProcess === 4242
            && $Authentication->serverSignature === 'SYNTH-DUMP-SIGNATURE', // @phpstan-ignore identical.alwaysTrue (the dumps ran through $Object, which PHPStan does not tie to $Authentication)
         description: 'Dumping never writes: every live value keeps its secret'
      );

      // @ One __debugInfo — the trait's — and #[Secret] only on stored, instance properties
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $foreign = [];
      $misplaced = [];
      $unexpected = [];
      foreach ($Holders as $holder => [$Object, , , $expected]) {
         $Method = new ReflectionMethod($Object, '__debugInfo');
         if ($Method->getFileName() !== $Trait->getFileName() || $Method->getStartLine() !== $Trait->getStartLine()) {
            $foreign[] = $holder;
         }

         $marked = [];
         $Class = new ReflectionClass($Object);
         // @@ The class, then each ancestor
         do {
            foreach ($Class->getProperties() as $Property) {
               if ($Property->class !== $Class->name || $Property->getAttributes(Secret::class) === []) {
                  continue;
               }
               if ($Property->isStatic() === true || $Property->isVirtual() === true) {
                  $misplaced[] = "{$holder}::\${$Property->name}";
               }
               $marked[] = $Property->name;
            }
            $Class = $Class->getParentClass();
         } while ($Class !== false);

         sort($marked);
         sort($expected);
         if ($marked !== $expected) {
            $names = implode(', ', $marked);
            $unexpected[] = "{$holder}: {$names}";
         }
      }
      $foreigners = implode(', ', $foreign);
      $wrong = implode(', ', [...$misplaced, ...$unexpected]);
      yield assert(
         assertion: $foreign === [] && $misplaced === [] && $unexpected === [],
         description: "Each holder dumps through Redactable's __debugInfo and marks exactly its stored secrets "
            . "(foreign: [{$foreigners}], misplaced or unexpected: [{$wrong}])"
      );
   }
);
