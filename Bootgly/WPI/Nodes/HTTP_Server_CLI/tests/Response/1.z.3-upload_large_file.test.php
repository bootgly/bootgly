<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: true),

   request: function () {
      return "GET /test/download/large_file/1 HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->upload('HTTP_Server_CLI/statics/screenshot.gif', close: false);
   },
   responseLength: 3101895,

   test: function ($response) {
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
);
