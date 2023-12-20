<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'describe' => 'It should process request body when HTTP verb is GET?!',
   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return
      <<<HTTP
      GET / HTTP/1.1\r
      User-Agent: Bootgly/TCP-Server\r
      Content-Type: text/plain\r
      Content-Length: 7\r
      \r
      Testing
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $Request->receive();
      return $Response(body: $Request->contents);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 7\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Testing
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];
