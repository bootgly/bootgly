<?php

use Bootgly\ABI\Debugging\Data\Vars;
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
 * L4 nested-defer and pooled-Fiber generation regression.
 *
 * Request A starts an outer deferred job; that job selects a nested child
 * which waits on its own readiness socket. Once the outer Fiber parks, request
 * B starts another deferred job and deterministically reuses that newest pooled
 * Fiber. Completing A's child must neither emit A's stale parent response nor
 * let A's old terminal observer cancel B's new generation.
 */
$markerPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$childPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$siblingPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($markerPair === false || $childPair === false || $siblingPair === false) {
   foreach ([$markerPair, $childPair, $siblingPair] as $pair) {
      if (is_array($pair)) {
         foreach ($pair as $socket) {
            fclose($socket);
         }
      }
   }

   throw new RuntimeException('L4 nested-defer fixture could not create rendezvous pairs.');
}
[$markerWorker, $markerTest] = $markerPair;
[$childWorker, $childTest] = $childPair;
[$siblingWorker, $siblingTest] = $siblingPair;

$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   public string $markers = '';
   public string $earlyA = '';
   public string $wireA = '';
   public string $wireB = '';
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses2xx = 0;
   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      if (($series['labels']['class'] ?? null) === '2xx') {
         $responses2xx = (int) ($series['value'] ?? 0);
         break;
      }
   }

   return [
      'requests_total' => (int) ($metrics['http_requests_total']['series'][0]['value'] ?? 0),
      'in_flight' => (int) ($metrics['http_requests_in_flight']['series'][0]['value'] ?? 0),
      'duration_count' => (int) (
         $metrics['http_request_duration_seconds']['series'][0]['count'] ?? 0
      ),
      'responses_2xx' => $responses2xx,
   ];
};

$Read = static function ($connection, float $seconds = 5.0): string {
   stream_set_blocking($connection, false);
   $wire = '';
   $deadline = microtime(true) + $seconds;

   while (microtime(true) < $deadline) {
      $read = [$connection];
      $write = null;
      $except = null;
      $remaining = max(0.0, $deadline - microtime(true));
      $secondsLeft = (int) $remaining;
      $microseconds = (int) (($remaining - $secondsLeft) * 1_000_000);
      $ready = stream_select($read, $write, $except, $secondsLeft, $microseconds);
      if ($ready === false || $ready === 0) {
         break;
      }

      $chunk = fread($connection, 8192);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $wire .= $chunk;

      $offset = 0;
      $complete = true;
      while ($offset < strlen($wire)) {
         $separator = strpos($wire, "\r\n\r\n", $offset);
         if ($separator === false) {
            $complete = false;
            break;
         }
         $matches = [];
         if (
            preg_match(
               '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
               substr($wire, $offset, $separator - $offset + 2),
               $matches,
            ) !== 1
         ) {
            $complete = false;
            break;
         }
         $next = $separator + 4 + (int) $matches[1];
         if (strlen($wire) < $next) {
            $complete = false;
            break;
         }
         $offset = $next;
      }
      if ($complete && $offset === strlen($wire)) {
         // @ Briefly leave room for an illicit second final response.
         $extraRead = [$connection];
         $extraWrite = null;
         $extraExcept = null;
         if (stream_select($extraRead, $extraWrite, $extraExcept, 0, 150000) === 0) {
            break;
         }
      }
   }

   return $wire;
};

return new Test(
   description: 'Nested defer must select one child without cancelling a reused Fiber generation',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/nested/setup HTTP/1.1\r\nHost: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $childTest,
         $childWorker,
         $markerTest,
         $markerWorker,
         $Probe,
         $Read,
         $siblingTest,
         $siblingWorker,
      ): string {
         try {
            // @ The master owns only the test endpoints after the suite fork.
            foreach ([$childWorker, $markerWorker, $siblingWorker] as $socket) {
               if (is_resource($socket)) {
                  fclose($socket);
               }
            }

            $ConnectionA = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCode,
               $errorMessage,
               timeout: 5,
            );
            if ($ConnectionA === false) {
               throw new RuntimeException(
                  "L4 nested request A connect failed: {$errorCode} {$errorMessage}"
               );
            }
            $requestA = "GET /l4/nested/a HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            fwrite($ConnectionA, $requestA);

            stream_set_blocking($markerTest, false);
            $deadline = microtime(true) + 5.0;
            while (
               microtime(true) < $deadline
               && (
                  str_contains($Probe->markers, "A-CHILD-READY\n") === false
                  || str_contains($Probe->markers, "A-OUTER-DONE\n") === false
               )
            ) {
               $chunk = fread($markerTest, 8192);
               if ($chunk !== false && $chunk !== '') {
                  $Probe->markers .= $chunk;
               }
               usleep(10000);
            }
            if (
               str_contains($Probe->markers, "A-CHILD-READY\n") === false
               || str_contains($Probe->markers, "A-OUTER-DONE\n") === false
            ) {
               throw new RuntimeException(
                  'Nested request A did not reach both child-ready and outer-done boundaries.'
               );
            }

            // ! Secure code emits nothing until the selected child completes.
            //   Vulnerable code has already serialized the stale outer 409.
            $read = [$ConnectionA];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 200000) === 1) {
               $Probe->earlyA = (string) fread($ConnectionA, 8192);
            }

            // @ The outer A Fiber is now the most recently parked pool entry;
            //   B deterministically reuses it for a new generation.
            $ConnectionB = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCode,
               $errorMessage,
               timeout: 5,
            );
            if ($ConnectionB === false) {
               throw new RuntimeException(
                  "L4 sibling request B connect failed: {$errorCode} {$errorMessage}"
               );
            }
            $requestB = "GET /l4/nested/b HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            fwrite($ConnectionB, $requestB);

            $deadline = microtime(true) + 5.0;
            while (
               microtime(true) < $deadline
               && str_contains($Probe->markers, "B-READY\n") === false
            ) {
               $chunk = fread($markerTest, 8192);
               if ($chunk !== false && $chunk !== '') {
                  $Probe->markers .= $chunk;
               }
               usleep(10000);
            }
            if (str_contains($Probe->markers, "B-READY\n") === false) {
               throw new RuntimeException('Sibling B never occupied its deferred Fiber generation.');
            }

            // ! Completing child A triggers every observer installed during A.
            //   None may drop the pooled Fiber now executing suspended B.
            fwrite($childTest, 'A');
            $Probe->wireA = $Probe->earlyA . $Read($ConnectionA);
            fwrite($siblingTest, 'B');
            $Probe->wireB = $Read($ConnectionB);

            fclose($ConnectionA);
            fclose($ConnectionB);
            fclose($childTest);
            fclose($siblingTest);
            fclose($markerTest);
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/nested/evidence HTTP/1.1\r\nHost: localhost\r\n\r\n";
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
      $Probe,
      $siblingTest,
      $siblingWorker,
      $Snapshot,
   ): Generator {
      yield $Router->route('/l4/nested/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);
         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-NESTED-SETUP');
      }, GET);

      yield $Router->route('/l4/nested/a', static function (
         Request $Request,
         Response $Response,
      ) use (
         $childTest,
         $childWorker,
         $markerTest,
         $markerWorker,
      ): Response {
         foreach ([$childTest, $markerTest] as $socket) {
            if (is_resource($socket)) {
               fclose($socket);
            }
         }

         return $Response->defer(static function (Response $Outer) use (
            $childWorker,
            $markerWorker,
         ): void {
            $Outer->defer(static function (Response $Child) use (
               $childWorker,
               $markerWorker,
            ): void {
               fwrite($markerWorker, "A-CHILD-READY\n");
               $Child->wait($childWorker);
               if (fread($childWorker, 1) !== 'A') {
                  throw new RuntimeException('Nested child A resumed without its release byte.');
               }
               fclose($childWorker);
               $Child(code: 202, body: 'L4-NESTED-CHILD-A');
            });

            fwrite($markerWorker, "A-OUTER-DONE\n");
            $Outer(code: 409, body: 'L4-NESTED-STALE-PARENT');
         });
      }, GET);

      yield $Router->route('/l4/nested/b', static function (
         Request $Request,
         Response $Response,
      ) use (
         $markerTest,
         $markerWorker,
         $siblingTest,
         $siblingWorker,
      ): Response {
         foreach ([$markerTest, $siblingTest] as $socket) {
            if (is_resource($socket)) {
               fclose($socket);
            }
         }

         return $Response->defer(static function (Response $Deferred) use (
            $markerWorker,
            $siblingWorker,
         ): void {
            fwrite($markerWorker, "B-READY\n");
            fclose($markerWorker);
            $Deferred->wait($siblingWorker);
            if (fread($siblingWorker, 1) !== 'B') {
               throw new RuntimeException('Sibling B resumed without its release byte.');
            }
            fclose($siblingWorker);
            $Deferred(code: 201, body: 'L4-REUSED-GENERATION-B');
         });
      }, GET);

      yield $Router->route('/l4/nested/evidence', static function (
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

         return $Response(body: 'L4-NESTED:' . json_encode($metrics));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-NESTED-SETUP') === false
      ) {
         return 'L4 nested-defer setup failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 nested-defer fixture failed: ' . $Probe->error;
      }

      $failures = [];
      if ($Probe->earlyA !== '') {
         $failures['early_parent_wire'] = $Probe->earlyA;
      }
      if (
         substr_count($Probe->wireA, 'HTTP/1.1 ') !== 1
         || str_contains($Probe->wireA, 'HTTP/1.1 202 Accepted') === false
         || str_contains($Probe->wireA, 'L4-NESTED-CHILD-A') === false
         || str_contains($Probe->wireA, 'L4-NESTED-STALE-PARENT')
      ) {
         $failures['nested_a_wire'] = $Probe->wireA;
      }
      if (
         substr_count($Probe->wireB, 'HTTP/1.1 ') !== 1
         || str_contains($Probe->wireB, 'HTTP/1.1 201 Created') === false
         || str_contains($Probe->wireB, 'L4-REUSED-GENERATION-B') === false
      ) {
         $failures['generation_b_wire'] = $Probe->wireB;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-NESTED:';
      $metrics = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'requests_total' => 2,
         'in_flight' => 1,
         'duration_count' => 2,
         'responses_2xx' => 2,
      ];
      if ($metrics !== $expected) {
         $failures['metrics'] = ['expected' => $expected, 'actual' => $metrics];
      }

      if ($failures !== []) {
         Vars::$labels = ['L4 nested-defer/Fiber-generation evidence'];
         dump(json_encode($failures));

         return 'L4 regression: nested defer emitted a stale parent or an old '
            . 'observer cancelled a reused Fiber generation. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
