<?php

use function class_exists;
use function fclose;
use function gc_collect_cycles;
use function is_resource;
use function json_encode;
use function str_contains;
use function str_repeat;
use function strlen;
use function time;
use function tmpfile;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
use Throwable;
use WeakReference;


if (! class_exists('HTTPServerCLIBodyBudgetConnection', false)) {
   class HTTPServerCLIBodyBudgetConnection extends Connection
   {
      public bool $closed = false;

      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      // ! No event loop in this process — detach from `Server::$Event`.
      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}

/**
 * PoC — HTTP/1 in-memory request bodies have no worker-wide budget and are
 * retained past connection close.
 *
 * `Decoder_Waiting` (Content-Length) and `Decoder_Chunked` (chunked) each hold
 * an unfinished body bounded only by the PER-REQUEST `Request::$maxBodySize`.
 * Nothing bounds their sum, so N concurrent peers that declare a legal body and
 * send only part of it retain N x cap in one worker. HTTP/2 already carries both
 * a per-connection and a per-worker ledger (`Decoder_HTTP2\Bodies`), which is
 * exactly the control HTTP/1 lacks.
 *
 * Neither HTTP/1 body decoder implements `Disconnecting`, so `Connection::close()`
 * does not tear them down; and `Packages::__construct()` stored a back-reference
 * to the very object that inherits it, so a closed Connection is a self-cycle that
 * plain refcounting can never free.
 *
 * Every leg below runs against production classes on the production decode path.
 */

$probe = [
   'error' => '',
   // # Aggregate budget
   'per_request_cap' => Request::$maxBodySize,
   'probe_budget' => 256 * 1024,
   'slice' => 128 * 1024,
   'declared' => 1024 * 1024,
   'connections' => 6,
   'budget_available' => class_exists(Bodies::class),
   'accepted' => 0,
   'rejected' => 0,
   'retained_body_bytes' => 0,
   'chunked_accepted' => 0,
   'chunked_rejected' => 0,
   'chunk_bytes' => 0,
   'multipart_accepted' => 0,
   'multipart_rejected' => 0,
   'multipart_field_bytes' => 0,
   'multipart_retained' => 0,
   'multipart_budget_free_after' => true,
   // # Teardown driven by the real Connection::close()
   'closed_via_transport' => false,
   'body_alive_after_close' => true,
   'budget_free_after_close' => false,
   // # Deterministic release
   'waiting_disconnecting' => false,
   'chunked_disconnecting' => false,
   'ledger_empty_after_disconnect' => false,
   'request_alive_after_disconnect' => true,
   // # Reservation isolation
   'isolated' => false,
   'ledger_exact' => false,
   // # Close without cyclic GC
   'connection_alive_without_gc' => true,
   'connection_gc_collected' => -1,
   // # Controls
   'control_complete_body' => '',
   'control_reservation_released' => false,
];

return new Specification(
   description: 'HTTP/1 unfinished request bodies must obey a worker-wide budget and release on close',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $harness = "GET /h1-budget-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;
      $OldWorkerBodySize = $probe['budget_available'] ? Bodies::$maxWorkerBodySize : 0;

      $Sockets = [];
      $Retained = [];

      try {
         Server::$Response = new Response;
         Server::$Router = new Router;
         Server::$Decoder = new Decoder_;
         SAPI::$Middlewares = new Middlewares;
         SAPI::$Handler = static function (Request $Request, Response $Response, Router $Router): Response {
            return $Response(code: 200, body: 'H1-BUDGET');
         };

         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $probe['probe_budget'];
         }

         // ! One independent peer: its own socket, Connection, Package and
         //   Request — exactly what N concurrent connections look like.
         $open = function () use (&$Sockets): array {
            $socket = tmpfile();
            if (! is_resource($socket)) {
               throw new RuntimeException('Could not allocate a temporary stream socket surrogate.');
            }
            $Sockets[] = $socket;

            $Connection = new HTTPServerCLIBodyBudgetConnection($socket);
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };
            $Request = new Request;
            Server::$Request = $Request;

            return [$Connection, $Package, $Request];
         };

         // --- Leg 1: N unfinished Content-Length bodies share one worker.

         $slice = str_repeat('A', $probe['slice']);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            // ! Head only: the declared body arrives afterwards, through
            //   `Decoder_Waiting` — the drip an attacker never finishes.
            $head = "POST /h1-budget/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Length: {$probe['declared']}\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: head {$peer} was rejected.";
               return $harness;
            }
            if ($Package->Decoder === null) {
               $probe['error'] = "Probe setup failed: peer {$peer} installed no body decoder.";
               return $harness;
            }

            $State = $Package->Decoder->decode($Package, $slice, $probe['slice']);
            if ($State === States::Rejected) {
               $probe['rejected']++;
            }
            else {
               $probe['accepted']++;
            }

            $probe['retained_body_bytes'] += strlen($Request->Body->raw);
            // ! Hold every peer alive: concurrency is the whole point.
            $Retained[] = [$Connection, $Package, $Request];
         }

         // ! Hand the budget back before the chunked leg draws on the same
         //   ledger — otherwise leg 2 would only re-measure leg 1's exhaustion.
         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 2: the same budget governs chunked bodies.

         // ! A chunked peer retains its framing bytes too, so its footprint is
         //   slightly larger than the raw slice — the budget is in bytes, not
         //   in connections.
         $chunk = "20000\r\n" . $slice;
         $probe['chunk_bytes'] = strlen($chunk);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            $head = "POST /h1-budget-chunked/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Transfer-Encoding: chunked\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: chunked head {$peer} was rejected.";
               return $harness;
            }
            if ($Package->Decoder === null) {
               $probe['error'] = "Probe setup failed: chunked peer {$peer} installed no body decoder.";
               return $harness;
            }

            $State = $Package->Decoder->decode($Package, $chunk, strlen($chunk));
            if ($State === States::Rejected) {
               $probe['chunked_rejected']++;
            }
            else {
               $probe['chunked_accepted']++;
            }

            $Retained[] = [$Connection, $Package, $Request];
         }

         // ! Same handback before the multipart leg.
         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 3: multipart TEXT parts are memory too, and draw on the
         //     same ledger. File parts stream to disk and must not.

         $probe['multipart_field_bytes'] = 60000;
         $field = str_repeat('M', $probe['multipart_field_bytes']);
         for ($peer = 0; $peer < $probe['connections']; $peer++) {
            [$Connection, $Package, $Request] = $open();

            $head = "POST /h1-budget-multipart/{$peer} HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary=BootglyH1\r\n"
               . "Content-Length: {$probe['declared']}\r\n"
               . "\r\n";
            if ($Request->decode($Package, $head, strlen($head)) === States::Rejected) {
               $probe['error'] = "Probe setup failed: multipart head {$peer} was rejected.";
               return $harness;
            }
            if ($Package->Decoder === null) {
               $probe['error'] = "Probe setup failed: multipart peer {$peer} installed no body decoder.";
               return $harness;
            }

            // ! One text part opened and never terminated: the value sits in
            //   `$fieldBuffer` for as long as the peer keeps the body open.
            $part = "--BootglyH1\r\n"
               . "Content-Disposition: form-data; name=\"a{$peer}\"\r\n"
               . "\r\n"
               . $field;

            $State = $Package->Decoder->decode($Package, $part, strlen($part));
            if ($State === States::Rejected) {
               $probe['multipart_rejected']++;
            }
            else {
               $probe['multipart_accepted']++;
               $probe['multipart_retained'] += $probe['multipart_field_bytes'];
            }

            $Retained[] = [$Connection, $Package, $Request];
         }

         if ($probe['budget_available']) {
            // ! With the admitted text parts outstanding, the whole budget
            //   must NOT still look free.
            $Probe = new Bodies;
            $probe['multipart_budget_free_after'] = $Probe->reserve($probe['probe_budget']);
            $Probe->release();
         }

         foreach ($Retained as [$Connection, $Package, $Request]) {
            if ($Package->Decoder instanceof Disconnecting) {
               $Package->Decoder->disconnect();
            }
            $Package->Decoder = null;
         }
         $Retained = [];

         // --- Leg 4: teardown releases the exact reservation, deterministically.

         $Retained = [];
         $probe['waiting_disconnecting'] = false;
         $probe['chunked_disconnecting'] = false;

         [$Connection, $Package, $Request] = $open();
         $head = "POST /h1-budget-release HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: {$probe['declared']}\r\n"
            . "\r\n";
         $Request->decode($Package, $head, strlen($head));
         $Decoder = $Package->Decoder;
         $probe['waiting_disconnecting'] = $Decoder instanceof Disconnecting;

         if ($Decoder !== null) {
            $Decoder->decode($Package, $slice, $probe['slice']);

            $Weak = WeakReference::create($Request);
            if ($Decoder instanceof Disconnecting) {
               $Decoder->disconnect();
            }
            $Package->Decoder = null;
            Server::$Request = new Request;
            unset($Decoder, $Request);

            // ! No `gc_collect_cycles()`: a body released only by the cycle
            //   collector is still a burst an attacker fully controls.
            $probe['request_alive_after_disconnect'] = $Weak->get() !== null;
         }

         [$Connection, $Package, $Request] = $open();
         $head = "POST /h1-budget-release-chunked HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n";
         $Request->decode($Package, $head, strlen($head));
         $probe['chunked_disconnecting'] = $Package->Decoder instanceof Disconnecting;
         if ($Package->Decoder instanceof Disconnecting) {
            $Package->Decoder->decode($Package, $chunk, strlen($chunk));
            $Package->Decoder->disconnect();
         }
         $Package->Decoder = null;

         if ($probe['budget_available']) {
            // ! The worker ledger is deliberately private, so prove its state
            //   by what it admits: after teardown the whole budget is free.
            $Empty = new Bodies;
            $probe['ledger_empty_after_disconnect'] = $Empty->reserve($probe['probe_budget']);
            $Empty->release();

            // --- Leg 5: one peer must never release another peer's bytes.

            $A = new Bodies;
            $B = new Bodies;
            $reservedA = $A->reserve(64 * 1024);
            $reservedB = $B->reserve(64 * 1024);
            // ! Twice: a double release must not credit the ledger twice.
            $A->release();
            $A->release();
            $probe['isolated'] = $reservedA && $reservedB && $B->retained === 64 * 1024;

            // ! With only B's reservation outstanding, exactly the remainder
            //   fits and one byte more does not.
            $C = new Bodies;
            $probe['ledger_exact'] = $C->reserve($probe['probe_budget'] - 64 * 1024)
               && $C->reserve($probe['probe_budget']) === false;
            $C->release();
            $B->release();

            $Free = new Bodies;
            $probe['control_reservation_released'] = $Free->reserve($probe['probe_budget']);
            $Free->release();
         }

         // --- Leg 6: a closed connection must not wait for the cycle collector.

         $socket = tmpfile();
         if (! is_resource($socket)) {
            throw new RuntimeException('Could not allocate the lifetime probe socket.');
         }
         $Sockets[] = $socket;

         // ! Drain whatever cyclic garbage earlier suites left in this worker,
         //   so the collector count below is attributable to THIS connection
         //   and not to the order the matrix happened to run in.
         gc_collect_cycles();

         // ! The REAL constructor — the self-reference is created there, not
         //   at close. `guard()` parks `[$this, 'expire']` in the static Timer
         //   map, so drop that root first: it is an unrelated, already-fixed
         //   retention path and would mask this one.
         $Lifetime = new Connection($socket, '127.0.0.1', 12345);
         foreach ($Lifetime->timers as $timer) {
            Timer::del($timer);
         }
         $Lifetime->timers = [];

         $Weak = WeakReference::create($Lifetime);
         unset($Lifetime);
         $probe['connection_alive_without_gc'] = $Weak->get() !== null;
         $probe['connection_gc_collected'] = gc_collect_cycles();

         // --- Leg 7: drive the teardown through the REAL transport close with
         //     a body attached, instead of calling disconnect() by hand. This
         //     is what proves Connection::close() actually reaches the decoder.

         $socket = tmpfile();
         if (! is_resource($socket)) {
            throw new RuntimeException('Could not allocate the transport-close socket.');
         }
         $Sockets[] = $socket;

         // ! A Connection IS its own Package, so it decodes into itself —
         //   exactly the production shape.
         $Transport = new Connection($socket, '127.0.0.1', 12345);
         $TransportRequest = new Request;
         Server::$Request = $TransportRequest;

         $head = "POST /h1-budget-transport-close HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: {$probe['declared']}\r\n"
            . "\r\n";
         $TransportRequest->decode($Transport, $head, strlen($head));
         if ($Transport->Decoder === null) {
            $probe['error'] = 'Probe setup failed: the transport peer installed no body decoder.';
            return $harness;
         }
         $Transport->Decoder->decode($Transport, $slice, $probe['slice']);

         $BodyWeak = WeakReference::create($TransportRequest);
         Server::$Request = new Request;
         unset($TransportRequest);

         // @ The production close path — nothing about the decoder is touched
         //   by hand here.
         $probe['closed_via_transport'] = $Transport->close();
         $probe['body_alive_after_close'] = $BodyWeak->get() !== null;

         if ($probe['budget_available']) {
            $Closed = new Bodies;
            $probe['budget_free_after_close'] = $Closed->reserve($probe['probe_budget']);
            $Closed->release();
         }
         unset($Transport);
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $Retained = [];

         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }
         if ($probe['budget_available']) {
            Bodies::$maxWorkerBodySize = $OldWorkerBodySize;
         }

         foreach ($Sockets as $socket) {
            if (is_resource($socket)) {
               @fclose($socket);
            }
         }
      }

      return $harness;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h1-budget-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H1-BUDGET-HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['H1 probe state'];
         dump(json_encode($probe));
         return $probe['error'];
      }
      if (! str_contains($response, 'H1-BUDGET-HARNESS-OK')) {
         Vars::$labels = ['H1 harness response'];
         dump(json_encode($response));
         return 'The H1 budget harness did not receive its control response.';
      }

      if ($probe['budget_available'] === false) {
         Vars::$labels = ['H1 aggregate-budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: HTTP/1 in-memory request bodies have no worker-wide '
            . 'accountant at all, so N connections retain N x the per-request cap ('
            . $probe['per_request_cap'] . ' bytes each); evidence=' . json_encode($probe);
      }

      // ? The aggregate a burst of unfinished Content-Length bodies retained.
      if ($probe['retained_body_bytes'] > $probe['probe_budget']) {
         Vars::$labels = ['H1 aggregate-budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: unfinished HTTP/1 Content-Length bodies retained '
            . $probe['retained_body_bytes'] . ' bytes across ' . $probe['connections']
            . ' connections, over the ' . $probe['probe_budget'] . '-byte worker budget; '
            . 'evidence=' . json_encode($probe);
      }
      if ($probe['accepted'] !== 2 || $probe['rejected'] !== 4) {
         Vars::$labels = ['H1 aggregate-budget evidence'];
         dump(json_encode($probe));
         return 'The worker budget did not admit exactly the two peers that fit and reject '
            . 'the remaining four; evidence=' . json_encode($probe);
      }
      // ? Chunked peers must draw on the SAME ledger. Assert it in bytes, not
      //   in connections: exactly the peers whose footprint fits are admitted,
      //   and the first one that does not fit is refused.
      if (
         $probe['chunked_rejected'] === 0
         || $probe['chunked_accepted'] * $probe['chunk_bytes'] > $probe['probe_budget']
         || ($probe['chunked_accepted'] + 1) * $probe['chunk_bytes'] <= $probe['probe_budget']
      ) {
         Vars::$labels = ['H1 chunked-budget evidence'];
         dump(json_encode($probe));
         return 'Chunked bodies did not draw on the same worker budget as Content-Length '
            . 'bodies; evidence=' . json_encode($probe);
      }

      // ? Multipart TEXT parts are held in memory exactly like a
      //   Content-Length body, so they must draw on the same ledger. File
      //   parts stream to disk and are accounted for separately.
      if (
         $probe['multipart_rejected'] === 0
         || $probe['multipart_retained'] > $probe['probe_budget']
         || $probe['multipart_budget_free_after'] !== false
      ) {
         Vars::$labels = ['H1 multipart-budget evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: unfinished multipart text parts retained '
            . $probe['multipart_retained'] . ' bytes across ' . $probe['connections']
            . ' connections without drawing on the worker budget, so the ceiling still '
            . 'looked free; evidence=' . json_encode($probe);
      }
      if (
         $probe['multipart_accepted'] * $probe['multipart_field_bytes'] > $probe['probe_budget']
         || ($probe['multipart_accepted'] + 1) * $probe['multipart_field_bytes']
            <= $probe['probe_budget']
      ) {
         Vars::$labels = ['H1 multipart-budget evidence'];
         dump(json_encode($probe));
         return 'The worker budget did not admit exactly the multipart peers that fit; '
            . 'evidence=' . json_encode($probe);
      }

      // ? Teardown must be deterministic, not cycle-collector dependent.
      if ($probe['waiting_disconnecting'] === false || $probe['chunked_disconnecting'] === false) {
         Vars::$labels = ['H1 teardown evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the HTTP/1 body decoders do not implement Disconnecting, '
            . 'so Connection::close() never releases their retained body; evidence='
            . json_encode($probe);
      }
      if ($probe['request_alive_after_disconnect'] !== false) {
         Vars::$labels = ['H1 teardown evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the decoded Request survived decoder teardown without a '
            . 'cycle collection; evidence=' . json_encode($probe);
      }
      if ($probe['ledger_empty_after_disconnect'] === false) {
         Vars::$labels = ['H1 teardown evidence'];
         dump(json_encode($probe));
         return 'Decoder teardown did not return the worker ledger to zero; evidence='
            . json_encode($probe);
      }
      if (
         $probe['isolated'] === false
         || $probe['ledger_exact'] === false
         || $probe['control_reservation_released'] === false
      ) {
         Vars::$labels = ['H1 reservation isolation evidence'];
         dump(json_encode($probe));
         return 'One decoder released bytes it never reserved, or a release did not return '
            . 'them to the worker ledger; evidence=' . json_encode($probe);
      }

      // ? The closed connection graph itself.
      if ($probe['connection_alive_without_gc'] !== false) {
         Vars::$labels = ['H1 connection lifetime evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: a released Connection stayed reachable until the cycle '
            . 'collector ran (collected=' . $probe['connection_gc_collected'] . '), so every '
            . 'byte it retains outlives the disconnect; evidence=' . json_encode($probe);
      }
      if ($probe['connection_gc_collected'] !== 0) {
         Vars::$labels = ['H1 connection lifetime evidence'];
         dump(json_encode($probe));
         return 'A released Connection still produced cycle-collector garbage; evidence='
            . json_encode($probe);
      }

      // ? The production teardown, driven by Connection::close() with a body
      //   attached — not by a hand-called disconnect().
      if ($probe['closed_via_transport'] !== true) {
         Vars::$labels = ['H1 transport-close evidence'];
         dump(json_encode($probe));
         return 'The transport-close leg never reached Connection::close(); evidence='
            . json_encode($probe);
      }
      if ($probe['body_alive_after_close'] !== false) {
         Vars::$labels = ['H1 transport-close evidence'];
         dump(json_encode($probe));
         return 'H1 still reproduced: the decoded Request survived Connection::close() '
            . 'without a cycle collection; evidence=' . json_encode($probe);
      }
      if ($probe['budget_free_after_close'] !== true) {
         Vars::$labels = ['H1 transport-close evidence'];
         dump(json_encode($probe));
         return 'Connection::close() did not return the retained body to the worker '
            . 'ledger; evidence=' . json_encode($probe);
      }

      return true;
   },
);
