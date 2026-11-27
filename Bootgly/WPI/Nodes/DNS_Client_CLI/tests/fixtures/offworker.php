<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/*
 * A lookup off any worker, in a process no test loop ever ran in (a server's reactor, once
 * installed, cannot be removed): resolve() asks a recording system resolver three times — twice
 * while it does not resolve the name, once after it does — and prints what it saw as JSON.
 */


use Bootgly\ABI\IO\Resolver;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\Configs;


// ! The framework's classes, from this tree
spl_autoload_register(static function (string $class): void {
   $file = dirname(__DIR__, 6) . '/' . str_replace('\\', '/', $class) . '.php';
   if (is_file($file)) {
      require $file;
   }
});

// ! A system resolver that records its calls and answers what `$ladder` holds
$calls = [];
$ladder = [];
$Answer = static function (string $host) use (&$calls, &$ladder): array|false {
   $calls[] = $host;
   $addresses = $ladder[$host] ?? [];

   return $addresses === [] ? false : $addresses;
};
$Ladder = new Resolver(
   Lookup: static function (string $host) use ($Answer): array|false {
      $addresses = $Answer($host);
      if ($addresses === false) {
         return false;
      }

      $infos = [];
      foreach ($addresses as $address) {
         $infos[] = ['ai_addr' => ['sin_addr' => $address]];
      }

      return $infos;
   },
   List: static fn (string $host): array|false => $Answer($host),
   Query: static fn (string $host): false => false
);

// ! No system file, no environment, a nameserver that is never asked (off a worker, nothing parks)
$DNS = new DNS_Client_CLI()->configure(new Configs(
   nameservers: ['127.0.0.1'],
   port: 9,
   search: [],
   timeout: 1,
   attempts: 1,
   resolv: null,
   hosts: null,
   nsswitch: null,
   environment: [],
   hostname: 'offworker.example',
   Probe: static fn (int $family): null|string => $family === 4 ? '192.0.2.50' : null,
   Resolver: $Ladder
));

// @
$seen = [$DNS->Reactor === null, $DNS->resolve('offworker.test'), $DNS->resolve('offworker.test')];
$ladder['offworker.test'] = ['192.0.2.45'];
$seen[] = $DNS->resolve('offworker.test');
$seen[] = $calls;

// :
echo json_encode($seen);
