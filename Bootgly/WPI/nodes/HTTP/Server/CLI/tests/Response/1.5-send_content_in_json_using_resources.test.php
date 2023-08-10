<?php
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\nodes\HTTP\Client\Request;
#use Bootgly\WPI\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separators' => [
      'separator' => 'Response Content',
      'header' => '@send'
   ],

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      return $Response->Json->send(['Hello' => 'World!']); // JSON
   },
   // Client API
   'request' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /test/content/json/1 HTTP/1.0\r\n\r\n";
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
      Content-Type: application/json\r
      Content-Length: 18\r
      \r
      {"Hello":"World!"}
      HTML_RAW;

      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response is a valid JSON?';
   }
];