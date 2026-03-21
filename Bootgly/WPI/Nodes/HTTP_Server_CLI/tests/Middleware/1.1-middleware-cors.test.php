<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\CORS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should set CORS headers on the response',
   Separator: new Separator(line: 'Middleware'),

   request: function () {
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Origin: http://example.com\r
      \r\n
      HTTP;
   },
   middlewares: [new CORS],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Hello World!');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Access-Control-Allow-Origin: *\r
      Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS\r
      Access-Control-Allow-Headers: Content-Type, Authorization\r
      Access-Control-Max-Age: 86400\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'CORS headers not matched';
      }

      return true;
   }
);
