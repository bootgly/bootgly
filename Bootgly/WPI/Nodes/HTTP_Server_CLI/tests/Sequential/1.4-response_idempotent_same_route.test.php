<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET / HTTP/1.0\r\n\r\n";
   },
   requests: [
      function () {
         return "GET / HTTP/1.0\r\n\r\n";
      },
      function () {
         return "GET / HTTP/1.0\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/', function ($Request, $Response) {
         return $Response(body: 'Hello World!');
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
      HTML_RAW;

      // @ Assert Response 1
      if ($responses[0] !== $expected) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expected));
         return 'First request not matched';
      }

      // @ Assert Response 2
      if ($responses[1] !== $expected) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expected));
         return 'Idempotency failed: second request to same route differs';
      }

      return true;
   }
);
