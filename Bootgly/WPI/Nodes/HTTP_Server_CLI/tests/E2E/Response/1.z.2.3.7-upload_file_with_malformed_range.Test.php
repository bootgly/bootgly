<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// RES-1 regression, the `-2` arm: a Range field with no `=` never parses
// into a ranges-specifier, so upload() rejects it with a real 400 (RFC 9110
// §14.2 allows ignore or reject) — not the 416-without-Content-Range the
// old end(400) fallthrough used to ship. The prepared caching fields
// survive by design: end() no longer cleans headers for non-416 codes.

return new Test(
   description: 'It should reject a malformed Range (no `=`) with a real 400',

   request: function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/malformed HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes 0-1\r
      \r\n
      HTTP_RAW;

      return $raw;
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->upload('statics/alphanumeric.txt', close: false);
   },

   test: function ($response) {
      if (preg_match('/Last-Modified: (.*)\r\n/i', $response, $matches)) {
         $lastModified = $matches[1];
      } else {
         $lastModified = '?';
      }

      $expected = <<<HTML_RAW
      HTTP/1.1 400 Bad Request\r
      Server: Bootgly\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 0\r
      \r\n
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'A malformed Range must be rejected with a real 400';
      }

      return true;
   }
);
