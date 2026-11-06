<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\CORS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   description: 'It should expose configured response headers to an allowed origin',

   request: function () {
      return <<<HTTP
      POST /mcp HTTP/1.1\r
      Host: localhost\r
      Origin: https://app.example.com\r
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
      return $Response(body: 'MCP');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Vary: Origin\r
      Access-Control-Allow-Origin: https://app.example.com\r
      Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS\r
      Access-Control-Allow-Headers: Content-Type, Authorization\r
      Access-Control-Max-Age: 86400\r
      Access-Control-Expose-Headers: Mcp-Session-Id, WWW-Authenticate\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 3\r
      \r
      MCP
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'CORS Access-Control-Expose-Headers not matched';
      }

      return true;
   }
);
