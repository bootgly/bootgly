<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI;


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
use Bootgly\WPI\Nodes\WS_Client_CLI;


/**
 * An armed WebSocket client holds the upgrade credentials three times: the
 * TLS context of its Configs (the key's `passphrase`), the extra headers given
 * to `connect()`/`open()` (`Authorization`, `Cookie`) and the encoded upgrade
 * request re-sent on every re-dial, which carries them. A debug dump of the
 * client or of its Configs shows none of them, while the certificate path and
 * every other header show as they are.
 */
return new Test(
   description: 'WS_Client_CLI: debug dumps mask the TLS passphrase, the credential headers and the encoded upgrade request',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-WS-CLIENT-PASSPHRASE-7.1';
      $bearer = 'SYNTH-WS-CLIENT-BEARER-7.1';
      $session = 'SYNTH-WS-CLIENT-SESSION-7.1';
      $certificate = '/nonexistent/visible-certificate-7.1.pem';
      $origin = 'https://visible-origin-7.1.invalid';
      $secure = [
         'local_cert' => $certificate,
         'passphrase' => $passphrase
      ];
      $headers = [
         'Authorization' => "Bearer {$bearer}",
         'Cookie' => "sid={$session}",
         'Origin' => $origin
      ];
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

      // ! The holders: the Configs, and a client armed as dial() arms it —
      //   its headers kept and its upgrade request encoded — without dialing
      $Configs = new Configs(host: '127.0.0.1', port: 1, secure: $secure);
      $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
      $Client->configure($Configs);
      Closure::bind(static function (WS_Client_CLI $Client) use ($headers): void {
         $Client->headers = $headers;
         $Client->request = $Client->build('/chat', $headers, []);
      }, null, WS_Client_CLI::class)($Client);
      $Holders = [
         'WS_Client_CLI\Configs' => [$Configs, [$passphrase], [$certificate, Secret::MASK]],
         'WS_Client_CLI' => [$Client, [$passphrase, $bearer, $session], [$certificate, $origin, Secret::MASK]]
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => [$Holder, $secrets, $visible]) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            foreach ($secrets as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$secret} in {$holder} via {$channel}";
               }
            }
            foreach ($visible as $shown) {
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
         description: "No passphrase, credential header nor upgrade request reaches var_dump, print_r, debug_zval_dump or Dumper, while the certificate path and the origin show (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      $armed = Closure::bind(static fn (WS_Client_CLI $Client): array => [
         $Client->headers,
         $Client->request
      ], null, WS_Client_CLI::class)($Client);
      yield assert(
         assertion: ($Configs->secure['passphrase'] ?? null) === $passphrase
            && ($Client->secure['passphrase'] ?? null) === $passphrase
            && $armed[0] === $headers
            && str_contains($armed[1], "Authorization: Bearer {$bearer}\r\n") === true,
         description: 'The live passphrase, headers and upgrade request are untouched by the dumps'
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored properties only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $expected = [
         'WS_Client_CLI\Configs' => ['secure'],
         'WS_Client_CLI' => ['headers', 'request', 'secure']
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
         sort($marked);
         if ($marked !== $expected[$holder]) {
            $list = implode(', ', $marked);
            $deviations[] = "{$holder} marks [{$list}]";
         }
      }
      $deviated = implode('; ', $deviations);
      yield assert(
         assertion: $deviations === [],
         description: "Each holder dumps through the Redactable trait, #[Secret] on the stored context, headers and request only (deviations: [{$deviated}])"
      );
   }
);
