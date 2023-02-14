<?php
use Bootgly\Bootgly;
use Bootgly\Debugger;
// SAPI
use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;
// CAPI?
#use Bootgly\Web\HTTP\Client\Request;
#use Bootgly\Web\HTTP\Client\Response;
// TODO ?

return [
   // ! Server
   'response.length' => 327,
   // API
   'sapi' => function (Request $Request, Response $Response, Router $Router) : Response {
      Bootgly::$Project->vendor = '@bootgly/';
      Bootgly::$Project->container = 'web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';

      Bootgly::$Project->setPath();

      return $Response('statics/screenshot.gif')->upload(close: false);
   },
   // ! Client
   // API
   'capi' => function ($host) {
      // return $Request->get('//header/changed/1');
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/1 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=0-2\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   'assert' => function ($response) : bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 3\r
      Accept-Ranges: bytes\r
      Content-Range: bytes 0-2/3101612\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="screenshot.gif"\r
      Last-Modified: Sun, 29 Jan 2023 14:14:08 GMT\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      GIF
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug($response, json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },

   'except' => function () : string {
      return 'Response contains part of file uploaded by server?';
   }
];