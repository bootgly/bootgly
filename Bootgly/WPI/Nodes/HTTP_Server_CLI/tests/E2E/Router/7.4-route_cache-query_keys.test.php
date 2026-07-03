<?php

use function hrtime;
use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Cache keys include the full request target: different query strings are
// distinct entries; repeating a query string hits its stored entry
// (byte-identical response).

return new Specification(
   description: 'It should key cached responses by full target (path + query string)',

   requests: [
      function () {
         return "GET /cached/query?page=1 HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/query?page=2 HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/query?page=1 HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/cached/query', function ($Request, $Response) {
         return $Response(body: 'at=' . hrtime(true));
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [$r1, $r2, $r3] = $responses;

      foreach ($responses as $index => $response) {
         if (! str_contains($response, 'at=')) {
            Vars::$labels = ['Response ' . ($index + 1) . ':'];
            dump(json_encode($response));
            return 'Handler body missing from response ' . ($index + 1);
         }
      }

      if ($r2 === $r1) {
         Vars::$labels = ['Response 1:', 'Response 2:'];
         dump(json_encode($r1), json_encode($r2));
         return 'Different query string served the first entry — key must include the query';
      }

      if ($r3 !== $r1) {
         Vars::$labels = ['Response 1:', 'Response 3:'];
         dump(json_encode($r1), json_encode($r3));
         return 'Repeated query string must hit its stored entry (byte-identical)';
      }

      return true;
   }
);
