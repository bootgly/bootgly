<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 reused-Request Exchange isolation regression.
 *
 * Request A is admitted through a Telemetry-enabled Emitter and suspends with
 * an active deferred generation. Its callback then replaces the global Emitter
 * with an empty one before request B reaches the same worker. B therefore has
 * no public Received listener, but still reuses Server::$Request. It must enter
 * without inheriting A, lazily promote only when SSE is mounted, complete that
 * fresh Exchange as 200, and leave A active until its explicit 202 release.
 */
$GatePair = stream_socket_pair(
   STREAM_PF_UNIX,
   STREAM_SOCK_STREAM,
   STREAM_IPPROTO_IP,
);
if ($GatePair === false) {
   throw new RuntimeException('L4-101.21 could not create its deferred rendezvous pair.');
}
[$gateWorker, $gateTest] = $GatePair;

$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public null|Exchange $ExchangeA = null;
   public null|Exchange $ExchangeB = null;
   public mixed $ConnectionA = null;
   public string $error = '';
   public string $marker = '';
   public string $earlyA = '';
   public string $wireA = '';
   public string $wireB = '';
   public int $requestA = 0;
   public int $requestB = 0;
   public bool $unobservedB = false;
   public bool $lazyBAtEntry = false;
   public bool $distinctB = false;
   public bool $activeAAtB = false;
   public bool $activeAAfterB = false;
   public bool $activeBAtB = false;
   public bool $terminalBAfterOpen = false;
   public bool $closedB = false;
   /** @var list<array{owner:string,exchange:bool,code:null|int}> */
   public array $terminals = [];
};

$Send = static function ($Connection, string $request, string $label): void {
   if (is_resource($Connection) === false) {
      throw new RuntimeException("L4-101.21 connection {$label} is unavailable.");
   }

   $offset = 0;
   while ($offset < strlen($request)) {
      $written = fwrite($Connection, substr($request, $offset));
      if ($written === false || $written === 0) {
         break;
      }
      $offset += $written;
   }
   if ($offset !== strlen($request)) {
      throw new RuntimeException("L4-101.21 request {$label} was not sent completely.");
   }
};

$ReadLine = static function ($Connection): string {
   if (is_resource($Connection) === false) {
      return '';
   }

   stream_set_blocking($Connection, true);
   stream_set_timeout($Connection, 10);
   $line = '';
   while (str_contains($line, "\n") === false) {
      $chunk = fread($Connection, 8192);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $line .= $chunk;
   }

   return $line;
};

$Read = static function ($Connection, float $grace = 0.0): string {
   if (is_resource($Connection) === false) {
      return '';
   }

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

      if ($completeAt !== null && microtime(true) - $completeAt >= $grace) {
         break;
      }
   }

   return $wire;
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

return new Test(
   description: 'A reused Request must not carry an active observed Exchange into an unobserved SSE',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/reused-exchange/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $gateTest,
         $gateWorker,
         $Probe,
         $Read,
         $ReadLine,
         $Send,
      ): string {
         if (is_resource($gateWorker)) {
            fclose($gateWorker);
         }

         try {
            $ConnectionA = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCodeA,
               $errorMessageA,
               timeout: 5,
            );
            if ($ConnectionA === false) {
               throw new RuntimeException(
                  "L4-101.21 connection A failed: {$errorCodeA} {$errorMessageA}"
               );
            }
            $Probe->ConnectionA = $ConnectionA;
            $Send(
               $ConnectionA,
               "GET /l4/reused-exchange/a HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n\r\n",
               'A',
            );

            // ! The marker is written only after A replaced the worker-global
            //   Emitter and suspended with its original Exchange still active.
            $Probe->marker = $ReadLine($gateTest);
            if ($Probe->marker !== "L4-101.21-A-READY\n") {
               throw new RuntimeException(
                  'L4-101.21 A did not reach its observed suspension boundary: '
                  . json_encode($Probe->marker)
               );
            }

            // ! Pipeline B onto A's still-open transport. The connection's
            //   decoded Request is the exact live object whose alias A retained.
            $Send(
               $ConnectionA,
               "HEAD /l4/reused-exchange/b HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n\r\n",
               'B',
            );
            $Probe->wireB = $Read($ConnectionA);

            // ! A must remain completely silent until its own release.
            stream_set_blocking($ConnectionA, false);
            $read = [$ConnectionA];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, 200000) === 1) {
               $Probe->earlyA = (string) fread($ConnectionA, 8192);
            }

            if (fwrite($gateTest, 'A') !== 1) {
               throw new RuntimeException('L4-101.21 could not release request A.');
            }
            fclose($gateTest);
            $Probe->wireA = $Probe->earlyA . $Read($ConnectionA, 0.05);
            fclose($ConnectionA);
            $Probe->ConnectionA = null;
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();

            if (is_resource($gateTest)) {
               @fwrite($gateTest, 'A');
               fclose($gateTest);
            }
            if (isset($ConnectionA) && is_resource($ConnectionA)) {
               fclose($ConnectionA);
            }
            $Probe->ConnectionA = null;
         }

         return "GET /l4/reused-exchange/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($gateTest, $gateWorker, $Probe, $Snapshot): Generator {
      yield $Router->route('/l4/reused-exchange/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-101.21-SETUP');
      }, GET);

      yield $Router->route('/l4/reused-exchange/a', static function (
         Request $Request,
         Response $Response,
      ) use ($gateTest, $gateWorker, $Probe): Response {
         $Probe->requestA = spl_object_id($Request);
         $ExchangeA = Exchange::fetch($Request);
         $Probe->ExchangeA = $ExchangeA;
         $ExchangeA?->observe(static function (
            Exchange $Observed,
            null|int $code,
         ) use ($ExchangeA, $Probe): void {
            $Probe->terminals[] = [
               'owner' => 'A',
               'exchange' => $Observed === $ExchangeA,
               'code' => $code,
            ];
         });

         // ! Launch A from a retained ordinary clone while returning the
         //   persistent source. The source itself never sets `deferred`; its
         //   lazy escape marker must still detach A's live Request alias when
         //   unobserved request B reuses that object.
         $Escaped = clone $Response;
         $Escaped->defer(static function (Response $Deferred) use (
            $gateTest,
            $gateWorker,
         ): void {
            if (is_resource($gateTest)) {
               fclose($gateTest);
            }

            // ! B is deliberately unobserved and arrives only after this
            //   process-global transition, while A retains its captured alias.
            Emitter::$Instance = new Emitter;
            stream_set_blocking($gateWorker, false);
            $marker = "L4-101.21-A-READY\n";
            if (fwrite($gateWorker, $marker) !== strlen($marker)) {
               throw new RuntimeException('L4-101.21 A could not publish its barrier.');
            }

            $Deferred->wait($gateWorker);
            $release = fread($gateWorker, 1);
            fclose($gateWorker);
            if ($release !== 'A') {
               throw new RuntimeException('L4-101.21 A resumed without its release byte.');
            }

            $Deferred(code: 202, body: 'L4-101.21-A-202');
         });

         return $Response;
      }, GET);

      yield $Router->route('/l4/reused-exchange/b', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->requestB = spl_object_id($Request);
         $Probe->unobservedB = Emitter::$Instance->check(RequestEvents::Received) === false;
         $ExchangeA = $Probe->ExchangeA;
         $Probe->lazyBAtEntry = Exchange::fetch($Request) === null;

         // @ SSE is B's first lifecycle-sensitive operation. Mounting it must
         //   promote a token for B without inheriting suspended request A.
         $Response(code: 409, body: 'L4-101.21-B-INHERITED');
         $SSE = $Response->SSE;
         $ExchangeB = Exchange::fetch($Response);
         $Probe->ExchangeB = $ExchangeB;
         $Probe->distinctB = $ExchangeA !== null
            && $ExchangeB !== null
            && $ExchangeB !== $ExchangeA;
         $Probe->activeAAtB = $ExchangeA !== null && $ExchangeA->check() === false;
         $Probe->activeBAtB = $ExchangeB !== null && $ExchangeB->check() === false;
         $ExchangeB?->observe(static function (
            Exchange $Observed,
            null|int $code,
         ) use ($ExchangeB, $Probe): void {
            $Probe->terminals[] = [
               'owner' => 'B',
               'exchange' => $Observed === $ExchangeB,
               'code' => $code,
            ];
         });

         // ! The fallback makes the vulnerable boundary observable on wire:
         //   inheriting A's active scheduler lease rejects this SSE as a 409.
         $SSE->heartbeat = 0;
         $SSE->open();
         $Probe->closedB = $SSE->closed;
         $Probe->terminalBAfterOpen = $ExchangeB?->check() ?? false;
         $Probe->activeAAfterB = $ExchangeA !== null && $ExchangeA->check() === false;

         return $Response;
      }, HEAD);

      yield $Router->route('/l4/reused-exchange/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = [
            'same_request' => $Probe->requestA !== 0
               && $Probe->requestA === $Probe->requestB,
            'unobserved_b' => $Probe->unobservedB,
            'lazy_b_at_entry' => $Probe->lazyBAtEntry,
            'distinct_b' => $Probe->distinctB,
            'active_a_at_b' => $Probe->activeAAtB,
            'active_b_at_b' => $Probe->activeBAtB,
            'closed_b' => $Probe->closedB,
            'terminal_b_after_open' => $Probe->terminalBAfterOpen,
            'active_a_after_b' => $Probe->activeAAfterB,
            'terminal_a_final' => $Probe->ExchangeA?->check() ?? false,
            'terminal_b_final' => $Probe->ExchangeB?->check() ?? false,
            'terminals' => $Probe->terminals,
            'metrics' => $Observability === null ? null : $Snapshot($Observability),
         ];

         $Probe->ExchangeA = null;
         $Probe->ExchangeB = null;
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-101.21-EVIDENCE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-101.21-SETUP') === false
      ) {
         return 'L4-101.21 control failed: native setup response missing.';
      }
      if ($Probe->error !== '') {
         return 'L4-101.21 fixture failed: ' . $Probe->error;
      }

      $failures = [];
      if ($Probe->marker !== "L4-101.21-A-READY\n") {
         $failures['barrier'] = $Probe->marker;
      }
      if ($Probe->earlyA !== '') {
         $failures['early_a'] = $Probe->earlyA;
      }
      if (
         substr_count($Probe->wireB, 'HTTP/1.1 ') !== 1
         || str_contains($Probe->wireB, 'HTTP/1.1 200 OK') === false
         || str_contains($Probe->wireB, 'Content-Type: text/event-stream') === false
         || str_contains($Probe->wireB, 'L4-101.21-B-INHERITED')
      ) {
         $failures['wire_b'] = $Probe->wireB;
      }
      if (
         substr_count($Probe->wireA, 'HTTP/1.1 ') !== 1
         || str_contains($Probe->wireA, 'HTTP/1.1 202 Accepted') === false
         || str_contains($Probe->wireA, 'L4-101.21-A-202') === false
      ) {
         $failures['wire_a'] = $Probe->wireA;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-101.21-EVIDENCE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'same_request' => true,
         'unobserved_b' => true,
         'lazy_b_at_entry' => true,
         'distinct_b' => true,
         'active_a_at_b' => true,
         'active_b_at_b' => true,
         'closed_b' => true,
         'terminal_b_after_open' => true,
         'active_a_after_b' => true,
         'terminal_a_final' => true,
         'terminal_b_final' => true,
         'terminals' => [
            ['owner' => 'B', 'exchange' => true, 'code' => 200],
            ['owner' => 'A', 'exchange' => true, 'code' => 202],
         ],
         'metrics' => [
            'requests_total' => 1,
            'in_flight' => 0,
            'duration_count' => 1,
            'responses_2xx' => 1,
            'responses_4xx' => 0,
            'responses_5xx' => 0,
         ],
      ];
      if ($evidence !== $expected) {
         $failures['lifecycle'] = ['expected' => $expected, 'actual' => $evidence];
      }

      if ($failures !== []) {
         return 'L4-101.21 CONFIRMED: an unobserved request reused A\'s active '
            . 'Exchange instead of owning an independent SSE lifecycle. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
