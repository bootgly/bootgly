<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should return first 3 bytes of file when `bytes=0-2`',
   Separator: new Separator(left: '.2.2 - Requests Range - Client - Single Part (Valid)'),

   request: function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/1 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=0-2\r
      \r\n
      HTTP_RAW;

      return $raw;
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->upload('statics/alphanumeric.txt', close: false);
   },
   responseLength: 302,

   test: function ($response) {
      if (preg_match('/Last-Modified: (.*)\r\n/i', $response, $matches)) {
         $lastModified = $matches[1];
      } else {
         $lastModified = '?';
      }

      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 3\r
      Content-Range: bytes 0-2/62\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="alphanumeric.txt"\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      abc
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response body contains part of file uploaded by server?';
      }

      return true;
   }
);
