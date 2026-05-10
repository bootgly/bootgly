<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$Requests = [];
foreach ([
   '',
   'Bearer',
   'Bearer    ',
   'Bearer a.b',
   'Token good-token',
   'Basic !!!',
   'Bearer-good-token',
] as $authorization) {
   $Requests[] = static function () use ($authorization) {
      return "GET / HTTP/1.1\r\nHost: localhost\r\nAuthorization: {$authorization}\r\n\r\n";
   };
}

return new Specification(
   description: 'It should reject malformed Authorization headers without crashing',

   requests: $Requests,
   middlewares: [
      new Authentication(new Authenticating(new Bearer(function (string $token): bool {
         return $token === 'good-token';
      })))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function (array $responses) {
      foreach ($responses as $index => $response) {
         if (str_contains($response, 'HTTP/1.1 401 Unauthorized') === false
            || str_contains($response, 'WWW-Authenticate: Bearer realm="Protected area", error="invalid_token"') === false
            || str_contains($response, 'handler executed') !== false
         ) {
            return "Authorization parser fuzz case {$index} was not rejected safely";
         }
      }

      return true;
   }
);
