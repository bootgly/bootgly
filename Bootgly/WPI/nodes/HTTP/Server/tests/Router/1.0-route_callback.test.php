<?php
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\modules\HTTP\Server\Router;
use Bootgly\WPI\nodes\HTTP\Server\Request;
use Bootgly\WPI\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\WPI\nodes\HTTP\Client\Request;
#use Bootgly\WPI\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separators' => [
      'separator' => 'Router'
   ],

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET / HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response, Router $Router)
   {
      $Router->route('/fail', function ($Response) {
         $Response(content: 'Fail...');
      }, GET);

      $Router->route('/', function ($Response) {
         $Response(content: 'Hello World!');
      }, GET);

      $Router->route('/*', function ($Response) {
         $Response(content: 'Catch-All!');
      }, GET);
   },

   // @ test
   'test' => function ($response) : bool {
      /*
      return $Response->status === '200 OK'
      && $Response->body === '127.0.0.1';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
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
      return 'Request not matched';
   }
];
