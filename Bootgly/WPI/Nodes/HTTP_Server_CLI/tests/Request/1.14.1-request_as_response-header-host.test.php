<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {

      return
      <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com\r
      \r
      
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $host = $Request->host;
      return $Response(body: $host);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 15\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      lab.bootgly.com
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
