<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\HTTP\Pin;


use const FILE_APPEND;
use const FILE_IGNORE_NEW_LINES;
use const SIGTERM;
use function array_map;
use function assert;
use function count;
use function explode;
use function fclose;
use function file;
use function file_put_contents;
use function fread;
use function fwrite;
use function getmypid;
use function json_decode;
use function json_encode;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_getppid;
use function posix_kill;
use function preg_match;
use function stream_socket_accept;
use function stream_socket_server;
use function strpos;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;
use Closure;
use Fiber;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;


// ? M3 — the embedded HTTP resource runs inside a server worker with the handler's
//   credentials: an upstream must not be able to bounce those requests (API keys,
//   bodies) to a service the worker can reach. Redirects stay on the configured
//   origin by default; a factory that trusts other destinations opts out.
return new Test(
   description: 'Resources: the HTTP resource follows redirects only within its upstream origin by default',
   test: function () {
      $A = 19916;
      $B = 19917;
      $log = (string) tempnam(sys_get_temp_dir(), 'rsrc117');

      // ! Blocking forked origins that log every request they read
      $serve = static function (string $address, Closure $Answer) use ($log): int {
         $parent = getmypid();
         $PID = pcntl_fork();
         // ? A failed fork must never hand back -1: the teardown would signal every process
         if ($PID < 0) {
            throw new RuntimeException("The origin could not fork for {$address}.");
         }
         if ($PID > 0) {
            return $PID;
         }
         $Server = @stream_socket_server("tcp://{$address}", $errno, $error);
         if ($Server === false) {
            exit(1);
         }
         while (true) {
            // ? Orphaned — the spec died before reaping it: free the port and go
            if (posix_getppid() !== $parent) {
               exit(0);
            }
            $Peer = @stream_socket_accept($Server, 1);
            if ($Peer === false) {
               continue;
            }
            $input = '';
            while (($end = strpos($input, "\r\n\r\n")) === false) {
               $chunk = @fread($Peer, 65535);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $input .= $chunk;
            }
            [$line] = explode("\r\n", $input);
            $target = explode(' ', $line)[1] ?? '';
            file_put_contents($log, json_encode([
               'address' => $address,
               'target' => $target,
               'key' => preg_match('/\r\nX-API-Key:/i', $input) === 1,
            ]) . "\n", FILE_APPEND);
            @fwrite($Peer, $Answer($target));
            usleep(20000);
            @fclose($Peer);
         }
      };
      $PIDs = [];
      $Answer = static fn (string $target): string => match ($target) {
            '/same' => "HTTP/1.1 302 Found\r\nLocation: /landing\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
            '/absolute' => "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:{$A}/landing\r\n"
               . "Content-Length: 0\r\nConnection: close\r\n\r\n",
            '/cross' => "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.2:{$B}/metadata\r\n"
               . "Content-Length: 0\r\nConnection: close\r\n\r\n",
            default => "HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\nLANDED",
      };

      // ! Host reactor standing in for the worker's — restored on teardown
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;
      $OldEvent = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      TCP_Server_CLI::$Event = $Event;
      $pump = static function (mixed $value = null) use ($Event): mixed {
         $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Event->defer(microtime(true) + 0.05, static function () use ($Event): void {
            $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Event->loop();

         return null;
      };

      // ! One deferred context per call, driven to completion
      $call = static function (HTTP $Resource, string $method, string $URI) use ($pump): array {
         $Resource->schedule($pump);
         $result = null;
         $Fiber = new Fiber(function () use ($Resource, $method, $URI, &$result): void {
            $Response = $Resource->request(
               $method,
               $URI,
               ['X-API-Key' => 'key-SECRET'],
               $method === 'POST' ? 'secret=body' : null
            );
            $result = [$Response->code, $Response->status, $Response->body];
         });
         $Token = Cancellation::open($Fiber);
         $Fiber->start();
         for ($slice = 0; $slice < 80 && $result === null; $slice++) {
            $Fiber->isSuspended() ? $Fiber->resume() : $pump();
         }
         $Token->finish();

         return $result ?? ['no result'];
      };

      try {
         $PIDs[] = $serve("127.0.0.1:{$A}", $Answer);
         $PIDs[] = $serve("127.0.0.2:{$B}", static fn (string $target): string =>
            "HTTP/1.1 200 OK\r\nContent-Length: 8\r\nConnection: close\r\n\r\nINTERNAL");
         usleep(200000);

         $Pinned = new HTTP(host: '127.0.0.1', port: $A, connectTimeout: 1, timeout: 2);
         $pinned = $Pinned->Client->Redirection instanceof Closure;
         $same = $call($Pinned, 'GET', '/same');
         $absolute = $call(new HTTP(host: '127.0.0.1', port: $A, connectTimeout: 1, timeout: 2), 'GET', '/absolute');
         $cross = $call(new HTTP(host: '127.0.0.1', port: $A, connectTimeout: 1, timeout: 2), 'POST', '/cross');

         // @ Batch: the pin holds there too — the hop is refused, not handed back as a 3xx
         $Batching = new HTTP(host: '127.0.0.1', port: $A, connectTimeout: 1, timeout: 2);
         $Batching->schedule($pump);
         $batched = null;
         $Fiber = new Fiber(function () use ($Batching, &$batched): void {
            $Batching->batch();
            $Batched = $Batching->request('POST', '/cross', ['X-API-Key' => 'key-SECRET'], 'secret=body');
            $Batching->drain();
            $batched = [$Batched->code, $Batched->status];
         });
         $Token = Cancellation::open($Fiber);
         $Fiber->start();
         for ($slice = 0; $slice < 80 && $batched === null; $slice++) {
            $Fiber->isSuspended() ? $Fiber->resume() : $pump();
         }
         $Token->finish();

         // @ Opt-out: the factory trusts other destinations
         $Open = new HTTP(host: '127.0.0.1', port: $A, connectTimeout: 1, timeout: 2);
         $Open->Client->Redirection = null;
         $opened = $call($Open, 'POST', '/cross');
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }

      $Received = array_map(
         static fn (string $line): array => (array) json_decode($line, true),
         file($log, FILE_IGNORE_NEW_LINES) ?: []
      );
      @unlink($log);
      $internal = [];
      foreach ($Received as $request) {
         if ($request['address'] === "127.0.0.2:{$B}") {
            $internal[] = $request;
         }
      }

      yield assert(
         assertion: $pinned,
         description: 'The resource client comes with a Redirection policy installed'
      );

      yield assert(
         assertion: $same === [200, 'OK', 'LANDED'] && $absolute === [200, 'OK', 'LANDED'],
         description: 'Relative and absolute same-origin redirects are followed: ' . json_encode([$same, $absolute])
      );

      yield assert(
         assertion: $cross === [0, 'Redirect Refused', ''],
         description: 'A redirect to another origin reaches the handler as a refusal: ' . json_encode($cross)
      );

      yield assert(
         assertion: $batched === [0, 'Redirect Refused'],
         description: 'Inside batch() the pin still refuses the hop: ' . json_encode($batched)
      );

      yield assert(
         assertion: $opened === [200, 'OK', 'INTERNAL'] && count($internal) === 1 && $internal[0]['key'] === false,
         description: 'With the pin removed the hop is followed — still without the API key: '
            . json_encode([$opened, $internal])
      );
   }
);
