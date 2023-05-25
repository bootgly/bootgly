<?php
use Bootgly\Project;
use Bootgly\API\Debugger;
// SAPI
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
// CAPI?
#use Bootgly\Web\nodes\HTTP\Client\Request;
#use Bootgly\Web\nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ arrange
   'separators' => [
      'header' => '@upload'
   ],

   'response.length' => 82928,

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      $Project = new Project;
      $Project->vendor = 'Bootgly/';
      $Project->container = 'Web/';
      $Project->package = 'examples/';
      $Project->version = 'app/';

      $Project->construct();

      return $Response('statics/image1.jpg')->upload(close: false);
   },
   // Client API
   'capi' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /test/download/small_file/1 HTTP/1.0\r\n\r\n";
   },

   // @ assert
   'test' => function ($response) : bool {
      // ! Asserts
      // @ Assert length of response
      $expected = 82928;

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
