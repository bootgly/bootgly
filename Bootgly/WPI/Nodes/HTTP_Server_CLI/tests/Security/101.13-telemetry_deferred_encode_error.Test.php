<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 deferred-serialization error regression.
 *
 * A deferred generation must not terminalize as its application status before
 * encode() succeeds. When serialization throws, Catcher selects one 500 wire
 * and Telemetry records that same 5xx exactly once.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public null|Response $Response = null;
   public string $error = '';
   public string $wire = '';
   public int $handlers = 0;
   public int $encodes = 0;
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

$Exchange = static function (string $hostPort, int $testIndex): string {
   $Connection = stream_socket_client(
      "tcp://{$hostPort}",
      $errorCode,
      $errorMessage,
      timeout: 5,
   );
   if ($Connection === false) {
      throw new RuntimeException(
         "L4 deferred encode-error connect failed: {$errorCode} {$errorMessage}"
      );
   }
   stream_set_blocking($Connection, false);

   $request = "GET /l4/deferred-encode-error/target HTTP/1.1\r\n"
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
      throw new RuntimeException('L4 deferred encode-error request was not sent completely.');
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
            && strlen($wire) >= $separator + 4 + (int) $matches[1]
         ) {
            $completeAt = microtime(true);
         }
      }
      // ! Observe long enough to catch a stale application head following
      //   the Catcher response (or vice versa).
      if ($completeAt !== null && microtime(true) - $completeAt >= 0.25) {
         break;
      }
   }
   fclose($Connection);

   return $wire;
};

return new Test(
   description: 'Deferred encode failure must select one Catcher 500 lifecycle and wire',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/deferred-encode-error/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Exchange,
         $Probe,
      ): string {
         try {
            $Probe->wire = $Exchange($hostPort, $testIndex);
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/deferred-encode-error/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/l4/deferred-encode-error/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);
         $Probe->Response = Server::$Response;

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;
         $Explosive = new class extends Response {
            public null|object $State = null;

            public function encode (Packages $Package, null|int &$length): string
            {
               if ($this->Body->raw === 'L4-DEFERRED-ENCODE-THROW') {
                  $State = $this->State;
                  if ($State !== null) {
                     $State->encodes++;
                  }
                  throw new RuntimeException('L4-DEFERRED-ENCODE-THROW');
               }

               return parent::encode($Package, $length);
            }
         };
         $Explosive->State = $Probe;
         Server::$Response = $Explosive;

         return $Explosive(body: 'L4-DEFERRED-ENCODE-SETUP');
      }, GET);

      yield $Router->route('/l4/deferred-encode-error/target', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->handlers++;

         return $Response->defer(static function (Response $Deferred): void {
            $Deferred(code: 201, body: 'L4-DEFERRED-ENCODE-THROW');
         });
      }, GET);

      yield $Router->route('/l4/deferred-encode-error/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = [
            'handlers' => $Probe->handlers,
            'encodes' => $Probe->encodes,
            'metrics' => $Observability === null ? null : $Snapshot($Observability),
         ];

         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }
         if ($Probe->Response !== null) {
            Server::$Response = $Probe->Response;
         }

         return $Response(body: 'L4-DEFERRED-ENCODE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-DEFERRED-ENCODE-SETUP') === false
      ) {
         return 'L4 deferred encode-error setup/harness response failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 deferred encode-error fixture failed: ' . $Probe->error;
      }

      $failures = [];
      if (
         substr_count($Probe->wire, 'HTTP/1.1 ') !== 1
         || str_contains(
            $Probe->wire,
            'HTTP/1.1 500 Internal Server Error',
         ) === false
         || str_contains($Probe->wire, 'L4-DEFERRED-ENCODE-THROW')
      ) {
         $failures['wire'] = $Probe->wire;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-DEFERRED-ENCODE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'handlers' => 1,
         'encodes' => 1,
         'metrics' => [
            'requests_total' => 1,
            'in_flight' => 1,
            'duration_count' => 1,
            'responses_2xx' => 0,
            'responses_4xx' => 0,
            'responses_5xx' => 1,
         ],
      ];
      if ($evidence !== $expected) {
         $failures['evidence'] = ['expected' => $expected, 'actual' => $evidence];
      }

      if ($failures !== []) {
         return 'L4 regression: deferred serialization failure did not select '
            . 'exactly one Catcher 500 wire and matching 5xx lifecycle. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
