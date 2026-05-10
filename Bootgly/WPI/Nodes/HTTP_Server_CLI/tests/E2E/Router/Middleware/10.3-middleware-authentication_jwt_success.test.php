<?php

use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$JWT = new JWT('bootgly-test-secret-32-bytes-long');
$token = $JWT->sign([
   'sub' => 'e2e-user',
   'exp' => time() + 60,
]);

return new Specification(
   description: 'It should authorize a real HTTP request with JWT middleware',

   request: function () use ($token) {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(new JWTGuard($JWT)))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'authorized');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 200 OK')
         && str_contains($response, 'authorized')
            ?: 'JWT middleware did not authorize the request';
   }
);