<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Basic as BasicGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject wrong Basic credentials with authentication middleware',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Basic YWRtaW46d3Jvbmc=\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(
         new BasicGuard(function (string $username, string $password): bool {
            return $username === 'admin' && $password === 'secret';
         })
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 401 Unauthorized')
         && str_contains($response, 'WWW-Authenticate: Basic realm="Protected area"')
         && str_contains($response, 'handler executed') === false
            ?: 'Basic middleware did not reject wrong credentials';
   }
);
