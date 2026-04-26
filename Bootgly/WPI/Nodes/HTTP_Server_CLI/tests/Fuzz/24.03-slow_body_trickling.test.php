<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function min;
use function microtime;
use function mt_rand;
use function preg_match;
use function strlen;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_client;
use function substr;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Property;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Sockets;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Fuzz 24.03 — slow body trickling over TCP.
 *
 * Property: a server that supports `Content-Length` framing MUST NOT
 * invoke the handler until all declared body bytes have been received,
 * regardless of how slowly the client trickles them in.
 *
 * Strategy: open a fresh socket, write headers + a few opening body
 * bytes, sleep, write more, sleep, ... until `CL` bytes are sent. A
 * non-blocking poll loop checks that *no response bytes* are read until
 * the final write completes. After that, the response MUST be a clean
 * 2xx/4xx with no `5xx`.
 *
 * Invariant violated if: server responds with 5xx OR responds before the
 * last body byte is written (premature handler invocation) OR never
 * responds (hang) within the post-final-write window.
 */

$probe = ['ok' => true, 'failure' => null];

return new Specification(
   description: 'Slow body trickling: handler must not run before last CL byte',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $specIndex) use (&$probe): string {
         Sockets::prime($hostPort, $specIndex, '/fuzz-trickle');

         $result = Property::test(
            generator: function (int $i) use ($hostPort): array {
               // @ Small bodies so the test stays fast — slowness is
               //   the inter-write delay, not the body length.
               $size = mt_rand(8, 64);
               $chunkSize = mt_rand(1, 4);
               return [
                  'hostPort' => $hostPort,
                  'size' => $size,
                  'chunkSize' => $chunkSize,
               ];
            },
            invariant: function (array $input): bool|string {
               $sock = @stream_socket_client(
                  "tcp://{$input['hostPort']}", $errno, $errstr, timeout: 2
               );
               if (! is_resource($sock)) {
                  return "connect failed ({$errno}): {$errstr}";
               }
               stream_set_blocking($sock, false);

               $size = $input['size'];
               $chunk = $input['chunkSize'];
               $headers =
                  "POST /fuzz-trickle HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Length: {$size}\r\n"
                  . "Content-Type: application/octet-stream\r\n"
                  . "Connection: close\r\n\r\n";

               if (@fwrite($sock, $headers) !== strlen($headers)) {
                  @fclose($sock);
                  return 'short write headers';
               }

               // @ Read helper — non-blocking poll.
               $read = static function ($s): string {
                  $r = @fread($s, 8192);
                  return $r === false ? '' : $r;
               };

               $premature = '';
               $written = 0;
               while ($written < $size) {
                  $take = min($chunk, $size - $written);
                  $bytes = str_repeat('Z', $take);
                  $n = @fwrite($sock, $bytes);
                  if ($n !== $take) {
                     @fclose($sock);
                     return "short write at offset {$written}";
                  }
                  $written += $take;
                  // @ Tiny gap so the server's event loop runs.
                  usleep(2_000);
                  if ($written < $size) {
                     $premature .= $read($sock);
                  }
               }

               // ! Property violation: handler ran before all bytes arrived.
               if ($premature !== '') {
                  @fclose($sock);
                  return 'handler invoked before last byte; premature='
                     . substr($premature, 0, 80);
               }

               // @ Drain the response (server uses Connection: close).
               $response = '';
               $deadline = microtime(true) + 2.0;
               while (microtime(true) < $deadline) {
                  $bytes = $read($sock);
                  if ($bytes !== '') {
                     $response .= $bytes;
                     continue;
                  }
                  if (feof($sock)) break;
                  usleep(10_000);
               }
               @fclose($sock);

               if ($response === '') {
                  return 'no response after final body byte';
               }
               if (preg_match('#^HTTP/1\.1 5\d\d#', $response) === 1) {
                  return '5xx after slow-trickled body';
               }
               return true;
            },
            iterations: 30,
         );

         if ($result !== true) {
            $probe['ok'] = false;
            $probe['failure'] = $result;
         }

         return "GET /fuzz-trickle HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/fuzz-trickle', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'OK');
      });
   },

   test: function (array $responses) use (&$probe): bool|string {
      if ($probe['ok'] !== true) {
         return 'Slow-trickle invariant violated: ' . $probe['failure'];
      }
      return true;
   }
);
