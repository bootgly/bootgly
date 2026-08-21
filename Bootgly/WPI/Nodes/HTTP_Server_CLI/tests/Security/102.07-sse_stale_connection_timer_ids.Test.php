<?php

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M3 — closed HTTP/2 SSE streams must release their Connection timer IDs.
 *
 * SSE::open() registers its supervisor both in Timer::$tasks and in the
 * long-lived Connection::$timers array. Before remediation,
 * SSE::disconnect() deleted the live Timer task but never removed the
 * corresponding Connection array member. HTTP/1 closed the dedicated
 * connection and therefore masked the retention; HTTP/2 kept the connection
 * alive after each event stream ended. Repeated requests to an
 * application-configured SSE route consequently retained one dead integer per
 * completed stream and made final Connection::close() revisit every dead ID.
 *
 * The remote client below opens and gracefully closes several SSE streams on
 * one real h2c connection. The route is intentionally application-provided:
 * arbitrary input cannot turn a normal route into SSE, but once a deployment
 * exposes this ordinary endpoint shape, unauthenticated requests drive the
 * framework-owned retention without further application error.
 *
 * Controls prove that every stream opened, closed and emitted END_STREAM, that
 * the connection's ordinary idle timer remains live, and that the SSE Timer
 * tasks themselves were actually deleted. A secure implementation keeps only
 * the baseline live connection timers in Connection::$timers.
 */
$streams = 32;
$evidenceStream = 2 * $streams + 3;
$Probe = new class {
   public mixed $Encoder = null;
   public string $error = '';
   public int $opened = 0;
   public int $closed = 0;
   /** @var array<string,int> */
   public array $baseline = [];
   /** @var array<string,int> */
   public array $after = [];
   /** @var array<int,true> */
   public array $terminal = [];
};

$Snapshot = static function (Response $Response): array {
   $PackageProperty = new ReflectionProperty(Response::class, 'Package');
   $Package = $PackageProperty->getValue($Response);
   $Connection = $Package?->Connection ?? null;
   if ($Connection === null) {
      return ['total' => -1, 'live' => -1, 'stale' => -1];
   }

   $StatusProperty = new ReflectionProperty(Timer::class, 'status');
   /** @var array<int,bool> $Status */
   $Status = $StatusProperty->getValue();
   $live = 0;
   foreach ($Connection->timers as $timer) {
      if (isset($Status[$timer])) {
         $live++;
      }
   }

   $total = count($Connection->timers);

   return [
      'total' => $total,
      'live' => $live,
      'stale' => $total - $live,
   ];
};

return new Test(
   description: 'Closed HTTP/2 SSE streams must not retain stale timer IDs on their connection',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /m3-sse-timers/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $evidenceStream,
         $Probe,
         $streams,
      ): string {
         try {
            $Socket = stream_socket_client(
               "tcp://{$hostPort}",
               $errorNumber,
               $errorMessage,
               timeout: 5,
            );
            if ($Socket === false) {
               throw new RuntimeException(
                  "M3 SSE timer connection failed: {$errorNumber} {$errorMessage}"
               );
            }
            stream_set_blocking($Socket, false);

            $Headers = static function (string $path) use ($testIndex): string {
               return HPACK::encode([
                  [':method', 'GET'],
                  [':scheme', 'http'],
                  [':path', $path],
                  [':authority', 'localhost'],
                  ['x-bootgly-test', (string) $testIndex],
               ]);
            };

            $request = HTTP2::PREFACE
               . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
               . Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                  1,
                  $Headers('/m3-sse-timers/baseline'),
               );
            for ($index = 0; $index < $streams; $index++) {
               $stream = 3 + 2 * $index;
               $request .= Frame::pack(
                  HTTP2::FRAME_HEADERS,
                  HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
                  $stream,
                  $Headers('/m3-sse-timers/target'),
               );
            }
            $request .= Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               $evidenceStream,
               $Headers('/m3-sse-timers/evidence'),
            );

            $offset = 0;
            while ($offset < strlen($request)) {
               $written = fwrite($Socket, substr($request, $offset));
               if ($written === false || $written === 0) {
                  break;
               }
               $offset += $written;
            }
            if ($offset !== strlen($request)) {
               throw new RuntimeException('M3 SSE timer request batch was incomplete.');
            }

            $buffer = '';
            $settledAt = null;
            $deadline = microtime(true) + 6.0;
            while (microtime(true) < $deadline) {
               $read = [$Socket];
               $write = null;
               $except = null;
               $ready = stream_select($read, $write, $except, 0, 50_000);
               if ($ready === false) {
                  break;
               }
               if ($ready === 1) {
                  $chunk = fread($Socket, 65_536);
                  if ($chunk === false || ($chunk === '' && feof($Socket))) {
                     break;
                  }
                  $buffer .= $chunk;
               }

               while (strlen($buffer) >= 9) {
                  $size = (ord($buffer[0]) << 16)
                     | (ord($buffer[1]) << 8)
                     | ord($buffer[2]);
                  if (strlen($buffer) < 9 + $size) {
                     break;
                  }

                  $flags = ord($buffer[4]);
                  $stream = ((ord($buffer[5]) & 0x7f) << 24)
                     | (ord($buffer[6]) << 16)
                     | (ord($buffer[7]) << 8)
                     | ord($buffer[8]);
                  $buffer = substr($buffer, 9 + $size);

                  if (($flags & HTTP2::FLAG_END_STREAM) !== 0) {
                     $Probe->terminal[$stream] = true;
                     if ($stream === $evidenceStream && $settledAt === null) {
                        $settledAt = microtime(true);
                     }
                  }
               }

               if ($settledAt !== null && microtime(true) - $settledAt >= 0.20) {
                  break;
               }
            }
            fclose($Socket);
         }
         catch (Throwable $Throwable) {
            if (isset($Socket) && is_resource($Socket)) {
               fclose($Socket);
            }
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /m3-sse-timers/report HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/m3-sse-timers/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Encoder = Server::$Encoder;
         Server::$Encoder = new Encoder_;

         return $Response(body: 'M3-SSE-TIMERS-SETUP');
      }, GET);

      yield $Router->route('/m3-sse-timers/baseline', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Probe->baseline = $Snapshot($Response);

         return $Response(body: 'M3-SSE-TIMERS-BASELINE');
      }, GET);

      yield $Router->route('/m3-sse-timers/target', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $SSE = $Response->SSE;
         $SSE->heartbeat = 0;
         $SSE->open();
         if ($SSE->opened) {
            $Probe->opened++;
         }
         $SSE->close();
         if ($SSE->closed) {
            $Probe->closed++;
         }

         return $Response;
      }, GET);

      yield $Router->route('/m3-sse-timers/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Probe->after = $Snapshot($Response);

         return $Response(body: 'M3-SSE-TIMERS-EVIDENCE');
      }, GET);

      yield $Router->route('/m3-sse-timers/report', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $evidence = [
            'opened' => $Probe->opened,
            'closed' => $Probe->closed,
            'baseline' => $Probe->baseline,
            'after' => $Probe->after,
         ];

         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'M3-SSE-TIMERS:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use (
      $evidenceStream,
      $Probe,
      $streams,
   ): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'M3-SSE-TIMERS-SETUP') === false
      ) {
         return 'M3 SSE timer setup or report harness failed.';
      }
      if ($Probe->error !== '') {
         return 'M3 SSE timer h2c fixture failed: ' . $Probe->error;
      }

      for ($index = 0; $index < $streams; $index++) {
         $stream = 3 + 2 * $index;
         if (isset($Probe->terminal[$stream]) === false) {
            return "M3 SSE timer control stream {$stream} never reached END_STREAM.";
         }
      }
      if (isset($Probe->terminal[$evidenceStream]) === false) {
         return 'M3 SSE timer evidence stream never reached END_STREAM.';
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'M3-SSE-TIMERS:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      if (is_array($evidence) === false) {
         return 'M3 SSE timer report was not valid JSON: ' . json_encode($body);
      }

      $baseline = $evidence['baseline'] ?? [];
      $after = $evidence['after'] ?? [];
      if (
         ($evidence['opened'] ?? null) !== $streams
         || ($evidence['closed'] ?? null) !== $streams
         || ($baseline['total'] ?? -1) < 1
         || ($baseline['live'] ?? -1) < 1
         || ($after['live'] ?? -1) !== ($baseline['live'] ?? -2)
      ) {
         return 'M3 SSE timer controls did not prove successful open/close and '
            . 'live idle-timer preservation: ' . json_encode($evidence);
      }

      $retained = ($after['total'] ?? -1) - ($baseline['total'] ?? -1);
      $stale = ($after['stale'] ?? -1) - ($baseline['stale'] ?? -1);
      if ($retained > 0 || $stale > 0) {
         return 'CONFIRMED M3: ' . $streams . ' remotely requested HTTP/2 SSE '
            . "streams opened and closed, their Timer tasks disappeared, but {$retained} "
            . "dead IDs remained in the persistent Connection array ({$stale} newly stale). "
            . 'Repeated traffic grows worker memory linearly and makes connection '
            . 'teardown revisit every dead ID. Evidence: ' . json_encode($evidence);
      }

      return true;
   },
);
