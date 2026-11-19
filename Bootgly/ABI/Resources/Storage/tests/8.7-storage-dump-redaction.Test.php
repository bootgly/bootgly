<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use function array_keys;
use function assert;
use function bin2hex;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function str_contains;
use function strlen;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ABI\Resources\Storage;
use Bootgly\ABI\Resources\Storage\Drivers\S3;
use Bootgly\ABI\Resources\Storage\Drivers\S3\Signer;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * The S3 secret and session token live in the disk definitions (`Storage\Config::$disks`),
 * in each driver's options (`Driver::$options`) and in the signer, with the signing key
 * derived from them. Every debug channel shows them masked — the `secret` and `token`
 * options in any letter case, at any depth — while the live values are untouched. The S3
 * disk and its Uploads handle keep their own dump of where the disk points.
 */
return new Test(
   description: 'Storage: a debug dump of the configuration, the facade, a driver and the S3 signer masks the credentials',
   test: function () {
      $secret = 'SYNTH-S3-SECRET-0123456789';
      $token = 'SYNTH-S3-TOKEN-0123456789';
      $Dumper = new Dumper('plain');
      // ! Each debug channel
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

      // ! An S3 disk and a scratch disk — the options in another letter case, one nested
      $disks = [
         'remote' => [
            'driver' => 's3', 'bucket' => 'examplebucket', 'key' => 'AKIAEXAMPLE', 'secret' => $secret, 'token' => $token
         ],
         'scratch' => ['driver' => 'memory', 'root' => 'scratch-root', 'Secret' => $secret, 'nested' => ['TOKEN' => $token]],
      ];
      $Config = new Config(['default' => 'remote', 'disks' => $disks]);
      $Storage = new Storage(['default' => 'remote', 'disks' => $disks]);
      $S3 = $Storage->open('remote');
      $Memory = $Storage->open('scratch');
      // ! A signer that derived its signing key
      $Signer = new Signer('AKIAEXAMPLE', $secret, 'eu-west-1', 's3', $token);
      $Signer->presign('GET', 'examplebucket.s3.amazonaws.com', '/k', [], 60);
      $Derived = Closure::bind(static fn (Signer $Signer): string => $Signer->derived, null, Signer::class);
      $derived = $Derived($Signer);

      // ! holder => [object, the values it must show]
      $Holders = [
         'Storage\Config' => [$Config, ['examplebucket', Secret::MASK]],
         'Storage' => [$Storage, ['examplebucket', 'scratch-root', Secret::MASK]],
         'Storage\Drivers\Memory' => [$Memory, ['scratch-root', Secret::MASK]],
         'S3\Signer' => [$Signer, ['AKIAEXAMPLE', 'eu-west-1', Secret::MASK]],
         'S3' => [$S3, ['examplebucket']],
         'S3\Uploads' => [$S3 instanceof S3 ? $S3->Uploads : $S3, []],
      ];
      $needles = [$secret, $token, $derived, bin2hex($derived)];

      // @ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Holder, $shown]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);
            foreach ($needles as $needle) {
               if (str_contains($output, $needle) === true) {
                  $leaks[] = "{$holder} {$channel}";
               }
            }
            foreach ($shown as $value) {
               if (str_contains($output, $value) === false) {
                  $missing[] = "{$holder} {$channel}: {$value}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: strlen($derived) === 32 && $leaks === [] && $missing === [],
         description: 'No debug channel shows the secret, the session token or the signing key — keyed in any letter case, '
            . "at any depth — while where the disk points shows (leaked: [{$leaked}], missing: [{$absent}])"
      );
      yield assert(
         assertion: str_contains($Channels['Dumper']($Signer), "-derived: '[redacted]'") === true
            && str_contains($Channels['print_r']($Memory), "[TOKEN] => [redacted]") === true,
         description: 'The signing key and a nested token show as masked rows, not as missing ones'
      );

      // @ The live values are untouched
      $Options = Closure::bind(static fn (Driver $Driver): array => $Driver->options, null, Driver::class);
      $Credentials = Closure::bind(
         static fn (Signer $Signer): array => [$Signer->secret, $Signer->token],
         null,
         Signer::class
      );
      yield assert(
         assertion: $Config->disks['remote']['secret'] === $secret
            && $Config->disks['scratch']['nested'] === ['TOKEN' => $token]
            && $Options($Memory) === ['Secret' => $secret, 'nested' => ['TOKEN' => $token]]
            && $Credentials($Signer) === [$secret, $token]
            && $Derived($Signer) === $derived,
         description: 'Dumping never writes: the definitions, the driver options and the signer keep their credentials'
      );

      // @ The trait is the dump of each holder
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $dumped = [];
      foreach (['Storage\Config' => $Config, 'Storage\Drivers\Memory' => $Memory, 'S3\Signer' => $Signer] as $holder => $Holder) {
         $Method = new ReflectionMethod($Holder, '__debugInfo');
         if ($Method->getFileName() !== $Trait->getFileName() || $Method->getStartLine() !== $Trait->getStartLine()) {
            $dumped[] = $holder;
         }
      }
      $own = implode(', ', $dumped);
      yield assert(
         assertion: $dumped === [],
         description: "Storage\Config, the drivers and the S3 signer dump through the Redactable trait (own dump: [{$own}])"
      );

      // @ The marked properties store their value: none is static or virtual
      $marked = [];
      $misplaced = [];
      foreach ([Config::class, Driver::class, Signer::class] as $class) {
         foreach ((new ReflectionClass($class))->getProperties() as $Property) {
            if ($Property->class !== $class || $Property->getAttributes(Secret::class) === []) {
               continue;
            }
            $marked["{$class}::{$Property->name}"] = true;
            if ($Property->isStatic() === true || $Property->isVirtual() === true) {
               $misplaced[] = $Property->name;
            }
         }
      }
      $names = implode(', ', array_keys($marked));
      yield assert(
         assertion: array_keys($marked) === [
            Config::class . '::disks',
            Driver::class . '::options',
            Signer::class . '::secret',
            Signer::class . '::token',
            Signer::class . '::derived',
         ] && $misplaced === [],
         description: "Only stored properties are marked: the disk definitions, the driver options, the signer's credentials and signing key (marked: [{$names}])"
      );
   }
);
