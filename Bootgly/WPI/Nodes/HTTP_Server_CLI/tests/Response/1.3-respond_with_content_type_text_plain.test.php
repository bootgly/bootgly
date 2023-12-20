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
   'separator.line' => 'Response Header',

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Response->Header->set('Content-Type', 'text/plain');
      return $Response(body: 'Hello World!');
   },
   // Client API
   'request' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /header/changed/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
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
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Header Content-Type not matched';
      }

      return true;
   }
];
