<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function count;
use function mt_rand;
use function preg_match_all;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Grammar\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Property;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Sockets;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Fuzz 24.02 — pipelined CL + chunked body mix.
 *
 * Generates a batch of 2..5 POST requests glued back-to-back on a single
 * connection, each randomly framed with either `Content-Length` or
 * `Transfer-Encoding: chunked`. Each request carries an `X-Fuzz-Seq: N`
 * header.
 *
 * Invariant: server returns ≥ 1 response, all bodies arrive in order
 * (echoes the seq), and no `5xx` shows up. (Some servers may legally
 * close after a chunked request — we accept fewer responses than batch
 * size as long as ordering holds and no 5xx occurs.)
 */

$probe = ['ok' => true, 'failure' => null];

return new Specification(
   description: 'Pipelined CL + chunked body mix: ordered, no 5xx',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $specIndex) use (&$probe): string {
         Sockets::prime($hostPort, $specIndex, '/fuzz-pipe');

         $result = Property::test(
            generator: function (int $i) use ($hostPort): array {
               $batch = mt_rand(2, 5);
               $bytes = '';
               $expected = [];
               for ($n = 0; $n < $batch; $n++) {
                  $useChunked = mt_rand(0, 1) === 1;
                  if ($useChunked) {
                     $body = Body::chunk(mt_rand(0, 256), 1, 64);
                     $bytes .=
                        "POST /fuzz-pipe HTTP/1.1\r\n"
                        . "Host: localhost\r\n"
                        . "Transfer-Encoding: chunked\r\n"
                        . "X-Fuzz-Seq: {$n}\r\n"
                        . "Content-Type: application/octet-stream\r\n"
                        . "\r\n"
                        . $body;
                  }
                  else {
                     [$body, $cl] = Body::fill(mt_rand(0, 256));
                     $bytes .=
                        "POST /fuzz-pipe HTTP/1.1\r\n"
                        . "Host: localhost\r\n"
                        . "Content-Length: {$cl}\r\n"
                        . "X-Fuzz-Seq: {$n}\r\n"
                        . "Content-Type: application/octet-stream\r\n"
                        . "\r\n"
                        . $body;
                  }
                  $expected[] = (string) $n;
               }
               // @ Final request closes the keep-alive cleanly.
               $bytes .=
                  "GET /fuzz-pipe HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Connection: close\r\n\r\n";
               return ['bytes' => $bytes, 'expected' => $expected, 'hostPort' => $hostPort];
            },
            invariant: function (array $input): bool|string {
               $response = Sockets::probe($input['hostPort'], $input['bytes'], timeout: 3.0);
               if ($response === '') {
                  return 'empty response (timeout / closed)';
               }
               // @ Reject 5xx outright.
               if (preg_match_all('#^HTTP/1\.1 5\d\d#m', $response) > 0) {
                  return '5xx in pipelined response window';
               }
               // @ Verify ordering: the seq markers in returned bodies must
               //   be a non-decreasing prefix of the expected sequence.
               $found = [];
               if (preg_match_all('#X-Echo-Seq:\s*(\d+)#', $response, $matches) > 0) {
                  $found = $matches[1];
               }
               if ($found === []) {
                  return 'no X-Echo-Seq markers in pipelined response window';
               }

               $expected = $input['expected'];
               if ($found[0] !== $expected[0]) {
                  return "ordering violated at first response: got {$found[0]} expected {$expected[0]}";
               }

               for ($k = 0; $k < count($found); $k++) {
                  if (! isset($expected[$k]) || $found[$k] !== $expected[$k]) {
                     return "ordering violated at index {$k}: got {$found[$k]} expected " . ($expected[$k] ?? 'none');
                  }
               }
               return true;
            },
            iterations: 60,
         );

         if ($result !== true) {
            $probe['ok'] = false;
            $probe['failure'] = $result;
         }

         return "GET /fuzz-pipe HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/fuzz-pipe', function (Request $Request, Response $Response) {
         $seq = $Request->Header->get('X-Fuzz-Seq') ?? '-';
         $Response->Header->set('X-Echo-Seq', (string) $seq);
         return $Response(code: 200, body: 'OK');
      });
   },

   test: function (array $responses) use (&$probe): bool|string {
      if ($probe['ok'] !== true) {
         return 'Pipelined invariant violated: ' . $probe['failure'];
      }
      return true;
   }
);
