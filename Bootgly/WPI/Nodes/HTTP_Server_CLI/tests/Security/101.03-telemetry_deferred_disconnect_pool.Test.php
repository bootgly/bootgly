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
 * L4 remediation regression — closing an HTTP/1 peer must terminalize core
 * Telemetry while its deferred Fiber is still parked on an unrelated socket.
 * No response status may be invented for that transport cancellation.
 *
 * The client half-closes only after the Fiber publishes its identity and
 * suspension marker, then waits for server EOF before requesting evidence on a
 * different connection. The dependency remains untouched until after that
 * snapshot. Releasing it later must not resume the dropped callback. Two fresh
 * deferred requests then prove that the worker can still pool and reuse one
 * Fiber with independent exchange tokens and exact production-wire accounting.
 */
$dependencyPair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($dependencyPair === false) {
   throw new RuntimeException('L4 disconnect regression could not create its dependency pair.');
}
[$dependencyWorker, $dependencyTest] = $dependencyPair;

$markerPair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($markerPair === false) {
   fclose($dependencyWorker);
   fclose($dependencyTest);

   throw new RuntimeException('L4 disconnect regression could not create its Fiber marker pair.');
}
[$markerWorker, $markerTest] = $markerPair;

$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public bool $disconnectAcknowledged = false;
   public string $disconnectWire = '';
   public string $firstMarker = '';
   public string $primeMarker = '';
   public string $reuseMarker = '';
   /** @var resource|null */
   public mixed $dependency = null;
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses = ['2xx' => null, '4xx' => null, '5xx' => null];

   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      $class = $series['labels']['class'] ?? null;
      if (is_string($class) && array_key_exists($class, $responses)) {
         $responses[$class] = $series['value'] ?? null;
      }
   }

   return [
      'requests_total' => $metrics['http_requests_total']['series'][0]['value'] ?? null,
      'in_flight' => $metrics['http_requests_in_flight']['series'][0]['value'] ?? null,
      'duration_count' => $metrics['http_request_duration_seconds']['series'][0]['count'] ?? null,
      'responses_2xx' => $responses['2xx'],
      'responses_4xx' => $responses['4xx'],
      'responses_5xx' => $responses['5xx'],
   ];
};

$ReadLine = static function (mixed $connection): string {
   if (is_resource($connection) === false) {
      return '';
   }

   stream_set_blocking($connection, true);
   stream_set_timeout($connection, 10);
   $line = '';

   while (str_contains($line, "\n") === false) {
      $chunk = fread($connection, 8192);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $line .= $chunk;
   }

   return $line;
};

$Restore = static function () use ($Probe): void {
   if ($Probe->Emitter !== null) {
      Emitter::$Instance = $Probe->Emitter;
   }
   if ($Probe->Encoder !== null) {
      Server::$Encoder = $Probe->Encoder;
   }
};

return new Test(
   description: 'Peer disconnect must close deferred Telemetry before dependency release and Fiber reuse',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/disconnect/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $dependencyWorker,
         $markerWorker,
         $markerTest,
         $Probe,
         $ReadLine,
      ): string {
         // @ The master owns the peer endpoints, never the worker endpoints.
         if (is_resource($dependencyWorker)) {
            fclose($dependencyWorker);
         }
         if (is_resource($markerWorker)) {
            fclose($markerWorker);
         }

         $connection = stream_socket_client(
            "tcp://{$hostPort}",
            $errorCode,
            $errorMessage,
            timeout: 5,
         );
         if ($connection === false) {
            throw new RuntimeException(
               "L4 disconnect regression could not open its side client: "
               . "{$errorCode} {$errorMessage}"
            );
         }

         $request = "GET /l4/disconnect/parked HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
         $offset = 0;
         while ($offset < strlen($request)) {
            $written = fwrite($connection, substr($request, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }
         if ($offset !== strlen($request)) {
            fclose($connection);
            throw new RuntimeException('L4 disconnect regression did not send its side request.');
         }

         // ! The marker is emitted immediately before wait(dependency), proving
         //   both Received and the unrelated-socket suspension happened first.
         $Probe->firstMarker = $ReadLine($markerTest);
         if (preg_match('/^FIRST:\d+\n$/', $Probe->firstMarker) !== 1) {
            fclose($connection);
            throw new RuntimeException(
               'L4 disconnect regression did not observe the parked Fiber: '
               . json_encode($Probe->firstMarker)
            );
         }

         // ! Half-close and wait for server EOF. This acknowledges that
         //   Connection::close() ran before the evidence request is admitted,
         //   while no byte has been written to the dependency endpoint.
         if (stream_socket_shutdown($connection, STREAM_SHUT_WR) === false) {
            fclose($connection);
            throw new RuntimeException('L4 disconnect regression could not half-close its client.');
         }
         stream_set_blocking($connection, true);
         stream_set_timeout($connection, 10);

         while (feof($connection) === false) {
            $chunk = fread($connection, 8192);
            if ($chunk === false) {
               break;
            }
            if ($chunk === '') {
               $metadata = stream_get_meta_data($connection);
               if (($metadata['timed_out'] ?? false) === true || feof($connection)) {
                  break;
               }
               continue;
            }
            $Probe->disconnectWire .= $chunk;
         }
         $Probe->disconnectAcknowledged = feof($connection);
         fclose($connection);

         if ($Probe->disconnectAcknowledged === false) {
            throw new RuntimeException(
               'L4 disconnect regression did not receive server EOF before evidence.'
            );
         }

         return "GET /l4/disconnect/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function () use (
         $dependencyTest,
      ): string {
         // ! This runs after disconnect evidence. Only now may the unrelated
         //   dependency become ready. A correctly dropped Fiber never resumes.
         if (is_resource($dependencyTest) === false || fwrite($dependencyTest, 'R') !== 1) {
            throw new RuntimeException('L4 disconnect regression could not release its dependency.');
         }
         fclose($dependencyTest);

         return "GET /l4/disconnect/pool-prime HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function () use ($markerTest, $Probe, $ReadLine): string {
         $Probe->primeMarker = $ReadLine($markerTest);
         if (preg_match('/^PRIME:\d+\n$/', $Probe->primeMarker) !== 1) {
            throw new RuntimeException(
               'L4 disconnect regression did not observe a clean pool-prime job: '
               . json_encode($Probe->primeMarker)
            );
         }

         return "GET /l4/disconnect/pool-reuse HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function () use ($markerTest, $Probe, $ReadLine): string {
         $Probe->reuseMarker = $ReadLine($markerTest);
         fclose($markerTest);

         return "GET /l4/disconnect/final-evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $dependencyTest,
      $dependencyWorker,
      $markerTest,
      $markerWorker,
      $Probe,
      $Restore,
      $Snapshot,
   ): Generator {
      yield $Router->route('/l4/disconnect/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-DISCONNECT-SETUP-OK');
      }, GET);

      yield $Router->route('/l4/disconnect/parked', static function (
         Request $Request,
         Response $Response,
      ) use (
         $dependencyTest,
         $dependencyWorker,
         $markerTest,
         $markerWorker,
         $Probe,
      ): Response {
         // ! Keep the worker endpoint rooted independently from the cancelled
         //   Fiber. GC may collect the abandoned Fiber before the master
         //   releases the dependency; the negative-resume control must not
         //   turn into an incidental EPIPE race.
         $Probe->dependency = $dependencyWorker;

         return $Response->defer(static function (Response $Response) use (
            $dependencyTest,
            $dependencyWorker,
            $markerTest,
            $markerWorker,
         ): void {
            if (is_resource($dependencyTest)) {
               fclose($dependencyTest);
            }
            if (is_resource($markerTest)) {
               fclose($markerTest);
            }

            $Fiber = Fiber::getCurrent();
            if ($Fiber === null) {
               throw new RuntimeException('L4 disconnect callback was not running in a Fiber.');
            }
            $FiberID = spl_object_id($Fiber);
            if (fwrite($markerWorker, "FIRST:{$FiberID}\n") === false) {
               throw new RuntimeException('L4 disconnect callback could not publish its Fiber ID.');
            }

            $Response->wait($dependencyWorker);
            $release = fread($dependencyWorker, 1);
            fclose($dependencyWorker);
            if ($release !== 'R') {
               throw new RuntimeException('L4 abandoned callback resumed without dependency release.');
            }
            if (fwrite($markerWorker, "FIRST-DONE:{$FiberID}\n") === false) {
               throw new RuntimeException('L4 abandoned callback could not publish cleanup progress.');
            }

            // @ No client exists for this response. The already-cancelled
            //   exchange must ignore this later status selection.
            $Response(code: 202, body: 'L4-ABANDONED-LATE');
         });
      }, GET);

      yield $Router->route('/l4/disconnect/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         if ($Probe->Observability === null) {
            return $Response(code: 500, body: 'L4-DISCONNECT-NO-REGISTRY');
         }

         return $Response(
            body: 'L4-DISCONNECT:' . json_encode($Snapshot($Probe->Observability))
         );
      }, GET);

      yield $Router->route('/l4/disconnect/pool-prime', static function (
         Request $Request,
         Response $Response,
      ) use ($markerWorker): Response {
         return $Response->defer(static function (Response $Response) use ($markerWorker): void {
            $Fiber = Fiber::getCurrent();
            if ($Fiber === null) {
               throw new RuntimeException('L4 pool-prime callback was not running in a Fiber.');
            }
            $FiberID = spl_object_id($Fiber);
            if (fwrite($markerWorker, "PRIME:{$FiberID}\n") === false) {
               throw new RuntimeException('L4 pool-prime callback could not publish its Fiber ID.');
            }

            $Response->wait();
            $Response(code: 206, body: 'L4-POOL-PRIME-OK');
         });
      }, GET);

      yield $Router->route('/l4/disconnect/pool-reuse', static function (
         Request $Request,
         Response $Response,
      ) use ($markerWorker): Response {
         return $Response->defer(static function (Response $Response) use ($markerWorker): void {
            $Fiber = Fiber::getCurrent();
            if ($Fiber === null) {
               throw new RuntimeException('L4 reuse callback was not running in a Fiber.');
            }
            $FiberID = spl_object_id($Fiber);
            if (fwrite($markerWorker, "REUSE:{$FiberID}\n") === false) {
               throw new RuntimeException('L4 reuse callback could not publish its Fiber ID.');
            }
            fclose($markerWorker);

            $Response->wait();
            $Response(code: 207, body: 'L4-POOLED-REUSE-OK');
         });
      }, GET);

      yield $Router->route('/l4/disconnect/final-evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Restore, $Snapshot): Response {
         // ! The cancelled Fiber can no longer run its post-wait cleanup.
         //   Close the worker's dependency endpoint explicitly after the
         //   negative-resume and pool-reuse evidence has been collected.
         if (is_resource($Probe->dependency)) {
            fclose($Probe->dependency);
         }
         $Probe->dependency = null;

         $Observability = $Probe->Observability;
         if ($Observability === null) {
            $Restore();

            return $Response(code: 500, body: 'L4-POOL-NO-REGISTRY');
         }

         $evidence = $Snapshot($Observability);
         $Restore();

         return $Response(body: 'L4-POOL:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (count($responses) !== 5) {
         return 'L4 disconnect regression expected five harness responses, got '
            . count($responses) . '.';
      }

      [$setup, $disconnectWire, $prime, $reuse, $poolWire] = $responses;

      $Decode = static function (string $wire, string $prefix): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $body = substr($wire, $separator + 4);
         if (str_starts_with($body, $prefix) === false) {
            return null;
         }

         $decoded = json_decode(substr($body, strlen($prefix)), true);

         return is_array($decoded) ? $decoded : null;
      };

      if (
         str_contains($setup, 'HTTP/1.1 200 OK') === false
         || str_contains($setup, 'L4-DISCONNECT-SETUP-OK') === false
         || $Probe->disconnectAcknowledged === false
         || $Probe->disconnectWire !== ''
      ) {
         Vars::$labels = ['L4 peer-disconnect transport controls'];
         dump(json_encode([
            'setup' => $setup,
            'disconnect_acknowledged' => $Probe->disconnectAcknowledged,
            'unexpected_disconnect_wire' => $Probe->disconnectWire,
         ]));

         return 'L4 disconnect regression did not prove a response-free server '
            . 'close after the deferred callback suspended.';
      }

      $disconnect = $Decode($disconnectWire, 'L4-DISCONNECT:');
      $expectedDisconnect = [
         'requests_total' => 1,
         'in_flight' => 1,
         'duration_count' => 1,
         'responses_2xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      if ($disconnect !== $expectedDisconnect) {
         Vars::$labels = ['L4 deferred peer-disconnect Telemetry evidence'];
         dump(json_encode([
            'expected' => $expectedDisconnect,
            'actual' => $disconnect,
         ]));

         return 'L4 regression: peer disconnect did not close total, duration '
            . 'and in-flight accounting without inventing a status. Evidence: '
            . json_encode([
               'expected' => $expectedDisconnect,
               'actual' => $disconnect,
            ]);
      }

      $first = [];
      $primed = [];
      $reused = [];
      $markersValid = preg_match('/^FIRST:(\d+)\n$/', $Probe->firstMarker, $first) === 1
         && preg_match('/^PRIME:(\d+)\n$/', $Probe->primeMarker, $primed) === 1
         && preg_match('/^REUSE:(\d+)\n$/', $Probe->reuseMarker, $reused) === 1;
      if (
         $markersValid === false
         || ($primed[1] ?? null) !== ($reused[1] ?? null)
         || str_contains($prime, 'HTTP/1.1 206 Partial Content') === false
         || str_contains($prime, 'L4-POOL-PRIME-OK') === false
         || str_contains($reuse, 'HTTP/1.1 207 Multi-status') === false
         || str_contains($reuse, 'L4-POOLED-REUSE-OK') === false
      ) {
         Vars::$labels = ['L4 pooled deferred Fiber reuse controls'];
         dump(json_encode([
            'first' => $Probe->firstMarker,
            'primed' => $Probe->primeMarker,
            'reused' => $Probe->reuseMarker,
            'prime_wire' => $prime,
            'wire' => $reuse,
         ]));

         return 'L4 disconnect regression did not prove that cancellation dropped '
            . 'the parked callback and later jobs pooled/reused a clean Fiber.';
      }

      $pool = $Decode($poolWire, 'L4-POOL:');
      $expectedPool = [
         'requests_total' => 4,
         'in_flight' => 1,
         'duration_count' => 4,
         'responses_2xx' => 3,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      if ($pool !== $expectedPool) {
         Vars::$labels = ['L4 pooled Fiber exchange-isolation evidence'];
         dump(json_encode([
            'disconnect' => $disconnect,
            'expected' => $expectedPool,
            'actual' => $pool,
         ]));

         return 'L4 regression: pooled Fiber reuse leaked or duplicated the '
            . 'cancelled exchange token. Evidence: ' . json_encode([
               'expected' => $expectedPool,
               'actual' => $pool,
            ]);
      }

      return true;
   },
);
