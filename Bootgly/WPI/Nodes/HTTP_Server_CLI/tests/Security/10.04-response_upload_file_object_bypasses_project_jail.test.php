<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Response::upload()` accepts only project-relative string paths and
 * enforces a realpath + str_starts_with jail against BOOTGLY_PROJECT->path.
 *
 * An attacker may attempt to escape the project directory via path traversal:
 *   $Response->upload('../../../etc/passwd')
 *
 * The guard must reject any path that resolves outside BOOTGLY_PROJECT->path,
 * returning 403 before any file bytes are read.
 *
 * Note: the former attack vector — passing a `File` object directly to bypass
 * the guard — is eliminated by restricting the method signature to `string`.
 */

return new Specification(
   description: 'Response::upload() must reject string path traversal outside projects/',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /leak HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/leak', function (Request $Request, Response $Response) {
         // : Simulates an attacker-controlled path that traverses out of the
         //   project jail. Must be rejected with 403.
         return $Response->upload('../../../etc/passwd');
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, 'root:') || str_contains($response, '/bin/bash')) {
         return 'Path traversal succeeded: Response::upload() served /etc/passwd '
            . 'via string path traversal outside projects/. '
            . 'Fix: ensure the realpath guard is applied before opening the file.';
      }

      if (! str_contains($response, '403')) {
         return 'Unexpected response (expected 403 from path traversal guard): '
            . \substr($response, 0, 200);
      }

      return true;
   }
);
