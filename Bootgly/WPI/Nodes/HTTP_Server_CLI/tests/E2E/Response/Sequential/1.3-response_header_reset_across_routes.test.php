<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   requests: [
      function () {
         return "GET /with-header HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /without-header HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/with-header', function ($Request, $Response) {
         return $Response(
            headers: ['X-Custom' => 'Foo'],
            body: 'With Header'
         );
      }, GET);

      yield $Router->route('/without-header', function ($Request, $Response) {
         return $Response(body: 'Without Header');
      }, GET);
   },

   test: function (array $responses) {
      $expectedWithHeader = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      X-Custom: Foo\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 11\r
      \r
      With Header
      HTML_RAW;

      $expectedWithoutHeader = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 14\r
      \r
      Without Header
      HTML_RAW;

      // @ Assert Response 1
      if ($responses[0] !== $expectedWithHeader) {
         Vars::$labels = ['Response 1:', 'Expected:'];
         dump(json_encode($responses[0]), json_encode($expectedWithHeader));
         return 'First request: expected X-Custom header';
      }

      // @ Assert Response 2
      if ($responses[1] !== $expectedWithoutHeader) {
         Vars::$labels = ['Response 2:', 'Expected:'];
         dump(json_encode($responses[1]), json_encode($expectedWithoutHeader));
         return 'Header leaked: second request still has X-Custom from first';
      }

      return true;
   }
);
