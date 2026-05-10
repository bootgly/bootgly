<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should authorize a real HTTP request with Bearer middleware',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer e2e-token\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(
         new Bearer(function (string $token): bool {
            return $token === 'e2e-token';
         })
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'authorized');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'authorized')
            ?: 'Bearer middleware did not authorize the request';
   }
);