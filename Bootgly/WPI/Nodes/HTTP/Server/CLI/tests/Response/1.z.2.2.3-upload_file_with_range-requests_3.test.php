<?php
use Bootgly\API\Project;
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'response.length' => 363,
   'describe' => 'It should return the entire file when `bytes=0-$fileSize`',

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = 'Bootgly/';
      $Project->container = 'WPI/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->construct();

      return $Response('statics/alphanumeric.txt')->upload(close: false);
   },
   // Client API
   'request' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/3 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=0-62\r
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
         return 'Response body did not return the entire contents of the file?';
      }

      return true;
   }
];
