<?php
use Bootgly\API\Project;
use Bootgly\ACI\Debugger;
// SAPI
use Bootgly\WPI\nodes\HTTP\Server\Request;
use Bootgly\WPI\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\WPI\nodes\HTTP\Client\Request;
#use Bootgly\WPI\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separators' => [
      'header' => '@authenticate'
   ],

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = 'Bootgly/';
      $Project->container = 'WPI/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->construct();

      $Response(content: 'Unauthorized page!');
      return $Response->authenticate();
   },
   // Client API
   'request' => function () {
      // return $Request->get('/test/auth/1');
      return "GET /test/auth/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) : bool {
      // ! Asserts
      // @ Assert response raw
      $expected = <<<HTML_RAW
      HTTP/1.1 401 Unauthorized\r
      Server: Bootgly\r
      WWW-Authenticate: Basic realm="Protected area"\r
      Content-Length: 18\r
      Content-Type: text/html; charset=UTF-8\r
      \r
      Unauthorized page!
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
      return 'Response raw not matched';
   }
];
