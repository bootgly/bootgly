<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\SecureHeaders;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should set all security headers on the response',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   middlewares: [new SecureHeaders],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Secure!');
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      X-Content-Type-Options: nosniff\r
      X-Frame-Options: SAMEORIGIN\r
      X-XSS-Protection: 1; mode=block\r
      Referrer-Policy: strict-origin-when-cross-origin\r
      Content-Security-Policy: default-src 'self'\r
      Permissions-Policy: camera=(), microphone=(), geolocation=()\r
      Strict-Transport-Security: max-age=31536000; includeSubDomains\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 7\r
      \r
      Secure!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Secure headers not matched';
      }

      return true;
   }
);
