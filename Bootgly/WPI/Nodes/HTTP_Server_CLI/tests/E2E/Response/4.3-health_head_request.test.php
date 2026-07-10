<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// ! HEAD probes get the same head with Content-Length and NO body
//   (RFC 9110 §9.3.2)
$expected = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Cache-Control: no-store\r\n"
   . "Content-Type: application/json\r\n"
   . "Content-Length: 15\r\n"
   . "\r\n";

return new Specification(
   description: 'It should answer HEAD health probes without a body',

   request: function () {
      return "HEAD /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   // ! Never dispatched — the health guard answers before SAPI routing
   response: function (Request $Request, Response $Response): Response {
      return $Response(code: 500, body: 'handler must not run');
   },
   responseLength: strlen($expected),

   test: function ($response) use ($expected) {
      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'HEAD health response not matched';
      }

      return true;
   }
);
