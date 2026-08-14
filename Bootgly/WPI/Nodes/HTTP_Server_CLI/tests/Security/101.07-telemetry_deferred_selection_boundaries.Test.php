<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 response-selection boundary regressions over the live worker.
 *
 * Applications can select asynchronous output after admission by cloning the
 * Response, deferring from a Handled listener, or opening SSE inside deferred
 * work. Each leg must produce one terminal Telemetry lifecycle and exactly one
 * final response head. A duplicate head on persistent HTTP/1 shifts subsequent
 * request/response correspondence, so the wire assertion is security-relevant.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   /** @var array<string,string> */
   public array $wires = [];
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

$Exchange = static function (
   string $hostPort,
   int $testIndex,
   string $path,
): string {
   $Connection = stream_socket_client(
      "tcp://{$hostPort}",
      $errorCode,
      $errorMessage,
      timeout: 5,
   );
   if ($Connection === false) {
      throw new RuntimeException(
         "L4 selection-boundary connect failed: {$errorCode} {$errorMessage}"
      );
   }
   stream_set_blocking($Connection, false);
   $request = "GET {$path} HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
   $offset = 0;
   while ($offset < strlen($request)) {
      $written = fwrite($Connection, substr($request, $offset));
      if ($written === false || $written === 0) {
         break;
      }
      $offset += $written;
   }
   if ($offset !== strlen($request)) {
      fclose($Connection);
      throw new RuntimeException("L4 selection-boundary send failed for {$path}.");
   }

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
         if ($chunk === false || $chunk === '') {
            break;
         }
         $wire .= $chunk;
      }

      $separator = strpos($wire, "\r\n\r\n");
      if ($separator !== false && $completeAt === null) {
         $matches = [];
         if (
            preg_match(
               '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
               substr($wire, 0, $separator + 2),
               $matches,
            ) === 1
         ) {
            if (strlen($wire) >= $separator + 4 + (int) $matches[1]) {
               $completeAt = microtime(true);
            }
         }
         else {
            // SSE has no finite body. Its complete head is the selected wire.
            $completeAt = microtime(true);
         }
      }

      // ! Keep reading briefly after the first complete response so a stale
      //   normal encoder head cannot hide behind a successful async head.
      if ($completeAt !== null && microtime(true) - $completeAt >= 0.25) {
         break;
      }
   }
   fclose($Connection);

   return $wire;
};

return new Test(
   description: 'Deferred/clone/SSE selection boundaries must emit one wire and one lifecycle',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/boundary/setup HTTP/1.1\r\nHost: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Exchange,
         $Probe,
      ): string {
         try {
            foreach ([
               'clone_defer' => '/l4/boundary/clone-defer',
               'clone_sse' => '/l4/boundary/clone-sse',
               'handled_defer' => '/l4/boundary/handled-defer',
               'handled_sse' => '/l4/boundary/handled-sse',
               'callback_sse' => '/l4/boundary/deferred-sse',
            ] as $name => $path) {
               $Probe->wires[$name] = $Exchange($hostPort, $testIndex, $path);
            }
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/boundary/evidence HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/l4/boundary/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);
         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Emitter::$Instance->listen(
            RequestEvents::Handled,
            static function (Emission $Emission): void {
               $Request = $Emission->payload[0] ?? null;
               $Response = $Emission->payload[1] ?? null;
               if (
                  $Request instanceof Request === false
                  || $Response instanceof Response === false
               ) {
                  return;
               }

               if ($Request->URI === '/l4/boundary/handled-defer') {
                  $Response->defer(static function (Response $Deferred): void {
                     $Deferred(code: 207, body: 'L4-HANDLED-DEFERRED-207');
                  });
               }
               else if ($Request->URI === '/l4/boundary/handled-sse') {
                  $SSE = $Response->SSE;
                  $SSE->heartbeat = 0;
                  $SSE->open();
               }
            },
         );
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-SELECTION-BOUNDARY-SETUP');
      }, GET);

      yield $Router->route('/l4/boundary/clone-defer', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Selected = clone $Response;

         return $Selected->defer(static function (Response $Deferred): void {
            $Deferred(code: 202, body: 'L4-CLONE-DEFERRED-202');
         });
      }, GET);

      yield $Router->route('/l4/boundary/clone-sse', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $Selected = clone $Response;
         $SSE = $Selected->SSE;
         $SSE->heartbeat = 0;
         $SSE->open();

         return $Selected;
      }, GET);

      yield $Router->route('/l4/boundary/handled-defer', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-HANDLED-STALE-WIRE');
      }, GET);

      yield $Router->route('/l4/boundary/handled-sse', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-HANDLED-SSE-STALE-WIRE');
      }, GET);

      yield $Router->route('/l4/boundary/deferred-sse', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->defer(static function (Response $Deferred): void {
            $SSE = $Deferred->SSE;
            $SSE->heartbeat = 0;
            $SSE->open();
         });
      }, GET);

      yield $Router->route('/l4/boundary/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $metrics = $Observability === null ? null : $Snapshot($Observability);

         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-BOUNDARIES:' . json_encode($metrics));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-SELECTION-BOUNDARY-SETUP') === false
      ) {
         return 'L4 selection-boundary setup failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 selection-boundary fixture failed: ' . $Probe->error;
      }

      $failures = [];
      foreach ($Probe->wires as $name => $wire) {
         if (substr_count($wire, 'HTTP/1.1 ') !== 1) {
            $failures["{$name}_head_count"] = $wire;
         }
      }

      $cloneDeferred = $Probe->wires['clone_defer'] ?? '';
      if (
         str_contains($cloneDeferred, 'HTTP/1.1 202 Accepted') === false
         || str_contains($cloneDeferred, 'L4-CLONE-DEFERRED-202') === false
      ) {
         $failures['clone_defer'] = $cloneDeferred;
      }
      foreach (['clone_sse', 'handled_sse', 'callback_sse'] as $name) {
         $wire = $Probe->wires[$name] ?? '';
         if (str_contains($wire, 'Content-Type: text/event-stream') === false) {
            $failures[$name] = $wire;
         }
      }
      $handledDeferred = $Probe->wires['handled_defer'] ?? '';
      if (
         str_contains($handledDeferred, 'HTTP/1.1 207 Multi-status') === false
         || str_contains($handledDeferred, 'L4-HANDLED-DEFERRED-207') === false
         || str_contains($handledDeferred, 'L4-HANDLED-STALE-WIRE')
      ) {
         $failures['handled_defer'] = $handledDeferred;
      }
      if (str_contains($Probe->wires['handled_sse'] ?? '', 'L4-HANDLED-SSE-STALE-WIRE')) {
         $failures['handled_sse_stale'] = $Probe->wires['handled_sse'];
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-BOUNDARIES:';
      $metrics = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'requests_total' => 5,
         'in_flight' => 1,
         'duration_count' => 5,
         'responses_2xx' => 5,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      if ($metrics !== $expected) {
         $failures['metrics'] = ['expected' => $expected, 'actual' => $metrics];
      }

      if ($failures !== []) {
         return 'L4 regression: clone/defer/SSE selection did not preserve one '
            . 'terminal lifecycle and exactly one final wire. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
