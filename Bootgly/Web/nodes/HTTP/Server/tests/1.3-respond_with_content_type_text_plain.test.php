<?php
use Bootgly\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separators' => [
      'separator' => 'Response Header'
   ],

   // @ simulate
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $Response->Header->set('Content-Type', 'text/plain');
      return $Response(content: 'Hello World!');
   },
   // Client API
   'capi' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /header/changed/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) : bool {
      /*
      return $Response->code === '500'
      && $Response->body === ' ';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/plain\r
      Content-Length: 12\r
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
      return 'Header Content-Type not matched';
   }
];
