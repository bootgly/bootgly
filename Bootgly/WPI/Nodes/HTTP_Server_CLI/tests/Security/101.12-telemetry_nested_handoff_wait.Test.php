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
 * L4 nested-handoff parent-wait regression.
 *
 * A nested child may suspend after it becomes the selected response. The
 * parent's generation is terminal at that handoff: a subsequent wait() on the
 * still-running parent callback must be a no-op, not park the parent Fiber and
 * compete with or cancel the selected child.
 */
$markerPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$childPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$outerPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($markerPair === false || $childPair === false || $outerPair === false) {
   foreach ([$markerPair, $childPair, $outerPair] as $pair) {
      if (is_array($pair)) {
         foreach ($pair as $socket) {
            fclose($socket);
         }
      }
   }

   throw new RuntimeException('L4 nested-handoff fixture could not create rendezvous pairs.');
}
[$markerWorker, $markerTest] = $markerPair;
[$childWorker, $childTest] = $childPair;
[$outerWorker, $outerTest] = $outerPair;

$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   public string $markers = '';
   public string $early = '';
   public string $wire = '';
   public bool $outerSuspended = false;
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

$Read = static function ($Connection): string {
   stream_set_blocking($Connection, false);
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
      if ($completeAt !== null && microtime(true) - $completeAt >= 0.20) {
         break;
      }
   }

   return $wire;
};

return new Test(
   description: 'A parent wait after nested handoff must no-op while the child remains selected',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/handoff-wait/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $childTest,
         $childWorker,
         $markerTest,
         $markerWorker,
         $outerTest,
         $outerWorker,
         $Probe,
         $Read,
      ): string {
         try {
            foreach ([$childWorker, $markerWorker, $outerWorker] as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }

            $Connection = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCode,
               $errorMessage,
               timeout: 5,
            );
            if ($Connection === false) {
               throw new RuntimeException(
                  "L4 nested-handoff connect failed: {$errorCode} {$errorMessage}"
               );
            }
            stream_set_blocking($Connection, false);
            $request = "GET /l4/handoff-wait/target HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            fwrite($Connection, $request);

            stream_set_blocking($markerTest, false);
            $deadline = microtime(true) + 3.0;
            while (
               microtime(true) < $deadline
               && str_contains($Probe->markers, "OUTER-AFTER-WAIT\n") === false
            ) {
               $chunk = fread($markerTest, 8192);
               if ($chunk !== false && $chunk !== '') {
                  $Probe->markers .= $chunk;
               }
               usleep(10000);
            }

            $Probe->outerSuspended = str_contains(
               $Probe->markers,
               "OUTER-AFTER-WAIT\n",
            ) === false;
            if ($Probe->outerSuspended) {
               // @ Let vulnerable code drain so the suite is not left with a
               //   parked parent Fiber after recording the failed boundary.
               fwrite($outerTest, 'O');
               $deadline = microtime(true) + 3.0;
               while (
                  microtime(true) < $deadline
                  && str_contains($Probe->markers, "OUTER-AFTER-WAIT\n") === false
               ) {
                  $chunk = fread($markerTest, 8192);
                  if ($chunk !== false && $chunk !== '') {
                     $Probe->markers .= $chunk;
                  }
                  usleep(10000);
               }
            }

            // ! Neither a normal parent response nor any child response may
            //   precede the explicit child release.
            $read = [$Connection];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 200000) === 1) {
               $Probe->early = (string) fread($Connection, 8192);
            }

            fwrite($childTest, 'C');
            $Probe->wire = $Probe->early . $Read($Connection);

            fclose($Connection);
            foreach ([$childTest, $markerTest, $outerTest] as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();

            // @ Wake either parked generation before closing the fixture.
            //   This keeps a failing regression from contaminating later
            //   Security cases with a retained Fiber or open rendezvous pair.
            foreach ([[$outerTest, 'O'], [$childTest, 'C']] as [$socket, $byte]) {
               if (is_resource($socket)) {
                  fwrite($socket, $byte);
               }
            }
            if (isset($Connection) && is_resource($Connection)) {
               fclose($Connection);
            }
            foreach ([$childTest, $markerTest, $outerTest] as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }
         }

         return "GET /l4/handoff-wait/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $childTest,
      $childWorker,
      $markerTest,
      $markerWorker,
      $outerTest,
      $outerWorker,
      $Probe,
      $Snapshot,
   ): Generator {
      yield $Router->route('/l4/handoff-wait/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-HANDOFF-WAIT-SETUP');
      }, GET);

      yield $Router->route('/l4/handoff-wait/target', static function (
         Request $Request,
         Response $Response,
      ) use (
         $childTest,
         $childWorker,
         $markerTest,
         $markerWorker,
         $outerTest,
         $outerWorker,
      ): Response {
         foreach ([$childTest, $markerTest, $outerTest] as $socket) {
            if (is_resource($socket)) {
               fclose($socket);
            }
         }

         return $Response->defer(static function (Response $Outer) use (
            $childWorker,
            $markerWorker,
            $outerWorker,
         ): void {
            $Outer->defer(static function (Response $Child) use (
               $childWorker,
               $markerWorker,
            ): void {
               fwrite($markerWorker, "CHILD-READY\n");
               $Child->wait($childWorker);
               if (fread($childWorker, 1) !== 'C') {
                  throw new RuntimeException(
                     'L4 nested child resumed without its release byte.'
                  );
               }
               fclose($childWorker);
               $Child(code: 202, body: 'L4-HANDOFF-CHILD-202');
            });

            fwrite($markerWorker, "OUTER-BEFORE-WAIT\n");
            $Outer->wait($outerWorker);
            fclose($outerWorker);
            fwrite($markerWorker, "OUTER-AFTER-WAIT\n");
            fclose($markerWorker);
            $Outer(code: 409, body: 'L4-HANDOFF-STALE-PARENT-409');
         });
      }, GET);

      yield $Router->route('/l4/handoff-wait/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = $Observability === null ? null : $Snapshot($Observability);

         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-HANDOFF-WAIT:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-HANDOFF-WAIT-SETUP') === false
      ) {
         return 'L4 nested-handoff wait setup/harness response failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 nested-handoff wait fixture failed: ' . $Probe->error;
      }

      $failures = [];
      if (
         $Probe->outerSuspended
         || str_contains($Probe->markers, "CHILD-READY\n") === false
         || str_contains($Probe->markers, "OUTER-BEFORE-WAIT\n") === false
         || str_contains($Probe->markers, "OUTER-AFTER-WAIT\n") === false
      ) {
         $failures['parent_wait'] = [
            'suspended' => $Probe->outerSuspended,
            'markers' => $Probe->markers,
         ];
      }
      if ($Probe->early !== '') {
         $failures['early_wire'] = $Probe->early;
      }
      if (
         substr_count($Probe->wire, 'HTTP/1.1 ') !== 1
         || str_contains($Probe->wire, 'HTTP/1.1 202 Accepted') === false
         || str_contains($Probe->wire, 'L4-HANDOFF-CHILD-202') === false
         || str_contains($Probe->wire, 'L4-HANDOFF-STALE-PARENT-409')
      ) {
         $failures['wire'] = $Probe->wire;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-HANDOFF-WAIT:';
      $metrics = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'requests_total' => 1,
         'in_flight' => 1,
         'duration_count' => 1,
         'responses_2xx' => 1,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      if ($metrics !== $expected) {
         $failures['metrics'] = ['expected' => $expected, 'actual' => $metrics];
      }

      if ($failures !== []) {
         return 'L4 regression: a terminal parent generation suspended after '
            . 'nested handoff or competed with the selected child. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
