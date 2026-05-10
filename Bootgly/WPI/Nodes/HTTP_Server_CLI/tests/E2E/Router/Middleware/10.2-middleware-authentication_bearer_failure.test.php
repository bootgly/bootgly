<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject a real HTTP request with invalid Bearer middleware credentials',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer bad-token\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(
         new Bearer(function (): bool {
            return false;
         })
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 401 Unauthorized')
         && str_contains($response, 'WWW-Authenticate: Bearer realm="Protected area", error="invalid_token"')
         && str_contains($response, 'handler executed') === false
            ?: 'Bearer middleware did not reject the request';
   }
);