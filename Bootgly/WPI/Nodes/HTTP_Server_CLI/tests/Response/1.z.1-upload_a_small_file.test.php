<?php

use Bootgly\ABI\Debugging\Data\Vars;
// SAPI
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
// CAPI?
#use Bootgly\WPI\Nodes\HTTP\Client\Request;
#use Bootgly\WPI\Nodes\HTTP\Client\Response;
// TODO ?

return [
   // @ configure
   'separator.header' => '@upload',

   'response.length' => 82928,

   // @ simulate
   // Server API
   'response' => function (Request $Request, Response $Response) : Response {
      return $Response->upload('statics/image1.jpg', close: false);
   },
   // Client API
   'request' => function () {
      // return $Request->get('//header/changed/1');
      return "GET /test/download/small_file/1 HTTP/1.0\r\n\r\n";
   },

   // @ test
   'test' => function ($response) {
      // ! Asserts
      // @ Assert length of response
      $expected = 82928;

      if (strlen($response) !== $expected) {
         Vars::$labels = ['HTTP Response length:', 'Expected:'];
         dump(strlen($response), $expected);
         return 'Response length of uploaded file by server is correct?';
      }

      return true;
   }
];
