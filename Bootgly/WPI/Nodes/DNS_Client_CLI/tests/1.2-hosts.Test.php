<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Hosts;


use function assert;
use function file_put_contents;
use function json_encode;
use function rmdir;
use function str_repeat;
use function time;
use function touch;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Hosts;


return new Test(
   description: 'DNS_Client_CLI Hosts: the hosts file, case-insensitive, reloaded when it changes',
   test: function () {
      // @ Parsing
      $Table = Hosts::parse(<<<'HOSTS'
         # comment line
         127.0.0.1   localhost Local.Example   # trailing comment
         192.0.2.10  app.example. app
         2001:DB8::10 app.example
         ::ffff:192.0.2.11 mapped.example
         fe80::1%eth0 zoned.example
         not-an-ip   bad.example
         192.0.2.10  app.example
         192.0.2.12
         HOSTS);
      yield assert(
         assertion: $Table === [
            'localhost' => ['127.0.0.1'],
            'local.example' => ['127.0.0.1'],
            'app.example' => ['192.0.2.10', '2001:db8::10'],
            'app' => ['192.0.2.10'],
            'mapped.example' => ['192.0.2.11'],
         ],
         description: 'addresses canonical, names lowercased without the dot, aliases, zone ids and junk skipped: ' . json_encode($Table)
      );

      // @ Lookups and reload
      $directory = Temporaries::reserve('dns-hosts');
      $path = "{$directory}/hosts";
      file_put_contents($path, "192.0.2.1 one.example\n");
      touch($path, 100);
      $Hosts = new Hosts($path);
      $first = $Hosts->find('ONE.example.', 1000.0);
      file_put_contents($path, "192.0.2.2 one.example two.example\n");
      touch($path, 200);
      $throttled = $Hosts->find('one.example', 1000.5);
      $reloaded = $Hosts->find('one.example', 1001.5);
      yield assert(
         assertion: $first === ['192.0.2.1'] && $throttled === ['192.0.2.1'] && $reloaded === ['192.0.2.2']
            && $Hosts->find('two.example', 1001.6) === ['192.0.2.2'] && $Hosts->find('three.example', 1001.6) === [],
         description: 'case-insensitive lookups; a changed file is re-read after the one-second interval: ' . json_encode([$first, $throttled, $reloaded])
      );

      // @ A same-size edit within the second it was read is still noticed
      // ! File times are wall-clock seconds
      $wall = time();
      file_put_contents($path, "192.0.2.3 one.example\n");
      touch($path, $wall);
      $Hosts = new Hosts($path);
      $before = $Hosts->find('one.example', 3000.2);
      file_put_contents($path, "192.0.2.4 one.example\n");
      touch($path, $wall);
      $after = $Hosts->find('one.example', 3000.4);
      yield assert(
         assertion: $before === ['192.0.2.3'] && $after === ['192.0.2.4'],
         description: 'a same-second, same-size edit is re-read (racy file): ' . json_encode([$before, $after])
      );

      // @ A future mtime is not racy: the one-second check interval still holds
      file_put_contents($path, "192.0.2.5 one.example\n");
      touch($path, $wall + 9000);
      $Future = new Hosts($path);
      $before = $Future->find('one.example', 5000.2);
      file_put_contents($path, "192.0.2.6 one.example\n");
      touch($path, $wall + 9000);
      $after = $Future->find('one.example', 5000.4);
      yield assert(
         assertion: $before === ['192.0.2.5'] && $after === ['192.0.2.5'],
         description: 'a file stamped in the future is not re-read on every lookup: ' . json_encode([$before, $after])
      );

      // @ Too large, missing: an empty table
      file_put_contents($path, '192.0.2.5 big.example' . str_repeat(' alias', 200_000) . "\n");
      $Large = new Hosts($path);
      $Missing = new Hosts("{$directory}/missing");
      $None = new Hosts(null);
      yield assert(
         assertion: $Large->find('big.example', 5000.0) === [] && $Missing->find('x', 5000.0) === [] && $None->find('x', 5000.0) === [],
         description: 'a file over 1 MiB, a missing file and no file are empty tables'
      );

      @unlink($path);
      @rmdir($directory);
   }
);
