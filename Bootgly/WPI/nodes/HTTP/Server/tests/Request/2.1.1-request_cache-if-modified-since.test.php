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
   'separators' => [
      'left' => 'HTTP/1.1 Caching Specification (RFC 7234)'
   ],
   'describe' => 'It should return stale when modified since the date',
   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return
      <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: insomnia/2023.4.0\r
      If-Modified-Since: Fri, 14 Jul 2023 09:00:00 GMT\r
      Accept: */*\r
      \r\n\r\n
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Response->Header->set('Last-Modified', 'Fri, 14 Jul 2023 10:00:00 GMT');

      if ($Request->fresh) {
         return $Response(status: 304);
      } else {
         return $Response(content: 'test')->send();
      }
   },

   // @ test
   'test' => function ($response): bool {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Last-Modified: Fri, 14 Jul 2023 10:00:00 GMT\r
      Content-Length: 4\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      test
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