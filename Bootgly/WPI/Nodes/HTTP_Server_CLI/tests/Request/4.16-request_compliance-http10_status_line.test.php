<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should respond with HTTP/1.0 status-line for HTTP/1.0 clients (RFC 9110 §2.5)',

   request: function () {
      return "GET / HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'ok');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.0 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 2\r
      \r
      ok
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
);
