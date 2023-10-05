<?php
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   // ...

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET /specific HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response, Router $Router)
   {
      $Router->route('/', function ($Response) {
         $Response(content: 'Fail...');
      }, GET);

      $Router->route('/fail', function ($Response) {
         $Response(content: 'Fail...');
      }, GET);

      $Router->route('/specific', function ($Response) {
         $Response(content: 'Hello World!');
      }, GET);

      $Router->route('/*', function ($Response) {
         $Response(content: 'Catch-All!');
      }, GET);
   },

   // @ test
   'test' => function ($response) {
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
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];
