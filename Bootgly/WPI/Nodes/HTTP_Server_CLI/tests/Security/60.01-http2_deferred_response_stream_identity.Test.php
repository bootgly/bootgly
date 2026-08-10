<?php

use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M2 — a deferred response must keep its HTTP/2 stream identity.
 *
 * The HTTP/2 decoder stamps the stream id onto Request. `Response::defer()`
 * clones the Response, and `Request::__clone()` unconditionally resets
 * `stream` to 0 — a reset that must stay unconditional, since branching there
 * deoptimizes the request loop's JIT trace. `Response\Raw` then selects HTTP/2
 * framing only for a nonzero stream, so the resumed Fiber serializes the
 * deferred response as HTTP/1 wire bytes and writes them into a live HTTP/2
 * connection, corrupting it for every stream that shares it.
 *
 * The attack opens one h2c connection carrying two streams: stream 1 requests
 * a deferred route, stream 3 requests an ordinary one. Confirmation is literal
 * `HTTP/1.` bytes arriving inside the HTTP/2 connection.
 *
 * Controls: the ordinary sibling stream must answer with well-formed frames
 * (proving h2c is healthy in this harness) and the selected HTTP/1 handler is
 * the suite control.
 */
$probe = [
   'error' => '',
   'connected' => false,
   'wire' => '',
   'wireLength' => 0,
   'http1Bytes' => false,
   'http1Excerpt' => '',
   'frames' => [],
   'deferredStreamFrames' => 0,
   'siblingAnswered' => false,
];

return new Test(
   description: 'a deferred HTTP/2 response must not serialize as HTTP/1 on the h2 connection',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      try {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            throw new RuntimeException(
               "M2 could not connect to {$hostPort}: {$errorNumber} {$errorMessage}"
            );
         }

         $probe['connected'] = true;
         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         // @ h2c prior knowledge: preface + empty SETTINGS.
         $preface = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
            . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '');
         @fwrite($socket, $preface);

         $Headers = static function (string $path) use ($testIndex): string {
            return HPACK::encode([
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', $path],
               [':authority', 'localhost'],
               ['x-bootgly-test', (string) $testIndex],
            ]);
         };

         // @ Stream 1 — the deferred route. Stream 3 — an ordinary sibling on
         //   the SAME connection, so corruption is observable as collateral.
         @fwrite($socket, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            $Headers('/m2-defer')
         ));
         @fwrite($socket, Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            3,
            $Headers('/m2-sibling')
         ));

         // @ Drain the connection: the deferred response is emitted after its
         //   Fiber resumes, so read past the sibling's answer.
         $wire = '';
         $deadline = microtime(true) + 6.0;
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
            if (str_contains($wire, 'HTTP/1.')) {
               break; // evidence is complete
            }
         }
         @fclose($socket);

         $probe['wire'] = $wire;
         $probe['wireLength'] = strlen($wire);
         $probe['http1Bytes'] = str_contains($wire, 'HTTP/1.');

         if ($probe['http1Bytes']) {
            $at = strpos($wire, 'HTTP/1.');
            $probe['http1Excerpt'] = substr($wire, $at, 120);
         }

         // @ Minimal frame walk (9-byte header: length/type/flags/stream).
         $offset = 0;
         $length = strlen($wire);
         while ($offset + 9 <= $length) {
            $header = substr($wire, $offset, 9);
            $size = (ord($header[0]) << 16) | (ord($header[1]) << 8) | ord($header[2]);
            $type = ord($header[3]);
            $stream = ((ord($header[5]) & 0x7F) << 24)
               | (ord($header[6]) << 16)
               | (ord($header[7]) << 8)
               | ord($header[8]);

            if ($size > 0x4000 || $offset + 9 + $size > $length) {
               break; // truncated or not a real frame boundary
            }

            $probe['frames'][] = ['type' => $type, 'stream' => $stream, 'size' => $size];

            if ($stream === 1 && ($type === HTTP2::FRAME_HEADERS || $type === HTTP2::FRAME_DATA)) {
               $probe['deferredStreamFrames']++;
            }
            if (
               $stream === 3
               && $type === HTTP2::FRAME_DATA
               && str_contains(substr($wire, $offset + 9, $size), 'M2-SIBLING')
            ) {
               $probe['siblingAnswered'] = true;
            }

            $offset += 9 + $size;
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /m2-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m2-defer', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response) {
            $Response->wait();

            $Response(body: 'M2-DEFERRED');
         });
      }, GET);

      yield $Router->route('/m2-sibling', static function (Request $Request, Response $Response) {
         return $Response(body: 'M2-SIBLING');
      }, GET);

      yield $Router->route('/m2-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M2 harness request did not reach /m2-harness.';
      }
      if ($probe['error'] !== '') {
         return 'M2 fixture error: ' . $probe['error'];
      }
      if ($probe['connected'] !== true) {
         return 'M2 fixture never opened its h2c connection.';
      }
      if ($probe['wireLength'] === 0) {
         return 'M2 h2c connection returned no bytes at all — the harness cannot judge framing.';
      }

      if ($probe['http1Bytes'] === true) {
         return 'CONFIRMED M2: HTTP/1 wire bytes were written into a live HTTP/2 connection '
            . 'after a deferred response lost its stream identity — found '
            . json_encode($probe['http1Excerpt'])
            . ' at offset ' . strpos($probe['wire'], 'HTTP/1.')
            . ' of ' . $probe['wireLength'] . ' bytes; frames seen: '
            . json_encode(array_slice($probe['frames'], 0, 12));
      }

      // ? Control — the ordinary sibling stream proves h2c is healthy here, so
      //   a clean run cannot be a silently broken connection.
      if ($probe['siblingAnswered'] !== true) {
         return 'M2 sibling-stream control did not answer over h2c, so the absence of HTTP/1 '
            . 'bytes proves nothing: ' . json_encode([
               'wireLength' => $probe['wireLength'],
               'frames' => array_slice($probe['frames'], 0, 12),
            ]);
      }

      if ($probe['deferredStreamFrames'] === 0) {
         return 'M2 deferred stream 1 received no HEADERS/DATA frames — the deferred response '
            . 'never reached the wire as HTTP/2: ' . json_encode([
               'frames' => array_slice($probe['frames'], 0, 12),
            ]);
      }

      return true;
   },
);
