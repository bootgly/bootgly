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
   'test' => function ($response): bool {
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
         return false;
      }

      return true;
   },
   'except' => function (): string {
      return 'Request not matched';
   }
];
