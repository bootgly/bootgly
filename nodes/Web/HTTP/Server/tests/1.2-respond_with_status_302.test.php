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
      return $Response->send(302); // 302 Not Found
   },
   // Client API
   'capi' => function () {
      // return $Request->get('/status');
      return "GET /status HTTP/1.0\r\n\r\n";
   },

   'assert' => function ($response) : bool {
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
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },

   'except' => function () : string {
      return 'Status not matched';
   }
];