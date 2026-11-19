<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI;


use function assert;
use function debug_zval_dump;
use function implode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function str_contains;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake\Request;


/**
 * The request adapter the handshake guards and the upgrade predicate receive
 * holds the upgrade headers twice — in its own `headers` and in its `Header`
 * bag — and the token a guard resolved. A debug dump of either shows no
 * credential header (`Authorization`, `Cookie`) nor the token, while every
 * other header shows as it is.
 */
return new Test(
   description: 'WS_Server_CLI handshake: debug dumps mask the credential headers and the resolved token',
   test: function () {
      // ! SYNTH credentials — never real ones
      $bearer = 'SYNTH-WS-SERVER-BEARER-7.1';
      $session = 'SYNTH-WS-SERVER-SESSION-7.1';
      $resolved = 'SYNTH-WS-SERVER-TOKEN-7.1';
      $host = 'ws.visible-host-7.1.invalid';
      $origin = 'https://visible-origin-7.1.invalid';
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

      // ! The adapter, as the guards receive it: the lowercased header map of
      //   the upgrade request, and the token a guard resolved
      $Request = new Request([
         'host' => $host,
         'origin' => $origin,
         'authorization' => "Bearer {$bearer}",
         'cookie' => ["sid={$session}"],
         'sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ=='
      ]);
      $Request->token = $resolved;
      $Holders = [
         'Handshake\Request' => [$Request, [$bearer, $session, $resolved]],
         'Handshake\Header' => [$Request->Header, [$bearer, $session]]
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Holder, $secrets]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$secret} in {$holder} via {$channel}";
               }
            }
            foreach ([$host, $origin, Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$shown} in {$holder} via {$channel}";
               }
            }
         }
      }
      $leaked = implode(', ', $leaks);
      $absent = implode(', ', $missing);
      yield assert(
         assertion: $leaks === [] && $missing === [],
         description: "No credential header nor token reaches var_dump, print_r, debug_zval_dump or Dumper, while the host and the origin show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      // ! The token as it is stored now
      $token = new ReflectionProperty($Request, 'token')->getValue($Request);
      yield assert(
         assertion: $Request->headers['authorization'] === "Bearer {$bearer}"
            && $Request->Header->get('Authorization') === "Bearer {$bearer}"
            && $Request->Header->get('Cookie') === "sid={$session}"
            && $token === $resolved,
         description: 'The live headers and token are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $expected = [
         'Handshake\Request' => ['headers', 'token'],
         'Handshake\Header' => ['fields']
      ];
      $deviations = [];
      foreach ($Holders as $holder => [$Holder]) {
         $Own = new ReflectionMethod($Holder, '__debugInfo');
         if (
            $Own->getFileName() !== $Trait->getFileName()
            || $Own->getStartLine() !== $Trait->getStartLine()
         ) {
            $deviations[] = "{$holder} dumps through its own __debugInfo";
         }

         $marked = [];
         foreach (new ReflectionClass($Holder)->getProperties() as $Property) {
            if ($Property->getAttributes(Secret::class) === []) {
               continue;
            }

            $marked[] = $Property->name;
            if ($Property->isStatic() === true || $Property->isVirtual() === true) {
               $deviations[] = "{$holder}::\${$Property->name} is static or virtual";
            }
         }
         if ($marked !== $expected[$holder]) {
            $list = implode(', ', $marked);
            $deviations[] = "{$holder} marks [{$list}]";
         }
      }
      $deviated = implode('; ', $deviations);
      yield assert(
         assertion: $deviations === [],
         description: "Each holder dumps through the Redactable trait, #[Secret] on the stored headers and token only (deviations: [{$deviated}])"
      );
   }
);
