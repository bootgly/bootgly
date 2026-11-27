<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\IO\Resolver\Tests\SpecialNames;


use const PHP_OS_FAMILY;
use function assert;
use function file_put_contents;
use function json_encode;
use function rmdir;
use function unlink;

use Bootgly\ABI\IO\Resolver;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Resolver: invalid names never reach a source; mDNS names never reach unicast DNS',
   test: function () {
      // ! nsswitch.conf stand-ins: an mDNS source before `dns`, after it, and none
      $directory = Temporaries::reserve('resolver-special-names');
      $mDNS = "{$directory}/mdns.conf";
      $late = "{$directory}/late.conf";
      $plain = "{$directory}/plain.conf";
      file_put_contents($mDNS, "# comment\nhosts: files mdns4_minimal [NOTFOUND=return] dns\n");
      file_put_contents($late, "hosts: files dns mdns4\n");
      file_put_contents($plain, "hosts: files dns\n");

      // ! Stand-ins that record what each source is asked
      $looked = [];
      $listed = [];
      $queried = [];
      $Lookup = static function (string $host) use (&$looked): array {
         $looked[] = $host;

         return [['ai_addr' => ['sin_addr' => '192.0.2.1']]];
      };
      $List = static function (string $host) use (&$listed): array {
         $listed[] = $host;

         return ['192.0.2.2'];
      };
      $Query = static function (string $name) use (&$queried): array {
         $queried[] = $name;

         return [['type' => 'AAAA', 'ipv6' => '2001:db8::2']];
      };

      // @ `invalid` / `*.invalid` (RFC 6761 §6.4): nothing, no source asked — both ladders
      $results = [];
      foreach ([new Resolver($Lookup), new Resolver(false, $List, $Query)] as $Resolver) {
         foreach (['invalid', 'INVALID.', 'host.invalid', 'a.b.Invalid', 'x.invalid.'] as $name) {
            $results[] = $Resolver->resolve($name);
         }
      }
      yield assert(
         assertion: $results === [[], [], [], [], [], [], [], [], [], []]
            && $looked === [] && $listed === [] && $queried === [],
         description: 'invalid names resolve to nothing without a lookup: ' . json_encode([$results, $looked, $listed, $queried])
      );

      // @ A name that only contains "invalid" is looked up
      $Resolver = new Resolver($Lookup);
      yield assert(
         assertion: $Resolver->resolve('invalid.example') === ['192.0.2.1'] && $looked === ['invalid.example'],
         description: 'a name with an "invalid" label that is not the last one is looked up'
      );

      // @ `*.local` without ext-sockets, an mDNS source before `dns`: the system's IPv4 answer,
      //   never a unicast AAAA query
      $Resolver = new Resolver(false, $List, $Query, $mDNS);
      $local = [];
      foreach (['printer.local', 'Printer.LOCAL.', 'local'] as $name) {
         $local[] = $Resolver->resolve($name);
      }
      yield assert(
         assertion: $local === [['192.0.2.2'], ['192.0.2.2'], ['192.0.2.2']]
            && $listed === ['printer.local', 'Printer.LOCAL.', 'local']
            && $queried === [],
         description: 'mDNS names get the system IPv4 answer and no unicast DNS query: ' . json_encode([$local, $queried])
      );

      // @ A name that only contains "local" still gets its AAAA query
      $queried = [];
      $Resolver->resolve('local.example');
      $Resolver->resolve('notlocal');
      yield assert(
         assertion: $queried === ['local.example.', 'notlocal.'],
         description: 'a name whose last label is not "local" is still queried for AAAA'
      );

      // @ Without an mDNS source before `dns` (or without a file), `*.local` is a plain DNS name —
      //   save a missing file on macOS, which has none and answers `*.local` over mDNS
      $queried = [];
      foreach ([$late, $plain, null] as $path) {
         new Resolver(false, $List, $Query, $path)->resolve('corp.local');
      }
      $missing = [];
      new Resolver(false, $List, static function (string $name) use (&$missing): array {
         $missing[] = $name;

         return [];
      }, "{$directory}/missing.conf")->resolve('corp.local');
      @unlink($mDNS);
      @unlink($late);
      @unlink($plain);
      @rmdir($directory);
      yield assert(
         assertion: $queried === ['corp.local.', 'corp.local.', 'corp.local.']
            && $missing === (PHP_OS_FAMILY === 'Darwin' ? [] : ['corp.local.']),
         description: 'without an mDNS source before dns, a .local name is queried for AAAA: ' . json_encode([$queried, $missing])
      );
   }
);
