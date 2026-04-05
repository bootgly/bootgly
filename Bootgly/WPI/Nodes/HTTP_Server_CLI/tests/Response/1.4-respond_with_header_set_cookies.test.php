<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Modules\HTTP\Server\Response\Raw\Header\Cookie;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      // return $Request->get('//header/changed/1');
      return "GET /header/cookies/1 HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      $Cookies = $Response->Header->Cookies;

      $Cookies->append(new Cookie('Test1', 'value1'));
      $Cookies->append(new Cookie('Test2', 'value2'));

      return $Response(body: 'Hello World!');
   },

   test: function ($response) {
      /*
      return $Response->code === '500'
      && $Response->body === ' ';
      */

      $expected = <<<HTML_RAW
      HTTP/1.1 200 OK\r
      Set-Cookie: Test1=value1\r
      Set-Cookie: Test2=value2\r
      Server: Bootgly\r
      Content-Type: text/html; charset=UTF-8\r
      Content-Length: 12\r
      \r
      Hello World!
      HTML_RAW;

      // @ Assert
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Header Set-Cookie not found?';
      }

      return true;
   }
);
