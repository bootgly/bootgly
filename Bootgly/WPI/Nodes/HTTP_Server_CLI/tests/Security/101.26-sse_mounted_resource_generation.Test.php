<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * A resource carried into a deferred clone must belong to that clone.
 *
 * `Resources::fork()` treats two resource kinds oppositely: a definition-backed
 * name is DROPPED and rebuilt against the forked Response, while a
 * user-mounted instance is carried and re-attached immediately. `attach()`
 * rebinds the resource's Package and Request but never its Response, so a
 * carried SSE keeps pointing at the response that mounted it — while the
 * generation `defer()` opens belongs to the clone.
 *
 * `open()` then compares the exchange's live lease against a generation the
 * resource can never observe, refuses, and the client that asked for an event
 * stream receives a plain `Content-Length: 0` 200. Silent, unlogged, and
 * dependent only on whether the mount used the default name.
 *
 * Requires an observed exchange: without Telemetry no lease exists, the
 * identity comparison is skipped entirely and every shape below opens.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   /** @var array<string,string> */
   public array $wires = [];
   public int $namedCalls = 0;
   public bool $namedOpened = false;
   public int $insideCalls = 0;
   public bool $insideOpened = false;
   public int $capturedCalls = 0;
   public bool $capturedOpened = false;
};

$Connect = static function (string $hostPort) {
   $Connection = stream_socket_client(
      "tcp://{$hostPort}",
      $errorCode,
      $errorMessage,
      timeout: 5,
   );
   if ($Connection === false) {
      throw new RuntimeException(
         "L4 SSE mount connect failed: {$errorCode} {$errorMessage}"
      );
   }
   stream_set_blocking($Connection, false);

   return $Connection;
};

$Write = static function ($Connection, string $request): void {
   $offset = 0;
   while ($offset < strlen($request)) {
      $written = fwrite($Connection, substr($request, $offset));
      if ($written === false || $written === 0) {
         break;
      }
      $offset += $written;
   }

   if ($offset !== strlen($request)) {
      throw new RuntimeException('L4 SSE mount request was not sent completely.');
   }
};

$Read = static function ($Connection, float $grace = 0.30): string {
   $wire = '';
   $completeAt = null;
   $deadline = microtime(true) + 5.0;

   while (microtime(true) < $deadline) {
      $read = [$Connection];
      $write = null;
      $except = null;

      if (stream_select($read, $write, $except, 0, 50_000) > 0) {
         $chunk = fread($Connection, 65_536);
         if ($chunk === false || ($chunk === '' && feof($Connection))) {
            break;
         }
         $wire .= $chunk;
      }

      // ! An event-stream head has no Content-Length and never "completes":
      //   settle for the head plus a grace window, which is also long enough
      //   for a refused shape to reveal its finite body.
      if ($completeAt === null && str_contains($wire, "\r\n\r\n")) {
         $completeAt = microtime(true);
      }
      if ($completeAt !== null && microtime(true) - $completeAt >= $grace) {
         break;
      }
   }

   return $wire;
};

return new Test(
   description: 'A user-mounted SSE carried into a deferred clone must open, exactly like the default name',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/sse-mount/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Connect,
         $Probe,
         $Read,
         $Write,
      ): string {
         foreach (['named', 'inside', 'captured'] as $shape) {
            try {
               $Connection = $Connect($hostPort);
               $Write($Connection, "GET /l4/sse-mount/{$shape} HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n\r\n");
               $Probe->wires[$shape] = $Read($Connection);
               fclose($Connection);
            }
            catch (Throwable $Throwable) {
               if (isset($Connection) && is_resource($Connection)) {
                  fclose($Connection);
               }
               $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
            }
         }

         return "GET /l4/sse-mount/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe): Generator {
      yield $Router->route('/l4/sse-mount/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         // ! An observed exchange is what creates the lease the guard compares
         //   against. Without it this whole class of defect is invisible.
         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-SSE-MOUNT-SETUP');
      }, GET);

      // @ The defect: a user-mounted instance under a non-default name is
      //   carried into the deferred clone by fork(), still pointing at the
      //   response that mounted it.
      yield $Router->route('/l4/sse-mount/named', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Response->mount(new SSE($Response), 'events');

         return $Response->defer(static function (Response $Deferred) use (
            $Probe,
         ): void {
            $Probe->namedCalls++;
            /** @var SSE $SSE */
            $SSE = $Deferred->events;
            $SSE->heartbeat = 0;
            $SSE->open();
            $Probe->namedOpened = $SSE->opened;
         });
      }, GET);

      // ? Positive control: the definition-backed name is dropped by fork()
      //   and rebuilt against the clone, so it observes the generation.
      yield $Router->route('/l4/sse-mount/inside', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         return $Response->defer(static function (Response $Deferred) use (
            $Probe,
         ): void {
            $Probe->insideCalls++;
            $SSE = $Deferred->SSE;
            $SSE->heartbeat = 0;
            $SSE->open();
            $Probe->insideOpened = $SSE->opened;
         });
      }, GET);

      // ? Negative control that must KEEP refusing: a handle bound to the
      //   response that deferred is not bound to the clone doing the work —
      //   structurally the sibling-clone shape 101.11 pins.
      yield $Router->route('/l4/sse-mount/captured', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $SSE = $Response->SSE;

         return $Response->defer(static function (Response $Deferred) use (
            $Probe,
            $SSE,
         ): void {
            $Probe->capturedCalls++;
            $SSE->heartbeat = 0;
            $SSE->open();
            $Probe->capturedOpened = $SSE->opened;

            $Deferred(code: 202, body: 'L4-SSE-CAPTURED-202');
         });
      }, GET);

      yield $Router->route('/l4/sse-mount/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-SSE-MOUNT:' . json_encode([
            'error' => $Probe->error,
            'named_calls' => $Probe->namedCalls,
            'named_opened' => $Probe->namedOpened,
            'inside_calls' => $Probe->insideCalls,
            'inside_opened' => $Probe->insideOpened,
            'captured_calls' => $Probe->capturedCalls,
            'captured_opened' => $Probe->capturedOpened,
         ]));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      $failures = [];

      if ($Probe->error !== '') {
         return "L4 SSE mount harness error: {$Probe->error}";
      }

      // ! The defect, stated on the wire: the client asked for an event
      //   stream and must receive one.
      $named = $Probe->wires['named'] ?? '';
      if (
         str_contains($named, 'HTTP/1.1 200 OK') === false
         || str_contains($named, 'Content-Type: text/event-stream') === false
      ) {
         $failures['named'] = $named;
      }

      $inside = $Probe->wires['inside'] ?? '';
      if (
         str_contains($inside, 'HTTP/1.1 200 OK') === false
         || str_contains($inside, 'Content-Type: text/event-stream') === false
      ) {
         $failures['inside'] = $inside;
      }

      // ! Must stay refused: a head here would mean a handle bound to another
      //   response can select a competing wire.
      $captured = $Probe->wires['captured'] ?? '';
      if (
         str_contains($captured, 'Content-Type: text/event-stream')
         || str_contains($captured, 'L4-SSE-CAPTURED-202') === false
      ) {
         $failures['captured'] = $captured;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-SSE-MOUNT:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'error' => '',
         'named_calls' => 1,
         'named_opened' => true,
         'inside_calls' => 1,
         'inside_opened' => true,
         'captured_calls' => 1,
         'captured_opened' => false,
      ];
      if ($evidence !== $expected) {
         $failures['evidence'] = ['expected' => $expected, 'actual' => $evidence];
      }

      if ($failures !== []) {
         return 'L4 regression: a carried SSE resource did not observe its '
            . "deferred clone's generation. Evidence: " . json_encode($failures);
      }

      return true;
   },
);
