<?php
use Bootgly\Bootgly;
use Bootgly\Debugger;
// SAPI
use Bootgly\CLI\HTTP\Server\Request;
use Bootgly\CLI\HTTP\Server\Response;
// CAPI?
#use Bootgly\CLI\HTTP\Client\Request;
#use Bootgly\CLI\HTTP\Client\Response;
// TODO ?

return [
   // @ arrange
   'describe' => 'It should return the content after 5 bytes of file when `bytes=5-`',

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      Bootgly::$Project->vendor = '@bootgly/';
      Bootgly::$Project->container = 'web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';

      Bootgly::$Project->setPath();

      return $Response('statics/alphanumeric.txt')->upload(close: false);
   },
   // Client API
   'capi' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/5 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=5-\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ assert
   'assert' => function ($response) : bool {
      if (preg_match('/Last-Modified: (.*)\r\n/i', $response, $matches)) {
         $lastModified = $matches[1];
      } else {
         $lastModified = '?';
      }

      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 57\r
      Content-Range: bytes 5-61/62\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="alphanumeric.txt"\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      fghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789
      HTML_RAW;

      if (substr($response, 0, 358) !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode(substr($response, 0, 358)), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response did not return the content after 5 bytes of the file?';
   }
];
