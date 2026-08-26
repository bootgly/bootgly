<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\HTTP;


use const BOOTGLY_ROOT_DIR;
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

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;


return new Test(
   description: 'Resources: the HTTP resource embeds one client per instance, owned by one deferred context and released with it',
   test: function () {
      // ! The exact refusal — a substring would also match the client's own
      //   parking refusal, which fires only AFTER the request was queued
      $outside = 'HTTP response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().';

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
      //   from TCP_Server_CLI::$Event, exactly as a worker would; restored on
      //   teardown so no later suite inherits a Select nobody loops
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      $Event = $Host->Event;
      $OldEvent = isSet(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
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
            if ($head !== false && $head !== '' && strpos($head, ' /hold ') === false) {
               @fwrite($Open, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: keep-alive\r\n\r\nok");
            }
         }
         $timer = $Event->defer(microtime(true) + 0.005, $serve);
      };
      $timer = $Event->defer(microtime(true) + 0.005, $serve);

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
         assertion: $refusal === $outside && $census() === ['connections' => 0, 'idle' => 0, 'busy' => 0],
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
         assertion: ($results['foreign'] ?? null) === $outside,
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

      // # A settled generation refuses further use (the handoff shape)
      //   SSE->open() and a nested defer() finish the token while the Fiber
      //   keeps running: settle() unpublishes the alias, the wait capability
      //   is gone, and the resource refuses instead of parking on a bridge
      //   that never suspends
      $FiberS = new Fiber(function () use ($Resource, &$results): void {
         $Token = Cancellation::fetch(Fiber::getCurrent());
         $Token?->finish();
         try {
            $Resource->request('GET', '/one');
            $results['S'] = 'served';
         }
         catch (LogicException $Refused) {
            $results['S'] = $Refused->getMessage();
         }
      });
      Cancellation::open($FiberS);
      $FiberS->start();

      yield assert(
         assertion: ($results['S'] ?? null) === $outside,
         description: 'A generation that already settled is refused like a context that never was, found: ' . json_encode($results['S'] ?? null)
      );

      // # Cancelling the generation mid-park terminalizes the in-flight
      //   request and closes its connection — the peer-left shape
      //   The Fiber is bound and scheduled on the reactor exactly as defer()
      //   does, so the cancel path runs through Select::evict(): the Fiber is
      //   never resumed, and the test never resumes it either.
      $Resource->schedule(static fn (mixed $value = null): mixed => Fiber::suspend($value));
      $FiberD = new Fiber(function () use ($Resource, &$results): void {
         $Resource->batch();
         $results['D'] = $Resource->request('GET', '/hold');
         $Resource->drain();
         $results['D-resumed'] = true;
      });
      $TokenD = Cancellation::open($FiberD);
      $suspended = $FiberD->start();
      $Event->bind($FiberD, static function (): void {}, static function (): void {});
      $Event->schedule($FiberD, $suspended);
      // ! Let the request reach the wire and be accepted
      $slice(3);
      // ! Still unanswered: code 0 with no named terminal yet, episode parked
      $parked = $FiberD->isSuspended() && $Client->parked === true
         && $census() === ['connections' => 1, 'idle' => 0, 'busy' => 1]
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

      // ! The evicted Fiber is never resumed, so its parking() finally never
      //   runs: the settled path must retire the episode notifier itself
      yield assert(
         assertion: $Client->parked === false,
         description: 'A cancelled generation retires the drain episode with its connections, found: ' . json_encode($Client->parked)
      );

      // ! Eviction: the suspended Fiber is dropped, never resumed — its parked
      //   drain must unwind cleanly on collection
      $FiberD = null;

      // # The next context parks and is answered — it must not inherit the
      //   cancelled generation's batch mode (a stale flag would hand it an
      //   unfilled Response without ever parking)
      $Resource->schedule($pump);
      $FiberE = new Fiber(function () use ($Resource, &$results): void {
         try {
            $Answer = $Resource->request('GET', '/three');
            $results['E'] = ['code' => $Answer->code, 'body' => $Answer->body];
         }
         catch (LogicException $Refused) {
            $results['E'] = $Refused->getMessage();
         }
      });
      $TokenE = Cancellation::open($FiberE);
      $FiberE->start();
      $TokenE->finish();

      yield assert(
         assertion: ($results['E']['code'] ?? 0) === 200
            && ($results['E']['body'] ?? '') === 'ok'
            && isset($results['D-resumed']) === false,
         description: 'After the cancelled context is dropped the resource is claimable again and no longer batching, found: ' . json_encode($results['E'] ?? null)
      );

      // # A carried instance keeps its owner's bridge across a foreign attach
      //   defer() starts by cloning the Response it works on, and __clone
      //   forks the resources — a mount without a definition is carried and
      //   RE-ATTACHED to the clone, which would swap the bridge under the
      //   context parked on it (a wait that never suspends: tripwire, scrap,
      //   a fabricated code 0). The rig's bridge is installed AFTER the mount.
      $Carrier = new Response;
      $Carried = $Carrier->mount(new HTTP(host: '127.0.0.1', port: (int) $port, connectTimeout: 1, timeout: 2), 'Carried');
      $Carried->schedule($pump);
      $FiberF = new Fiber(function () use ($Carried, $Carrier, &$results): void {
         $Carried->batch();
         $Answer = $Carried->request('GET', '/four');
         // ! The framework hop, from inside the owner
         $Clone = clone $Carrier;
         $Carried->drain();
         $results['F'] = ['code' => $Answer->code, 'body' => $Answer->body];
      });
      $TokenF = Cancellation::open($FiberF);
      $FiberF->start();
      $TokenF->finish();

      yield assert(
         assertion: ($results['F']['code'] ?? 0) === 200 && ($results['F']['body'] ?? '') === 'ok',
         description: 'A foreign attach while owned does not swap the owner bridge, found: ' . json_encode($results['F'] ?? null)
      );

      // # ...and the context whose attach was refused is told, not tripwired
      $FiberG = new Fiber(function () use ($Carried, &$results): void {
         try {
            $Carried->batch();
            $results['G'] = 'claimed';
         }
         catch (LogicException $Refused) {
            $results['G'] = $Refused->getMessage();
         }
      });
      Cancellation::open($FiberG);
      $FiberG->start();

      // ! A fresh attach (the next clone's fork, here the rig) re-arms it
      $Carried->schedule($pump);
      $FiberH = new Fiber(function () use ($Carried, &$results): void {
         try {
            $Carried->batch();
            $results['H'] = 'claimed';
         }
         catch (LogicException $Refused) {
            $results['H'] = $Refused->getMessage();
         }
      });
      $TokenH = Cancellation::open($FiberH);
      $FiberH->start();
      $TokenH->finish();

      yield assert(
         assertion: str_contains($results['G'] ?? '', 'attached to another response while owned')
            && ($results['H'] ?? null) === 'claimed',
         description: 'A refused attach is reported at the claim and a fresh attach re-arms the resource, found: ' . json_encode([$results['G'] ?? null, $results['H'] ?? null])
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
