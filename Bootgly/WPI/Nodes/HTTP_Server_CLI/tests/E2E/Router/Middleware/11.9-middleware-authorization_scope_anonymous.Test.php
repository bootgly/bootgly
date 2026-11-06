<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Scope;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   description: 'It should answer a real request without identity with the Bearer 401 challenge',

   request: function () {
      return "POST /mcp HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n";
   },
   // @ No Authentication middleware ran: the request carries no identity at
   //   all, so the Scope answers as the authentication would (401), never
   //   with a 403 insufficient_scope.
   middlewares: [
      new Authorization(new Authorizing(
         new Scope('mcp:write', Bearer: new Bearer(
            function (): bool {
               return false;
            },
            realm: 'mcp',
            metadata: 'https://mcp.example.com/.well-known/oauth-protected-resource'
         ))
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 401 Unauthorized')
         && str_contains($response, "\r\nWWW-Authenticate: Bearer realm=\"mcp\", error=\"invalid_token\", resource_metadata=\"https://mcp.example.com/.well-known/oauth-protected-resource\"\r\n")
         && str_contains($response, 'insufficient_scope') === false
         && str_contains($response, 'handler executed') === false
            ?: 'Scope middleware did not answer the Bearer 401 challenge without identity';
   }
);
