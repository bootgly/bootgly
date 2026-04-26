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
use function in_array;
use function mt_rand;
use function preg_match;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Grammar\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Property;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Fuzz\Sockets;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Fuzz 24.04 — multipart/form-data shape fuzzing.
 *
 * Generates multipart bodies with random shapes (variable number of
 * fields and files, variable sizes, random boundary). The body is
 * **structurally legal** — every part has CRLF terminators and the body
 * ends with a closing boundary.
 *
 * Invariant: response status ∈ {200, 400, 411, 413, 415}; never `5xx`,
 * never empty, never a hang.
 */

$probe = ['ok' => true, 'failure' => null];

return new Specification(
   description: 'Multipart shape fuzz: any legal shape must yield a clean status',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort, int $specIndex) use (&$probe): string {
         Sockets::prime($hostPort, $specIndex, '/fuzz-multipart');

         $result = Property::test(
            generator: function (int $i) use ($hostPort): array {
               $fields = mt_rand(0, 8);
               $files  = mt_rand(0, 4);
               $fieldSize = mt_rand(1, 64);
               $fileSize  = mt_rand(1, 256);
               [$body, $boundary] = Body::form(
                  fields: $fields,
                  files: $files,
                  fieldSize: $fieldSize,
                  fileSize: $fileSize,
               );
               $cl = strlen($body);
               $bytes =
                  "POST /fuzz-multipart HTTP/1.1\r\n"
                  . "Host: localhost\r\n"
                  . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                  . "Content-Length: {$cl}\r\n"
                  . "Connection: close\r\n"
                  . "\r\n"
                  . $body;
               return ['bytes' => $bytes, 'hostPort' => $hostPort, 'shape' => "f={$fields},files={$files}"];
            },
            invariant: function (array $input): bool|string {
               $response = Sockets::probe($input['hostPort'], $input['bytes'], timeout: 5.0);
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
               $allowed = [200, 400, 411, 413, 415];
               if (! in_array($status, $allowed, true)) {
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

         return "GET /fuzz-multipart HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/fuzz-multipart', function (Request $Request, Response $Response) {
         $count = count($Request->fields ?? []) + count($Request->files ?? []);
         return $Response(code: 200, body: "OK parts={$count}");
      });
   },

   test: function (array $responses) use (&$probe): bool|string {
      if ($probe['ok'] !== true) {
         return 'Multipart shape invariant violated: ' . $probe['failure'];
      }
      return true;
   }
);
