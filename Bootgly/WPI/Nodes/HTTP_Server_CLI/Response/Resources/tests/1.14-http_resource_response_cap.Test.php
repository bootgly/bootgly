<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\HTTP\Cap;


use function assert;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function json_encode;
use function microtime;
use function str_contains;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use Fiber;
use ReflectionProperty;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;


// ? M2 — the embedded HTTP resource runs inside a server worker: an upstream (or any redirect
//   target it follows by default) must not be able to fill the worker's memory. The resource
//   takes maxResponseBytes in its constructor, defaults to the client's finite cap, and an
//   over-cap answer reaches the handler as a code 0 failure.
return new Test(
   description: 'Resources: the HTTP resource caps an upstream response and hands the failure to the handler',
   test: function () {
      // ! Host reactor standing in for the worker's — restored on teardown
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;
      $OldEvent = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      TCP_Server_CLI::$Event = $Event;

      // ! Upstream served BY the host reactor: `/big` answers 100 000 bytes,
      //   anything else 2 bytes
      $Server = stream_socket_server('tcp://127.0.0.1:0');
      if ($Server === false) {
         throw new RuntimeException('Unable to open the HTTP resource upstream.');
      }
      stream_set_blocking($Server, false);
      [, $port] = explode(':', (string) stream_socket_get_name($Server, false));

      $Peers = [];
      $serving = true;
      $timer = 0;
      $serve = null;
      $serve = function () use (&$serve, &$serving, &$Peers, &$timer, $Server, $Event): void {
         if ($serving === false) {
            return;
         }
         $Peer = @stream_socket_accept($Server, 0);
         if ($Peer !== false) {
            stream_set_blocking($Peer, false);
            $Peers[] = $Peer;
         }
         foreach ($Peers as $Open) {
            $head = @fread($Open, 4096);
            if ($head !== false && $head !== '') {
               @fwrite($Open, str_contains($head, ' /big ')
                  ? "HTTP/1.1 200 OK\r\nContent-Length: 100000\r\n\r\n" . str_repeat('B', 100000)
                  : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
            }
         }
         $timer = $Event->defer(microtime(true) + 0.005, $serve);
      };
      $timer = $Event->defer(microtime(true) + 0.005, $serve);

      // ! Pump bridge: runs the host reactor inline for one short slice
      $pump = static function (mixed $value = null) use ($Event): mixed {
         $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Event->defer(microtime(true) + 0.05, static function () use ($Event): void {
            $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Event->loop();

         return null;
      };

      // # The default is the client's own finite cap — one default only
      $Default = new HTTP(host: '127.0.0.1', port: (int) $port);
      $default = (new ReflectionProperty(HTTP_Client_CLI::class, 'maxResponseBytes'))->getDefaultValue();

      yield assert(
         assertion: $Default->Client->maxResponseBytes === $default && $default === 16_777_216,
         description: 'without the parameter the resource keeps the client default (16 MiB), found: ' . json_encode($Default->Client->maxResponseBytes)
      );

      // # The parameter reaches the client, and an over-cap answer is a failure
      $Resource = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 1, timeout: 2, maxResponseBytes: 65536);
      $Resource->schedule($pump);

      yield assert(
         assertion: $Resource->Client->maxResponseBytes === 65536,
         description: 'the constructor parameter reaches the embedded client'
      );

      $results = [];
      $Fiber = new Fiber(function () use ($Resource, &$results): void {
         $Big = $Resource->request('GET', '/big');
         $results['big'] = ['code' => $Big->code, 'status' => $Big->status];

         $Small = $Resource->request('GET', '/small');
         $results['small'] = ['code' => $Small->code, 'body' => $Small->body];
      });
      $Token = Cancellation::open($Fiber);
      $Fiber->start();
      $Token->finish();

      yield assert(
         assertion: ($results['big'] ?? null) === ['code' => 0, 'status' => 'Response Too Large'],
         description: 'an upstream answer past the cap reaches the handler as a failure, found: ' . json_encode($results['big'] ?? null)
      );

      yield assert(
         assertion: ($results['small'] ?? null) === ['code' => 200, 'body' => 'ok'],
         description: 'the next request on the resource still succeeds, found: ' . json_encode($results['small'] ?? null)
      );

      // # Teardown
      $serving = false;
      $Event->cancel($timer);
      foreach ($Peers as $Open) {
         @fclose($Open);
      }
      fclose($Server);
      if ($OldEvent !== null) {
         TCP_Server_CLI::$Event = $OldEvent;
      }
   }
);
