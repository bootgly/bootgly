<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should process request body when HTTP verb is POST',
   Separator: new Separator(line: 'Request Body'),

   request: function () {

      return
      <<<HTTP
      POST / HTTP/1.1\r
      User-Agent: Bootgly/TCP-Server\r
      Content-Type: text/plain\r
      Content-Length: 7\r
      \r
      Testing
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $Request->receive();
      return $Response(body: $Request->input);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 7\r
      \r
      Testing
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
