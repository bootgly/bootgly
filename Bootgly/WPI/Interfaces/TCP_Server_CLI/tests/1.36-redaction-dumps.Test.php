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
use function str_contains;
use function var_dump;
use Closure;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI;


/**
 * The TLS context of a server is stored twice: by the transport Configs (and
 * the HTTP and WebSocket node Configs that inherit its `secure`) and by the
 * server that adopts it. Neither may show the key's `passphrase` in a debug
 * dump, while the rest of the context — the certificate path — shows as it is.
 */
return new Test(
   description: 'TCP_Server_CLI: debug dumps mask the TLS passphrase of the Configs and of the server',
   test: function () {
      // ! SYNTH credentials — never real ones
      $passphrase = 'SYNTH-TCP-SERVER-PASSPHRASE-1.36';
      $certificate = '/nonexistent/visible-certificate-1.36.pem';
      $secure = [
         'local_cert' => $certificate,
         'passphrase' => $passphrase
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

      // ! The server, as configure() leaves it: built without its constructor,
      //   which boots the process, its signals and the log sinks
      $Server = new ReflectionClass(TCP_Server_CLI::class)->newInstanceWithoutConstructor();
      Closure::bind(static function (TCP_Server_CLI $Server) use ($secure): void {
         $Server->secure = $secure;
      }, null, TCP_Server_CLI::class)($Server);
      // ! The holders
      $Holders = [
         'TCP_Server_CLI\Configs' => new Configs(host: '127.0.0.1', port: 0, workers: 1, secure: $secure),
         'HTTP_Server_CLI\Configs' => new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, secure: $secure),
         'WS_Server_CLI\Configs' => new WS_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, secure: $secure),
         'TCP_Server_CLI' => $Server
      ];

      // @@ Every holder through every channel
      $leaks = [];
      $missing = [];
      foreach ($Holders as $holder => $Holder) {
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Holder);

            if (str_contains($output, $passphrase) === true) {
               $leaks[] = "passphrase in {$holder} via {$channel}";
            }
            foreach ([$certificate, Secret::MASK] as $shown) {
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
         description: "No passphrase reaches var_dump, print_r, debug_zval_dump or Dumper, while the certificate path shows (leaked: [{$leaked}], missing: [{$absent}])"
      );

      // @ Dumping never writes
      $live = [];
      foreach ($Holders as $holder => $Holder) {
         if (($Holder->secure['passphrase'] ?? null) !== $passphrase) {
            $live[] = $holder;
         }
      }
      $changed = implode(', ', $live);
      yield assert(
         assertion: $live === [],
         description: "The live passphrase is untouched by the dumps (changed: [{$changed}])"
      );

      // @ The one way: the trait's __debugInfo, #[Secret] on the stored `secure` only
      $Trait = new ReflectionMethod(Redactable::class, '__debugInfo');
      $deviations = [];
      foreach ($Holders as $holder => $Holder) {
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
         if ($marked !== ['secure']) {
            $list = implode(', ', $marked);
            $deviations[] = "{$holder} marks [{$list}]";
         }
      }
      $deviated = implode('; ', $deviations);
      yield assert(
         assertion: $deviations === [],
         description: "Each holder dumps through the Redactable trait, #[Secret] on the stored `secure` only (deviations: [{$deviated}])"
      );
   }
);
