<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should handle multi-value Set-Cookie headers via getAll()',

   // HTTP response with multiple Set-Cookie headers
   response: function () {
      return "HTTP/1.1 200 OK\r\nSet-Cookie: session=abc123; Path=/\r\nSet-Cookie: user=john; HttpOnly\r\nSet-Cookie: theme=dark; Secure\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/login'
      );
   },

   // Test: Set-Cookie should NOT be combined with comma
   test: function (Response $Response) {
      // @ get() returns comma-joined (traditional behavior)
      $joined = $Response->Header->get('Set-Cookie');
      yield assert(
         assertion: str_contains($joined, 'session=abc123'),
         description: "get() contains session cookie"
      );

      // @ getAll() returns array of individual cookies (new behavior)
      $cookies = $Response->Header->getAll('Set-Cookie');
      yield assert(
         assertion: count($cookies) === 3,
         description: "getAll() returns 3 cookies: " . count($cookies)
      );

      yield assert(
         assertion: $cookies[0] === 'session=abc123; Path=/',
         description: "First cookie: {$cookies[0]}"
      );

      yield assert(
         assertion: $cookies[1] === 'user=john; HttpOnly',
         description: "Second cookie: {$cookies[1]}"
      );

      yield assert(
         assertion: $cookies[2] === 'theme=dark; Secure',
         description: "Third cookie: {$cookies[2]}"
      );
   }
);
