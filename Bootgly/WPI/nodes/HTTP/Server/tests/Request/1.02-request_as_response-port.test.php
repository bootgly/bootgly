<?php
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\nodes\HTTP\Server\Request;
use Bootgly\WPI\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\WPI\nodes\HTTP\Client\Request;
#use Bootgly\WPI\nodes\HTTP\Client\Response;
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
   'test' => function ($response) : bool {
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
         Debugger::$labels = ['HTTP Code:'];
         debug($lastLine);
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Request not matched';
   }
];
