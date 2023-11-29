<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'describe' => 'It should return parts of file `bytes=1-2,4-5,-1`',
   'separator.left' => '.2.4 - Requests Range - Client - Multi Part (Valid)',

   'response.length' => 557,

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      return $Response('statics/alphanumeric.txt')->upload(close: false);
   },
   // Client API
   'request' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/multi_range/1 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=1-2,4-5,-1\r
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
      Content-Type: multipart/byteranges; boundary=00000000000000000001\r
      Content-Length: 320\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      \r
      --00000000000000000001
      Content-Type: application/octet-stream
      Content-Range: bytes 1-2/62\r
      \r
      bc\r
      --00000000000000000001
      Content-Type: application/octet-stream
      Content-Range: bytes 4-5/62\r
      \r
      ef\r
      --00000000000000000001
      Content-Type: application/octet-stream
      Content-Range: bytes 61-61/62\r
      \r
      9\r
      --00000000000000000001--\r
      
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response Status did not return multiple parts of file?';
      }

      return true;
   }
];
