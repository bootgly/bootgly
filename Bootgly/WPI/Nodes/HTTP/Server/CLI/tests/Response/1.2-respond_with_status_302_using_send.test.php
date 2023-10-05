<?php
use Bootgly\ABI\Debugging\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separator.line' => 'Response Meta',

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      return $Response(status: 302); // 302 Not Found
   },
   // Client API
   'request' => function () {
      // return $Request->get('/status');
      return "GET /status HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
      /*
      return $Response->status === '302 Found'
      && $Response->body === '';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 302 Found\r
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
