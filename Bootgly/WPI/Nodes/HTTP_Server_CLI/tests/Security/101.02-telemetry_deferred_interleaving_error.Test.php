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
 * L4 remediation regression — terminal Telemetry accounting must remain
 * correlated when a synchronous request completes inside a suspended deferred
 * lifecycle, and a deferred exception must close exactly once as a 5xx.
 *
 * Request A runs on a side connection and cannot resume until request B has
 * completed on the harness connection. The first evidence route therefore
 * observes a causally ordered A-received, B-completed, A-completed lifecycle.
 * A second deferred route then suspends once and deliberately throws; a socket
 * marker proves the callback reached that exact post-resume throw point before
 * the Catcher-produced 500 and final metric snapshot are accepted.
 */
$interleavePair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($interleavePair === false) {
   throw new RuntimeException('L4 regression could not create the interleaving rendezvous pair.');
}
[$interleaveWorker, $interleaveTest] = $interleavePair;

$errorPair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($errorPair === false) {
   fclose($interleaveWorker);
   fclose($interleaveTest);

   throw new RuntimeException('L4 regression could not create the error-control marker pair.');
}
[$errorWorker, $errorTest] = $errorPair;

$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public mixed $connection = null;
   public string $deferredWire = '';
   public string $errorMarker = '';
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

$ReadWire = static function (mixed $connection): string {
   if (is_resource($connection) === false) {
      return '';
   }

   stream_set_blocking($connection, true);
   stream_set_timeout($connection, 10);
   $wire = '';

   while (true) {
      $chunk = fread($connection, 8192);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $wire .= $chunk;

      $separator = strpos($wire, "\r\n\r\n");
      if ($separator === false) {
         continue;
      }

      $matches = [];
      if (
         preg_match(
            '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
            substr($wire, 0, $separator + 2),
            $matches,
         ) === 1
         && strlen($wire) - $separator - 4 >= (int) $matches[1]
      ) {
         break;
      }
   }

   return $wire;
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
   description: 'Interleaved and errored deferred responses must close Telemetry exactly once',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/regression/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $interleaveWorker,
         $interleaveTest,
         $Probe,
      ): string {
         // @ The master owns only the test endpoint of this inherited pair.
         if (is_resource($interleaveWorker)) {
            fclose($interleaveWorker);
         }

         $connection = stream_socket_client(
            "tcp://{$hostPort}",
            $errorCode,
            $errorMessage,
            timeout: 5,
         );
         if ($connection === false) {
            throw new RuntimeException(
               "L4 regression could not open deferred request A: {$errorCode} {$errorMessage}"
            );
         }
         $Probe->connection = $connection;

         $requestA = "GET /l4/regression/deferred-a HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
         $offset = 0;
         while ($offset < strlen($requestA)) {
            $written = fwrite($connection, substr($requestA, $offset));
            if ($written === false || $written === 0) {
               break;
            }
            $offset += $written;
         }
         if ($offset !== strlen($requestA)) {
            throw new RuntimeException('L4 regression did not send deferred request A completely.');
         }

         // ! B is not admitted until A proves it is suspended in deferred work.
         stream_set_blocking($interleaveTest, true);
         stream_set_timeout($interleaveTest, 10);
         $ready = '';
         while (str_contains($ready, "\n") === false) {
            $chunk = fread($interleaveTest, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $ready .= $chunk;
         }
         if ($ready !== "L4-A-READY\n") {
            throw new RuntimeException(
               'L4 regression did not observe request A at its suspension point: '
               . json_encode($ready)
            );
         }

         return "GET /l4/regression/sync-b HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function () use (
         $interleaveTest,
         $Probe,
         $ReadWire,
      ): string {
         // ! This closure runs only after the harness received B's 418.
         if (is_resource($interleaveTest) === false || fwrite($interleaveTest, 'R') !== 1) {
            throw new RuntimeException('L4 regression could not release deferred request A after B.');
         }
         fclose($interleaveTest);

         $Probe->deferredWire = $ReadWire($Probe->connection);
         if (is_resource($Probe->connection)) {
            fclose($Probe->connection);
         }
         $Probe->connection = null;

         return "GET /l4/regression/interleave-evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function () use ($errorWorker): string {
         // @ The master owns only the test endpoint of the error marker pair.
         if (is_resource($errorWorker)) {
            fclose($errorWorker);
         }

         return "GET /l4/regression/deferred-error HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },

      static function () use ($errorTest, $Probe): string {
         // ! Prove the intentional deferred callback resumed and reached throw.
         stream_set_blocking($errorTest, true);
         stream_set_timeout($errorTest, 10);
         while (str_contains($Probe->errorMarker, "\n") === false) {
            $chunk = fread($errorTest, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $Probe->errorMarker .= $chunk;
         }
         fclose($errorTest);

         return "GET /l4/regression/error-evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $errorTest,
      $errorWorker,
      $interleaveTest,
      $interleaveWorker,
      $Probe,
      $Restore,
      $Snapshot,
   ): Generator {
      yield $Router->route('/l4/regression/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();

         // ! The setup call remains on the test encoder and its captured bus.
         //   Every measured request after it traverses the production encoder.
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-REGRESSION-SETUP-OK');
      }, GET);

      yield $Router->route('/l4/regression/deferred-a', static function (
         Request $Request,
         Response $Response,
      ) use ($interleaveTest, $interleaveWorker): Response {
         return $Response->defer(static function (Response $Response) use (
            $interleaveTest,
            $interleaveWorker,
         ): void {
            // @ The worker owns only the worker endpoint of the inherited pair.
            if (is_resource($interleaveTest)) {
               fclose($interleaveTest);
            }
            stream_set_blocking($interleaveWorker, false);
            if (fwrite($interleaveWorker, "L4-A-READY\n") !== 11) {
               throw new RuntimeException('L4 request A could not publish its suspension marker.');
            }

            $Response->wait($interleaveWorker);
            $release = fread($interleaveWorker, 1);
            fclose($interleaveWorker);
            if ($release !== 'R') {
               throw new RuntimeException('L4 request A resumed without its causal release marker.');
            }

            $Response(code: 202, body: 'L4-DEFERRED-A-OK');
         });
      }, GET);

      yield $Router->route('/l4/regression/sync-b', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 418, body: 'L4-SYNC-B-OK');
      }, GET);

      yield $Router->route('/l4/regression/interleave-evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         if ($Probe->Observability === null) {
            return $Response(code: 500, body: 'L4-INTERLEAVE-NO-REGISTRY');
         }

         return $Response(
            body: 'L4-INTERLEAVE:' . json_encode($Snapshot($Probe->Observability))
         );
      }, GET);

      yield $Router->route('/l4/regression/deferred-error', static function (
         Request $Request,
         Response $Response,
      ) use ($errorTest, $errorWorker): Response {
         return $Response->defer(static function (Response $Response) use (
            $errorTest,
            $errorWorker,
         ): void {
            if (is_resource($errorTest)) {
               fclose($errorTest);
            }

            $Response->wait();

            if (fwrite($errorWorker, "L4-ERROR-CALLBACK\n") !== 18) {
               throw new RuntimeException('L4 deferred error callback could not publish its marker.');
            }
            fclose($errorWorker);

            throw new RuntimeException('L4 intentional deferred terminal error.');
         });
      }, GET);

      yield $Router->route('/l4/regression/error-evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Restore, $Snapshot): Response {
         $Observability = $Probe->Observability;
         if ($Observability === null) {
            $Restore();

            return $Response(code: 500, body: 'L4-ERROR-NO-REGISTRY');
         }

         $evidence = $Snapshot($Observability);

         // @ Current Encoder_/Emitter locals finish this response consistently;
         //   suite globals are clean before the following Security case starts.
         $Restore();

         return $Response(body: 'L4-ERROR:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (count($responses) !== 5) {
         return 'L4 regression expected five harness responses, got '
            . count($responses) . '.';
      }

      [$setup, $syncB, $interleaveWire, $errored, $errorWire] = $responses;
      $deferredA = $Probe->deferredWire;

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
         || str_contains($setup, 'L4-REGRESSION-SETUP-OK') === false
         || str_contains($syncB, "HTTP/1.1 418 I'm a teapot") === false
         || str_contains($syncB, 'L4-SYNC-B-OK') === false
         || str_contains($deferredA, 'HTTP/1.1 202 Accepted') === false
         || str_contains($deferredA, 'L4-DEFERRED-A-OK') === false
      ) {
         Vars::$labels = ['L4 interleaved production-wire controls'];
         dump(json_encode([
            'setup' => $setup,
            'sync_b' => $syncB,
            'deferred_a' => $deferredA,
         ]));

         return 'L4 regression did not prove the setup, synchronous B, and '
            . 'deferred A routes over production wire.';
      }

      $interleave = $Decode($interleaveWire, 'L4-INTERLEAVE:');
      $expectedInterleave = [
         'requests_total' => 2,
         'in_flight' => 1,
         'duration_count' => 2,
         'responses_2xx' => 1,
         'responses_4xx' => 1,
         'responses_5xx' => 0,
      ];
      if ($interleave !== $expectedInterleave) {
         Vars::$labels = ['L4 interleaved deferred/synchronous Telemetry evidence'];
         dump(json_encode([
            'expected' => $expectedInterleave,
            'actual' => $interleave,
         ]));

         return 'L4 regression: deferred A and synchronous B did not close two '
            . 'distinct Telemetry lifecycles exactly once. Evidence: '
            . json_encode([
               'expected' => $expectedInterleave,
               'actual' => $interleave,
            ]);
      }

      if (
         $Probe->errorMarker !== "L4-ERROR-CALLBACK\n"
         || str_contains($errored, 'HTTP/1.1 500 Internal Server Error') === false
      ) {
         Vars::$labels = ['L4 deferred terminal-error controls'];
         dump(json_encode([
            'marker' => $Probe->errorMarker,
            'wire' => $errored,
         ]));

         return 'L4 regression did not prove the deferred callback resumed, '
            . 'threw intentionally, and traversed the Catcher 500 path.';
      }

      $error = $Decode($errorWire, 'L4-ERROR:');
      $expectedError = [
         'requests_total' => 4,
         'in_flight' => 1,
         'duration_count' => 4,
         'responses_2xx' => 2,
         'responses_4xx' => 1,
         'responses_5xx' => 1,
      ];
      if ($error !== $expectedError) {
         Vars::$labels = ['L4 deferred terminal-error Telemetry evidence'];
         dump(json_encode([
            'interleaved' => $interleave,
            'expected' => $expectedError,
            'actual' => $error,
         ]));

         return 'L4 regression: the deferred Catcher 500 did not close exactly '
            . 'one duration, gauge, request-total and 5xx lifecycle. Evidence: '
            . json_encode([
               'expected' => $expectedError,
               'actual' => $error,
            ]);
      }

      return true;
   },
);
