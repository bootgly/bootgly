<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   description: 'It should never expose response headers to a disallowed origin',

   request: function () {
      return <<<HTTP
      POST /mcp HTTP/1.1\r
      Host: localhost\r
      Origin: https://evil.example.com\r
      Content-Length: 0\r
      \r\n
      HTTP;
   },
   middlewares: [
      new CORS(
         origins: ['https://app.example.com'],
         expose: ['Mcp-Session-Id', 'WWW-Authenticate']
      )
   ],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'handler executed');
   },

   test: function ($response) {
      return str_contains($response, 'HTTP/1.1 403 Forbidden')
         && str_contains($response, 'Origin not allowed')
         && str_contains($response, 'Access-Control-Expose-Headers') === false
         && str_contains($response, 'Access-Control-Allow-Origin') === false
         && str_contains($response, 'handler executed') === false
            ?: 'CORS exposed headers to a disallowed origin';
   }
);
