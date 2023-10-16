<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'describe' => 'It should process request post (application/x-www-form-urlencoded)!',
   #'response.length' => 50,
   // @ simulate
   // Client API
   'request' => function () {
      // ...
      return
      <<<HTTP
      POST / HTTP/1.1\r
      Host: lab.bootgly.com:8080\r
      User-Agent: insomnia/2023.4.0\r
      Content-Type: application/x-www-form-urlencoded\r
      Accept: */*\r
      Content-Length: 25\r
      \r
      test1=value1&test2=value2\r\n
      HTTP;
   },
   // Server API
   'response' => function (Request $Request, Response $Response): Response {
      $Request->receive();
      return $Response->Json->send($Request->posts);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: application/json\r
      Content-Length: 35\r
      \r
      {"test1":"value1","test2":"value2"}
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
