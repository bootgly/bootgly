<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

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
 * L4 reused-Request Exchange isolation regression, on the in-order wire.
 *
 * Request A is admitted through a Telemetry-enabled Emitter and parks with an
 * active deferred generation. Its work then replaces the global Emitter with
 * an empty one, and request B is pipelined on A's connection while A is
 * parked. HTTP/1.1 answers in request order (RFC 9112 §9.3.2; H-HSC-6,
 * ENC-3): B waits undecoded behind A's park, so the connection stays silent
 * until A's explicit 202 release, and then exactly two answers leave — A's
 * 202 first, B's after it. B has no public Received listener but still
 * reuses Server::$Request, the live object whose alias A retained. It must
 * enter without inheriting A's Exchange, lazily promote only when SSE is
 * mounted, and complete that fresh Exchange as 200 — after A's terminal 202,
 * never while A is active.
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
   /** What the connection wrote while A was parked and B pipelined behind it. */
   public string $early = '';
   /** What the connection wrote once A was released. */
   public string $wire = '';
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

/**
 * Split a wire into its complete answers — a head with Content-Length takes
 * exactly that many body bytes; a head without one (B's HEAD) has no body —
 * and whatever follows the last complete one.
 *
 * @return array{0:list<array{head:string,body:string}>,1:string}
 */
$Split = static function (string $wire): array {
   $answers = [];
   $offset = 0;

   while (true) {
      $separator = strpos($wire, "\r\n\r\n", $offset);
      if ($separator === false) {
         break;
      }
      $head = substr($wire, $offset, $separator + 4 - $offset);
      $matches = [];
      $length = preg_match('/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i', $head, $matches) === 1
         ? (int) $matches[1]
         : 0;
      if (strlen($wire) < $separator + 4 + $length) {
         break;
      }
      $answers[] = ['head' => $head, 'body' => substr($wire, $separator + 4, $length)];
      $offset = $separator + 4 + $length;
   }

   return [$answers, substr($wire, $offset)];
};

/**
 * Read until `$answers` complete answers arrived, then `$window` seconds more
 * — anything written in that window is kept too. Bounded at 5 s.
 */
$Read = static function ($Connection, int $answers, float $window) use ($Split): string {
   if (is_resource($Connection) === false) {
      return '';
   }

   stream_set_blocking($Connection, false);
   $wire = '';
   $completeAt = $answers === 0 ? microtime(true) : null;
   $deadline = microtime(true) + 5.0;

   while (microtime(true) < $deadline) {
      $read = [$Connection];
      $write = null;
      $except = null;
      $ready = stream_select($read, $write, $except, 0, 20000);
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

      if ($completeAt === null && count($Split($wire)[0]) >= $answers) {
         $completeAt = microtime(true);
      }
      if ($completeAt !== null && microtime(true) - $completeAt >= $window) {
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
   description: 'A request pipelined behind a parked deferral waits for its answer, then owns an unobserved SSE Exchange',
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
            //   Emitter and parked with its original Exchange still active.
            $Probe->marker = $ReadLine($gateTest);
            if ($Probe->marker !== "L4-101.21-A-READY\n") {
               throw new RuntimeException(
                  'L4-101.21 A did not reach its observed suspension boundary: '
                  . json_encode($Probe->marker)
               );
            }

            // ! Pipeline B onto A's connection while A is parked: B waits
            //   behind A, then reuses the connection's live Request object.
            $Send(
               $ConnectionA,
               "HEAD /l4/reused-exchange/b HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n\r\n",
               'B',
            );

            // @ Nothing may be answered while A is parked: neither A nor B
            $Probe->early = $Read($ConnectionA, 0, 0.2);

            // @ Release A, then read both answers — and anything after them
            if (fwrite($gateTest, 'A') !== 1) {
               throw new RuntimeException('L4-101.21 could not release request A.');
            }
            fclose($gateTest);
            $Probe->wire = $Read($ConnectionA, 2, 0.2);
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
         //   promote a token for B without inheriting request A.
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

         // ! The fallback makes an inherited lifecycle observable on wire:
         //   inheriting A's scheduler lease rejects this SSE as a 409.
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

   test: static function (array $responses) use ($Probe, $Split): bool|string {
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
      // ? In request order: nothing leaves while A is parked — B waits for it
      if ($Probe->early !== '') {
         $failures['early'] = $Probe->early;
      }
      // ? Then exactly two answers: A's 202, then B's own SSE head (HEAD: no
      //   body), and nothing after them
      [$answers, $rest] = $Split($Probe->wire);
      $first = $answers[0] ?? ['head' => '', 'body' => ''];
      $second = $answers[1] ?? ['head' => '', 'body' => ''];
      if (
         count($answers) !== 2
         || $rest !== ''
         || substr_count($Probe->wire, 'HTTP/1.1 ') !== 2
         || str_starts_with($first['head'], "HTTP/1.1 202 Accepted\r\n") === false
         || $first['body'] !== 'L4-101.21-A-202'
         || str_starts_with($second['head'], "HTTP/1.1 200 OK\r\n") === false
         || str_contains($second['head'], "\r\nContent-Type: text/event-stream\r\n") === false
         || $second['body'] !== ''
         || str_contains($Probe->wire, 'L4-101.21-B-INHERITED')
      ) {
         $failures['wire'] = $Probe->wire;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-101.21-EVIDENCE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      // ! B runs only once A's Exchange is terminal (A's 202 observed first),
      //   and still owns a fresh, unobserved Exchange of its own
      $expected = [
         'same_request' => true,
         'unobserved_b' => true,
         'lazy_b_at_entry' => true,
         'distinct_b' => true,
         'active_a_at_b' => false,
         'active_b_at_b' => true,
         'closed_b' => true,
         'terminal_b_after_open' => true,
         'active_a_after_b' => false,
         'terminal_a_final' => true,
         'terminal_b_final' => true,
         'terminals' => [
            ['owner' => 'A', 'exchange' => true, 'code' => 202],
            ['owner' => 'B', 'exchange' => true, 'code' => 200],
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
         $evidence = json_encode($failures);

         return "L4-101.21 CONFIRMED: request B, pipelined behind parked A, was answered out of request order or did not own an independent SSE lifecycle. Evidence: {$evidence}";
      }

      return true;
   },
);
