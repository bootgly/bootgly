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

   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com
      User-Agent: Bootgly/TCP-Server
      Accept-Language: en-US,en;q=0.9\r
      \r
      
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $headers = $Request->headers;
      return $Response->Json->send($headers);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 92\r
      \r
      {"Host":"lab.bootgly.com\\nUser-Agent: Bootgly\/TCP-Server\\nAccept-Language: en-US,en;q=0.9"}
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug($response, $expected);
         return 'Response raw not matched';
      }

      return true;
   }
];
