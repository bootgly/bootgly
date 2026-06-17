<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Alternating distinct headers within the same second must not leak through the
// content-cache: A then B then A. Each response must carry only its own header value
// and never the other route's — proving the cache rebuilds (and re-captures its
// signature) whenever the header inputs change, even rapidly back and forth.

return new Specification(
   requests: [
      function () {
         return "GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /b HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /a HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/a', function ($Request, $Response) {
         return $Response(headers: ['X-Route' => 'A'], body: 'a');
      }, GET);

      yield $Router->route('/b', function ($Request, $Response) {
         return $Response(headers: ['X-Route' => 'B'], body: 'b');
      }, GET);
   },

   test: function (array $responses) {
      [$a1, $b, $a2] = $responses;

      if (! str_contains($a1, "X-Route: A\r\n") || str_contains($a1, "X-Route: B\r\n")) {
         Vars::$labels = ['Response A1:'];
         dump(json_encode($a1));
         return 'First /a response wrong or leaked B';
      }
      if (! str_contains($b, "X-Route: B\r\n") || str_contains($b, "X-Route: A\r\n")) {
         Vars::$labels = ['Response B:'];
         dump(json_encode($b));
         return 'Contamination: /b response leaked X-Route: A from the cache';
      }
      if (! str_contains($a2, "X-Route: A\r\n") || str_contains($a2, "X-Route: B\r\n")) {
         Vars::$labels = ['Response A2:'];
         dump(json_encode($a2));
         return 'Contamination: second /a response leaked X-Route: B from the cache';
      }

      return true;
   }
);
