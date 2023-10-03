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
      return "GET /param8/123/param9/321/param10/abc HTTP/1.0\r\n\r\n";
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

      $Route = $Router->Route;
      $Route->Params->id = '[0-9]+';
      $Router->route('/param8/:id/param9/:id/param10/:abc', function ($Response) use ($Route) {
         $Params = $Route->Params;

         $Response(content: <<<TEXT
         Equals, Different named params: {$Params->id[0]}, {$Params->id[1]}, $Params->abc
         TEXT);
      }, GET);

      $Router->route('/*', function ($Response) {
         $Response(content: 'Catch-All!');
      }, GET);
   },

   // @ test
   'test' => function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Length: 45\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Equals, Different named params: 123, 321, abc
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Debugger::$labels = ['HTTP Response:', 'Expected:'];
         debug(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
];
