<?php

use function gzdecode;
use function str_contains;
use function str_repeat;
use function strpos;
use function substr;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Compression;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should compress large response body with gzip',

   request: function () {
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: localhost\r
      Accept-Encoding: gzip, deflate\r
      \r\n
      HTTP;
   },
   middlewares: [new Compression(minSize: 100)],
   response: function (Request $Request, Response $Response): Response {
      $body = str_repeat('Hello World! ', 100);
      return $Response(body: $body);
   },

   test: function ($response) {
      // @ Assert Content-Encoding header
      if (str_contains($response, 'Content-Encoding: gzip') === false) {
         return 'Content-Encoding: gzip header not found';
      }

      // @ Assert Vary header
      if (str_contains($response, 'Vary: Accept-Encoding') === false) {
         return 'Vary: Accept-Encoding header not found';
      }

      // @ Assert body can be decompressed
      $bodyStart = strpos($response, "\r\n\r\n");
      if ($bodyStart === false) {
         return 'Response body separator not found';
      }

      $compressedBody = substr($response, $bodyStart + 4);
      $decompressed = gzdecode($compressedBody);
      if ($decompressed === false) {
         return 'Failed to decompress gzip body';
      }

      $expected = str_repeat('Hello World! ', 100);
      if ($decompressed !== $expected) {
         return 'Decompressed body does not match original';
      }

      return true;
   }
);
