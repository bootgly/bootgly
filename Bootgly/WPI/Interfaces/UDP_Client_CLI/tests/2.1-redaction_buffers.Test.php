<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const STREAM_SERVER_BIND;
use function assert;
use function debug_zval_dump;
use function fclose;
use function implode;
use function is_resource;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function sort;
use function str_contains;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function var_dump;
use ReflectionClass;
use ReflectionMethod;

use Bootgly\ABI\Debugging\Data\Vars\Dumper;
use Bootgly\ABI\Debugging\Data\Vars\Redactable;
use Bootgly\ABI\Debugging\Data\Vars\Redactable\Secret;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


/**
 * A client connection holds the datagrams of its exchange: the output it
 * sends (Authorization, Cookie) and the input it receives (Set-Cookie). A
 * debug dump of the connection — a real one, built over a loopback peer, that
 * references itself — shows neither, while its peer address still shows, and
 * the dump never changes a live buffer.
 */
return new Test(
   description: 'UDP_Client_CLI: debug dumps mask the datagram buffers of a connection',
   test: function () {
      // ! SYNTH credentials — never real ones
      $token = 'SYNTH-UDP-CLIENT-BEARER-2.1';
      $cookie = 'SYNTH-UDP-CLIENT-COOKIE-2.1';
      $request = "GET / HTTP/1.1\r\nAuthorization: Bearer {$token}\r\n\r\n";
      $response = "HTTP/1.1 200 OK\r\nSet-Cookie: sid={$cookie}\r\n\r\n";
      $Dumper = new Dumper('plain');
      // ! No limit may hide a leak
      $Dumper->depth = 32;
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

      // ! A real connection over a bound loopback peer — nothing is ever sent;
      //   the connect hook stays out of this case
      $OldConnect = UDP_Client_CLI::$onClientConnect;
      UDP_Client_CLI::$onClientConnect = null;
      $Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $address = $Peer === false ? false : stream_socket_get_name($Peer, false);
      $Socket = $address === false ? false : stream_socket_client("udp://{$address}", $code, $message, 2.0);
      try {
         yield assert(
            assertion: $Socket !== false,
            description: 'The client socket is connected to the loopback peer'
         );
         if ($Socket === false) {
            return;
         }

         $Connection = new Connection($Socket);
         // ! Mid-exchange: a queued datagram and a received one
         $Connection->output = $request;
         $Connection->input = $response;
         $peer = $Connection->address;

         // @@ Every channel
         $leaks = [];
         $missing = [];
         foreach ($Channels as $channel => $Channel) {
            $output = $Channel($Connection);

            foreach ([$token, $cookie] as $secret) {
               if (str_contains($output, $secret) === true) {
                  $leaks[] = "{$secret} via {$channel}";
               }
            }
            foreach ([$peer, Secret::MASK] as $shown) {
               if (str_contains($output, $shown) === false) {
                  $missing[] = "{$shown} via {$channel}";
               }
            }
         }
         $leaked = implode(', ', $leaks);
         $absent = implode(', ', $missing);
         yield assert(
            assertion: $leaks === [] && $missing === [],
            description: "No datagram buffer reaches var_dump, print_r, debug_zval_dump or Dumper, while the peer address shows (leaked: [{$leaked}], missing: [{$absent}])"
         );

         // @ Dumping never writes
         yield assert(
            assertion: $Connection->output === $request
               && $Connection->input === $response
               && $Connection->Connection === $Connection,
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
         if ($marked !== ['input', 'output']) {
            $listed = implode(', ', $marked);
            $wrong[] = "marks [{$listed}]";
         }
         if ($Own->getFileName() !== $Trait->getFileName() || $Own->getStartLine() !== $Trait->getStartLine()) {
            $wrong[] = 'does not dump through Redactable';
         }
         $listed = implode('; ', $wrong);
         yield assert(
            assertion: $wrong === [],
            description: "A connection dumps through the Redactable trait, #[Secret] on its datagram buffers only ({$listed})"
         );
      }
      finally {
         // @ Cleanup
         if (is_resource($Socket) === true) {
            fclose($Socket);
         }
         if (is_resource($Peer) === true) {
            fclose($Peer);
         }
         UDP_Client_CLI::$onClientConnect = $OldConnect;
      }
   }
);
