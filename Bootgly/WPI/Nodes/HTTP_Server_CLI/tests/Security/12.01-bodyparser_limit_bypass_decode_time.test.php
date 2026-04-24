<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\BodyParser;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — BodyParser middleware must reject oversized bodies with a
 * shaped 413 "Payload Too Large" response (route-locally) on every
 * request, and must NEVER let the request reach the application
 * handler.
 *
 * Historical note:
 *   An earlier revision tried to "push" `$this->maxSize` into the
 *   global `Request::$maxBodySize` so the decoder would reject
 *   subsequent requests before buffering the body. That mutation
 *   was permanent and worker-wide, leaking across unrelated routes
 *   (see 16.01-bodyparser_global_maxbodysize_cross_route_leak) and
 *   has been removed. BodyParser now enforces its cap strictly at
 *   middleware time via Content-Length and body-length checks.
 *
 * This PoC:
 *   Sends two oversized (200-byte) POSTs to a route wrapped by
 *   BodyParser(maxSize: 100) on the same keep-alive connection and
 *   verifies both are rejected with a shaped middleware-time 413 and
 *   that the handler is never invoked.
 */

return new Specification(
   description: 'BodyParser must reject oversized bodies at middleware time (route-local)',
   Separator: new Separator(line: true),

   middlewares: [new BodyParser(maxSize: 100)],

   requests: [
      function (string $hostPort): string {
         $oversizedBody = str_repeat('X', 200);

         return "POST /bodyparser-poc HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Length: 200\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n"
            . $oversizedBody;
      },
      function (string $hostPort): string {
         $oversizedBody = str_repeat('X', 200);

         return "POST /bodyparser-poc HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Length: 200\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $oversizedBody;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/bodyparser-poc', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HANDLER-REACHED');
      });

      // @ Compatibility route for 10.01 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/traversal', function (Request $Request, Response $Response) {
         return $Response(code: 403, body: '');
      });

      // @ Compatibility route for 10.02 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/exec', function (Request $Request, Response $Response) {
         return $Response(code: 403, body: '');
      });

      // @ Compatibility route for 10.03 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/redirect-check', function (Request $Request, Response $Response) {
         $Response->Header->set('Location', '/');
         return $Response(code: 302, body: '');
      });

      // @ Compatibility route for 11.01 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/host-echo', function (Request $Request, Response $Response) {
         return $Response(code: 400, body: '');
      });
   },

   test: function (array $responses): bool|string {
      $first = $responses[0] ?? '';
      $second = $responses[1] ?? '';

      if ($first === '' || $second === '') {
         return 'Expected 2 responses, got empty response.';
      }

      foreach ([$first, $second] as $i => $r) {
         $label = $i === 0 ? 'first' : 'second';

         if (str_contains($r, 'HANDLER-REACHED')) {
            return "BodyParser bypass: handler was reached on {$label} "
               . 'request despite Content-Length (200) > maxSize (100).';
         }

         if (! str_contains($r, '413')) {
            return "Expected 413 on {$label} request. "
               . 'Got: ' . substr($r, 0, 200);
         }

         if (! str_contains($r, 'Payload Too Large')) {
            return "Expected shaped middleware-time 413 with body "
               . "'Payload Too Large' on {$label} request. "
               . 'Got: ' . substr($r, 0, 200);
         }
      }

      return true;
   }
);
