<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 HTTP/2 HEAD+SSE regression.
 *
 * The SSE resource serializes HEAD out-of-band as one HEADERS frame with
 * END_STREAM. Telemetry must classify the status selected for that exact wire,
 * not the application's stale pre-open status, and the ordinary encoder must
 * not append DATA or a second response on the stream.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public mixed $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   /** @var array<int,array{type:int,flags:int,stream:int,payload:string}> */
   public array $Frames = [];
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
   description: 'HTTP/2 HEAD SSE must terminalize as one 2xx HEADERS-only response',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/h2-head-sse/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use ($Probe): string {
         try {
            $Socket = stream_socket_client(
               "tcp://{$hostPort}",
               $errorNumber,
               $errorMessage,
               timeout: 5,
            );
            if ($Socket === false) {
               throw new RuntimeException(
                  "L4 h2 HEAD SSE connection failed: {$errorNumber} {$errorMessage}"
               );
            }
            stream_set_blocking($Socket, false);

            $headers = HPACK::encode([
               [':method', 'HEAD'],
               [':scheme', 'http'],
               [':path', '/l4/h2-head-sse/target'],
               [':authority', 'localhost'],
               ['x-bootgly-test', (string) $testIndex],
            ]);
            fwrite(
               $Socket,
               HTTP2::PREFACE
               . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
               . Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                  1,
                  $headers,
               ),
            );

            $buffer = '';
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
               $chunk = fread($Socket, 65536);
               if ($chunk !== false && $chunk !== '') {
                  $buffer .= $chunk;
               }

               while (strlen($buffer) >= 9) {
                  $size = (ord($buffer[0]) << 16)
                     | (ord($buffer[1]) << 8)
                     | ord($buffer[2]);
                  if (strlen($buffer) < 9 + $size) {
                     break;
                  }

                  $Probe->Frames[] = [
                     'type' => ord($buffer[3]),
                     'flags' => ord($buffer[4]),
                     'stream' => (
                        ((ord($buffer[5]) & 0x7f) << 24)
                        | (ord($buffer[6]) << 16)
                        | (ord($buffer[7]) << 8)
                        | ord($buffer[8])
                     ),
                     'payload' => substr($buffer, 9, $size),
                  ];
                  $buffer = substr($buffer, 9 + $size);
               }

               $terminal = false;
               foreach ($Probe->Frames as $Frame) {
                  if (
                     $Frame['stream'] === 1
                     && ($Frame['flags'] & HTTP2::FLAG_END_STREAM) !== 0
                  ) {
                     $terminal = true;
                     break;
                  }
               }
               if ($terminal) {
                  break;
               }

               usleep(10000);
            }
            fclose($Socket);
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/h2-head-sse/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/l4/h2-head-sse/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-H2-HEAD-SSE-SETUP');
      }, GET);

      yield $Router->route('/l4/h2-head-sse/target', static function (
         Request $Request,
         Response $Response,
      ): Response {
         // ! A stale application status is deliberate. SSE selects 200 for
         //   the actual HEADERS frame and Telemetry must follow the wire.
         $Response->code(409);
         $SSE = $Response->SSE;
         $SSE->heartbeat = 0;
         $SSE->open();

         return $Response;
      }, HEAD);

      yield $Router->route('/l4/h2-head-sse/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = $Observability === null
            ? null
            : $Snapshot($Observability);

         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-H2-HEAD-SSE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-H2-HEAD-SSE-SETUP') === false
      ) {
         return 'L4 h2 HEAD SSE setup/harness response failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 h2 HEAD SSE fixture failed: ' . $Probe->error;
      }

      $streamFrames = array_values(array_filter(
         $Probe->Frames,
         static fn (array $Frame): bool => $Frame['stream'] === 1,
      ));
      if (
         count($streamFrames) !== 1
         || $streamFrames[0]['type'] !== HTTP2::FRAME_HEADERS
         || ($streamFrames[0]['flags'] & HTTP2::FLAG_END_STREAM) === 0
      ) {
         return 'L4 h2 HEAD SSE emitted DATA, duplicate frames, or no terminal '
            . 'HEADERS on stream 1: ' . json_encode($streamFrames);
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-H2-HEAD-SSE:';
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
         return 'L4 h2 HEAD SSE did not close exactly once with the wire-selected '
            . '2xx status: ' . json_encode([
               'expected' => $expected,
               'actual' => $metrics,
               'frames' => $streamFrames,
            ]);
      }

      return true;
   },
);
