<?php

use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   // ...

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "TEST1 /route4 HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response, Router $Router) {
      $Router->route('/route4', function ($Response) {
         $Response(content: 'TEST1 HTTP method!');
      }, 'TEST1');
   },

   // @ test
   'test' => function ($response) {
      /*
      return $Response->status === '200 OK'
      && $Response->body === '127.0.0.1';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 18\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      TEST1 HTTP method!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];
