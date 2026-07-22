<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function array_unique;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function mt_rand;
use function preg_match_all;
use function sort;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function substr;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Grammar\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Property;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Sockets;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Fuzz 24.06 — receive-carry fragmentation matrix.
 *
 * Generates a pipelined wire of 2..4 requests (GET / Content-Length POST /
 * chunked POST mix, each tagged `X-Fuzz-Seq: N`) and delivers it across
 * random fragment boundaries — including boundaries inside the request
 * line, header block, chunk framing and bodies. One variant forces a
 * 1-byte `P` first fragment (the h2c prior-knowledge sniff ambiguity);
 * iteration 0 delivers a small wire byte by byte.
 *
 * Invariant: exactly one response per request, in order, no 5xx — a
 * fragmented head must never be dropped or answered out of order.
 */

$probe = ['ok' => true, 'failure' => null];

return new Specification(
   description: 'Fragmented pipelined wires must yield exactly N ordered responses',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $specIndex) use (&$probe): string {
         Sockets::prime($hostPort, $specIndex, '/fuzz-carry');

         $Request = function (int $seq, int $kind): string {
            switch ($kind) {
               case 0:
                  return "GET /fuzz-carry HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "X-Fuzz-Seq: {$seq}\r\n"
                     . "\r\n";
               case 1:
                  [$body, $cl] = Body::fill(mt_rand(0, 128));
                  return "POST /fuzz-carry HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "Content-Length: {$cl}\r\n"
                     . "X-Fuzz-Seq: {$seq}\r\n"
                     . "Content-Type: application/octet-stream\r\n"
                     . "\r\n"
                     . $body;
               default:
                  $body = Body::chunk(mt_rand(0, 128), 1, 32);
                  return "POST /fuzz-carry HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "Transfer-Encoding: chunked\r\n"
                     . "X-Fuzz-Seq: {$seq}\r\n"
                     . "Content-Type: application/octet-stream\r\n"
                     . "\r\n"
                     . $body;
            }
         };

         $result = Property::test(
            generator: function (int $i) use ($hostPort, $Request): array {
               $bytes = '';
               $expected = [];

               // # Variant: 1-byte `P` first fragment (sniff ambiguity)
               $sniffDrip = ($i % 4) === 3;
               // # Variant: byte-by-byte delivery of a small wire
               $byteByByte = $i === 0;

               $batch = $byteByByte ? 2 : mt_rand(2, 4);
               for ($n = 0; $n < $batch; $n++) {
                  $kind = match (true) {
                     $byteByByte => 0,
                     $sniffDrip && $n === 0 => 1,
                     default => mt_rand(0, 2),
                  };
                  $bytes .= $Request($n, $kind);
                  $expected[] = (string) $n;
               }

               // @ Final request closes the keep-alive cleanly.
               $bytes .= "GET /fuzz-carry HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "X-Fuzz-Seq: {$batch}\r\n"
                  . "Connection: close\r\n\r\n";
               $expected[] = (string) $batch;

               // @ Fragment boundaries.
               $length = strlen($bytes);
               $cuts = [];
               if ($byteByByte) {
                  for ($p = 1; $p < $length; $p++) {
                     $cuts[] = $p;
                  }
               }
               else {
                  $splits = mt_rand(1, 16);
                  for ($s = 0; $s < $splits; $s++) {
                     $cuts[] = mt_rand(1, $length - 1);
                  }
                  if ($sniffDrip) {
                     $cuts[] = 1;
                  }
                  $cuts = array_unique($cuts);
                  sort($cuts);
               }

               $fragments = [];
               $previous = 0;
               foreach ($cuts as $cut) {
                  $fragments[] = substr($bytes, $previous, $cut - $previous);
                  $previous = $cut;
               }
               $fragments[] = substr($bytes, $previous);

               return [
                  'fragments' => $fragments,
                  'expected' => $expected,
                  'hostPort' => $hostPort,
               ];
            },
            invariant: function (array $input): bool|string {
               $socket = @stream_socket_client(
                  "tcp://{$input['hostPort']}", $errorNumber, $errorMessage, timeout: 5
               );
               if (! is_resource($socket)) {
                  return "connect failed: {$errorNumber} {$errorMessage}";
               }

               stream_set_blocking($socket, true);
               stream_set_timeout($socket, 3);

               // @ Deliver each fragment as its own transport write.
               foreach ($input['fragments'] as $fragment) {
                  if ($fragment === '') {
                     continue;
                  }

                  $remaining = $fragment;
                  while ($remaining !== '') {
                     $sent = @fwrite($socket, $remaining);
                     if ($sent === false || $sent === 0) {
                        fclose($socket);
                        return 'fragment write failed';
                     }
                     $remaining = substr($remaining, $sent);
                  }

                  usleep(2_000);
               }

               $response = '';
               while (true) {
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

                  $response .= $chunk;
               }
               fclose($socket);

               if ($response === '') {
                  return 'empty response (timeout / closed)';
               }
               if (preg_match_all('#^HTTP/1\.1 5\d\d#m', $response) > 0) {
                  return '5xx in fragmented response window';
               }

               $found = [];
               if (preg_match_all('#X-Echo-Seq:\s*(\d+)#', $response, $matches) > 0) {
                  $found = $matches[1];
               }

               $expected = $input['expected'];
               if (count($found) !== count($expected)) {
                  return 'response count mismatch: got ' . count($found)
                     . ' expected ' . count($expected)
                     . ' (fragments: ' . count($input['fragments']) . ')';
               }

               for ($k = 0; $k < count($found); $k++) {
                  if ($found[$k] !== $expected[$k]) {
                     return "ordering violated at index {$k}: got {$found[$k]} expected {$expected[$k]}";
                  }
               }

               return true;
            },
            iterations: 40,
         );

         if ($result !== true) {
            $probe['ok'] = false;
            $probe['failure'] = $result;
         }

         return "GET /fuzz-carry HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/fuzz-carry', function (Request $Request, Response $Response) {
         $seq = $Request->Header->get('X-Fuzz-Seq') ?? '-';
         $Response->Header->set('X-Echo-Seq', (string) $seq);
         return $Response(code: 200, body: 'OK');
      });
   },

   test: function (array $responses) use (&$probe): bool|string {
      if ($probe['ok'] !== true) {
         return 'Receive-carry invariant violated: ' . $probe['failure'];
      }
      return true;
   }
);
