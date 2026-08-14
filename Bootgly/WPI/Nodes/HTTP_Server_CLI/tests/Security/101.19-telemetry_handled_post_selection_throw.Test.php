<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 post-selection Handled-Throwable regression over the live worker.
 *
 * A Handled listener selects a final out-of-band response through inline
 * deferred work or SSE. A later listener throws. The selected response must
 * remain the only wire/lifecycle and the Throwable must not kill the worker;
 * a fresh control request proves that the same fixture remains serviceable.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   /** @var array<string,string> */
   public array $wires = [];
   public int $first = 0;
   public int $throwers = 0;
   public int $sentinels = 0;
   public int $controls = 0;
   /** @var array<int,array<string,mixed>> */
   public array $contexts = [];
   /** @var array<int,Closure(Throwable,array<string,mixed>):void> */
   public array $Reporters = [];
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
         "L4 Handled-Throwable connect failed: {$errorCode} {$errorMessage}"
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
      throw new RuntimeException("L4 Handled-Throwable send failed for {$path}.");
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

      $separator = strpos($wire, "\r\n\r\n");
      if ($separator !== false && $completeAt === null) {
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
      if ($completeAt !== null && microtime(true) - $completeAt >= 0.25) {
         break;
      }
   }
   fclose($Connection);

   return $wire;
};

return new Test(
   description: 'Handled throw after deferred/SSE selection must preserve worker and one wire',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/handled-throw/setup HTTP/1.1\r\nHost: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Exchange,
         $Probe,
      ): string {
         try {
            $Probe->wires['defer'] = $Exchange(
               $hostPort,
               $testIndex,
               '/l4/handled-throw/defer?token=L4-HANDLED-SECRET',
            );
            $Probe->wires['defer_control'] = $Exchange(
               $hostPort,
               $testIndex,
               '/l4/handled-throw/control',
            );
            $Probe->wires['sse'] = $Exchange(
               $hostPort,
               $testIndex,
               '/l4/handled-throw/sse?token=L4-HANDLED-SECRET',
            );
            $Probe->wires['sse_control'] = $Exchange(
               $hostPort,
               $testIndex,
               '/l4/handled-throw/control',
            );
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/handled-throw/evidence HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/l4/handled-throw/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);
         $Probe->Reporters = Throwables::$reporters;
         Throwables::$reporters[] = static function (
            Throwable $Throwable,
            array $context,
         ) use ($Probe): void {
            if ($Throwable->getMessage() === 'L4-HANDLED-POST-SELECTION-THROW') {
               $Probe->contexts[] = $context;
            }
         };

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Emitter::$Instance->listen(
            RequestEvents::Handled,
            static function (Emission $Emission) use ($Probe): void {
               $Request = $Emission->payload[0] ?? null;
               $Response = $Emission->payload[1] ?? null;
               if (
                  $Request instanceof Request === false
                  || $Response instanceof Response === false
                  || str_starts_with($Request->URI, '/l4/handled-throw/') === false
                  || $Request->URI === '/l4/handled-throw/control'
                  || $Request->URI === '/l4/handled-throw/evidence'
               ) {
                  return;
               }

               $Probe->first++;
               if (str_starts_with($Request->URI, '/l4/handled-throw/defer')) {
                  $Response->defer(static function (Response $Deferred): void {
                     $Deferred(code: 207, body: 'L4-HANDLED-THROW-DEFER-207');
                  });
               }
               else if (str_starts_with($Request->URI, '/l4/handled-throw/sse')) {
                  $SSE = $Response->SSE;
                  $SSE->heartbeat = 0;
                  $SSE->open();
               }
            },
         );
         Emitter::$Instance->listen(
            RequestEvents::Handled,
            static function (Emission $Emission) use ($Probe): void {
               $Request = $Emission->payload[0] ?? null;
               if (
                  $Request instanceof Request
                  && (
                     str_starts_with($Request->URI, '/l4/handled-throw/defer')
                     || str_starts_with($Request->URI, '/l4/handled-throw/sse')
                  )
               ) {
                  $Probe->throwers++;
                  throw new RuntimeException('L4-HANDLED-POST-SELECTION-THROW');
               }
            },
         );
         Emitter::$Instance->listen(
            RequestEvents::Handled,
            static function (Emission $Emission) use ($Probe): void {
               $Request = $Emission->payload[0] ?? null;
               if (
                  $Request instanceof Request
                  && (
                     str_starts_with($Request->URI, '/l4/handled-throw/defer')
                     || str_starts_with($Request->URI, '/l4/handled-throw/sse')
                  )
               ) {
                  $Probe->sentinels++;
               }
            },
         );
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-HANDLED-THROW-SETUP');
      }, GET);

      yield $Router->route('/l4/handled-throw/defer', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 409, body: 'L4-HANDLED-STALE-409');
      }, GET);

      yield $Router->route('/l4/handled-throw/sse', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 409, body: 'L4-HANDLED-STALE-409');
      }, GET);

      yield $Router->route('/l4/handled-throw/control', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->controls++;

         return $Response(body: 'L4-HANDLED-THROW-CONTROL-OK');
      }, GET);

      yield $Router->route('/l4/handled-throw/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = [
            'first' => $Probe->first,
            'throwers' => $Probe->throwers,
            'sentinels' => $Probe->sentinels,
            'controls' => $Probe->controls,
            'contexts' => $Probe->contexts,
            'metrics' => $Observability === null ? null : $Snapshot($Observability),
         ];

         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }
         Throwables::$reporters = $Probe->Reporters;

         return $Response(body: 'L4-HANDLED-THROW:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-HANDLED-THROW-SETUP') === false
      ) {
         return 'L4 Handled post-selection setup failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 regression: a post-selection Handled Throwable killed the '
            . 'worker before the follow-up control. Evidence: ' . $Probe->error;
      }

      $failures = [];
      $defer = $Probe->wires['defer'] ?? '';
      if (
         substr_count($defer, 'HTTP/1.1 ') !== 1
         || str_contains($defer, 'HTTP/1.1 207 Multi-status') === false
         || str_contains($defer, 'L4-HANDLED-THROW-DEFER-207') === false
         || str_contains($defer, 'L4-HANDLED-STALE-409')
      ) {
         $failures['defer'] = $defer;
      }
      $sse = $Probe->wires['sse'] ?? '';
      if (
         substr_count($sse, 'HTTP/1.1 ') !== 1
         || str_contains($sse, 'HTTP/1.1 200 OK') === false
         || str_contains($sse, 'Content-Type: text/event-stream') === false
         || str_contains($sse, 'L4-HANDLED-STALE-409')
      ) {
         $failures['sse'] = $sse;
      }
      foreach (['defer_control', 'sse_control'] as $name) {
         $wire = $Probe->wires[$name] ?? '';
         if (
            str_contains($wire, 'HTTP/1.1 200 OK') === false
            || str_contains($wire, 'L4-HANDLED-THROW-CONTROL-OK') === false
         ) {
            $failures[$name] = $wire;
         }
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-HANDLED-THROW:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'first' => 2,
         'throwers' => 2,
         'sentinels' => 0,
         'controls' => 2,
         'contexts' => [
            [
               'interface' => 'WPI',
               'phase' => 'Handled',
               'method' => 'GET',
               'URI' => '/l4/handled-throw/defer',
               'peer' => '127.0.0.1',
            ],
            [
               'interface' => 'WPI',
               'phase' => 'Handled',
               'method' => 'GET',
               'URI' => '/l4/handled-throw/sse',
               'peer' => '127.0.0.1',
            ],
         ],
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
         return 'L4 regression: a post-selection Handled Throwable killed the '
            . 'worker or changed the selected wire/lifecycle. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
