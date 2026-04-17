<?php

use function hash;
use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\ETag;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should generate ETag header on the response',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   middlewares: [new ETag],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Hello World!');
   },

   test: function ($response) {
      // @ Compute expected ETag
      $expectedHash = hash('xxh3', 'Hello World!');
      $expectedETag = 'W/"' . $expectedHash . '"';

      // @ Assert ETag header
      if (str_contains($response, 'ETag: ' . $expectedETag) === false) {
         return 'ETag header not found or hash not matched';
      }

      // @ Assert response status and body
      if (str_contains($response, 'HTTP/1.1 200 OK') === false) {
         return 'Expected 200 OK status';
      }

      if (str_contains($response, 'Hello World!') === false) {
         return 'Expected body not found';
      }

      return true;
   }
);
