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
   'separator.line' => true,
   'separator.left' => '.2.1 - Requests Range - Dev',

   'response.length' => 301,

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      return $Response->upload('statics/alphanumeric.txt', offset: 0, length: 2, close: false);
   },
   // Client API
   'request' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /test/download/file_with_offset_length/1 HTTP/1.0\r\n\r\n";
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
      Content-Length: 2\r
      Content-Range: bytes 0-2/62\r
      Content-Type: application/octet-stream\r
      Content-Disposition: attachment; filename="alphanumeric.txt"\r
      Last-Modified: $lastModified\r
      Cache-Control: no-cache, must-revalidate\r
      Expires: 0\r
      \r
      ab
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response body contains part of file uploaded by server?';
      }

      return true;
   }
];
