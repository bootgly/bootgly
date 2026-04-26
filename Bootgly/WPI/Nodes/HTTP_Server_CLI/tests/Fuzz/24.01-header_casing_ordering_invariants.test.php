<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function implode;
use function json_encode;
use function mt_rand;
use function preg_match;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Grammar\Headers;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Property;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Sockets;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Fuzz 24.01 — header casing / ordering invariants.
 *
 * Grammar: pick a random subset of {Host, Content-Length, Connection,
 * User-Agent, Accept, Authorization, Cookie, X-Forwarded-For} and emit
 * them in random order with random mixed-case names and random OWS around
 * the colon. Always include a `Host` and a valid framing (CL: 0).
 *
 * Invariant: server returns 200 with body `OK` (the trigger route below
 * unconditionally responds OK). Failure modes covered: 500 from a panicked
 * parser, 400 from a regression on RFC-valid OWS / no-space header parsing,
 * hang/timeout from header-loop OOB.
 */

$probe = ['ok' => true, 'failure' => null];

return new Specification(
   description: 'Header casing/ordering: server must accept any RFC-9110 permutation',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $specIndex) use (&$probe): string {
         Sockets::prime($hostPort, $specIndex, '/fuzz-trigger');

         $result = Property::test(
            generator: function (int $i) use ($hostPort): array {
               $headers = [
                  'Host'              => 'localhost',
                  'Content-Length'    => '0',
                  'Connection'        => 'close',
                  'User-Agent'        => Headers::mint(8, 24),
                  'Accept'            => '*/*',
                  'Authorization'     => 'Bearer ' . Headers::mint(16, 32),
                  'Cookie'            => 'sid=' . Headers::mint(8, 16),
                  'X-Forwarded-For'   => '203.0.113.' . mt_rand(1, 254),
               ];
               $lines = Headers::permute($headers, mix: true, pad: true);
               $raw = "GET /fuzz-trigger HTTP/1.1\r\n"
                  . implode("\r\n", $lines)
                  . "\r\n\r\n";
               return ['raw' => $raw, 'hostPort' => $hostPort];
            },
            invariant: function (array $input): bool|string {
               $response = Sockets::probe($input['hostPort'], $input['raw'], timeout: 2.0);

               if ($response === '') {
                  return 'empty response (timeout / closed) for: '
                     . json_encode(substr($input['raw'], 0, 200));
               }
               // @ Every generated header line is RFC-valid. Any non-200
               //   response means legal casing/OWS/no-space variants changed
               //   request semantics.
               if (preg_match('#^HTTP/1\.1 200\b#', $response) !== 1) {
                  return 'server returned non-200 for legal permutation: '
                     . json_encode(substr($input['raw'], 0, 200))
                     . ' -- response: '
                     . json_encode(substr($response, 0, 200));
               }
               return true;
            },
            iterations: 100,
         );

         if ($result !== true) {
            $probe['ok'] = false;
            $probe['failure'] = $result;
         }

         // @ Sentinel request to satisfy the harness reader.
         return "GET /fuzz-trigger HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/fuzz-trigger', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'OK');
      });
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) use (&$probe): bool|string {
      if ($probe['ok'] !== true) {
         return 'Header permutation invariant violated: ' . $probe['failure'];
      }
      return true;
   }
);
