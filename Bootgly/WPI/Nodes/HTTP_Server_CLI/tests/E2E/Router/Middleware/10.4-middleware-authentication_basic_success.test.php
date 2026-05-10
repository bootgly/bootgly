<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic as BasicGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should authorize a real HTTP request with Basic middleware',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Basic YWRtaW46c2VjcmV0\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(
         new BasicGuard(function (string $username, string $password): bool {
            return $username === 'admin' && $password === 'secret';
         })
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'authorized');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'authorized')
            ?: 'Basic middleware did not authorize the request';
   }
);