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
      return $Response(status: 500);
   },
   // Client API
   'capi' => function () {
      // return $Request->get('/status');
      return "GET /status/500 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) : bool {
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
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return '500 Status not matched';
   }
];