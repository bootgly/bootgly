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
   'separator.left' => 'Route Catch-All',

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET /catch1 HTTP/1.0\r\n\r\n";
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

      $Router->route('/profile/:*', function () use ($Router) {
         $Router->route('default', function ($Response) {
            $Response(content: 'Default Profile!');
         });

         $Route = $Router->Route;
         $Router->route('user/:id', function ($Response) use ($Route) {
            $Response(content: 'User ID: ' . $Route->Params->id);
         });

         $Router->route('user/bob', function ($Response) {
            $Response(content: 'Bob!');
         });
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
      Content-Length: 10\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Catch-All!
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
