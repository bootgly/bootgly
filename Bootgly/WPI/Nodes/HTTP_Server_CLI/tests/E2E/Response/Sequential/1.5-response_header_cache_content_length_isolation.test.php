<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Header content-cache must NOT leak a stale Content-Length across requests that
// share the same custom header but return different body lengths. Both requests
// carry an identical `X-Cache: Hit` header (cache-eligible), but their bodies — and
// therefore their Content-Length — differ. A correct cache keys on every header
// input (Content-Length lives in `fields`), so the second response must rebuild
// with its own length, never reuse the first.

return new Specification(
   requests: [
      function () {
         return "GET /short HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /long HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/short', function ($Request, $Response) {
         return $Response(headers: ['X-Cache' => 'Hit'], body: 'AB'); // len 2
      }, GET);

      yield $Router->route('/long', function ($Request, $Response) {
         return $Response(headers: ['X-Cache' => 'Hit'], body: 'ABCDEFGHIJ'); // len 10
      }, GET);
   },

   test: function (array $responses) {
      [$short, $long] = $responses;

      if (! str_contains($short, "X-Cache: Hit")) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($short));
         return 'First response missing X-Cache header';
      }
      if (! str_contains($short, "Content-Length: 2\r\n")) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($short));
         return 'First response wrong Content-Length (expected 2)';
      }

      if (! str_contains($long, "X-Cache: Hit")) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($long));
         return 'Second response lost X-Cache header';
      }
      if (str_contains($long, "Content-Length: 2\r\n")) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($long));
         return 'Contamination: second response reused first Content-Length (2) from the header cache';
      }
      if (! str_contains($long, "Content-Length: 10\r\n")) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($long));
         return 'Second response wrong Content-Length (expected 10)';
      }

      return true;
   }
);
