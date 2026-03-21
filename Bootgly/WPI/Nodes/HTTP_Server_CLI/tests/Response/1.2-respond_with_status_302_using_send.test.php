<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'Response Meta'),

   request: function () {
      // return $Request->get('/status');
      return "GET /status HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(code: 302); // 302 Not Found
   },

   test: function ($response) {
      /*
      return $Response->status === '302 Found'
      && $Response->body === '';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 302 Found\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 0\r
      \r\n
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response Status not matched';
      }

      return true;
   }
);
