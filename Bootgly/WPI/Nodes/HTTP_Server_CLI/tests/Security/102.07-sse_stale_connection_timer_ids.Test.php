<?php

use Bootgly\ACI\Events\Repeating;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
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
 * Security PoC M3 — closed HTTP/2 SSE streams must release their timers.
 *
 * SSE::open() registers a supervisor on the worker's timer owner. Before
 * remediation, on the static Timer it was listed both in Timer::$tasks and in
 * the long-lived Connection::$timers array, and SSE::disconnect() deleted the
 * live Timer task but never removed the corresponding Connection array member.
 * HTTP/1 closed the dedicated connection and therefore masked the retention;
 * HTTP/2 kept the connection alive after each event stream ended. Repeated
 * requests to an application-configured SSE route consequently retained one
 * dead integer per completed stream and made final Connection::close() revisit
 * every dead ID.
 *
 * The remote client below opens and gracefully closes several SSE streams on
 * one real h2c connection. The route is intentionally application-provided:
 * arbitrary input cannot turn a normal route into SSE, but once a deployment
 * exposes this ordinary endpoint shape, unauthenticated requests drive the
 * framework-owned retention without further application error.
 *
 * The verdict follows the owner the worker actually runs:
 * - On a loop that owns recurring timers (`Repeating`, the built-in Select)
 *   the supervisor is a recurring timer of the loop and the connection's idle
 *   expiration rides the worker's supervision sweep, so no static Timer id may
 *   ever reach Connection::$timers. Controls prove that every stream opened,
 *   closed and emitted END_STREAM, that each one armed a loop timer while it
 *   was open, and that the connection stays on the sweep. A secure
 *   implementation cancels every one of those loop timers — the loop holds as
 *   many recurring timers after the streams as before them.
 * - On the static Timer, controls prove that the connection's ordinary idle
 *   timer remains live and that the SSE Timer tasks themselves were deleted. A
 *   secure implementation keeps only the baseline live connection timers in
 *   Connection::$timers.
 */
$streams = 32;
$evidenceStream = 2 * $streams + 3;
$Probe = new class {
   public mixed $Encoder = null;
   public string $error = '';
   public int $opened = 0;
   public int $closed = 0;
   /** @var array<string,bool|int|string> */
   public array $baseline = [];
   /** @var array<string,bool|int|string> */
   public array $after = [];
   /** @var array<int,true> */
   public array $terminal = [];
   // # Loop owner: streams whose open() armed a loop timer, their ids, and
   //   how many of those the loop still holds once the streams closed
   public int $armed = 0;
   /** @var array<int,int> */
   public array $own = [];
   public int $left = -1;
};

// @ Ids of the recurring timers the worker's loop holds right now; null when
//   the static Timer owns the worker's timers, `[-1]` when the loop owns them
//   but is not the built-in reactor whose timers this PoC can read
$Recurring = static function (): null|array {
   $Loop = isset(Server::$Event) ? Server::$Event : null;
   // ?
   if ($Loop instanceof Repeating === false) {
      return null;
   }
   if ($Loop instanceof Select === false) {
      return [-1];
   }

   // :
   return array_keys(new ReflectionProperty(Select::class, 'Repeaters')->getValue($Loop));
};

$Snapshot = static function (Response $Response) use ($Recurring): array {
   $PackageProperty = new ReflectionProperty(Response::class, 'Package');
   $Package = $PackageProperty->getValue($Response);
   $Connection = $Package?->Connection ?? null;
   if ($Connection === null) {
      return ['owner' => '', 'total' => -1, 'live' => -1, 'stale' => -1, 'recurring' => -1, 'supervised' => false];
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

   // # Loop owner: the recurring timers of the loop, and the connection's
   //   idle expiration on the worker's supervision sweep
   $Timers = $Recurring();
   $Supervised = new ReflectionProperty(Connections::class, 'Supervised')->getValue();

   return [
      'owner' => $Timers === null ? 'timer' : 'loop',
      'total' => $total,
      'live' => $live,
      'stale' => $total - $live,
      'recurring' => $Timers === null || $Timers === [-1] ? -1 : count($Timers),
      'supervised' => isset($Supervised[spl_object_id($Connection)]) && $Connection->due > 0,
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
   ) use ($Probe, $Recurring, $Snapshot): Generator {
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
      ) use ($Probe, $Recurring): Response {
         $SSE = $Response->SSE;
         $SSE->heartbeat = 0;
         $Before = $Recurring();
         $SSE->open();
         if ($SSE->opened) {
            $Probe->opened++;
         }
         // ? Loop owner: the loop timers this stream armed while open
         if ($Before !== null && $Before !== [-1]) {
            $Own = array_values(array_diff($Recurring() ?? [], $Before));
            if ($Own !== []) {
               $Probe->armed++;
               array_push($Probe->own, ...$Own);
            }
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
      ) use ($Probe, $Recurring, $Snapshot): Response {
         $Probe->after = $Snapshot($Response);
         // @ Loop owner: the timers the streams armed that the loop still holds
         $Probe->left = count(array_intersect($Probe->own, $Recurring() ?? []));

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
            'armed' => $Probe->armed,
            'left' => $Probe->left,
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
      $owner = $baseline['owner'] ?? '';
      if (
         ($evidence['opened'] ?? null) !== $streams
         || ($evidence['closed'] ?? null) !== $streams
         || ($after['owner'] ?? null) !== $owner
      ) {
         return 'M3 SSE timer controls did not prove successful open/close on one timer owner: '
            . json_encode($evidence);
      }

      // ?: The worker's loop owns the timers (`Repeating`): the supervisors
      //    are recurring loop timers, the idle expiration rides the sweep
      if ($owner === 'loop') {
         if (
            ($baseline['recurring'] ?? -1) < 0
            || ($after['recurring'] ?? -1) < 0
            || ($evidence['armed'] ?? null) !== $streams
            || ($baseline['supervised'] ?? false) !== true
            || ($after['supervised'] ?? false) !== true
         ) {
            return 'M3 SSE timer controls did not prove, on the loop owner, that every stream '
               . 'armed a readable loop timer and that the connection kept its idle '
               . 'expiration on the supervision sweep: ' . json_encode($evidence);
         }

         $static = ($baseline['total'] ?? -1) + ($after['total'] ?? -1);
         $left = $evidence['left'] ?? -1;
         $grown = ($after['recurring'] ?? -1) - ($baseline['recurring'] ?? -1);
         if ($static !== 0 || $left !== 0 || $grown !== 0) {
            return "CONFIRMED M3 (loop owner): {$streams} remotely requested HTTP/2 SSE "
               . "streams opened and closed, yet {$left} of their loop timers are still "
               . "scheduled, the loop holds {$grown} recurring timers more than before them "
               . "and the connection lists {$static} static Timer ids. Repeated traffic "
               . 'grows worker memory and wakeups linearly. Evidence: ' . json_encode($evidence);
         }

         return true;
      }

      if (
         $owner !== 'timer'
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
