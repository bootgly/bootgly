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
   // ! Server
   'response.length' => 326,
   // API
   'sapi' => function (Request $Request, Response $Response) : Response {
      Bootgly::$Project->vendor = '@bootgly/';
      Bootgly::$Project->container = 'web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';

      Bootgly::$Project->setPath();

      return $Response('statics/screenshot.gif')->upload(offset: 0, length: 2, close: false);
   },
   // ! Client
   // API
   'capi' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /test/download/file_with_offset_length/1 HTTP/1.0\r\n\r\n";
   },

   'assert' => function ($response) : bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 2\r
      Accept-Ranges: bytes\r
      Content-Range: bytes 0-2/3101612\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="screenshot.gif"\r
      Last-Modified: Sun, 29 Jan 2023 14:14:08 GMT\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      GI
      HTML_RAW;

      // @ Assert
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