<?php
use Bootgly\ABI\Debugging\Vars;
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
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Response->Header->Cookie->append('Test1', 'value1');
      $Response->Header->Cookie->append('Test2', 'value2');

      return $Response(content: 'Hello World!');
   },
   // Client API
   'request' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /header/cookies/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
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
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Header Set-Cookie not found?';
      }

      return true;
   }
];
