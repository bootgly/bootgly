<?php

use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M3 — ordinary HTTP/2 response backlogs need an aggregate budget.
 *
 * When the peer's send window is exhausted, `Encoder_HTTP2` parks the WHOLE
 * response body in `Stream::$backlog` and leaves the stream open. Nothing
 * bounded the sum of those parked bodies across streams: a peer that advertises
 * a zero initial window and opens several streams against a route with a
 * material body holds one full body per stream in worker memory, multiplying
 * the transport pending cap by the stream limit. Inbound bodies and SSE already
 * carry aggregate controls; ordinary outbound responses did not.
 *
 * The attack advertises SETTINGS_INITIAL_WINDOW_SIZE=0 and opens 8 streams of
 * 1 MiB each — 8 MiB retained against a 4 MiB connection budget.
 *
 * Controls: with a normal window the same route completes and its body is
 * delivered whole (so the budget cannot be satisfied by simply breaking large
 * responses), and the selected HTTP/1 handler is the suite control.
 */
$STREAMS = 8;
$BODY = 1048576;

$probe = [
   'error' => '',
   'calmResets' => 0,
   'attackFrames' => 0,
   'retainedTarget' => $STREAMS * $BODY,
   'budget' => TCP_Server_CLI::$maxPendingBytes,
   'controlDelivered' => 0,
   'controlError' => '',
];

return new Test(
   description: 'ordinary HTTP/2 response backlogs must honour an aggregate connection budget',

   request: static function (string $hostPort, int $testIndex) use (&$probe, $STREAMS, $BODY): string {
      $Headers = static function (string $path, int $testIndex): string {
         return HPACK::encode([
            [':method', 'GET'],
            [':scheme', 'http'],
            [':path', $path],
            [':authority', 'localhost'],
            ['x-bootgly-test', (string) $testIndex],
         ]);
      };

      $Open = static function (string $hostPort, int $window) {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return null;
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         $settings = $window === null
            ? ''
            : pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, $window);
         @fwrite(
            $socket,
            "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, $settings)
         );

         // ! SETTINGS_INITIAL_WINDOW_SIZE moves the STREAM window only; the
         //   connection window stays at its 65535 default. Credit it too, or a
         //   healthy control would stall at 64 KiB and look like a budget.
         if ($window > 0) {
            @fwrite($socket, Frame::pack(
               HTTP2::FRAME_WINDOW_UPDATE, 0, 0, pack('N', 8388608)
            ));
         }

         return $socket;
      };

      $Drain = static function ($socket, float $seconds): string {
         $wire = '';
         $deadline = microtime(true) + $seconds;
         while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }

               $metadata = stream_get_meta_data($socket);
               if (($metadata['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }
            $wire .= $chunk;
         }

         return $wire;
      };

      // @ Walk frames, tallying RST_STREAM(ENHANCE_YOUR_CALM) and DATA bytes.
      $Walk = static function (string $wire): array {
         $calm = 0;
         $frames = 0;
         $data = 0;
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $header = substr($wire, $offset, 9);
            $size = (ord($header[0]) << 16) | (ord($header[1]) << 8) | ord($header[2]);
            $type = ord($header[3]);
            if ($size > 0x1000000 || $offset + 9 + $size > $length) {
               break;
            }

            $frames++;
            if ($type === HTTP2::FRAME_DATA) {
               $data += $size;
            }
            if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               /** @var array{1:int} $parts */
               $parts = unpack('N', substr($wire, $offset + 9, 4));
               if ($parts[1] === Errors::EnhanceYourCalm->value) {
                  $calm++;
               }
            }

            $offset += 9 + $size;
         }

         return ['calm' => $calm, 'frames' => $frames, 'data' => $data];
      };

      try {
         // ! Control — a normal window must deliver the whole body.
         $socket = $Open($hostPort, 1048576);
         if ($socket === null) {
            throw new RuntimeException('M3 control could not connect.');
         }
         @fwrite($socket, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers('/m3-big', $testIndex)
         ));
         $control = $Walk($Drain($socket, 6.0));
         @fclose($socket);
         $probe['controlDelivered'] = $control['data'];

         // @ Attack — zero window, many concurrent large bodies.
         $socket = $Open($hostPort, 0);
         if ($socket === null) {
            throw new RuntimeException('M3 attack could not connect.');
         }
         $burst = '';
         for ($i = 0, $stream = 1; $i < $STREAMS; $i++, $stream += 2) {
            $burst .= Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               $stream,
               $Headers('/m3-big', $testIndex)
            );
         }
         @fwrite($socket, $burst);
         $attack = $Walk($Drain($socket, 8.0));
         @fclose($socket);

         $probe['calmResets'] = $attack['calm'];
         $probe['attackFrames'] = $attack['frames'];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /m3-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) use ($BODY) {
      yield $Router->route('/m3-big', static function (Request $Request, Response $Response) use ($BODY) {
         return $Response(body: str_repeat('M', $BODY));
      }, GET);

      yield $Router->route('/m3-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe, $BODY): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M3 harness request did not reach /m3-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M3 fixture error: ' . $probe['error'];
      }

      // ? Control — a credited peer must still receive the complete body, so a
      //   budget that simply truncates large responses cannot pass.
      if ($probe['controlDelivered'] < $BODY) {
         return 'M3 control failed: a normally credited stream received only '
            . $probe['controlDelivered'] . ' of ' . $BODY . ' body bytes, so the attack leg '
            . 'cannot distinguish a budget from a broken large-response path.';
      }

      if ($probe['calmResets'] === 0) {
         return 'CONFIRMED M3: ' . $probe['retainedTarget'] . ' bytes of response body were '
            . 'parked across concurrent flow-stalled streams against a '
            . $probe['budget'] . '-byte connection budget, and the server never reset a '
            . 'stream (0 ENHANCE_YOUR_CALM in ' . $probe['attackFrames'] . ' frames) — '
            . 'ordinary outbound backlogs are unbounded.';
      }

      return true;
   },
);
