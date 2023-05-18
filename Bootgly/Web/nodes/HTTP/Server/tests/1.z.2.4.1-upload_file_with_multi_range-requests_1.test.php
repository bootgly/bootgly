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
   'response.length' => 557,
   'describe' => 'It should return parts of file `bytes=1-2,4-5,-1`',
   'separators' => [
      'left' => '.2.4 - Requests Range - Client - Multi Part (Valid)'
   ],

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
      GET /test/download/file_with_range/multi_range/1 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=1-2,4-5,-1\r
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
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response did not return multiple parts of file?';
   }
];
