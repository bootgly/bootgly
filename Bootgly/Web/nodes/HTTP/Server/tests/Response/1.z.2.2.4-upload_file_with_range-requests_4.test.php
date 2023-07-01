<?php
use Bootgly\API\Project;
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'response.length' => 363,
   'describe' => 'It should return the entire file when `end` overlap',

   // @ simulate
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = 'Bootgly/';
      $Project->container = 'Web/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->construct();

      return $Response('statics/alphanumeric.txt')->upload(close: false);
   },
   // Client API
   'capi' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/4 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=0-63\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ test
   'test' => function ($response) : bool {
      if (preg_match('/Last-Modified: (.*)\r\n/i', $response, $matches)) {
         $lastModified = $matches[1];
      } else {
         $lastModified = '?';
      }

      $expected = <<<HTML_RAW
      HTTP/1.1 206 Partial Content\r
      Server: Bootgly\r
      Content-Length: 62\r
      Content-Range: bytes 0-61/62\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="alphanumeric.txt"\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789
      HTML_RAW;

      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected string:', 'Expected length:'];
         debug(json_encode($response), json_encode($expected), strlen($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response did not return the entire contents of the file?';
   }
];