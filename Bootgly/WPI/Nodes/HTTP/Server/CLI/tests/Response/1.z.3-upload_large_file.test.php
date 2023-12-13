<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separator.line' => true,

   'response.length' => 3101895,

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      return $Response->upload('statics/screenshot.gif', close: false);
   },
   // Client API
   'request' => function () {
      return "GET /test/download/large_file/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
      // ! Asserts
      // @ Assert length of response
      $expected = 3101895;

      if (strlen($response) !== $expected) {
         Vars::$labels = ['HTTP Response length:', 'Expected:'];
         dump(strlen($response), $expected);
         return 'Response length of uploaded file by server is correct?';
      }

      return true;
   }
];
