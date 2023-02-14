<?php
use Bootgly\Bootgly;

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
      return $Response->send(500);
   },
   // Client API
   'capi' => function () {
      // return $Request->get('/status');
      return "GET /status/500 HTTP/1.0\r\n\r\n";
   },

   'assert' => function ($response) : bool {
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
         debug(json_encode($response), $expected);
         return false;
      }

      return true;
   },

   'except' => function () : string {
      return '500 Status not matched';
   }
];