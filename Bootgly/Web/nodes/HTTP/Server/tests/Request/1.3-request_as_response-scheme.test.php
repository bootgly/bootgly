<?php
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure

   // @ simulate
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $scheme = $Request->scheme;
      return $Response(content: $scheme);
   },
   // Client API
   'capi' => function () {
      // return $Request->get('/');
      return "GET / HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) : bool {
      /*
      return $Response->status === '200 OK'
      && $Response->code === ...;
      */

      $lines = explode("\r\n", $response);
      $body = $lines[count($lines) - 1];

      $code = 0;
      if ($body) {
         $code = (int) $body;
      }

      // @ Assert
      if ( !($code > 1000 && $code < 65535) ) {
         Debugger::$labels = ['HTTP Code:'];
         debug($body, $lines);
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Request not matched';
   }
];
