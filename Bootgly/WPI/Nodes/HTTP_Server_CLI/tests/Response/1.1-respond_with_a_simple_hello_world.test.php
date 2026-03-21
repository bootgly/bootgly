<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'Response'),

   request: function () {
      // return $Request->get('/');
      return "GET / HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Hello World!');
   },

   test: function ($response) {
      /*
      return $Response->status === '200 OK'
      && $Response->body === 'Hello World!';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response not matched';
      }

      return true;
   }
);
