<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Session as SessionGuard;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should reject a request with Session but without identity key',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   middlewares: [
      new class implements Middleware {
         public function process (object $Request, object $Response, \Closure $next): object
         {
            $Request->Session;
            return $next($Request, $Response);
         }
      },
      new Authentication(new Authenticating(new SessionGuard))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 401 Unauthorized')
         && str_contains($response, 'handler executed') === false
            ?: 'Session middleware did not reject missing identity key';
   }
);
