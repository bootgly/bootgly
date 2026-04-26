<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function in_array;
use function mt_rand;
use function preg_match;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Property;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Sockets;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Fuzz 24.05 — degenerate but legal framings.
 *
 * Picks a random shape from a small set of intentionally-edge framings
 * (each individually legal per RFC 9112):
 *
 *   shape 0: chunked body with immediate `0\r\n\r\n` (zero-length stream)
 *   shape 1: POST with `Content-Length: 0`
 *   shape 2: GET with `Content-Length: 0` and many empty `X-Pad: ` headers
 *   shape 3: POST chunked with a single chunk + trailer section
 *
 * Invariant: status ∈ {200, 400, 411}; never `5xx`; never hang.
 */

$probe = ['ok' => true, 'failure' => null];

return new Specification(
   description: 'Degenerate framing shapes: legal framings must not trigger 5xx or hangs',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $specIndex) use (&$probe): string {
         Sockets::prime($hostPort, $specIndex, '/fuzz-frame');

         $result = Property::test(
            generator: function (int $i) use ($hostPort): array {
               $shape = mt_rand(0, 3);
               $bytes = match ($shape) {
                  0 =>
                     "POST /fuzz-frame HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "Transfer-Encoding: chunked\r\n"
                     . "Connection: close\r\n\r\n"
                     . "0\r\n\r\n",
                  1 =>
                     "POST /fuzz-frame HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "Content-Length: 0\r\n"
                     . "Connection: close\r\n\r\n",
                  2 => (function () {
                     $pads = '';
                     for ($k = 0; $k < mt_rand(1, 16); $k++) {
                        $pads .= "X-Pad-{$k}: \r\n";
                     }
                     return
                        "GET /fuzz-frame HTTP/1.1\r\n"
                        . "Host: localhost\r\n"
                        . $pads
                        . "Connection: close\r\n\r\n";
                  })(),
                  default => (function () {
                     // chunked with one chunk + trailer-section + terminator
                     return
                        "POST /fuzz-frame HTTP/1.1\r\n"
                        . "Host: localhost\r\n"
                        . "Transfer-Encoding: chunked\r\n"
                        . "Connection: close\r\n\r\n"
                        . "5\r\nABCDE\r\n"
                        . "0\r\n"
                        . "X-Trailer: ok\r\n"
                        . "\r\n";
                  })(),
               };
               return ['bytes' => $bytes, 'shape' => $shape, 'hostPort' => $hostPort];
            },
            invariant: function (array $input): bool|string {
               $response = Sockets::probe($input['hostPort'], $input['bytes'], timeout: 3.0);
               if ($response === '') {
                  return "empty response (timeout) for shape {$input['shape']}";
               }
               if (preg_match('#^HTTP/1\.1 5\d\d#', $response) === 1) {
                  return "5xx for shape {$input['shape']}";
               }
               if (preg_match('#^HTTP/1\.1 (\d{3})#', $response, $m) !== 1) {
                  return "malformed response for shape {$input['shape']}";
               }
               $status = (int) $m[1];
               if (! in_array($status, [200, 400, 411], true)) {
                  return "unexpected status {$status} for shape {$input['shape']}";
               }
               return true;
            },
            iterations: 60,
         );

         if ($result !== true) {
            $probe['ok'] = false;
            $probe['failure'] = $result;
         }

         return "GET /fuzz-frame HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/fuzz-frame', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'OK');
      });
   },

   test: function (array $responses) use (&$probe): bool|string {
      if ($probe['ok'] !== true) {
         return 'Degenerate framing invariant violated: ' . $probe['failure'];
      }
      return true;
   }
);
