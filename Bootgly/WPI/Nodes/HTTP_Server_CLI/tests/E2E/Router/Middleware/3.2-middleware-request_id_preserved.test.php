<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should preserve existing X-Request-Id from request',

   request: function () {
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      X-Request-Id: my-custom-trace-id-123\r
      \r\n
      HTTP;
   },
   middlewares: [new RequestId],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'OK');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      X-Request-Id: my-custom-trace-id-123\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 2\r
      \r
      OK
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'RequestId preservation not matched';
      }

      return true;
   }
);
