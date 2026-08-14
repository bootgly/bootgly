<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 stale-Response clone SSE regression.
 *
 * A retained Response clone keeps the original HTTP/1.1 transport. Once its
 * exchange has completed, opening a lazily mounted SSE resource must not write
 * a late head onto that transport after the same keep-alive connection starts
 * its next request. A live ordinary clone created inside deferred work remains
 * the positive control: it must select one SSE head without a parent head. A
 * sibling cloned before the parent defer cannot start competing deferred work
 * from inside the running parent callback.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public null|Response $Stale = null;
   public string $error = '';
   /** @var array<string,string> */
   public array $wires = [];
   public int $staleCalls = 0;
   public bool $staleOpened = false;
   public int $activeCalls = 0;
   public bool $activeOpened = false;
   public int $siblingCalls = 0;
   public int $siblingWork = 0;
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses = ['2xx' => 0, '4xx' => 0, '5xx' => 0];
   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      $class = $series['labels']['class'] ?? null;
      if (is_string($class) && array_key_exists($class, $responses)) {
         $responses[$class] = (int) ($series['value'] ?? 0);
      }
   }

   return [
      'requests_total' => (int) ($metrics['http_requests_total']['series'][0]['value'] ?? 0),
      'in_flight' => (int) ($metrics['http_requests_in_flight']['series'][0]['value'] ?? 0),
      'duration_count' => (int) (
         $metrics['http_request_duration_seconds']['series'][0]['count'] ?? 0
      ),
      'responses_2xx' => $responses['2xx'],
      'responses_4xx' => $responses['4xx'],
      'responses_5xx' => $responses['5xx'],
   ];
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
         "L4 stale-clone SSE connect failed: {$errorCode} {$errorMessage}"
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
      throw new RuntimeException('L4 stale-clone SSE request was not sent completely.');
   }
};

$Read = static function ($Connection, float $grace = 0.0): string {
   $wire = '';
   $completeAt = null;
   $deadline = microtime(true) + 5.0;

   while (microtime(true) < $deadline) {
      $read = [$Connection];
      $write = null;
      $except = null;
      $ready = stream_select($read, $write, $except, 0, 50000);
      if ($ready === false) {
         break;
      }
      if ($ready === 1) {
         $chunk = fread($Connection, 8192);
         if ($chunk === false) {
            break;
         }
         if ($chunk !== '') {
            $wire .= $chunk;
         }
         else if (feof($Connection)) {
            break;
         }
      }

      if ($completeAt === null) {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator !== false) {
            $matches = [];
            $finite = preg_match(
               '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
               substr($wire, 0, $separator + 2),
               $matches,
            ) === 1;
            if (
               $finite === false
               || strlen($wire) >= $separator + 4 + (int) $matches[1]
            ) {
               $completeAt = microtime(true);
            }
         }
      }

      // ! A grace read is essential: a stale SSE head can precede or follow
      //   the legitimate second response and must remain visible to the PoC.
      if ($completeAt !== null && microtime(true) - $completeAt >= $grace) {
         break;
      }
   }

   return $wire;
};

return new Test(
   description: 'A stale Response clone must not emit SSE onto a reused HTTP/1.1 connection',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/stale-sse/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Connect,
         $Probe,
         $Read,
         $Write,
      ): string {
         try {
            $Connection = $Connect($hostPort);
            $request = "GET /l4/stale-sse/retain HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['persistent_first'] = $Read($Connection);

            // ! The exact same socket now owns another admitted exchange.
            //   Its handler invokes SSE through the retained old Response.
            $request = "GET /l4/stale-sse/next HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['persistent_second'] = $Read($Connection, 0.30);
            fclose($Connection);

            // ! A sibling clone made before its parent defers is not linked to
            //   the parent's scheduler generation. Even when invoked from the
            //   running callback, it cannot select a competing wire.
            $Connection = $Connect($hostPort);
            $request = "GET /l4/stale-sse/sibling HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['sibling'] = $Read($Connection, 0.30);
            fclose($Connection);

            // ? Positive control: an active ordinary clone inside deferred
            //   work is allowed to select SSE, but only one head may appear.
            $Connection = $Connect($hostPort);
            $request = "GET /l4/stale-sse/active HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['active_clone'] = $Read($Connection, 0.30);
            fclose($Connection);
         }
         catch (Throwable $Throwable) {
            if (isset($Connection) && is_resource($Connection)) {
               fclose($Connection);
            }
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/stale-sse/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/l4/stale-sse/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-STALE-SSE-SETUP');
      }, GET);

      yield $Router->route('/l4/stale-sse/retain', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         // ! Deliberately retain a clone whose Package still points at this
         //   persistent transport. The SSE resource is mounted only later.
         $Probe->Stale = clone $Response;

         return $Response(code: 201, body: 'L4-STALE-SSE-FIRST-201');
      }, GET);

      yield $Router->route('/l4/stale-sse/next', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Stale = $Probe->Stale;
         if ($Stale === null) {
            return $Response(code: 500, body: 'L4-STALE-SSE-MISSING-CLONE');
         }

         $Probe->staleCalls++;
         $SSE = $Stale->SSE;
         $SSE->heartbeat = 0;
         $SSE->open();
         $Probe->staleOpened = $SSE->opened;

         return $Response(code: 202, body: 'L4-STALE-SSE-SECOND-202');
      }, GET);

      yield $Router->route('/l4/stale-sse/active', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         return $Response->defer(static function (Response $Deferred) use (
            $Probe,
         ): void {
            $Selected = clone $Deferred;
            $Probe->activeCalls++;
            $SSE = $Selected->SSE;
            $SSE->heartbeat = 0;
            $SSE->open();
            $Probe->activeOpened = $SSE->opened;
         });
      }, GET);

      yield $Router->route('/l4/stale-sse/sibling', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Sibling = clone $Response;

         return $Response->defer(static function (Response $Deferred) use (
            $Probe,
            $Sibling,
         ): void {
            $Probe->siblingCalls++;
            $Sibling->defer(static function (Response $Competing) use (
               $Probe,
            ): void {
               $Probe->siblingWork++;
               $Competing(code: 409, body: 'L4-SIBLING-COMPETING-409');
            });

            $Deferred(code: 202, body: 'L4-SIBLING-PARENT-202');
         });
      }, GET);

      yield $Router->route('/l4/stale-sse/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = [
            'stale_calls' => $Probe->staleCalls,
            'stale_opened' => $Probe->staleOpened,
            'active_calls' => $Probe->activeCalls,
            'active_opened' => $Probe->activeOpened,
            'sibling_calls' => $Probe->siblingCalls,
            'sibling_work' => $Probe->siblingWork,
            'metrics' => $Observability === null ? null : $Snapshot($Observability),
         ];

         $Probe->Stale = null;
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-STALE-SSE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-STALE-SSE-SETUP') === false
      ) {
         return 'L4 stale-clone SSE setup/harness response failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 stale-clone SSE fixture failed: ' . $Probe->error;
      }

      $failures = [];
      $first = $Probe->wires['persistent_first'] ?? '';
      if (
         substr_count($first, 'HTTP/1.1 ') !== 1
         || str_contains($first, 'HTTP/1.1 201 Created') === false
         || str_contains($first, 'L4-STALE-SSE-FIRST-201') === false
      ) {
         $failures['persistent_first'] = $first;
      }

      $second = $Probe->wires['persistent_second'] ?? '';
      if (
         substr_count($second, 'HTTP/1.1 ') !== 1
         || str_contains($second, 'HTTP/1.1 202 Accepted') === false
         || str_contains($second, 'L4-STALE-SSE-SECOND-202') === false
         || str_contains($second, 'Content-Type: text/event-stream')
      ) {
         $failures['persistent_second'] = $second;
      }

      $active = $Probe->wires['active_clone'] ?? '';
      if (
         substr_count($active, 'HTTP/1.1 ') !== 1
         || str_contains($active, 'HTTP/1.1 200 OK') === false
         || str_contains($active, 'Content-Type: text/event-stream') === false
      ) {
         $failures['active_clone'] = $active;
      }

      $sibling = $Probe->wires['sibling'] ?? '';
      if (
         substr_count($sibling, 'HTTP/1.1 ') !== 1
         || str_contains($sibling, 'HTTP/1.1 202 Accepted') === false
         || str_contains($sibling, 'L4-SIBLING-PARENT-202') === false
         || str_contains($sibling, 'L4-SIBLING-COMPETING-409')
      ) {
         $failures['sibling'] = $sibling;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-STALE-SSE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'stale_calls' => 1,
         'stale_opened' => false,
         'active_calls' => 1,
         'active_opened' => true,
         'sibling_calls' => 1,
         'sibling_work' => 0,
         'metrics' => [
            'requests_total' => 4,
            'in_flight' => 1,
            'duration_count' => 4,
            'responses_2xx' => 4,
            'responses_4xx' => 0,
            'responses_5xx' => 0,
         ],
      ];
      if ($evidence !== $expected) {
         $failures['evidence'] = ['expected' => $expected, 'actual' => $evidence];
      }

      if ($failures !== []) {
         return 'L4 regression: a retained stale Response clone emitted onto '
            . 'a reused connection, or an active deferred clone selected more '
            . 'than one head/lifecycle. Evidence: ' . json_encode($failures);
      }

      return true;
   },
);
