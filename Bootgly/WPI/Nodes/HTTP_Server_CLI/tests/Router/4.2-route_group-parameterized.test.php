<?php
use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
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
      return "GET /profile/user/1 HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/', function ($Request, $Response) {
         return $Response(body: 'Fail...');
      }, GET);

      yield $Router->route('/fail', function ($Request, $Response) {
         return $Response(body: 'Fail...');
      }, GET);

      yield $Router->route('/profile/:*', function () use ($Router) {
         yield $Router->route('default', function ($Request, $Response) {
            return $Response(body: 'Default Profile!');
         });

         yield $Router->route('user/:id', function ($Request, $Response) {
            return $Response(body: 'User ID: ' . $this->Params->id);
         });
         yield $Router->route('user/bob', function ($Request, $Response) {
            return $Response(body: 'Bob!');
         });
      }, GET);

      yield $Router->route('/*', function ($Request, $Response) {
         return $Response(body: 'Catch-All!');
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
      User ID: 1
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
