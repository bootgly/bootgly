<?php

use Bootgly\ACI\Debugger;
// SAPI
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
      // ...
      return
      <<<HTTP
      GET / HTTP/1.1\r
      User-Agent: Bootgly/TCP-Server\r
      Content-Type: text/plain\r
      \r
      
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      return $Response(content: $Request->at);
   },

   // @ test
   'test' => function ($response): bool {
      $at = date("H:i:s");

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 8\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      $at
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function (): string {
      return 'Request not matched';
   }
];