<?php
use Bootgly\Bootgly;
use Bootgly\Debugger;
// SAPI
use Bootgly\CLI\HTTP\Server\Request;
use Bootgly\CLI\HTTP\Server\Response;
// CAPI?
#use Bootgly\CLI\HTTP\Client\Request;
#use Bootgly\CLI\HTTP\Client\Response;
// TODO ?

return [
   // @ arrange

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $Response->Header->Cookie->append('Test1', 'value1');
      $Response->Header->Cookie->append('Test2', 'value2');

      return $Response(content: 'Hello World!');
   },
   // Client API
   'capi' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /header/cookies/1 HTTP/1.0\r\n\r\n";
   },

   // @ assert
   'assert' => function ($response) : bool {
      /*
      return $Response->code === '500'
      && $Response->body === ' ';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Set-Cookie: Test1=value1\r
      Set-Cookie: Test2=value2\r
      Server: Bootgly\r
      Content-Length: 12\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Hello World!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Header Set-Cookie not found?';
   }
];
