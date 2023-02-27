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
   'response.length' => 303,

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      Bootgly::$Project->vendor = '@bootgly/';
      Bootgly::$Project->container = 'web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';

      Bootgly::$Project->setPath();

      return $Response('statics/screenshot.gif')->upload(close: false);
   },
   // Client API
   'capi' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/2 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=0-0\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ assert
   'assert' => function ($response) : bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 1\r
      Content-Range: bytes 0-0/3101612\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="screenshot.gif"\r
      Last-Modified: Sun, 29 Jan 2023 14:14:08 GMT\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      G
      HTML_RAW;

      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response contains part of file uploaded by server?';
   }
];