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
   'describe' => 'It should be fresh on exact match',
   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return
      <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: insomnia/2023.4.0\r
      If-None-Match: "foo"\r
      Accept: */*\r
      \r\n\r\n
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Response->Header->set('ETag', 'W/"foo"');

      if ($Request->fresh) {
         return $Response(status: 304);
      } else {
         return $Response(content: 'test')->send();
      }
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 304 Not Modified\r
      Server: Bootgly\r
      ETag: W/"foo"\r
      Content-Length: 0\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];
