<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should resolve real IP from X-Forwarded-For via trusted proxy',

   request: function () {
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      X-Forwarded-For: 203.0.113.50, 70.41.3.18\r
      \r\n
      HTTP;
   },
   middlewares: [new TrustedProxy(proxies: ['127.0.0.1'])],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: $Request->address);
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      203.0.113.50
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'TrustedProxy IP resolution not matched';
      }

      return true;
   }
);
