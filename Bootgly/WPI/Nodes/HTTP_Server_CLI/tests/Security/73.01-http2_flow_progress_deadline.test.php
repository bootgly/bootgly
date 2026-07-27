<?php

use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Errors;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC — a flow-stalled HTTP/2 response must be bounded by TIME, not
 * only by the aggregate volume budget.
 *
 * A peer that advertises a zero window and then withholds WINDOW_UPDATE parks
 * a whole response body in worker memory. The aggregate budget caps how MUCH
 * can be parked; nothing capped how LONG. The transport idle reaper does not
 * help, because the peer can keep the connection "active" by eliciting writes —
 * periodic PINGs, whose ACKs refresh write activity — so a sub-budget backlog
 * could be held indefinitely.
 *
 * The attack advertises a zero window, requests a body, then PINGs steadily
 * without ever granting credit. The stream must be reset once its
 * flow-control clock stops advancing.
 *
 * The parking route lowers `maxWriteWallTime` inside the worker so the case
 * runs in seconds instead of the 30s default, and the HTTP/1 harness route —
 * which runs after the h2 legs — restores it, so no later case inherits a
 * shortened write deadline.
 */
$probe = ['error' => '', 'calm' => 0, 'frames' => 0, 'pings' => 0];

return new Specification(
   description: 'a flow-stalled HTTP/2 stream must be reset once progress stops',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      try {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            throw new RuntimeException("connect failed: {$errorNumber} {$errorMessage}");
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 2);

         // @ h2c prior knowledge with a ZERO initial send window.
         @fwrite(
            $socket,
            "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
            . Frame::pack(
               HTTP2::FRAME_SETTINGS, 0, 0,
               pack('nN', HTTP2::SETTINGS_INITIAL_WINDOW_SIZE, 0)
            )
            . Frame::pack(
               HTTP2::FRAME_HEADERS,
               HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
               1,
               HPACK::encode([
                  [':method', 'GET'],
                  [':scheme', 'http'],
                  [':path', '/m6-park'],
                  [':authority', 'localhost'],
                  ['x-bootgly-test', (string) $testIndex],
               ])
            )
         );

         // @ Keep the connection "active" without ever granting credit — this
         //   is what defers the transport idle reaper.
         $wire = '';
         $deadline = microtime(true) + 8.0;
         while (microtime(true) < $deadline) {
            @fwrite($socket, Frame::pack(HTTP2::FRAME_PING, 0, 0, 'M6PINGxx'));
            $probe['pings']++;

            $chunk = @fread($socket, 65535);
            if ($chunk !== false && $chunk !== '') {
               $wire .= $chunk;
            }
            else if (@feof($socket)) {
               break;
            }

            if (str_contains($wire, 'M6-RESET-SEEN')) {
               break;
            }
            usleep(300000);
         }
         @fclose($socket);

         // @ Walk frames for RST_STREAM(ENHANCE_YOUR_CALM).
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $header = substr($wire, $offset, 9);
            $size = (ord($header[0]) << 16) | (ord($header[1]) << 8) | ord($header[2]);
            $type = ord($header[3]);
            if ($size > 0x1000000 || $offset + 9 + $size > $length) {
               break;
            }

            $probe['frames']++;
            if ($type === HTTP2::FRAME_RST_STREAM && $size >= 4) {
               /** @var array{1:int} $parts */
               $parts = unpack('N', substr($wire, $offset + 9, 4));
               if ($parts[1] === Errors::EnhanceYourCalm->value) {
                  $probe['calm']++;
               }
            }

            $offset += 9 + $size;
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /m6-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      // ! Captured per worker: the parking route shortens the write deadline,
      //   the harness route puts it back.
      static $original = null;

      yield $Router->route('/m6-park', static function (
         Request $Request,
         Response $Response
      ) use (&$original): Response {
         $original ??= TCP_Server_CLI::$maxWriteWallTime;
         TCP_Server_CLI::$maxWriteWallTime = 1;

         return $Response(body: str_repeat('M', 262144));
      }, GET);

      yield $Router->route('/m6-harness', static function (
         Request $Request,
         Response $Response
      ) use (&$original): Response {
         if ($original !== null) {
            TCP_Server_CLI::$maxWriteWallTime = $original;
         }

         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M6 harness request did not reach /m6-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M6 fixture error: ' . $probe['error'];
      }

      // ? Control — the attack must actually have kept the connection busy,
      //   otherwise a reset (or its absence) says nothing about the deadline.
      if ($probe['pings'] < 3 || $probe['frames'] < 2) {
         return 'M6 control failed: the connection was not exercised ('
            . $probe['pings'] . ' pings, ' . $probe['frames'] . ' frames), so the result '
            . 'is not attributable to the flow-progress deadline.';
      }

      if ($probe['calm'] === 0) {
         return 'CONFIRMED M6: a flow-stalled stream held its parked response body while the '
            . 'peer kept the connection alive with ' . $probe['pings'] . ' PINGs and never '
            . 'granted credit — no RST_STREAM(ENHANCE_YOUR_CALM) in ' . $probe['frames']
            . ' frames. Retention was bounded by volume but never by time.';
      }

      return true;
   },
);
