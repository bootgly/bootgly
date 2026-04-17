<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   requests: [
      function () {
         return "GET /error HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/error', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      }, GET);

      yield $Router->route('/', function ($Request, $Response) {
         return $Response(body: 'OK');
      }, GET);
   },

   test: function (array $responses) {
      $expected404 = <<<HTML_RAW
      HTTP/1.1 404 Not Found\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 9\r
      \r
      Not Found
      HTML_RAW;

      $expected200 = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 2\r
      \r
      OK
      HTML_RAW;

      // @ Assert Response 1
      if ($responses[0] !== $expected404) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expected404));
         return 'First request: expected 404 Not Found';
      }

      // @ Assert Response 2
      if ($responses[1] !== $expected200) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expected200));
         return 'Status code leaked: second request got stale 404 instead of 200';
      }

      return true;
   }
);
