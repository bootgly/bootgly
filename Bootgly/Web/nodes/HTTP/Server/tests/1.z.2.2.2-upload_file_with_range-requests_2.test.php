<?php
use Bootgly\Project;
use Bootgly\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ arrange
   'response.length' => 300,
   'describe' => 'It should return first 1 byte of file when `bytes=0-0`',

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = '@bootgly/';
      $Project->container = 'Web/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->setPath();

      return $Response('statics/alphanumeric.txt')->upload(close: false);
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
      if (preg_match('/Last-Modified: (.*)\r\n/i', $response, $matches)) {
         $lastModified = $matches[1];
      } else {
         $lastModified = '?';
      }

      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 1\r
      Content-Range: bytes 0-0/62\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="alphanumeric.txt"\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      a
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