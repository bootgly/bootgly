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
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET / HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $port = (string) $Request->port;
      return $Response(body: $port);
   },

   // @ test
   'test' => function ($response) {
      /*
      return $Response->status === '200 OK'
      && $Response->code === ...;
      */

      $lines = explode("\r\n", $response);
      $lastLine = $lines[count($lines) - 1];

      $code = 0;
      if ($lastLine) {
         $code = (int) $lastLine;
      }

      // @ Assert
      if ( !($code > 1000 && $code < 65535) ) {
         Vars::$labels = ['HTTP Code:'];
         dump($lastLine);
         return 'Response raw not matched';
      }

      return true;
   }
];
