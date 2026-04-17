<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   Separator: new Separator(line: 'Request Header'),

   request: function () {

      return <<<HTTP
      GET / HTTP/1.1\r
      Host: lab.bootgly.com\r
      Authorization: Basic dXNlcm5hbWU6cGFzc3dvcmQ=\r
      \r
      
      HTTP;
   },
   response: function (Request $Request, Response $Response): Response {
      $username = $Request->username;
      $password = $Request->password;

      return $Response(body: "{$username}:{$password}");
   },

   test: function ($response) {
      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 17\r
      \r
      username:password
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Response raw not matched';
      }

      return true;
   }
);
