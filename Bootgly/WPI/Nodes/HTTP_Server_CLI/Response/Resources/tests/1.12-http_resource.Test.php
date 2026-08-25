<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\HTTP;


use const PHP_BINARY;
use function assert;
use function count;
use function escapeshellarg;
use function explode;
use function fclose;
use function fread;
use function fwrite;
use function getenv;
use function json_encode;
use function microtime;
use function shell_exec;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strpos;
use function var_export;
use Fiber;
use LogicException;
use RuntimeException;

use const BOOTGLY_ROOT_DIR;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;


return new Test(
   description: 'Resources: the HTTP resource embeds one client per instance, owned by one deferred context and released with it',
   test: function () {
      // # Without a server reactor the resource refuses to build
      //   The static reactor cannot be unset in-process (the rig below sets it),
      //   so the guard is probed in a fresh interpreter through the same autoboot.
      $code = 'require ' . var_export(BOOTGLY_ROOT_DIR . 'autoboot.php', true) . ';'
         . ' try { new Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP(host: "127.0.0.1"); echo "built"; }'
         . ' catch (RuntimeException $Refusal) { echo $Refusal->getMessage(); }';
      $probe = (string) shell_exec(
         'env -i PATH=' . escapeshellarg((string) getenv('PATH'))
         . ' HOME=' . escapeshellarg((string) getenv('HOME'))
         . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'
      );

      yield assert(
         assertion: str_contains($probe, 'HTTP server reactor') && str_contains($probe, 'built') === false,
         description: 'Outside a server there is no reactor to park on and the resource refuses, found: ' . json_encode($probe)
      );

      // ! Host reactor standing in for the worker's — the resource reads it
      //   from TCP_Server_CLI::$Event, exactly as a worker would
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;
      TCP_Server_CLI::$Event = $Event;

      // ! Keep-alive loopback upstream served BY the host reactor: `/hold`
      //   is accepted and never answered, everything else answers and KEEPS
      //   the connection open — the shape whose leftovers this spec pins
      $Server = stream_socket_server('tcp://127.0.0.1:0');
      if ($Server === false) {
         throw new RuntimeException('Unable to open the HTTP resource upstream.');
      }
      stream_set_blocking($Server, false);
      [, $port] = explode(':', (string) stream_socket_get_name($Server, false));

      $Peers = [];
      $serving = true;
      $serve = null;
      $serve = function () use (&$serve, &$serving, &$Peers, $Server, $Event): void {
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
            if ($head !== false && $head !== '' && strpos($head, ' /hold ') === false) {
               @fwrite($Open, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");
            }
         }
         $Event->defer(microtime(true) + 0.005, $serve);
      };
      $Event->defer(microtime(true) + 0.005, $serve);

      // ! Pump bridge: runs the host reactor inline for one short slice (the
      //   8.2 rig) — honest progress without a real suspension
      $pump = static function (mixed $value = null) use ($Event): mixed {
         $Event->loop = true; // @phpstan-ignore-line (property on the Select impl)
         $Event->defer(microtime(true) + 0.05, static function () use ($Event): void {
            $Event->loop = false; // @phpstan-ignore-line (property on the Select impl)
         });
         $Event->loop();

         return null;
      };
      $slice = static function (int $slices) use ($pump): void {
         for ($i = 0; $i < $slices; $i++) {
            $pump();
         }
      };

      $Resource = new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 1, timeout: 2);
      $Resource->schedule($pump);
      $Client = $Resource->Client;

      /** @return array{connections:int,idle:int,busy:int} */
      $census = static fn (): array => [
         'connections' => count($Client->Connections->Connections),
         'idle' => count($Client->Pool->idle),
         'busy' => count($Client->Pool->busy),
      ];

      yield assert(
         assertion: $Client->owned === false && $Client->Event === $Event,
         description: 'The embedded client is adopted by the server reactor'
      );

      // # Outside any Fiber the resource refuses before touching the client
      $refusal = null;
      try {
         $Resource->request('GET', '/one');
      }
      catch (LogicException $Refused) {
         $refusal = $Refused->getMessage();
      }

      yield assert(
         assertion: $refusal !== null && str_contains($refusal, 'deferred context') && $census() === ['connections' => 0, 'idle' => 0, 'busy' => 0],
         description: 'Outside a Fiber the resource refuses and dials nothing, found: ' . json_encode([$refusal, $census()])
      );

      // # A Fiber without a generation token is not a deferred context either
      $results = [];
      $Foreign = new Fiber(function () use ($Resource, &$results): void {
         try {
            $Resource->batch();
            $results['foreign'] = 'claimed';
         }
         catch (LogicException $Refused) {
            $results['foreign'] = $Refused->getMessage();
         }
      });
      $Foreign->start();

      yield assert(
         assertion: str_contains($results['foreign'] ?? '', 'deferred context'),
         description: 'A Fiber that is not a deferred context is refused, found: ' . json_encode($results['foreign'] ?? null)
      );

      // # A deferred context claims the resource; its request completes on the
      //   host reactor and the keep-alive connection stays pooled for reuse
      $FiberA = new Fiber(function () use ($Resource, &$results, $census): void {
         $Answer = $Resource->request('GET', '/one');
         $results['A'] = ['code' => $Answer->code, 'body' => $Answer->body, 'census' => $census()];
      });
      $TokenA = Cancellation::open($FiberA);
      $FiberA->start();

      yield assert(
         assertion: ($results['A']['code'] ?? 0) === 200
            && ($results['A']['body'] ?? '') === 'ok'
            && ($results['A']['census'] ?? []) === ['connections' => 1, 'idle' => 1, 'busy' => 0],
         description: 'The deferred request completes and its keep-alive connection is pooled, found: ' . json_encode($results['A'] ?? null)
      );

      // # A second deferred context is refused while the first still owns it
      $FiberB = new Fiber(function () use ($Resource, &$results): void {
         try {
            $Resource->batch();
            $results['B'] = 'claimed';
         }
         catch (LogicException $Refused) {
            $results['B'] = $Refused->getMessage();
         }
      });
      Cancellation::open($FiberB);
      $FiberB->start();

      yield assert(
         assertion: str_contains($results['B'] ?? '', 'another deferred context'),
         description: 'A second deferred context cannot take an owned resource, found: ' . json_encode($results['B'] ?? null)
      );

      // # Finishing the generation releases the resource AND closes the idle
      //   keep-alive connection — nothing outlives the deferral on the reactor
      $TokenA->finish();

      yield assert(
         assertion: $census() === ['connections' => 0, 'idle' => 0, 'busy' => 0],
         description: 'A finished generation leaves no connection registered on the reactor, found: ' . json_encode($census())
      );

      // # ...and the next deferred context claims it and dials afresh
      $FiberC = new Fiber(function () use ($Resource, &$results, $census): void {
         $Answer = $Resource->request('GET', '/two');
         $results['C'] = ['code' => $Answer->code, 'census' => $census()];
      });
      $TokenC = Cancellation::open($FiberC);
      $FiberC->start();
      $TokenC->finish();

      yield assert(
         assertion: ($results['C']['code'] ?? 0) === 200
            && ($results['C']['census'] ?? []) === ['connections' => 1, 'idle' => 1, 'busy' => 0]
            && $census() === ['connections' => 0, 'idle' => 0, 'busy' => 0],
         description: 'A released resource is claimable again and released again, found: ' . json_encode([$results['C'] ?? null, $census()])
      );

      // # Cancelling the generation mid-park terminalizes the in-flight
      //   request and closes its connection — the peer-left shape
      //   The parked Fiber is evicted by the scheduler and never resumed, so
      //   the bridge here really suspends and the test never resumes it.
      $Resource->schedule(static fn (mixed $value = null): mixed => Fiber::suspend($value));
      $FiberD = new Fiber(function () use ($Resource, &$results): void {
         $Resource->batch();
         $results['D'] = $Resource->request('GET', '/hold');
         $Resource->drain();
         $results['D-resumed'] = true;
      });
      $TokenD = Cancellation::open($FiberD);
      $FiberD->start();
      // ! Let the request reach the wire and be accepted
      $slice(3);
      // ! Still unanswered: code 0 with no named terminal yet
      $parked = $FiberD->isSuspended() && $census() === ['connections' => 1, 'idle' => 0, 'busy' => 1]
         && ($results['D']->code ?? -1) === 0 && ($results['D']->status ?? 'x') === '';

      $TokenD->cancel();
      $Answer = $results['D'] ?? null;

      yield assert(
         assertion: $parked
            && $Answer !== null
            && $Answer->code === 0
            && $Answer->status === 'Connection Failed'
            && $census() === ['connections' => 0, 'idle' => 0, 'busy' => 0],
         description: 'A cancelled generation terminalizes the parked request and closes its connection, found: '
            . json_encode(['parked' => $parked, 'code' => $Answer?->code, 'status' => $Answer?->status, 'census' => $census()])
      );

      // ! Eviction: the suspended Fiber is dropped, never resumed — its parked
      //   drain must unwind cleanly (notifier pair closed by the finally)
      $FiberD = null;

      $FiberE = new Fiber(function () use ($Resource, &$results): void {
         try {
            $Resource->batch();
            $results['E'] = 'claimed';
         }
         catch (LogicException $Refused) {
            $results['E'] = $Refused->getMessage();
         }
      });
      $TokenE = Cancellation::open($FiberE);
      $FiberE->start();
      $TokenE->finish();

      yield assert(
         assertion: ($results['E'] ?? null) === 'claimed' && isset($results['D-resumed']) === false,
         description: 'After the cancelled context is dropped the resource is claimable again, found: ' . json_encode($results['E'] ?? null)
      );

      // # Teardown
      $serving = false;
      foreach ($Peers as $Open) {
         @fclose($Open);
      }
      fclose($Server);
   }
);
