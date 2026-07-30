<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$bodySize = 1024 * 1024;
$workerCap = 1536 * 1024;
$workerOriginal = null;
$probe = [
   'error' => '',
   'setup' => '',
   'first' => [],
   'second' => [],
   'fresh' => [],
   'sse_first' => [],
   'sse_second' => [],
   'sse_fresh' => [],
   'wide_file' => [],
   'reset' => '',
];

return new Specification(
   description: 'HTTP/2 backlogs on separate connections must share the TCP worker budget',
   Separator: new Separator(line: true),

   request: static function (
      string $hostPort,
      int $testIndex,
   ) use (&$probe): string {
      $Headers = static function (int $testIndex, string $path): string {
         return HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['x-bootgly-test', (string) $testIndex],
         ]);
      };

      $Call = static function (
         string $hostPort,
         int $testIndex,
         string $path,
      ): string {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not call {$path}: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_timeout($Socket, 3);
         @fwrite(
            $Socket,
            "GET {$path} HTTP/1.1\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\n"
            . "Connection: close\r\n\r\n"
         );
         $wire = (string) @stream_get_contents($Socket);
         @fclose($Socket);

         return $wire;
      };

      $Open = static function (
         string $hostPort,
         int $window = 0,
         bool $expand = false,
      ) {
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not open HTTP/2 fixture: {$errorNumber} {$errorMessage}"
            );
         }

         stream_set_blocking($Socket, true);
         stream_set_timeout($Socket, 1);
         $preface = HTTP2::PREFACE . Frame::pack(
            HTTP2::FRAME_SETTINGS,
            0,
            0,
            pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, $window),
         );
         if ($expand) {
            $preface .= Frame::pack(
               HTTP2::FRAME_WINDOW_UPDATE,
               0,
               0,
               pack('N', 2147418112),
            );
         }
         @fwrite($Socket, $preface);

         return $Socket;
      };

      $Drain = static function ($Socket, float $seconds): string {
         $wire = '';
         $deadline = microtime(true) + $seconds;
         while (microtime(true) < $deadline) {
            $chunk = @fread($Socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($Socket)) {
                  break;
               }
               continue;
            }
            $wire .= $chunk;
         }

         return $wire;
      };

      $Walk = static function (string $wire): array {
         $headers = 0;
         $calm = 0;
         $cancel = 0;
         $dataBytes = 0;
         $ended = false;
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $head = substr($wire, $offset, 9);
            $size = (ord($head[0]) << 16)
               | (ord($head[1]) << 8)
               | ord($head[2]);
            $type = ord($head[3]);
            if ($offset + 9 + $size > $length) {
               break;
            }

            if ($type === HTTP2::FRAME_HEADERS) {
               $headers++;
            }
            if ($type === HTTP2::FRAME_DATA) {
               $dataBytes += $size;
               $ended = $ended || (ord($head[4]) & HTTP2::FLAG_END_STREAM) !== 0;
            }
            if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               /** @var array{1:int} $Error */
               $Error = unpack('N', substr($wire, $offset + 9, 4));
               if ($Error[1] === Errors::EnhanceYourCalm->value) {
                  $calm++;
               }
               if ($Error[1] === Errors::Cancel->value) {
                  $cancel++;
               }
            }

            $offset += 9 + $size;
         }

         return [
            'headers' => $headers,
            'calm' => $calm,
            'cancel' => $cancel,
            'data_bytes' => $dataBytes,
            'ended' => $ended,
            'wire_bytes' => $length,
         ];
      };

      $First = null;
      $Second = null;
      $Fresh = null;
      $Wide = null;
      try {
         $probe['setup'] = $Call($hostPort, $testIndex, '/l1-h2-setup');

         // # First connection: one 1 MiB backlog fits the 1.5 MiB worker cap.
         $First = $Open($hostPort);
         @fwrite($First, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers($testIndex, '/l1-h2-body'),
         ));
         $probe['first'] = $Walk($Drain($First, 1.25));

         // # Second connection: individually valid, but worker aggregate +1
         //   MiB would exceed the cap, so this new Stream must be reset.
         $Second = $Open($hostPort);
         @fwrite($Second, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers($testIndex, '/l1-h2-body'),
         ));
         $probe['second'] = $Walk($Drain($Second, 1.25));
         @fclose($Second);
         $Second = null;

         // # Release the first owner explicitly, then prove fresh admission.
         @fwrite($First, Frame::pack(
            HTTP2::FRAME_RST_STREAM,
            0,
            1,
            pack('N', Errors::Cancel->value),
         ));
         usleep(150000);
         @fclose($First);
         $First = null;

         $Fresh = $Open($hostPort);
         @fwrite($Fresh, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers($testIndex, '/l1-h2-body'),
         ));
         $probe['fresh'] = $Walk($Drain($Fresh, 1.25));

         @fwrite($Fresh, Frame::pack(
            HTTP2::FRAME_RST_STREAM,
            0,
            1,
            pack('N', Errors::Cancel->value),
         ));
         usleep(150000);
         @fclose($Fresh);
         $Fresh = null;

         // # A peer may advertise the RFC-maximum stream and connection
         //   windows once, then send no WINDOW_UPDATE at all. The 3 MiB file
         //   must still progress across locally-sliced 1 MiB callbacks.
         $Wide = $Open($hostPort, 2147483647, true);
         @fwrite(
            $Wide,
            Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               1,
               $Headers($testIndex, '/l1-h2-file'),
            )
            // @ A graceful peer GOAWAY forbids new requests but must not
            //   suppress locally-scheduled DATA for this accepted stream.
            . Frame::pack(
               HTTP2::FRAME_GOAWAY,
               0,
               0,
               pack('NN', 1, Errors::None->value),
            ),
         );
         $probe['wide_file'] = $Walk($Drain($Wide, 4.0));
         @fclose($Wide);
         $Wide = null;

         // # SSE uses the same Stream token through its own append path.
         $First = $Open($hostPort);
         @fwrite($First, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers($testIndex, '/l1-sse-body'),
         ));
         $probe['sse_first'] = $Walk($Drain($First, 1.25));

         $Second = $Open($hostPort);
         @fwrite($Second, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers($testIndex, '/l1-sse-body'),
         ));
         $probe['sse_second'] = $Walk($Drain($Second, 1.25));
         @fclose($Second);
         $Second = null;

         @fwrite($First, Frame::pack(
            HTTP2::FRAME_RST_STREAM,
            0,
            1,
            pack('N', Errors::Cancel->value),
         ));
         usleep(150000);
         @fclose($First);
         $First = null;

         $Fresh = $Open($hostPort);
         @fwrite($Fresh, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers($testIndex, '/l1-sse-body'),
         ));
         $probe['sse_fresh'] = $Walk($Drain($Fresh, 1.25));
         @fwrite($Fresh, Frame::pack(
            HTTP2::FRAME_RST_STREAM,
            0,
            1,
            pack('N', Errors::Cancel->value),
         ));
         usleep(150000);
         @fclose($Fresh);
         $Fresh = null;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ([$First, $Second, $Fresh, $Wide] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }

         try {
            $probe['reset'] = $Call($hostPort, $testIndex, '/l1-h2-reset');
         }
         catch (Throwable $Throwable) {
            $probe['error'] .= ($probe['error'] === '' ? '' : ' | ')
               . 'reset: ' . $Throwable::class . ': ' . $Throwable->getMessage();
         }
      }

      return "GET /l1-h2-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $bodySize,
      $workerCap,
      &$workerOriginal,
   ) {
      yield $Router->route(
         '/l1-h2-setup',
         static function (
            Request $Request,
            Response $Response,
         ) use (
            $workerCap,
            &$workerOriginal,
         ): Response {
            $workerOriginal ??= TCP_Server_CLI::$maxWorkerPendingBytes;
            TCP_Server_CLI::$maxWorkerPendingBytes = $workerCap;

            return $Response(code: 200, body: 'L1-H2-SETUP-OK');
         },
         GET,
      );

      yield $Router->route(
         '/l1-h2-body',
         static function (
            Request $Request,
            Response $Response,
         ) use ($bodySize): Response {
            return $Response(code: 200, body: str_repeat('B', $bodySize));
         },
         GET,
      );

      yield $Router->route(
         '/l1-h2-file',
         static function (Request $Request, Response $Response): Response {
            return $Response->upload('statics/screenshot.gif', close: false);
         },
         GET,
      );

      yield $Router->route(
         '/l1-sse-body',
         static function (
            Request $Request,
            Response $Response,
         ) use ($bodySize): Response {
            $SSE = $Response->SSE;
            $SSE->heartbeat = 0;
            $SSE->open();
            $SSE->send(str_repeat('S', $bodySize));

            return $Response;
         },
         GET,
      );

      yield $Router->route(
         '/l1-h2-reset',
         static function (
            Request $Request,
            Response $Response,
         ) use (&$workerOriginal): Response {
            if (is_int($workerOriginal)) {
               TCP_Server_CLI::$maxWorkerPendingBytes = $workerOriginal;
            }

            return $Response(code: 200, body: 'L1-H2-RESET-OK');
         },
         GET,
      );

      yield $Router->route(
         '/l1-h2-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'L1-H2-HARNESS-OK');
         },
         GET,
      );

      yield $Router->route(
         '/*',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 404, body: 'Not Found');
         },
      );
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['L1 HTTP/2 worker-budget fixture'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 worker-budget fixture failed: ' . $probe['error'];
      }
      if (
         ! str_contains($probe['setup'], 'L1-H2-SETUP-OK')
         || ! str_contains($probe['reset'], 'L1-H2-RESET-OK')
         || ! str_contains($response, 'L1-H2-HARNESS-OK')
      ) {
         Vars::$labels = ['L1 HTTP/2 control routes'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 setup, reset, or harness route did not complete.';
      }
      if (
         ($probe['first']['headers'] ?? 0) < 1
         || ($probe['first']['calm'] ?? -1) !== 0
      ) {
         Vars::$labels = ['L1 first HTTP/2 owner'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 control failed: the first sub-cap backlog was not admitted.';
      }
      if (
         ($probe['second']['headers'] ?? 0) < 1
         || ($probe['second']['calm'] ?? 0) < 1
      ) {
         Vars::$labels = ['L1 second HTTP/2 owner'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 worker cap did not reset the second growing connection.';
      }
      if (
         ($probe['fresh']['headers'] ?? 0) < 1
         || ($probe['fresh']['calm'] ?? -1) !== 0
      ) {
         Vars::$labels = ['L1 fresh HTTP/2 admission'];
         dump(json_encode($probe));

         return 'L1 HTTP/2 release did not restore fresh worker-budget admission.';
      }
      if (
         ($probe['wide_file']['headers'] ?? 0) < 1
         || ($probe['wide_file']['data_bytes'] ?? 0) <= 2 * 1024 * 1024
         || ($probe['wide_file']['ended'] ?? null) !== true
         || ($probe['wide_file']['calm'] ?? -1) !== 0
         || ($probe['wide_file']['cancel'] ?? -1) !== 0
      ) {
         Vars::$labels = ['L1 bounded HTTP/2 file continuation'];
         dump(json_encode($probe));

         return 'L1 bounded HTTP/2 file batches did not continue to END_STREAM '
            . 'under already-expanded peer windows.';
      }
      if (
         ($probe['sse_first']['headers'] ?? 0) < 1
         || ($probe['sse_first']['cancel'] ?? -1) !== 0
         || ($probe['sse_second']['headers'] ?? 0) < 1
         || ($probe['sse_second']['cancel'] ?? 0) < 1
         || ($probe['sse_fresh']['headers'] ?? 0) < 1
         || ($probe['sse_fresh']['cancel'] ?? -1) !== 0
      ) {
         Vars::$labels = ['L1 SSE worker budget'];
         dump(json_encode($probe));

         return 'L1 SSE append or release bypassed the shared worker budget.';
      }

      return true;
   },
);
