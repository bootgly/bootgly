<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should decode chunked request body (single chunk)',

   request: function () {
      // @ Yield headers first, then chunked body
      yield "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n";
      yield "5\r\nhello\r\n0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: $Request->Body->raw);
   },

   test: function ($response) {
      // @ Assert
      if ( ! str_contains($response, '200 OK')) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Should have responded with 200 OK';
      }

      if ( ! str_contains($response, 'hello')) {
         Vars::$labels = ['HTTP Response:'];
         dump(json_encode($response));
         return 'Response body should contain decoded chunk data "hello"';
      }

      return true;
   }
);
