<?php

use Bootgly\API\Security\Identity;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorizing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authorization\Scope;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


$Bearer = new Bearer(
   function (string $token): null|Identity {
      return $token === 'read-only-token'
         ? new Identity(id: 'mcp-client', scopes: ['mcp:read'])
         : null;
   },
   realm: 'mcp',
   metadata: 'https://mcp.example.com/.well-known/oauth-protected-resource'
);

return new Test(
   description: 'It should answer a real request lacking a scope with an insufficient_scope challenge',

   request: function () {
      return "POST /mcp HTTP/1.1\r\nHost: localhost\r\nOrigin: https://app.example.com\r\nAuthorization: Bearer read-only-token\r\nContent-Length: 0\r\n\r\n";
   },
   // @ CORS first: the 403 keeps the CORS headers, so a browser client can
   //   read the exposed WWW-Authenticate challenge.
   middlewares: [
      new CORS(
         origins: ['https://app.example.com'],
         expose: ['Mcp-Session-Id', 'WWW-Authenticate']
      ),
      new Authentication(new Authenticating($Bearer)),
      new Authorization(new Authorizing(
         new Scope(['mcp:read', 'mcp:write'], Bearer: $Bearer)
      ))
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 403 Forbidden')
         && str_contains($response, "\r\nWWW-Authenticate: Bearer realm=\"mcp\", error=\"insufficient_scope\", scope=\"mcp:read mcp:write\", resource_metadata=\"https://mcp.example.com/.well-known/oauth-protected-resource\"\r\n")
         && str_contains($response, "\r\nAccess-Control-Allow-Origin: https://app.example.com\r\n")
         && str_contains($response, "\r\nAccess-Control-Expose-Headers: Mcp-Session-Id, WWW-Authenticate\r\n")
         && str_ends_with($response, "\r\n\r\nForbidden")
         && str_contains($response, 'handler executed') === false
            ?: 'Scope middleware did not answer the insufficient_scope challenge';
   }
);
