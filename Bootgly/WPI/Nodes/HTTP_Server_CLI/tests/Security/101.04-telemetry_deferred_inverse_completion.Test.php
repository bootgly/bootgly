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
 * L4 remediation regression — two simultaneously suspended deferred exchanges
 * must remain independently correlated when they complete in reverse order.
 *
 * Request A suspends first on rendezvous A. Only then is request B sent on a
 * second connection and suspended on rendezvous B. The test releases B and
 * reads its complete 409 wire response before writing any release byte for A;
 * A then completes as 202. The final live snapshot must contain exactly one
 * duration, total and distinguishable status class for each exchange.
 */
$PairA = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($PairA === false) {
   throw new RuntimeException('L4 inverse-order regression could not create rendezvous A.');
}
[$workerA, $testA] = $PairA;

$PairB = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($PairB === false) {
   fclose($workerA);
   fclose($testA);

   throw new RuntimeException('L4 inverse-order regression could not create rendezvous B.');
}
[$workerB, $testB] = $PairB;

$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public mixed $ConnectionA = null;
   public mixed $ConnectionB = null;
   public string $readyA = '';
   public string $readyB = '';
   public string $wireA = '';
   public string $wireB = '';
   /** @var list<string> */
   public array $completionOrder = [];
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

$Send = static function (mixed $connection, string $request, string $label): void {
   if (is_resource($connection) === false) {
      throw new RuntimeException("L4 inverse-order connection {$label} is unavailable.");
   }

   $offset = 0;
   while ($offset < strlen($request)) {
      $written = fwrite($connection, substr($request, $offset));
      if ($written === false || $written === 0) {
         break;
      }
      $offset += $written;
   }
   if ($offset !== strlen($request)) {
      throw new RuntimeException("L4 inverse-order request {$label} was not sent completely.");
   }
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
   description: 'Overlapping deferred exchanges must retain Telemetry identity in inverse completion order',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/inverse/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Probe,
         $ReadLine,
         $ReadWire,
         $Send,
         $testA,
         $testB,
         $workerA,
         $workerB,
      ): string {
         // @ The master owns only the test endpoints of the inherited pairs.
         if (is_resource($workerA)) {
            fclose($workerA);
         }
         if (is_resource($workerB)) {
            fclose($workerB);
         }

         $ConnectionA = stream_socket_client(
            "tcp://{$hostPort}",
            $errorCodeA,
            $errorMessageA,
            timeout: 5,
         );
         if ($ConnectionA === false) {
            throw new RuntimeException(
               "L4 inverse-order connection A failed: {$errorCodeA} {$errorMessageA}"
            );
         }
         $Probe->ConnectionA = $ConnectionA;
         $Send(
            $ConnectionA,
            "GET /l4/inverse/a HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n",
            'A',
         );

         // ! B cannot be admitted until A proves it reached its wait point.
         $Probe->readyA = $ReadLine($testA);
         if ($Probe->readyA !== "L4-INVERSE-A-READY\n") {
            throw new RuntimeException(
               'L4 inverse-order request A did not publish its barrier: '
               . json_encode($Probe->readyA)
            );
         }

         $ConnectionB = stream_socket_client(
            "tcp://{$hostPort}",
            $errorCodeB,
            $errorMessageB,
            timeout: 5,
         );
         if ($ConnectionB === false) {
            throw new RuntimeException(
               "L4 inverse-order connection B failed: {$errorCodeB} {$errorMessageB}"
            );
         }
         $Probe->ConnectionB = $ConnectionB;
         $Send(
            $ConnectionB,
            "GET /l4/inverse/b HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n",
            'B',
         );

         // ! Both exchanges are now simultaneously suspended.
         $Probe->readyB = $ReadLine($testB);
         if ($Probe->readyB !== "L4-INVERSE-B-READY\n") {
            throw new RuntimeException(
               'L4 inverse-order request B did not publish its barrier: '
               . json_encode($Probe->readyB)
            );
         }

         // ! Complete B fully before A receives any release byte.
         if (fwrite($testB, 'B') !== 1) {
            throw new RuntimeException('L4 inverse-order request B could not be released.');
         }
         fclose($testB);
         $Probe->wireB = $ReadWire($ConnectionB);
         $Probe->completionOrder[] = 'B';
         fclose($ConnectionB);
         $Probe->ConnectionB = null;

         if (
            str_contains($Probe->wireB, 'HTTP/1.1 409 Conflict') === false
            || str_contains($Probe->wireB, 'L4-INVERSE-B-OK') === false
         ) {
            throw new RuntimeException(
               'L4 inverse-order request B did not complete before A release: '
               . json_encode($Probe->wireB)
            );
         }

         if (fwrite($testA, 'A') !== 1) {
            throw new RuntimeException('L4 inverse-order request A could not be released.');
         }
         fclose($testA);
         $Probe->wireA = $ReadWire($ConnectionA);
         $Probe->completionOrder[] = 'A';
         fclose($ConnectionA);
         $Probe->ConnectionA = null;

         return "GET /l4/inverse/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $Probe,
      $Restore,
      $Snapshot,
      $testA,
      $testB,
      $workerA,
      $workerB,
   ): Generator {
      yield $Router->route('/l4/inverse/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-INVERSE-SETUP-OK');
      }, GET);

      yield $Router->route('/l4/inverse/a', static function (
         Request $Request,
         Response $Response,
      ) use ($testA, $workerA): Response {
         return $Response->defer(static function (Response $Response) use (
            $testA,
            $workerA,
         ): void {
            if (is_resource($testA)) {
               fclose($testA);
            }
            stream_set_blocking($workerA, false);
            if (fwrite($workerA, "L4-INVERSE-A-READY\n") !== 19) {
               throw new RuntimeException('L4 inverse-order request A could not publish READY.');
            }

            $Response->wait($workerA);
            $release = fread($workerA, 1);
            fclose($workerA);
            if ($release !== 'A') {
               throw new RuntimeException('L4 inverse-order request A resumed without release A.');
            }

            $Response(code: 202, body: 'L4-INVERSE-A-OK');
         });
      }, GET);

      yield $Router->route('/l4/inverse/b', static function (
         Request $Request,
         Response $Response,
      ) use ($testB, $workerB): Response {
         return $Response->defer(static function (Response $Response) use (
            $testB,
            $workerB,
         ): void {
            if (is_resource($testB)) {
               fclose($testB);
            }
            stream_set_blocking($workerB, false);
            if (fwrite($workerB, "L4-INVERSE-B-READY\n") !== 19) {
               throw new RuntimeException('L4 inverse-order request B could not publish READY.');
            }

            $Response->wait($workerB);
            $release = fread($workerB, 1);
            fclose($workerB);
            if ($release !== 'B') {
               throw new RuntimeException('L4 inverse-order request B resumed without release B.');
            }

            $Response(code: 409, body: 'L4-INVERSE-B-OK');
         });
      }, GET);

      yield $Router->route('/l4/inverse/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Restore, $Snapshot): Response {
         $Observability = $Probe->Observability;
         if ($Observability === null) {
            $Restore();

            return $Response(code: 500, body: 'L4-INVERSE-NO-REGISTRY');
         }

         $evidence = $Snapshot($Observability);
         $Restore();

         return $Response(body: 'L4-INVERSE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (count($responses) !== 2) {
         return 'L4 inverse-order regression expected setup and evidence responses, got '
            . count($responses) . '.';
      }

      [$setup, $evidenceWire] = $responses;

      if (
         str_contains($setup, 'HTTP/1.1 200 OK') === false
         || str_contains($setup, 'L4-INVERSE-SETUP-OK') === false
         || $Probe->readyA !== "L4-INVERSE-A-READY\n"
         || $Probe->readyB !== "L4-INVERSE-B-READY\n"
         || $Probe->completionOrder !== ['B', 'A']
         || str_contains($Probe->wireB, 'HTTP/1.1 409 Conflict') === false
         || str_contains($Probe->wireB, 'L4-INVERSE-B-OK') === false
         || str_contains($Probe->wireA, 'HTTP/1.1 202 Accepted') === false
         || str_contains($Probe->wireA, 'L4-INVERSE-A-OK') === false
      ) {
         Vars::$labels = ['L4 inverse deferred completion wire/barrier controls'];
         dump(json_encode([
            'setup' => $setup,
            'ready_a' => $Probe->readyA,
            'ready_b' => $Probe->readyB,
            'completion_order' => $Probe->completionOrder,
            'wire_b' => $Probe->wireB,
            'wire_a' => $Probe->wireA,
         ]));

         return 'L4 inverse-order regression did not prove simultaneous suspension '
            . 'followed by complete B-before-A production responses.';
      }

      $separator = strpos($evidenceWire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($evidenceWire, $separator + 4);
      $prefix = 'L4-INVERSE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'requests_total' => 2,
         'in_flight' => 1,
         'duration_count' => 2,
         'responses_2xx' => 1,
         'responses_4xx' => 1,
         'responses_5xx' => 0,
      ];
      if ($evidence !== $expected) {
         Vars::$labels = ['L4 inverse deferred completion Telemetry evidence'];
         dump(json_encode([
            'expected' => $expected,
            'actual' => $evidence,
         ]));

         return 'L4 regression: inverse deferred completion did not close each '
            . 'exchange exactly once with its own status class. Evidence: '
            . json_encode([
               'expected' => $expected,
               'actual' => $evidence,
            ]);
      }

      return true;
   },
);
