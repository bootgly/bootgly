<?php
use Bootgly\API\Project;
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
   'separator.line' => true,

   'response.length' => 3101895,

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = 'Bootgly/';
      $Project->container = 'WPI/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->construct();

      return $Response('statics/screenshot.gif')->upload(close: false);
   },
   // Client API
   'request' => function () {
      return "GET /test/download/large_file/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) : bool {
      // ! Asserts
      // @ Assert length of response
      $expected = 3101895;

      if (strlen($response) !== $expected) {
         Debugger::$labels = ['HTTP Response length:', 'Expected:'];
         debug(strlen($response), $expected);
         return false;
      }

      return true;
   },
   'except' => function () : string {
      return 'Response length of uploaded file by server is correct?';
   }
];
