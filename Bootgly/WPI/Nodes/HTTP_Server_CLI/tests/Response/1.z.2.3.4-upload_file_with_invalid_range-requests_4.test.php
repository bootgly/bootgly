<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'describe' => 'It should return 416 status: float `bytes=0-1.1`',

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      return $Response->upload('statics/alphanumeric.txt', close: false);
   },
   // Client API
   'request' => function ($host) {
      $raw = <<<HTTP_RAW
      GET /test/download/file_with_range/one_range/5 HTTP/1.1\r
      Host: {$host}\r
      User-Agent: Bootgly\r
      Range: bytes=0-1.1\r
      \r\n
      HTTP_RAW;

      return $raw;
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 416 Range Not Satisfiable\r
      Server: Bootgly\r
      Content-Range: bytes */62\r
      Content-Length: 1\r
      Content-Type: text/html; charset=UTF-8\r
      \r
       
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response Status did not return 416 HTTP Status?';
      }

      return true;
   }
];
