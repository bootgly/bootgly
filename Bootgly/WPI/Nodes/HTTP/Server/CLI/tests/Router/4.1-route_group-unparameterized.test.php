<?php
use Bootgly\ABI\Debugging\Code\Vars;
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
   'separator.left' => 'Route group',

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET /profile/default HTTP/1.0\r\n\r\n";
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

         $Router->route('user/maria', function ($Response) {
            $Response(content: 'Maria!');
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
      Content-Length: 16\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Default Profile!
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
