<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Exercises the header content-cache HIT path: three identical requests to the same
// route returning a constant header + constant body. Every response must be
// byte-identical and correct — this is the hot path the benchmark relies on, and it
// must stay self-consistent across the persistent worker.

return new Specification(
   requests: [
      function () {
         return "GET /const HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /const HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /const HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/const', function ($Request, $Response) {
         return $Response(headers: ['Content-Type' => 'text/plain'], body: 'Hello');
      }, GET);
   },

   test: function (array $responses) {
      [$r1, $r2, $r3] = $responses;

      if ($r1 !== $r2 || $r2 !== $r3) {
         Vars::$labels = ['Response 1:', 'Response 2:', 'Response 3:'];
         dump(json_encode($r1), json_encode($r2), json_encode($r3));
         return 'Cache-hit responses diverged across identical requests';
      }
      if (! str_contains($r1, "Content-Type: text/plain\r\n")) {
         Vars::$labels = ['Response:'];
         dump(json_encode($r1));
         return 'Missing Content-Type: text/plain';
      }
      if (! str_contains($r1, "Content-Length: 5\r\n")) {
         Vars::$labels = ['Response:'];
         dump(json_encode($r1));
         return 'Wrong Content-Length (expected 5)';
      }

      return true;
   }
);
