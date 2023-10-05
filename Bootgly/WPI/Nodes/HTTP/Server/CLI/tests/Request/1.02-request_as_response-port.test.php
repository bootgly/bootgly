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

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET / HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $port = $Request->port;
      return $Response(content: $port);
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
