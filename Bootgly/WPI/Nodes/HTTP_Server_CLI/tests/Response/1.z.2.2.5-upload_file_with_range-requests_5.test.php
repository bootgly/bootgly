<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'response.length' => 306,
   'describe' => 'It should return the last 5 bytes of file when `bytes=-5`',

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      return $Response->upload('statics/alphanumeric.txt', close: false);
   },
   // Client API
   'request' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/5 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=-5\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ test
   'test' => function ($response) {
      if (preg_match('/Last-Modified: (.*)\r\n/i', $response, $matches)) {
         $lastModified = $matches[1];
      } else {
         $lastModified = '?';
      }

      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 5\r
      Content-Range: bytes 57-61/62\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="alphanumeric.txt"\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      56789
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected string:', 'Expected length:'];
         dump(json_encode($response), json_encode($expected), strlen($expected));
         return 'Response body did not return the last 5 bytes of file?';
      }

      return true;
   }
];
