<?php

use Bootgly\API\Security\JWT;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\JWT as JWTGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


$JWT = new JWT('bootgly-test-secret-32-bytes-long');
$token = $JWT->sign([
   'sub' => 'e2e-user',
   'exp' => time() + 60,
]);
$Strategy = new Authenticating(new JWTGuard($JWT));

return new Specification(
   description: 'It should reset authentication metadata between sequential requests',

   requests: [
      function () use ($token) {
         return "GET /auth HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer {$token}\r\n\r\n";
      },
      function () {
         return "GET /open HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router) use ($Strategy) {
      yield $Router->route('/auth', function (Request $Request, Response $Response) {
         return $Response(body: 'auth:' . $Request->identity->id . ':' . $Request->claims['sub']);
      }, GET, middlewares: [new Authentication($Strategy)]);

      yield $Router->route('/open', function (Request $Request, Response $Response) {
         $identity = $Request->identity === null ? 'null' : 'leak';
         $claims = $Request->claims === [] ? 'empty' : 'leak';

         return $Response(body: "open:{$identity}:{$claims}");
      }, GET);
   },

   test: function (array $responses) {
      return str_contains($responses[0], 'auth:e2e-user:e2e-user')
         && str_contains($responses[1], 'open:null:empty')
            ?: 'Authentication metadata leaked between sequential requests';
   }
);
