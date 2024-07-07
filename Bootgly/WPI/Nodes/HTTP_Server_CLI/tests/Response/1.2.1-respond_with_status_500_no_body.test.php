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

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      return $Response(code: 500);
   },
   // Client API
   'request' => function () {
      // return $Request->get('/status');
      return "GET /status/500 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
      /*
      return $Response->code === '500'
      && $Response->body === ' ';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 500 Internal Server Error\r
      Server: Bootgly\r
      Content-Length: 0\r
      Content-Type: text/html; charset=UTF-8\r
      \r\n
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response Status not matched';
      }

      return true;
   }
];
