<?php
use Bootgly\Bootgly;
use Bootgly\Debugger;
// SAPI
use Bootgly\CLI\HTTP\Server\Request;
use Bootgly\CLI\HTTP\Server\Response;
// CAPI?
#use Bootgly\CLI\HTTP\Client\Request;
#use Bootgly\CLI\HTTP\Client\Response;
// TODO ?

return [
   // @ arrange
   'response.length' => 3101873,
   'separators' => [
      'separator' => true
   ],

   // @ act
   // Server API
   'sapi' => function (Request $Request, Response $Response) : Response {
      Bootgly::$Project->vendor = '@bootgly/';
      Bootgly::$Project->container = 'web/';
      Bootgly::$Project->package = 'examples/';
      Bootgly::$Project->version = 'app/';

      Bootgly::$Project->setPath();

      return $Response('statics/screenshot.gif')->upload(close: false);
   },
   // Client API
   'capi' => function () {
      return "GET /test/download/large_file/1 HTTP/1.0\r\n\r\n";
   },

   // @ assert
   'assert' => function ($response) : bool {
      // ! Asserts
      // @ Assert length of response
      $expected = 3101873;

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
