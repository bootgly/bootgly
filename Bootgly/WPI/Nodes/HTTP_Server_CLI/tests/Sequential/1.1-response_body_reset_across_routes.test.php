<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'Sequential'),

   requests: [
      function () {
         return "GET /route-a HTTP/1.0\r\n\r\n";
      },
      function () {
         return "GET /route-b HTTP/1.0\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/route-a', function ($Request, $Response) {
         return $Response(body: 'From Route A');
      }, GET);

      yield $Router->route('/route-b', function ($Request, $Response) {
         return $Response(body: 'From Route B');
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) {
      $expectedA = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      From Route A
      HTML_RAW;

      $expectedB = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      From Route B
      HTML_RAW;

      // @ Assert Response 1
      if ($responses[0] !== $expectedA) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expectedA));
         return 'Response body leaked: first request not matched';
      }

      // @ Assert Response 2
      if ($responses[1] !== $expectedB) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expectedB));
         return 'Response body leaked: second request got stale body from first';
      }

      return true;
   }
);
