<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Settings;


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
use Bootgly\WPI\Nodes\DNS_Client_CLI\Configs;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Settings;


return new Test(
   description: 'DNS_Client_CLI Settings: resolv.conf, nsswitch.conf and the environment, overridden by Configs',
   test: function () {
      $directory = Temporaries::reserve('dns-settings');
      $resolv = "{$directory}/resolv.conf";
      $nsswitch = "{$directory}/nsswitch.conf";
      $load = static function (array $values) use ($resolv, $nsswitch): Settings {
         $Settings = new Settings(new Configs(...($values + [
            'resolv' => $resolv, 'hosts' => null, 'nsswitch' => $nsswitch, 'environment' => [], 'hostname' => 'box.corp.example',
         ])));
         $Settings->reload(1000.0);

         return $Settings;
      };

      // @ The options glibc honours; the rest ignored
      file_put_contents($resolv, <<<'CONF'
         # comment
         nameserver 192.0.2.53 ; trailing comment
         nameserver resolver.example
         nameserver 2001:db8::53
         nameserver fe80::1%eth0
         nameserver 198.51.100.53
         domain first.example
         search one.example two.example.
         options ndots:3 timeout:99 attempts:0 rotate edns0 use-vc no-aaaa single-request
         sortlist 192.0.2.0/255.255.255.0
         CONF);
      file_put_contents($nsswitch, "passwd: files\nhosts: files myhostname mdns4_minimal [NOTFOUND=return] resolve [!UNAVAIL=return] dns\n");
      $Settings = $load([]);
      yield assert(
         assertion: $Settings->nameservers === ['192.0.2.53', '2001:db8::53', 'fe80::1%eth0']
            && $Settings->search === ['one.example', 'two.example']
            && $Settings->ndots === 3 && $Settings->timeout === 30.0 && $Settings->attempts === 1
            && $Settings->rotate === true && $Settings->EDNS === true,
         description: 'nameserver literals (3 at most, names skipped), the last search line, options clamped: '
            . json_encode([$Settings->nameservers, $Settings->search, $Settings->ndots, $Settings->timeout, $Settings->attempts])
      );
      yield assert(
         assertion: $Settings->sources === ['files', 'myhostname', 'mdns4_minimal', 'resolve', 'dns']
            && $Settings->files && $Settings->mDNS && $Settings->myhostname && $Settings->hostname === 'box.corp.example',
         description: 'nsswitch hosts: sources in order (actions dropped); mDNS and myhostname before dns'
      );

      // @ Defaults: no nameserver → 127.0.0.1; no search → the host name's domain; nsswitch missing → files dns
      file_put_contents($resolv, "options timeout:2\n");
      $Settings = $load(['nsswitch' => "{$directory}/missing"]);
      yield assert(
         assertion: $Settings->nameservers === ['127.0.0.1'] && $Settings->search === ['corp.example']
            && $Settings->ndots === 1 && $Settings->timeout === 2.0 && $Settings->attempts === 2
            && $Settings->rotate === false && $Settings->EDNS === false
            && $Settings->sources === ['files', 'dns'] && $Settings->files && $Settings->mDNS === false && $Settings->myhostname === false,
         description: 'glibc defaults without the lines (and without nsswitch.conf)'
      );

      // @ mDNS after dns does not count
      file_put_contents($nsswitch, "hosts: files dns mdns4\n");
      $Settings = $load([]);
      yield assert(
         assertion: $Settings->mDNS === false && $Settings->files,
         description: 'an mDNS source after dns is not consulted first'
      );
      file_put_contents($nsswitch, "hosts: dns\n");
      $Settings = $load([]);
      yield assert(
         assertion: $Settings->files === false,
         description: 'without files in hosts:, the hosts file is not a source'
      );

      // @ The environment: LOCALDOMAIN replaces the search list, RES_OPTIONS adds options
      file_put_contents($resolv, "search a.example\noptions ndots:2\n");
      $Settings = $load(['environment' => ['LOCALDOMAIN' => 'env1.example env2.example', 'RES_OPTIONS' => 'ndots:4 rotate']]);
      yield assert(
         assertion: $Settings->search === ['env1.example', 'env2.example'] && $Settings->ndots === 4 && $Settings->rotate,
         description: 'LOCALDOMAIN and RES_OPTIONS override resolv.conf'
      );

      // @ Configs win over every file
      $Settings = $load(['nameservers' => ['198.51.100.1'], 'search' => ['cfg.example'], 'ndots' => 0, 'timeout' => 0.5, 'attempts' => 3, 'rotate' => false, 'EDNS' => true]);
      yield assert(
         assertion: $Settings->nameservers === ['198.51.100.1'] && $Settings->search === ['cfg.example']
            && $Settings->ndots === 0 && $Settings->timeout === 0.5 && $Settings->attempts === 3
            && $Settings->rotate === false && $Settings->EDNS === true,
         description: 'Configs values win over resolv.conf and the environment'
      );

      // @ Reload: re-read when the file changes, at most once per second
      touch($resolv, 100);
      touch($nsswitch, 100);
      $Settings = $load([]);
      file_put_contents($resolv, "search b.example b2.example b3.example\n");
      touch($resolv, 200);
      $Settings->reload(1000.5);
      $early = $Settings->search;
      $Settings->reload(1001.5);
      yield assert(
         assertion: $early === ['a.example'] && $Settings->search === ['b.example', 'b2.example', 'b3.example'],
         description: 'a changed file is re-read after the one-second check interval: ' . json_encode([$early, $Settings->search])
      );

      // @ A same-second, same-size edit is re-read (racy file)
      // ! File times are wall-clock seconds
      $wall = time();
      file_put_contents($resolv, "options ndots:2\n");
      touch($resolv, $wall);
      touch($nsswitch, $wall);
      $Racy = new Settings(new Configs(
         resolv: $resolv, hosts: null, nsswitch: $nsswitch, environment: [], hostname: 'box.corp.example'
      ));
      $Racy->reload(3000.2);
      $before = $Racy->ndots;
      file_put_contents($resolv, "options ndots:3\n");
      touch($resolv, $wall);
      $Racy->reload(3000.4);
      yield assert(
         assertion: $before === 2 && $Racy->ndots === 3,
         description: 'a file modified within the second it was read is re-read on the next check: ' . json_encode([$before, $Racy->ndots])
      );

      // @ A future mtime is not racy: the one-second check interval still holds
      file_put_contents($resolv, "options ndots:2\n");
      touch($resolv, $wall + 9000);
      touch($nsswitch, $wall + 9000);
      $Future = new Settings(new Configs(
         resolv: $resolv, hosts: null, nsswitch: $nsswitch, environment: [], hostname: 'box.corp.example'
      ));
      $Future->reload(5000.2);
      $before = $Future->ndots;
      file_put_contents($resolv, "options ndots:3\n");
      touch($resolv, $wall + 9000);
      $Future->reload(5000.4);
      yield assert(
         assertion: $before === 2 && $Future->ndots === 2,
         description: 'a file stamped in the future is not re-read on every lookup: ' . json_encode([$before, $Future->ndots])
      );

      // @ resolv.conf search domains that are not domain names are dropped
      file_put_contents($resolv, 'search good.example a..b ' . str_repeat('x', 64) . ".example .lead ok.example.\n");
      $Domains = new Settings(new Configs(
         resolv: $resolv, hosts: null, nsswitch: $nsswitch, environment: [], hostname: 'box.corp.example'
      ));
      $Domains->reload(7000.0);
      yield assert(
         assertion: $Domains->search === ['good.example', 'ok.example'],
         description: 'empty labels and labels over 63 characters drop the domain: ' . json_encode($Domains->search)
      );

      @unlink($resolv);
      @unlink($nsswitch);
      @rmdir($directory);
   }
);
