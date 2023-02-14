<?php
use Bootgly\Bootgly;
use Bootgly\Debugger;
// SAPI
use Bootgly\Web\HTTP\Server\Request;
use Bootgly\Web\HTTP\Server\Response;
use Bootgly\Web\HTTP\Server\Router;
// CAPI?
#use Bootgly\Web\HTTP\Client\Request;
#use Bootgly\Web\HTTP\Client\Response;
// TODO ?

return [
   // Server API
   'sapi' => function (Request $Request, Response $Response, Router $Router) : Response {
      return $Response(content: 'Hello World!');
   },
   // Client API
   'capi' => function () {
      // return $Request->get('/');
      return "GET / HTTP/1.0\r\n\r\n";
   },

   'assert' => function ($response) : bool {
      /*
      return $Response->status === '200 OK'
      && $Response->body === 'Hello World!';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 12\r
      Content-Type: text/html; charset=UTF-8\r
      \r.
      Hello World!
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
      return 'Response not matched';
   }
];