<?php

use function hash;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\ETag;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return 304 when If-None-Match matches ETag',

   request: function () {
      $hash = hash('xxh3', 'Hello World!');
      $etag = 'W/"' . $hash . '"';

      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      If-None-Match: {$etag}\r
      \r\n
      HTTP;
   },
   middlewares: [new ETag],
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Hello World!');
   },

   test: function ($response) {
      $hash = hash('xxh3', 'Hello World!');
      $etag = 'W/"' . $hash . '"';

      $expected = <<<HTML_RAW
      HTTP/1.1 304 Not Modified\r
      Server: Bootgly\r
      ETag: {$etag}\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 0\r
      \r\n
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'ETag 304 response not matched';
      }

      return true;
   }
);
