<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2\Stream;


/**
 * `Stream::close()` is terminal: it runs once and never runs again.
 *
 * Several independent release paths converge here — RST_STREAM, GOAWAY,
 * connection teardown, graceful end and the SSE resource — and any of them can
 * fire after another already did. Every collaborator the method touches is
 * separately idempotent (`Buffers::release()` early-returns at zero,
 * `Bodies::release()` saturates, `Ownership::close()` keeps a terminal
 * tombstone, and the primary owner is captured and cleared before any
 * callback), so a plain second close on an untouched stream converges no matter
 * what — which is exactly why the guard's absence is invisible to 1.31 and to
 * every wire-level suite.
 *
 * What the guard actually buys is terminality against state that appears AFTER
 * closure, and the legs below are the shapes that carry it:
 *
 * - a re-entrant close is a no-op, so the outer frame's teardown is not
 *   executed underneath it while an owner is still being notified. In
 *   production the re-entrant caller is a `Cancellation` attached to the stream
 *   scope by `Response::retain()`, whose settlement runs user `Cancelling`
 *   callbacks;
 * - an owner assigned to a closed stream is not notified by a later close.
 *   `SSE::open()` assigns `$Stream->Owner` (`SSE.php`) in a window its own
 *   comment describes as one where teardown may already have run re-entrantly,
 *   and the property is a bare public one with no setter to intercept;
 * - state parked on a closed stream is left alone rather than half-collected,
 *   because a dead stream is not the owner of anything attached to it later.
 *
 * Leg D is not decoration: without it a `close()` that returned immediately and
 * did nothing at all would satisfy every other leg here.
 */
return new Test(
   description: 'HTTP/2 Stream close should be terminal — a second call must do nothing at all',
   test: new Assertions(Case: function (): Generator {
      // ! The retained-byte ledger is worker-wide and shared with every other
      //   spec in this process. Record the entry value and prove the legs give
      //   back exactly what they take instead of asserting an absolute.
      $entered = TCPServer::$pendingBytes;

      $Prototype = new class implements Disconnecting {
         public int $calls = 0;

         public function disconnect (): void
         {
            $this->calls++;
         }
      };

      // --- Leg A — an owner assigned after closure is never notified.

      $BodiesA = new Bodies(1024, 1024);
      $StreamA = new Stream(1, 65_535, 65_535, $BodiesA);

      $Primary = clone $Prototype;
      $Late = clone $Prototype;

      $StreamA->Owner = $Primary;
      $StreamA->close();

      // @ The shape SSE::open() produces when the stream it is opening on was
      //   already torn down: a live owner bound to a dead stream.
      $StreamA->Owner = $Late;
      $StreamA->close();

      $A = [
         'primary' => $Primary->calls,
         'late' => $Late->calls,
         // ! Not merely un-notified: the second close returns before it can
         //   consume the reference at all, so the late owner is left exactly
         //   as the caller assigned it.
         'late_owner_untouched' => $StreamA->Owner === $Late,
      ];

      // --- Leg B — a re-entrant close does not tear down the outer frame.

      $BodiesB = new Bodies(1024, 1024);
      $StreamB = new Stream(2, 65_535, 65_535, $BodiesB);

      $reservedB = $BodiesB->reserve(8);
      $StreamB->body = 'SSE-BODY';

      $Reentrant = new class ($StreamB) implements Disconnecting {
         public int $calls = 0;
         public string $before = '';
         public string $after = '';

         public function __construct (private Stream $Stream) {}

         public function disconnect (): void
         {
            $this->calls++;

            // ! The outer frame has committed closure and cleared the primary
            //   owner, but has NOT yet released the body — it releases after
            //   the registry callbacks return. A re-entrant close that ran the
            //   teardown would empty the body under this owner's feet.
            $this->before = $this->Stream->body;
            $this->Stream->close();
            $this->after = $this->Stream->body;
         }
      };

      Ownership::attach($StreamB, $Reentrant);
      $StreamB->close();

      $B = [
         'calls' => $Reentrant->calls,
         'body_before' => $Reentrant->before,
         'body_after' => $Reentrant->after,
         // ! The OUTER frame still owes the release, and must still perform it.
         'reserved' => $reservedB,
         'released' => $BodiesB->retained,
      ];

      // --- Leg C — state parked on a closed stream is left alone.

      $BodiesC = new Bodies(1024, 1024);
      $StreamC = new Stream(3, 65_535, 65_535, $BodiesC);
      $StreamC->close();

      $Handler = fopen('php://memory', 'rb+');
      $StreamC->chunks = [['handler' => $Handler, 'data' => null]];
      $StreamC->close();

      $C = [
         'handler_open' => is_resource($Handler),
         'chunks' => count($StreamC->chunks),
      ];

      if (is_resource($Handler)) {
         fclose($Handler);
      }

      // --- Leg D — positive control: the FIRST close does the whole job.

      $BodiesD = new Bodies(1024, 1024);
      $StreamD = new Stream(4, 65_535, 65_535, $BodiesD);

      $Owner = clone $Prototype;
      $Registered = clone $Prototype;

      $StreamD->method = 'GET';
      $StreamD->target = '/terminality';
      $StreamD->scheme = 'https';
      $StreamD->authority = 'localhost';
      $StreamD->fields = ['accept' => '*/*'];
      $StreamD->deadline = 1;
      $StreamD->backlog = 'TAIL';
      $StreamD->Owner = $Owner;
      Ownership::attach($StreamD, $Registered);

      $reservedD = $BodiesD->reserve(9);
      $StreamD->body = 'BODY-NINE';
      $StreamD->Buffers->reserve(4);
      $StreamD->HeadBuffers->reserve(16);

      $Collected = fopen('php://memory', 'rb+');
      $StreamD->chunks = [['handler' => $Collected, 'data' => null]];
      $StreamD->chunk = 1;

      $StreamD->close();

      $D = [
         'owner' => $Owner->calls,
         'registered' => $Registered->calls,
         'head' => $StreamD->method . $StreamD->target . $StreamD->scheme
            . $StreamD->authority,
         'fields' => $StreamD->fields,
         'deadline' => $StreamD->deadline,
         'backlog' => $StreamD->backlog,
         'chunks' => count($StreamD->chunks),
         'chunk' => $StreamD->chunk,
         'handler_open' => is_resource($Collected),
         'reserved' => $reservedD,
         'body_released' => $BodiesD->retained,
         'buffers' => $StreamD->Buffers->retained,
         'head_buffers' => $StreamD->HeadBuffers->retained,
      ];

      if (is_resource($Collected)) {
         fclose($Collected);
      }

      yield new Assertion(
         description: 'a second close notifies no one, collects nothing and undoes nothing',
      )
         ->expect([
            'A' => $A,
            'B' => $B,
            'C' => $C,
            'D' => $D,
            'ledger' => TCPServer::$pendingBytes - $entered,
         ])
         ->to->be([
            'A' => [
               'primary' => 1,
               'late' => 0,
               'late_owner_untouched' => true,
            ],
            'B' => [
               'calls' => 1,
               'body_before' => 'SSE-BODY',
               'body_after' => 'SSE-BODY',
               'reserved' => true,
               'released' => 0,
            ],
            'C' => [
               'handler_open' => true,
               'chunks' => 1,
            ],
            'D' => [
               'owner' => 1,
               'registered' => 1,
               'head' => '',
               'fields' => [],
               'deadline' => 0,
               'backlog' => '',
               'chunks' => 0,
               'chunk' => 0,
               'handler_open' => false,
               'reserved' => true,
               'body_released' => 0,
               'buffers' => 0,
               'head_buffers' => 0,
            ],
            'ledger' => 0,
         ])
         ->assert();
   }),
);
