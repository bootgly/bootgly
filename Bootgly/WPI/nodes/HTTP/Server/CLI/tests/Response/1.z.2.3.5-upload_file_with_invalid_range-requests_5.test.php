<?php
use Bootgly\API\Project;
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\nodes\HTTP\Client\Request;
#use Bootgly\WPI\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'describe' => 'It should return 416 status: float `bytes=-1.1`',

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
      GET /test/download/file_with_range/one_range/5 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=-1.1\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ test
   'test' => function ($response) : bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 416 Range Not Satisfiable\r
      Server: Bootgly\r
      Content-Range: bytes */62\r
      Content-Length: 1\r
      Content-Type: text/html; charset=UTF-8\r
      \r
       
      HTML_RAW;

      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response did not return 416 HTTP Status?';
   }
];