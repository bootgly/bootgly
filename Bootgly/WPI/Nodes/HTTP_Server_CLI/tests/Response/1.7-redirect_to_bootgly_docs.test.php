<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(header: '@redirect'),

   request: function () {
      // return $Request->get('/test/auth/1');
      return "GET /test/redirect/1 HTTP/1.0\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response->redirect('https://docs.bootgly.com/');
   },

   test: function ($response) {
      // ! Asserts
      // @ Assert response raw
      $expected = <<<HTML_RAW
      HTTP/1.1 307 Temporary Redirect\r
      Server: Bootgly\r
      Location: https://docs.bootgly.com/\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 0\r
      \r
      
      HTML_RAW;

      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
);
