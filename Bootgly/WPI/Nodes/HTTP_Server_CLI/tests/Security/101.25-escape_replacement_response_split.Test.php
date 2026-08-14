<?php

use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Escape-detection blindness when the handler returns a replacement Response.
 *
 * The encoder aliases its local to the worker-persistent static
 * (`$Response = &Server::$Response`). When a handler returns a Response other
 * than the injected one, the encoder assigns it through that alias WITHOUT
 * breaking it first — unlike the 503 branch, whose comment states the hazard
 * explicitly. The static therefore becomes the replacement.
 *
 * The escape gate afterwards reads
 * `$Response->deferred || $Response->cacheable === false
 *  || Server::$Response->cacheable === false`.
 * After the alias write, the second and third operands are the SAME object, so
 * the third — the one that exists precisely to catch "the escape happened on
 * the injected Response but the handler returned another one" — can never fire.
 *
 * Measured on ONE keep-alive connection carrying TWO pipelined requests, which
 * is the only shape that distinguishes a genuine suppression from a second
 * write that `Connection: close` happened to swallow: a correct server answers
 * with exactly two responses, a splitting server with three, and the client
 * consumes the stale one as the answer to the following request.
 */
$Probe = new class {
   // # Worker side (the `response:` routes run in the server process)
   public int $handlers = 0;
   public int $work = 0;
   /** @var array<int,array<string,mixed>> One entry per escape-route call. */
   public array $preconditions = [];
   public null|Encoder $Encoder = null;

   // # Test side (the `requests:` closures run in the test process)
   /** @var array<string,string> Raw pipelined wire, per encoder leg. */
   public array $wires = ['testing' => '', 'production' => ''];
   public string $error = '';
};

/**
 * Drive the escape + replacement shape on ONE keep-alive connection carrying
 * TWO pipelined requests, and hand the raw wire back to the probe.
 *
 * Pipelining is the only shape that distinguishes a genuine suppression from a
 * second write that `Connection: close` happened to swallow: a correct server
 * answers two requests with two responses, a splitting server with three, and
 * the client consumes the stale one as the answer to the following request.
 */
$Pipeline = static function (
   object $Probe,
   string $leg,
   string $hostPort,
   int $testIndex,
): void {
   try {
      $Connection = stream_socket_client(
         "tcp://{$hostPort}",
         $errorCode,
         $errorMessage,
         timeout: 5,
      );
      if ($Connection === false) {
         throw new RuntimeException(
            "L4 split connect failed: {$errorCode} {$errorMessage}"
         );
      }

      // ! The positional test slot must ride BOTH requests, or the server
      //   rejects the second one.
      fwrite(
         $Connection,
         "GET /l4/split/replacement HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n\r\n"
         . "GET /l4/split/echo HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Connection: close\r\n\r\n"
      );

      stream_set_blocking($Connection, false);
      $deadline = microtime(true) + 5.0;
      while (microtime(true) < $deadline) {
         $chunk = fread($Connection, 65535);
         if ($chunk !== false && $chunk !== '') {
            $Probe->wires[$leg] .= $chunk;

            continue;
         }
         if (feof($Connection)) {
            break;
         }
         usleep(2000);
      }
      fclose($Connection);
   }
   catch (Throwable $Throwable) {
      $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
   }
};

return new Test(
   description: 'A deferred escape plus a returned replacement Response must not desynchronise a pipelined connection',
   Separator: new Separator(line: true),

   requests: [
      // ! Leg A — the suite's default Encoder_Testing. The pipelined pair rides
      //   a private socket as a SIDE EFFECT; the harness always sends whatever
      //   the closure RETURNS, so the returned request is the encoder switch
      //   that sets leg B up.
      static function (string $hostPort, int $testIndex) use ($Probe, $Pipeline): string {
         $Pipeline($Probe, 'testing', $hostPort, $testIndex);

         return "GET /l4/split/promote HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },

      // ! Leg B — the production Encoder_, which the switch above installed.
      //   Both encoders carry the same gate, so both need the same proof.
      static function (string $hostPort, int $testIndex) use ($Probe, $Pipeline): string {
         $Pipeline($Probe, 'production', $hostPort, $testIndex);

         return "GET /l4/split/evidence HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe): Generator {
      yield $Router->route('/l4/split/replacement', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->handlers++;

         // ! Precondition. This finding is about the UNOBSERVED configuration:
         //   with an admitted Exchange the gate resolves the lifecycle through
         //   `Exchange::fetch($Request)` and the hole is closed. If a previous
         //   case left this worker observed, the leg must report that instead
         //   of reporting a false SECURE.
         $Probe->preconditions[] = [
            'encoder' => Server::$Encoder::class,
            'exchange_admitted' => Exchange::fetch($Request) !== null,
            'injected_is_static' => $Response === Server::$Response,
            'injected_cacheable' => $Response->cacheable,
         ];

         // @ Escape #1 — on the INJECTED Response. Work completes inline inside
         //   Fiber::start(), so `Response::loop()` encodes and writes it here.
         $Response->defer(static function (Response $Deferred) use ($Probe): void {
            $Probe->work++;

            $Deferred(code: 200, body: 'L4-SPLIT-DEFERRED');
         });

         // @ The handler then returns a DIFFERENT Response object. This is what
         //   the alias write replaces the static with.
         return new Response(code: 201, body: 'L4-SPLIT-SYNC');
      }, GET);

      // @ Pipelined tail. Its only job is to occupy the second slot on the
      //   connection, so an extra response from the first request is observable
      //   as a status-line count of three instead of two.
      yield $Router->route('/l4/split/echo', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 200, body: 'L4-SPLIT-ECHO');
      }, GET);

      // @ Switch this worker to the production encoder for leg B, remembering
      //   the suite's own encoder so the evidence route can restore it.
      yield $Router->route('/l4/split/promote', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Encoder ??= Server::$Encoder;
         Server::$Encoder = new Encoder_;

         return $Response(code: 200, body: 'L4-SPLIT-PROMOTED');
      }, GET);

      // @ Runs in the same worker, so it can report what the escape route saw.
      //   Restores the suite encoder before returning.
      yield $Router->route('/l4/split/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $body = 'L4-SPLIT-EVIDENCE:' . json_encode([
            'handlers' => $Probe->handlers,
            'work' => $Probe->work,
            'preconditions' => $Probe->preconditions,
         ]);

         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
            $Probe->Encoder = null;
         }

         return $Response(code: 200, body: $body);
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         return "L4 split harness: {$Probe->error}";
      }

      $harness = implode('', $responses);
      $marker = 'L4-SPLIT-EVIDENCE:';
      $at = strpos($harness, $marker);
      $Evidence = $at === false
         ? []
         : (json_decode(trim(substr($harness, $at + strlen($marker))), true) ?? []);

      $Legs = [];
      foreach ($Probe->wires as $leg => $wire) {
         $Legs[$leg] = [
            'status_lines' => substr_count($wire, 'HTTP/1.'),
            'deferred_bodies' => substr_count($wire, 'L4-SPLIT-DEFERRED'),
            'sync_bodies' => substr_count($wire, 'L4-SPLIT-SYNC'),
            'echo_bodies' => substr_count($wire, 'L4-SPLIT-ECHO'),
            'wire' => substr($wire, 0, 320),
         ];
      }

      $report = json_encode(['legs' => $Legs, 'worker' => $Evidence]);

      // ? Harness guards — never a pass, never a finding.
      if ($at === false) {
         return "L4 split harness: the worker evidence never arrived. Evidence: {$report}";
      }
      if (($Evidence['handlers'] ?? null) !== 2 || ($Evidence['work'] ?? null) !== 2) {
         return 'L4 split harness: expected the escape route and its deferred work to run once '
            . "per encoder leg. Evidence: {$report}";
      }
      $Preconditions = $Evidence['preconditions'] ?? [];
      if (count($Preconditions) !== 2) {
         return "L4 split harness: expected two recorded preconditions. Evidence: {$report}";
      }
      $encoders = [];
      foreach ($Preconditions as $index => $Precondition) {
         if (($Precondition['injected_is_static'] ?? null) !== true) {
            return "L4 split harness: leg {$index} did not receive the worker-persistent "
               . "Response, so defer() could not bind a transport. Evidence: {$report}";
         }
         if (($Precondition['exchange_admitted'] ?? null) !== false) {
            return "L4 split harness: leg {$index} ran OBSERVED (an Exchange was admitted), so "
               . "it cannot exercise the unobserved gate. Evidence: {$report}";
         }
         $encoders[] = $Precondition['encoder'] ?? '';
      }
      // ! Both encoders carry the same gate; a leg that silently stayed on the
      //   suite encoder would leave the production fix unproven.
      if (count(array_unique($encoders)) !== 2) {
         return 'L4 split harness: both legs ran under the same encoder ('
            . implode(', ', $encoders) . "). Evidence: {$report}";
      }

      foreach ($Legs as $leg => $Leg) {
         // ?: Vulnerable — the stale synchronous replacement also reached the
         //   wire, so the pipelined connection carries one response too many.
         if ($Leg['status_lines'] !== 2 || $Leg['sync_bodies'] !== 0) {
            return "CONFIRMED ({$leg}): a deferred escape plus a returned replacement emitted an "
               . "extra response, desynchronising the pipelined connection. Evidence: {$report}";
         }
         // ?: Secure — one response per request, and the representation already
         //   committed to the socket by the Fiber is the one that stands.
         if ($Leg['deferred_bodies'] !== 1 || $Leg['echo_bodies'] !== 1) {
            return "L4 split ({$leg}): unexpected pipelined shape. Evidence: {$report}";
         }
      }

      return true;
   },
);
