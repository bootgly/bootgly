<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   description: 'It should advertise RFC 9728 resource_metadata in a real Bearer challenge',

   request: function () {
      return "POST /mcp HTTP/1.1\r\nHost: localhost\r\nContent-Length: 0\r\n\r\n";
   },
   middlewares: [
      new Authentication(new Authenticating(
         new Bearer(
            function (): bool {
               return false;
            },
            realm: 'mcp "tools"',
            metadata: 'https://mcp.example.com/.well-known/oauth-protected-resource'
         )
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   // @ The request carries no token: no error code (RFC 6750 §3.1)
   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 401 Unauthorized')
         && str_contains($response, "\r\nWWW-Authenticate: Bearer realm=\"mcp \\\"tools\\\"\", resource_metadata=\"https://mcp.example.com/.well-known/oauth-protected-resource\"\r\n")
         && str_contains($response, 'handler executed') === false
            ?: 'Bearer challenge did not advertise resource_metadata';
   }
);
