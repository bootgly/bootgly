<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /errors/legacy HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      throw new Exception('legacy wire probe');
   },

   test: function ($response) {
      // ! Test environment keeps the legacy byte-exact 500 (body = one space)
      $expected = "HTTP/1.1 500 Internal Server Error\r\n"
         . "Server: Bootgly\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n"
         . "Content-Length: 1\r\n"
         . "\r\n"
         . ' ';

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Legacy Test-environment 500 wire not matched';
      }

      return true;
   }
);
