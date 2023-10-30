<?php
use Bootgly\ABI\Debugging\Data\Vars;
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
   'separator.left' => 'Named params',

   // @ simulate
   // Client API
   'request' => function () {
      // return $Request->get('/');
      return "GET /param1/123 HTTP/1.0\r\n\r\n";
   },
   // Server API
   'response' => function (Request $Request, Response $Response, Router $Router)
   {
      $Router->route('/', function ($Request, $Response) {
         return $Response(content: 'Fail...');
      }, GET);

      $Router->route('/fail', function ($Request, $Response) {
         return $Response(content: 'Fail...');
      }, GET);

      $Route = $Router->Route;
      $Route->Params->id = '[0-9]+';
      $Router->route('/param1/:id', function ($Request, $Response) use ($Route) {
         return $Response(content: 'Single named param: ' . $Route->Params->id);
      }, GET);

      $Router->route('/*', function ($Request, $Response) {
         return $Response(content: 'Catch-All!');
      }, GET);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 23\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Single named param: 123
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
