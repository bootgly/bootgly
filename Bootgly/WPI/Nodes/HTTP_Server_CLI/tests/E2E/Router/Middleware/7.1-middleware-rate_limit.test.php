<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should set rate limit headers on the response',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   middlewares: [new RateLimit(limit: 100, window: 60)],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'OK');
   },

   test: function ($response) {
      // @ Assert rate limit headers present
      if (str_contains($response, 'X-RateLimit-Limit: 100') === false) {
         return 'X-RateLimit-Limit header not found or wrong value';
      }

      if (str_contains($response, 'X-RateLimit-Remaining: ') === false) {
         return 'X-RateLimit-Remaining header not found';
      }

      if (str_contains($response, 'X-RateLimit-Reset: ') === false) {
         return 'X-RateLimit-Reset header not found';
      }

      // @ Assert response status and body
      if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
         return 'Expected 200 OK status';
      }

      if (str_contains($response, 'OK') === false) {
         return 'Expected body not found';
      }

      return true;
   }
);
