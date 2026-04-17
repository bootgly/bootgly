<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should send 100 Continue for Expect: 100-continue',

   request: function () {
      // @ Yield headers first (with Expect), then body separately
      yield "POST / HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 5\r\n\r\n";
      yield "hello";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: $Request->Body->raw);
   },

   test: function ($response) {
      // @ Assert
      // Server sends "100 Continue" interim response inline via fwrite,
      // then the final response with the echoed body
      if (str_contains($response, '100 Continue') && str_contains($response, '200 OK')) {
         return true;
      }

      // If 100 Continue received but 200 not yet (timing), still valid
      if (str_contains($response, '100 Continue')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Should have received 100 Continue interim response';
   }
);
