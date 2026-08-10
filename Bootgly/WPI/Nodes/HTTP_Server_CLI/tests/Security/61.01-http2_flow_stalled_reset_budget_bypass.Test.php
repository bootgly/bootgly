<?php

use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M4 — the rapid-reset budget must cover flow-stalled streams.
 *
 * `Decoder_HTTP2` counts a peer RST_STREAM toward the CVE-2023-44487 budget
 * only while `$Stream->responded === false`. `Encoder_HTTP2` sets that flag as
 * soon as a response is PARTIALLY emitted, which is exactly what happens when
 * the peer advertises a zero send window: the handler runs, the body is built
 * and parked in the stream backlog, `responded` becomes true — and the
 * following RST_STREAM is then free. The cycle repeats without bound, running
 * application work per iteration while never spending budget.
 *
 * The attack advertises SETTINGS_INITIAL_WINDOW_SIZE=0 and runs 100 cycles of
 * HEADERS(END_STREAM) -> handler -> RST_STREAM against a route with a body,
 * well past the 64-reset budget.
 *
 * Control: the SAME burst count against UNANSWERED streams (no END_STREAM, so
 * no handler and no response) must still draw GOAWAY(ENHANCE_YOUR_CALM),
 * proving the budget itself is alive and isolating the bypass to the
 * flow-stalled state.
 */
$CYCLES = 100;

$probe = [
   'error' => '',
   'attackGoaway' => null,
   'attackFrames' => 0,
   'controlGoaway' => null,
   'cycles' => $CYCLES,
];

return new Test(
   description: 'flow-stalled responded streams must not bypass the HTTP/2 rapid-reset budget',

   request: static function (string $hostPort, int $testIndex) use (&$probe, $CYCLES): string {
      $Burst = static function (
         string $hostPort,
         int $testIndex,
         int $cycles,
         bool $endStream
      ): array {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return ['error' => "connect failed: {$errorNumber} {$errorMessage}"];
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         // @ h2c prior knowledge, advertising a ZERO initial send window so
         //   every response parks in its stream backlog instead of completing.
         @fwrite(
            $socket,
            "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
            . Frame::pack(
               HTTP2::FRAME_SETTINGS, 0, 0,
               pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 0)
            )
         );

         $flags = HTTP2::FLAG_END_HEADERS | ($endStream ? HTTP2::FLAG_END_STREAM : 0);
         $burst = '';
         for ($i = 0, $stream = 1; $i < $cycles; $i++, $stream += 2) {
            $burst .= Frame::pack(
               HTTP2::FRAME_HEADERS, $flags, $stream,
               HPACK::encode([
                  [':method', 'GET'],
                  [':scheme', 'http'],
                  [':path', '/m4-work'],
                  [':authority', 'localhost'],
                  ['x-bootgly-test', (string) $testIndex],
               ])
            );
            $burst .= Frame::pack(
               HTTP2::FRAME_RST_STREAM, 0, $stream, pack('N', Errors::Cancel->value)
            );
         }
         @fwrite($socket, $burst);

         // @ Drain, looking for GOAWAY.
         $wire = '';
         $deadline = microtime(true) + 8.0;
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
         @fclose($socket);

         // @ Walk frames for a GOAWAY and its error code.
         $goaway = null;
         $frames = 0;
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
            if ($type === HTTP2::FRAME_GOAWAY && $size >= 8) {
               /** @var array{last:int, error:int} $parts */
               $parts = unpack('Nlast/Nerror', substr($wire, $offset + 9, 8));
               $goaway = $parts['error'];
            }

            $offset += 9 + $size;
         }

         return ['error' => '', 'goaway' => $goaway, 'frames' => $frames];
      };

      // ! Control first — unanswered streams must still trip the budget.
      $control = $Burst($hostPort, $testIndex, $CYCLES, false);
      if (($control['error'] ?? '') !== '') {
         $probe['error'] = 'M4 control burst: ' . $control['error'];

         return "GET /m4-harness HTTP/1.1\r\nX-Bootgly-Test: {$testIndex}\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      }
      $probe['controlGoaway'] = $control['goaway'];

      // @ Attack — END_STREAM makes the handler run and the response park.
      $attack = $Burst($hostPort, $testIndex, $CYCLES, true);
      if (($attack['error'] ?? '') !== '') {
         $probe['error'] = 'M4 attack burst: ' . $attack['error'];
      }
      else {
         $probe['attackGoaway'] = $attack['goaway'];
         $probe['attackFrames'] = $attack['frames'];
      }

      return "GET /m4-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m4-work', static function (Request $Request, Response $Response) {
         // ! A non-empty body is what parks into the stream backlog under a
         //   zero send window and flips `responded` to true.
         return $Response(body: str_repeat('M4', 512));
      }, GET);

      yield $Router->route('/m4-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M4 harness request did not reach /m4-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M4 fixture error: ' . $probe['error'];
      }

      // ? Control — the budget must fire for plain unanswered streams.
      if ($probe['controlGoaway'] !== Errors::EnhanceYourCalm->value) {
         return 'M4 control failed: ' . $probe['cycles'] . ' unanswered open+reset cycles did '
            . 'not draw GOAWAY(ENHANCE_YOUR_CALM), so the rapid-reset budget is not alive here '
            . '(got ' . json_encode($probe['controlGoaway']) . ') — the attack leg proves nothing.';
      }

      if ($probe['attackGoaway'] !== Errors::EnhanceYourCalm->value) {
         return 'CONFIRMED M4: ' . $probe['cycles'] . ' flow-stalled open+reset cycles ran '
            . 'application work and were never charged to the rapid-reset budget — no '
            . 'GOAWAY(ENHANCE_YOUR_CALM) arrived (got ' . json_encode($probe['attackGoaway'])
            . ', ' . $probe['attackFrames'] . ' frames), while the identical burst against '
            . 'unanswered streams was rejected at 64.';
      }

      return true;
   },
);
