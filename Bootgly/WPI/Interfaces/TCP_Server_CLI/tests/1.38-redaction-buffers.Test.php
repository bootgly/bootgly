<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function sort;
use function str_contains;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers\Packages as Server_Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


/**
 * A server connection holds the wire bytes of its requests and responses: the
 * read input, the output being written, the last request head it keyed, the
 * output a full socket deferred, the inbound carry a fragment left, and the
 * handler slot that aliases the input. They
 * carry the credential headers (Authorization, Cookie, Set-Cookie), so a debug
 * dump of the connection shows none of them, while its other fields still show
 * — and the dump never changes a live buffer.
 */
return new Test(
   description: 'TCP_Server_CLI: debug dumps mask the wire buffers of a connection',
   test: function () {
      // ! SYNTH credentials — never real ones
      $token = 'SYNTH-TCP-SERVER-BEARER-1.38';
      $cookie = 'SYNTH-TCP-SERVER-COOKIE-1.38';
      $carry = 'SYNTH-TCP-SERVER-CARRY-1.38';
      $pending = 'SYNTH-TCP-SERVER-PENDING-1.38';
      $IP = 'visible-ip-1.38';
      $request = "GET / HTTP/1.1\r\nAuthorization: Bearer {$token}\r\n\r\n";
      $response = "HTTP/1.1 200 OK\r\nSet-Cookie: sid={$cookie}\r\n\r\n";
      $Dumper = new Dumper('plain');
      // ! No truncation may hide a leak
      $Dumper->strings = 1_000_000;
      $Dumper->items = 10_000;
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

      // ! A connection mid-exchange, built without its constructor (it needs a live socket) but
      //   through the base one every connection runs: it aliases `callbacks[0]` to the input
      $Connection = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
      new ReflectionMethod(Server_Packages::class, '__construct')->invoke($Connection);
      $Connection->ip = $IP;
      $Connection->input = $request;
      $Connection->known = $request;
      $Connection->output = $response;
      $Connection->pendingBuffer = "Set-Cookie: remember={$pending}\r\n";
      Closure::bind(static function (Connection $Connection) use ($carry): void {
         $Connection->carry = "Authorization: Basic {$carry}";
      }, null, Connection::class)($Connection);

      // ? The handler slot aliases the input — the dumps must mask both
      $aliased = $Connection->callbacks[0] === $request;

      // @@ Every channel
      $leaks = [];
      $missing = [];
      foreach ($Channels as $channel => $Channel) {
         $output = $Channel($Connection);

         foreach ([$token, $cookie, $carry, $pending] as $secret) {
            if (str_contains($output, $secret) === true) {
               $leaks[] = "{$secret} via {$channel}";
            }
         }
         foreach ([$IP, Secret::MASK] as $shown) {
            if (str_contains($output, $shown) === false) {
               $missing[] = "{$shown} via {$channel}";
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $aliased === true && $leaks === [] && $missing === [],
         description: "No wire buffer — nor the handler slot aliasing the input — reaches var_dump, print_r, debug_zval_dump or Dumper, while the peer address shows (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      yield assert(
         assertion: $Connection->input === $request
            && $Connection->known === $request
            && $Connection->output === $response
            && $Connection->pendingBuffer === "Set-Cookie: remember={$pending}\r\n"
            && $Connection->carry === "Authorization: Basic {$carry}"
            && $Connection->callbacks[0] === $request,
         description: 'The live buffers are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored buffers only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $Own = new ReflectionMethod($Connection, '__debugInfo');
      $marked = [];
      $wrong = [];
      foreach (new ReflectionClass($Connection)->getProperties() as $Property) {
         if ($Property->getAttributes(Secret::class) === []) {
            continue;
         }

         $marked[] = $Property->name;
         if ($Property->isStatic() === true || $Property->isVirtual() === true) {
            $wrong[] = "\${$Property->name} is static or virtual";
         }
      }
      sort($marked);
      if ($marked !== ['callbacks', 'carry', 'input', 'known', 'output', 'pendingBuffer']) {
         $listed = implode(', ', $marked);
         $wrong[] = "marks [{$listed}]";
      }
      if ($Own->getFileName() !== $Trait->getFileName() || $Own->getStartLine() !== $Trait->getStartLine()) {
         $wrong[] = 'does not dump through Redactable';
      }
      $listed = implode('; ', $wrong);
      yield assert(
         assertion: $wrong === [],
         description: "A connection dumps through the Redactable trait, #[Secret] on its wire buffers only ({$listed})"
      );
   }
);
