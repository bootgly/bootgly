<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Configs;


use const INF;
use function assert;
use function count;
use function str_repeat;
use ArgumentCountError;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Configs as UDP_Configs;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Configs;


return new Test(
   description: 'DNS_Client_CLI Configs: validated values; configure() takes one DNS_Client_CLI Configs',
   test: function () {
      $refuse = static function (array $values): bool {
         try {
            new Configs(...$values);
         }
         catch (InvalidArgumentException) {
            return true;
         }

         return false;
      };

      // @ Values out of range
      $cases = [
         ['nameservers' => []],
         ['nameservers' => ['192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4']],
         ['nameservers' => ['ns.example']],
         ['nameservers' => ['[2001:db8::1]']],
         ['nameservers' => ['192.0.2.1%eth0']],
         ['nameservers' => ['192.0.2.1:53']],
         ['nameservers' => [53]],
         ['port' => 0],
         ['port' => 65536],
         ['search' => ['a', 'b', 'c', 'd', 'e', 'f', 'g']],
         ['search' => ['bad domain']],
         ['search' => ['a..b']],
         ['search' => ['.lead']],
         ['search' => [str_repeat('x', 64) . '.example']],
         // ! 254 characters without a trailing dot: over a name's 253
         ['search' => [str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 62)]],
         ['ndots' => 16],
         ['timeout' => 0],
         ['timeout' => 31],
         ['timeout' => INF],
         ['attempts' => 0],
         ['attempts' => 6],
         ['environment' => ['LOCALDOMAIN' => 1]],
      ];
      $refused = 0;
      foreach ($cases as $values) {
         $refused += $refuse($values) ? 1 : 0;
      }
      yield assert(
         assertion: $refused === count($cases),
         description: "every out-of-range value is refused ({$refused}/" . count($cases) . ')'
      );

      // @ Accepted values
      $Configs = new Configs(nameservers: ['192.0.2.1', '2001:db8::1', 'fe80::1%eth0'], timeout: 2, search: ['a.example']);
      yield assert(
         assertion: $Configs->nameservers === ['192.0.2.1', '2001:db8::1', 'fe80::1%eth0']
            && $Configs->timeout === 2.0 && $Configs->search === ['a.example'] && $Configs->port === 53,
         description: 'literals (a link-local IPv6 one with its zone) and an integer timeout as seconds'
      );

      // @ Search domains at the bounds: 253 characters (and its trailing dot), the root alone
      $longest = str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61);
      $Bounds = new Configs(search: [$longest, "{$longest}."]);
      $Root = new Configs(search: ['.']);
      yield assert(
         assertion: $Bounds->search === [$longest, "{$longest}."] && $Root->search === [],
         description: 'a 253-character search domain is accepted; the root alone searches nothing'
      );

      // @ configure(): this node's Configs only, never empty
      $DNS = new DNS_Client_CLI();
      $applied = $DNS->configure($Configs)->Configs === $Configs;
      $foreign = false;
      try {
         $DNS->configure(new UDP_Configs(host: '127.0.0.1', port: 53));
      }
      catch (InvalidArgumentException) {
         $foreign = true;
      }
      $empty = false;
      try {
         $DNS->configure();
      }
      catch (ArgumentCountError) {
         $empty = true;
      }
      yield assert(
         assertion: $applied && $foreign && $empty && $DNS->Configs === $Configs,
         description: 'configure() applies its Configs and refuses a foreign one or none'
      );

      // @ Refused names: no lookup, no exception
      $Bare = new DNS_Client_CLI()->configure(new Configs(nameservers: ['192.0.2.1'], resolv: null, hosts: null, nsswitch: null, environment: []));
      $names = ['', "a\0b", 'a%b', '[a]', 'a..b', '.a', 'a..', str_repeat('x', 64) . '.example', str_repeat('a.', 127) . 'ab'];
      $results = [];
      foreach ($names as $name) {
         $results[] = $Bare->resolve($name);
      }
      $Answer = $Bare->query('a..b', 1);
      yield assert(
         assertion: $results === [[], [], [], [], [], [], [], [], []] && $Answer->Outcome === DNS_Client_CLI\Answer\Outcomes::Failed,
         description: 'refused names resolve to nothing without a lookup'
      );

      // @ Literals: canonical, without a lookup; a zone id or a v4 in brackets refused
      yield assert(
         assertion: $Bare->resolve('192.0.2.7') === ['192.0.2.7'] && $Bare->resolve('2001:DB8::7') === ['2001:db8::7']
            && $Bare->resolve('[2001:db8::7]') === ['2001:db8::7'] && $Bare->resolve('[192.0.2.7]') === []
            && $Bare->resolve('[fe80::1%eth0]') === [] && $Bare->resolve('::ffff:192.0.2.7') === ['192.0.2.7'],
         description: 'IP literals come back canonical (bracketed IPv6 too)'
      );
   }
);
