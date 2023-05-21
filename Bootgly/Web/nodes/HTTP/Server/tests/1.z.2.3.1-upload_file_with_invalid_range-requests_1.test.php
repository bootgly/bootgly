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
   'describe' => 'It should return 416 status: negative range start `-5-10`',
   'separators' => [
      'left' => '.2.3 - Requests Range - Client - Single Part (Invalid)'
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
      GET /test/download/file_with_range/one_range/5 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=-5-10\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ assert
   'assert' => function ($response) : bool {
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