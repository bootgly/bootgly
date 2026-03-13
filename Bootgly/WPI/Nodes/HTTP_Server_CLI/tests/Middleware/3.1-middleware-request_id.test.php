<?php

use function preg_match;
use function str_contains;

use Bootgly\WPI\Modules\HTTP\Server\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should generate X-Request-Id header on the response',

   request: function () {
      return "GET / HTTP/1.0\r\n\r\n";
   },
   middlewares: [new RequestId],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'OK');
   },

   test: function ($response) {
      // @ Assert X-Request-Id header present with UUID v4 format
      if (str_contains($response, 'X-Request-Id: ') === false) {
         return 'X-Request-Id header not found';
      }

      if (preg_match('/X-Request-Id: ([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/', $response, $matches) !== 1) {
         return 'X-Request-Id does not match UUID v4 format';
      }

      // @ Assert response status
      if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
         return 'Expected 200 OK status';
      }

      return true;
   }
);
